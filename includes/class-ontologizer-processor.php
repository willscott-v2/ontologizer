<?php

class OntologizerProcessor {
    
    private $api_keys = array();
    private $cache_duration;
    private $rate_limit_delay;
    
    public function __construct() {
        $this->api_keys = array(
            'openai' => get_option('ontologizer_openai_key', ''),
            'google_kg' => get_option('ontologizer_google_kg_key', '')
        );
        $this->cache_duration = get_option('ontologizer_cache_duration', 3600);
        $this->rate_limit_delay = get_option('ontologizer_rate_limit_delay', 0.2);
    }
    
    public function process_url($url) {
        // Allow this script to run for up to 3 minutes to prevent timeouts on complex pages.
        @set_time_limit(180);

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL format provided');
        }
        
        // Check cache first
        $cache_key = 'ontologizer_' . md5($url);
        $cached_result = get_transient($cache_key);
        if ($cached_result !== false) {
            $cached_result['cached'] = true; // Add flag to indicate cached result
            return $cached_result;
        }
        
        // Step 1: Fetch and parse the webpage
        $html_content = $this->fetch_webpage($url);
        if (!$html_content) {
            throw new Exception('Failed to fetch webpage content. Please check the URL and try again.');
        }
        
        // Step 2: Extract named entities
        $start_time = microtime(true);
        $entities = $this->extract_entities($html_content);
        error_log(sprintf('Ontologizer: Entity extraction took %.2f seconds.', microtime(true) - $start_time));
        
        // Step 3: Enrich entities with external data
        $start_time = microtime(true);
        $enriched_entities = $this->enrich_entities($entities);
        error_log(sprintf('Ontologizer: Entity enrichment took %.2f seconds.', microtime(true) - $start_time));
        
        // Step 4: Generate JSON-LD schema
        $json_ld = $this->generate_json_ld($enriched_entities, $url);
        
        // Step 5: Analyze content and provide recommendations
        $start_time = microtime(true);
        $recommendations = $this->analyze_content($html_content, $enriched_entities);
        error_log(sprintf('Ontologizer: Recommendation generation took %.2f seconds.', microtime(true) - $start_time));
        
        // Step 6: Calculate Topical Salience Score
        $topical_salience = $this->calculate_topical_salience_score($enriched_entities);
        $primary_topic = !empty($enriched_entities) ? $enriched_entities[0]['name'] : null;
        
        $result = array(
            'url' => $url,
            'entities' => $enriched_entities,
            'json_ld' => $json_ld,
            'recommendations' => $recommendations,
            'topical_salience' => $topical_salience,
            'primary_topic' => $primary_topic,
            'processing_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            'entities_count' => count($enriched_entities),
            'enriched_count' => count(array_filter($enriched_entities, function($e) {
                return !empty($e['wikipedia_url']) || !empty($e['wikidata_url']) || 
                       !empty($e['google_kg_url']) || !empty($e['productontology_url']);
            })),
            'cached' => false
        );
        
        // Cache the result
        set_transient($cache_key, $result, $this->cache_duration);
        
        return $result;
    }
    
    private function fetch_webpage($url) {
        $args = array(
            'timeout'     => 45, // Increased timeout
            'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36', // More recent User-Agent
            'sslverify'   => true, // Default to true for security
            'redirection' => 10,   // Allow more redirects
            'headers'     => array(
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
            )
        );
        
        $response = wp_remote_get($url, $args);

        // If the request fails, it might be due to SSL verification. Try again without it.
        if (is_wp_error($response)) {
            error_log('Ontologizer: Request failed for ' . $url . '. Error: ' . $response->get_error_message() . '. Retrying without SSL verification.');
            $args['sslverify'] = false;
            $response = wp_remote_get($url, $args);
        }
        
        if (is_wp_error($response)) {
            error_log('Ontologizer: Failed to fetch URL ' . $url . ' - ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('Ontologizer: HTTP ' . $status_code . ' for URL ' . $url);
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Check if content is too large
        if (strlen($body) > 5000000) { // 5MB limit
            $body = substr($body, 0, 5000000);
        }
        
        return $body;
    }
    
    private function extract_entities($html_content) {
        // Clean HTML and extract text content
        $text_content = $this->extract_text_from_html($html_content);
        
        // Use OpenAI API for entity extraction if available
        if (!empty($this->api_keys['openai'])) {
            $entities = $this->extract_entities_openai($text_content);
            if (!empty($entities)) {
                return $entities;
            }
        }
        
        // Fallback to improved basic entity extraction
        return $this->extract_entities_basic($text_content);
    }
    
    private function extract_text_from_html($html) {
        if (empty($html)) {
            return '';
        }

        $dom = new DOMDocument();
        // Suppress warnings from malformed HTML
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $xpath = new DOMXPath($dom);

        // Remove elements that are typically not part of the main content
        $selectors_to_remove = [
            "//script", "//style", "//noscript", "//header", "//footer", "//nav", "//aside", "//form",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' sidebar ')]",
            "//*[contains(concat(' ', normalize-space(@id), ' '), ' sidebar ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' comment ')]",
            "//*[contains(concat(' ', normalize-space(@id), ' '), ' comment ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' nav ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' footer ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' header ')]",
            "//*[contains(@id, 'cookie') or contains(@class, 'cookie') or contains(@id, 'consent') or contains(@class, 'consent')]",
            "//div[@aria-label='cookieconsent']"
        ];

        foreach ($xpath->query(implode('|', $selectors_to_remove)) as $node) {
            if ($node && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
        
        // Attempt to find the main content element by finding the element with the most text
        $main_content_selectors = [
            '//article',
            '//main',
            "//*[@role='main']",
            "//*[contains(@class, 'post-content')]",
            "//*[contains(@class, 'entry-content')]",
            "//*[contains(@id, 'main')]",
            "//*[contains(@class, 'main')]",
            "//*[contains(@id, 'content')]",
            "//*[contains(@class, 'content')]",
        ];
        
        $best_node = null;
        $max_length = 0;

        foreach ($main_content_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes) {
                foreach ($nodes as $node) {
                    $current_length = strlen(trim($node->nodeValue));
                    if ($current_length > $max_length) {
                        $max_length = $current_length;
                        $best_node = $node;
                    }
                }
            }
        }

        $text_content = '';
        if ($best_node) {
            $text_content = $best_node->nodeValue;
        } else {
            // Fallback to the whole body if a specific content area isn't found
            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body) {
                $text_content = $body->nodeValue;
            }
        }

        // Clean up text
        $text = html_entity_decode($text_content, ENT_QUOTES, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
    
    private function extract_entities_openai($text) {
        $prompt = "You are a Semantic SEO expert. Your task is to extract the most topically relevant entities from the following webpage content. Focus on the primary, secondary, and tertiary topics that define the core subject matter of the page. Prioritize concepts that would have entries in knowledge graphs like Wikipedia. Return a JSON object with a single key 'entities' which contains an array of these topics, strictly ordered from most to least important. Example: {\"entities\": [\"Topic 1\", \"Topic 2\"]}\n\n" . substr($text, 0, 8000);
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_keys['openai'],
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o',
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                ),
                'max_tokens' => 700,
                'temperature' => 0.5,
                'response_format' => ['type' => 'json_object']
            )),
            'timeout' => 45
        ));
        
        if (is_wp_error($response)) {
            error_log('Ontologizer: OpenAI API error - ' . $response->get_error_message());
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            $content = json_decode($data['choices'][0]['message']['content'], true);
            
            if (isset($content['entities']) && is_array($content['entities'])) {
                return $content['entities'];
            }
        }
        
        // Fallback if the response is not as expected
        return $this->extract_entities_basic($text);
    }
    
    private function extract_entities_basic($text) {
        $entities = array();
        
        // Extract capitalized words that might be entities
        preg_match_all('/\b[A-Z][a-zA-Z\s&]+(?:\s+[A-Z][a-zA-Z\s&]+)*\b/', $text, $matches);
        
        foreach ($matches[0] as $match) {
            $match = trim($match);
            if (strlen($match) > 2 && strlen($match) < 50) {
                $entities[] = $match;
            }
        }
        
        // Extract potential product names and brands
        preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\s+(?:Pro|Max|Plus|Ultra|Elite|Premium|Standard|Basic|Lite|Mini|Air|Pro|Studio|Enterprise|Professional)\b/i', $text, $product_matches);
        foreach ($product_matches[0] as $match) {
            $entities[] = trim($match);
        }
        
        // Remove duplicates and common words
        $entities = array_unique($entities);
        $common_words = array('The', 'And', 'Or', 'But', 'In', 'On', 'At', 'To', 'For', 'Of', 'With', 'By', 'From', 'This', 'That', 'These', 'Those', 'All', 'Some', 'Any', 'Each', 'Every', 'No', 'Not', 'Only', 'Just', 'Very', 'More', 'Most', 'Less', 'Least', 'Much', 'Many', 'Few', 'Several', 'Various', 'Different', 'Same', 'Similar', 'Other', 'Another', 'Next', 'Last', 'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth');
        $entities = array_diff($entities, $common_words);
        
        // Filter out entities that are too generic
        $entities = array_filter($entities, function($entity) {
            $generic_patterns = array('/^\d+$/', '/^[A-Z]$/', '/^[A-Z]\s*[A-Z]$/');
            foreach ($generic_patterns as $pattern) {
                if (preg_match($pattern, $entity)) {
                    return false;
                }
            }
            return true;
        });
        
        return array_slice($entities, 0, 40);
    }
    
    private function enrich_entities($entities) {
        $enriched = array();
        $total_entities = count($entities);

        foreach ($entities as $index => $entity) {
            // Calculate a base score based on relevance (position in the array)
            // The most relevant entity (index 0) gets the highest base score.
            $relevance_score = (($total_entities - $index) / $total_entities) * 70;

            $enriched_entity = array(
                'name' => $entity,
                'wikipedia_url' => null,
                'wikidata_url' => null,
                'google_kg_url' => null,
                'productontology_url' => null,
                'confidence_score' => round($relevance_score)
            );
            
            // Enrich with Wikipedia
            $enriched_entity['wikipedia_url'] = $this->find_wikipedia_url($entity);
            
            // Enrich with Wikidata
            if ($enriched_entity['wikipedia_url']) {
                $enriched_entity['wikidata_url'] = $this->find_wikidata_url_from_wikipedia($enriched_entity['wikipedia_url']);
            }
            // Fallback to direct Wikidata search if not found via Wikipedia
            if (!$enriched_entity['wikidata_url']) {
                $enriched_entity['wikidata_url'] = $this->find_wikidata_url_direct($entity);
            }
            
            // Enrich with Google Knowledge Graph
            $enriched_entity['google_kg_url'] = $this->find_google_kg_url($entity);
            
            // Enrich with ProductOntology
            $enriched_entity['productontology_url'] = $this->find_productontology_url($entity);
            
            // Update confidence score based on found sources
            $enriched_entity['confidence_score'] = $this->calculate_confidence_score($enriched_entity, $relevance_score);
            
            $enriched[] = $enriched_entity;
            
            // Rate limiting
            usleep($this->rate_limit_delay * 1000000);
        }
        
        // Sort by final confidence score
        usort($enriched, function($a, $b) {
            return $b['confidence_score'] - $a['confidence_score'];
        });
        
        return $enriched;
    }
    
    private function find_wikipedia_url($entity) {
        $search_url = 'https://en.wikipedia.org/w/api.php?action=opensearch&search=' . urlencode($entity) . '&limit=1&namespace=0&format=json';
        
        $response = wp_remote_get($search_url, array('timeout' => 10));
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data[3][0])) {
            return $data[3][0];
        }
        
        return null;
    }
    
    private function find_wikidata_url_from_wikipedia($wikipedia_url) {
        if (!$wikipedia_url) {
            return null;
        }
        
        // Extract page title from Wikipedia URL
        $page_title = basename($wikipedia_url);
        
        $api_url = 'https://en.wikipedia.org/w/api.php?action=query&prop=pageprops&titles=' . urlencode($page_title) . '&format=json';
        
        $response = wp_remote_get($api_url, array('timeout' => 10));
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['query']['pages'])) {
            foreach ($data['query']['pages'] as $page) {
                if (isset($page['pageprops']['wikibase_item'])) {
                    return 'https://www.wikidata.org/wiki/' . $page['pageprops']['wikibase_item'];
                }
            }
        }
        
        return null;
    }

    private function find_wikidata_url_direct($entity) {
        $api_url = 'https://www.wikidata.org/w/api.php?action=wbsearchentities&search=' . urlencode($entity) . '&language=en&limit=1&format=json';

        $response = wp_remote_get($api_url, array('timeout' => 10));
        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['search'][0]['id'])) {
            return 'https://www.wikidata.org/wiki/' . $data['search'][0]['id'];
        }

        return null;
    }
    
    private function find_google_kg_url($entity) {
        if (!empty($this->api_keys['google_kg'])) {
            // Use the Google Knowledge Graph API
            $api_url = 'https://kgsearch.googleapis.com/v1/entities:search?query=' . urlencode($entity) . '&key=' . $this->api_keys['google_kg'] . '&limit=1';
            
            $response = wp_remote_get($api_url, array('timeout' => 10));
            if (is_wp_error($response)) {
                error_log('Ontologizer: Google KG API error - ' . $response->get_error_message());
                return $this->get_google_search_fallback_url($entity);
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (isset($data['itemListElement'][0]['result']['@id'])) {
                $kgid = $data['itemListElement'][0]['result']['@id'];
                $mid = str_replace('kg:', '', $kgid);
                return 'https://www.google.com/search?kgmid=' . $mid;
            }
        }
        
        // Fallback to a standard Google search if no API key or no result
        return $this->get_google_search_fallback_url($entity);
    }
    
    private function get_google_search_fallback_url($entity) {
        return 'https://www.google.com/search?q=' . urlencode($entity);
    }
    
    private function find_productontology_url($entity) {
        $slugs_to_try = [
            str_replace(' ', '_', ucwords(strtolower($entity))), // Ucwords_With_Underscores
            str_replace(' ', '-', strtolower($entity)),          // lowercase-with-hyphens
            str_replace(' ', '_', strtolower($entity)),          // lowercase_with_underscores
            ucfirst(strtolower($entity)),                        // Capitalized
            strtoupper($entity)                                  // UPPERCASE
        ];

        $paths_to_try = ['/id/', '/doc/'];

        foreach ($paths_to_try as $path) {
            foreach (array_unique($slugs_to_try) as $slug) {
                $url = 'http://www.productontology.org' . $path . $slug;
                $response = wp_remote_head($url, array('timeout' => 5));
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    return $url;
                }
            }
        }
        
        return null;
    }
    
    private function calculate_confidence_score($entity, $base_score) {
        $source_bonus = 0;
        
        if ($entity['wikipedia_url']) $source_bonus += 10;
        if ($entity['wikidata_url']) $source_bonus += 5;
        if ($entity['google_kg_url'] && strpos($entity['google_kg_url'], 'kgmid=') !== false) $source_bonus += 10;
        if ($entity['productontology_url']) $source_bonus += 5;

        $final_score = $base_score + $source_bonus;
        
        return min(100, round($final_score)); // Cap score at 100
    }
    
    private function generate_json_ld($enriched_entities, $page_url) {
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'url' => $page_url,
            'about' => array(),
            'mentions' => array()
        );
        
        foreach ($enriched_entities as $entity) {
            $same_as = array();
            
            if ($entity['wikipedia_url']) {
                $same_as[] = $entity['wikipedia_url'];
            }
            if ($entity['wikidata_url']) {
                $same_as[] = $entity['wikidata_url'];
            }
            if ($entity['google_kg_url']) {
                $same_as[] = $entity['google_kg_url'];
            }
            
            $thing = array(
                '@type' => 'Thing',
                'name' => $entity['name']
            );
            
            if ($entity['productontology_url']) {
                $thing['additionalType'] = $entity['productontology_url'];
            }
            
            if (!empty($same_as)) {
                $thing['sameAs'] = $same_as;
            }
            
            $schema['about'][] = $thing;
            $schema['mentions'][] = $thing;
        }
        
        return $schema;
    }
    
    private function analyze_content($html_content, $enriched_entities) {
        // If OpenAI key is available, use the advanced recommendation engine.
        if (!empty($this->api_keys['openai'])) {
            return $this->generate_seo_recommendations_openai($html_content, $enriched_entities);
        }

        // Fallback to basic analysis if no API key.
        $text_content = $this->extract_text_from_html($html_content);
        $recommendations = array();
        
        $entity_names = array_column($enriched_entities, 'name');
        
        foreach ($entity_names as $entity) {
            $count = substr_count(strtolower($text_content), strtolower($entity));
            if ($count <= 1) {
                $recommendations[] = "Consider expanding coverage of '{$entity}' with additional context, examples, or data to build more topical authority.";
            }
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Content appears to have good entity coverage. Review the generated JSON-LD for inclusion in your page schema to improve SEO.';
        }
        
        return array_slice($recommendations, 0, 5);
    }

    private function generate_seo_recommendations_openai($html_content, $enriched_entities) {
        $text_content = $this->extract_text_from_html($html_content);
        
        $top_entities = array_slice(array_filter($enriched_entities, function($e) {
            return $e['confidence_score'] > 50;
        }), 0, 5);
        $entity_list_str = implode(', ', array_column($top_entities, 'name'));

        $prompt = "You are a world-class Semantic SEO strategist, specializing in topical authority and schema optimization. Analyze the following webpage content and its most salient topical entities to provide expert, actionable recommendations for improving its semantic density and authority.

        **Page Text Summary:**
        " . substr($text_content, 0, 2500) . "...

        **Most Salient Topical Entities Identified:**
        {$entity_list_str}

        **Your Task:**
        Provide a structured set of recommendations in a JSON object format. The JSON object must contain a single key: `recommendations`. The value should be an array of objects, where each object has two keys: `category` (e.g., 'Semantic Gaps', 'Content Depth', 'Strategic Guidance') and `advice` (the specific recommendation string).

        Example:
        {
          \"recommendations\": [
            { \"category\": \"Semantic Gaps\", \"advice\": \"Cover the topic of 'Voice Search Optimization' as it's highly relevant.\" },
            { \"category\": \"Content Depth\", \"advice\": \"Expand on 'Local SEO' by including case studies and FAQs.\" }
          ]
        }

        Return *only* the raw JSON object, without any surrounding text, formatting, or explanations.";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_keys['openai'],
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o',
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                ),
                'max_tokens' => 800,
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object']
            )),
            'timeout' => 45
        ));

        if (is_wp_error($response)) {
            error_log('Ontologizer: OpenAI Recommendation API error - ' . $response->get_error_message());
            return $this->analyze_content_fallback($html_content, $enriched_entities); // Fallback to basic
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['choices'][0]['message']['content'])) {
            $content = json_decode($data['choices'][0]['message']['content'], true);
            
            if (isset($content['recommendations']) && is_array($content['recommendations'])) {
                return $content['recommendations'];
            }
        }
        
        // Fallback if the response is not as expected
        return $this->analyze_content_fallback($html_content, $enriched_entities);
    }

    private function analyze_content_fallback($html_content, $enriched_entities) {
        $text_content = $this->extract_text_from_html($html_content);
        $recommendations = array();
        
        $entity_names = array_column($enriched_entities, 'name');
        
        foreach ($entity_names as $entity) {
            $count = substr_count(strtolower($text_content), strtolower($entity));
            if ($count <= 1) {
                $recommendations[] = "Consider expanding coverage of '{$entity}' with additional context, examples, or data to build more topical authority.";
            }
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Content appears to have good entity coverage. Review the generated JSON-LD for inclusion in your page schema to improve SEO.';
        }
        
        return array_slice($recommendations, 0, 5);
    }

    private function calculate_topical_salience_score($enriched_entities) {
        if (empty($enriched_entities)) {
            return 0;
        }

        $total_entities = count($enriched_entities);
        $sum_of_scores = array_sum(array_column($enriched_entities, 'confidence_score'));
        $high_confidence_count = count(array_filter($enriched_entities, function($e) {
            return $e['confidence_score'] >= 85;
        }));
        $kg_mid_count = count(array_filter($enriched_entities, function($e) {
            return strpos($e['google_kg_url'] ?? '', 'kgmid=') !== false;
        }));

        // Weighted score calculation
        $avg_score_component = ($sum_of_scores / $total_entities); // Max 100
        $high_confidence_component = ($high_confidence_count / $total_entities) * 100; // Max 100
        $kg_mid_component = ($kg_mid_count / $total_entities) * 100; // Max 100

        // Combine with weights: Average score is most important, then high confidence entities, then KG presence.
        $final_score = ($avg_score_component * 0.5) + ($high_confidence_component * 0.35) + ($kg_mid_component * 0.15);

        return round($final_score);
    }
} 