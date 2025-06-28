<?php

class OntologizerProcessor {
    
    private $api_keys = array();
    private $cache_duration;
    private $rate_limit_delay;
    private $openai_token_usage = 0;
    private $openai_cost_usd = 0.0;
    
    // Synonym/alias map for flexible entity matching
    private static $entity_synonyms = [
        'seo' => ['search engine optimization', 'seo'],
        'search engine optimization' => ['seo', 'search engine optimization'],
        'ppc' => ['pay per click', 'ppc'],
        'pay per click' => ['ppc', 'pay per click'],
        'sem' => ['search engine marketing', 'sem'],
        'search engine marketing' => ['sem', 'search engine marketing'],
        'higher education' => ['higher education', 'university', 'college'],
        'digital marketing' => ['digital marketing', 'online marketing'],
        'content marketing' => ['content marketing'],
        'smm' => ['social media marketing', 'smm'],
        'social media marketing' => ['smm', 'social media marketing'],
        // Add more as needed
    ];

    // Normalize a string to its canonical synonym key (lowercase, trimmed)
    private static function normalize_entity($entity) {
        $entity_lc = strtolower(trim($entity));
        foreach (self::$entity_synonyms as $key => $aliases) {
            if (in_array($entity_lc, $aliases)) {
                return $key;
            }
        }
        return $entity_lc;
    }

    // Get all synonym/alias variants for an entity
    private static function get_entity_variants($entity) {
        $entity_lc = strtolower(trim($entity));
        foreach (self::$entity_synonyms as $key => $aliases) {
            if (in_array($entity_lc, $aliases)) {
                return $aliases;
            }
        }
        return [$entity_lc];
    }
    
    public function __construct() {
        $this->api_keys = array(
            'openai' => get_option('ontologizer_openai_key', ''),
            'google_kg' => get_option('ontologizer_google_kg_key', '')
        );
        $this->cache_duration = get_option('ontologizer_cache_duration', 3600);
        $this->rate_limit_delay = get_option('ontologizer_rate_limit_delay', 0.2);
    }
    
    public function process_url($url, $main_topic_strategy = 'strict') {
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
            $cached_result['openai_token_usage'] = $this->openai_token_usage;
            $cached_result['openai_cost_usd'] = round($this->openai_cost_usd, 6);
            $cached_result['page_title'] = $cached_result['entities'][0]['name'];
            return $cached_result;
        }
        
        // Step 1: Fetch and parse the webpage
        $html_content = $this->fetch_webpage($url);
        if (!$html_content) {
            throw new Exception('Failed to fetch webpage content. Please check the URL and try again.');
        }
        
        // Step 2: Extract named entities
        $start_time = microtime(true);
        $text_parts = $this->extract_text_from_html($html_content);
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
        $main_topic = $entities[0] ?? null;
        $search_fields = [strtolower($text_parts['title']), strtolower($text_parts['meta'])];
        foreach ($text_parts['headings'] as $h) { $search_fields[] = strtolower($h); }
        $top_entities = array_slice($entities, 0, 5);
        $combos = self::get_entity_combinations($top_entities, 3);
        $best_score = 0;
        $best_combo = '';
        $best_combo_type = '';
        $entity_types = [];
        foreach ($enriched_entities as $e) {
            $entity_types[strtolower($e['name'])] = $e['type'] ?? '';
        }
        // Main topic selection strategy
        // Always prefer the longest capitalized phrase in the title that ends with Course/Program/Certificate/Workshop/Seminar
        $title_phrase = null;
        if (preg_match_all('/([A-Z][a-zA-Z]*(?: [A-Z][a-zA-Z]*)* (Course|Program|Certificate|Workshop|Seminar))/i', $text_parts['title'], $matches)) {
            if (!empty($matches[0])) {
                usort($matches[0], function($a, $b) { return strlen($b) - strlen($a); });
                $title_phrase = trim($matches[0][0]);
            }
        }
        if ($title_phrase) {
            $main_topic = $title_phrase;
        } else if ($main_topic_strategy === 'title') {
            // Use the longest entity/phrase that appears in the title
            $title_lc = strtolower($text_parts['title']);
            $candidates = array_filter($entities, function($e) use ($title_lc) {
                return strpos($title_lc, strtolower($e)) !== false;
            });
            if (!empty($candidates)) {
                usort($candidates, function($a, $b) { return strlen($b) - strlen($a); });
                $main_topic = $candidates[0];
            }
        } else if ($main_topic_strategy === 'frequent') {
            // Use the most frequent entity/phrase in the body
            $body_lc = strtolower($text_parts['body']);
            $freqs = [];
            foreach ($entities as $e) {
                $freqs[$e] = substr_count($body_lc, strtolower($e));
            }
            arsort($freqs);
            $main_topic = key($freqs);
        } else if ($main_topic_strategy === 'pattern') {
            // Use the page title if it matches a pattern (e.g., multi-word noun phrase)
            if (preg_match('/([A-Z][a-z]+( [A-Z][a-z]+)+)/', $text_parts['title'], $matches)) {
                $main_topic = $matches[1];
            }
        } else { // strict (default)
            // Prefer exact phrase matches for combos (must appear in title and body)
            foreach ($combos as $combo_arr) {
                $variant_lists = array_map([self::class, 'get_entity_variants'], $combo_arr);
                $all_variants = [[]];
                foreach ($variant_lists as $variants) {
                    $new = [];
                    foreach ($all_variants as $prefix) {
                        foreach ($variants as $v) {
                            $new[] = array_merge($prefix, [$v]);
                        }
                    }
                    $all_variants = $new;
                }
                foreach ($all_variants as $variant_combo) {
                    $phrase = implode(' ', $variant_combo);
                    $in_title = strpos(strtolower($text_parts['title']), strtolower($phrase)) !== false;
                    $in_body = strpos(strtolower($text_parts['body']), strtolower($phrase)) !== false;
                    if ($in_title && $in_body) {
                        $score = 200 + strlen($phrase);
                        $types = array_map(function($v) use ($entity_types) { return $entity_types[strtolower($v)] ?? ''; }, $variant_combo);
                        if (count(array_unique($types)) === 1 && in_array($types[0], ['person','organization'])) {
                            $score += 100;
                        }
                        if ($score > $best_score) {
                            $best_score = $score;
                            $best_combo = $phrase;
                            $best_combo_type = $types[0] ?? '';
                        }
                    }
                }
            }
            $single_entity_boosted = false;
            foreach ($enriched_entities as $e) {
                $name_lc = strtolower($e['name']);
                $type = $e['type'] ?? '';
                if (in_array($type, ['person','organization']) && $e['confidence_score'] > 60) {
                    foreach ($search_fields as $field) {
                        if (strpos($field, $name_lc) !== false) {
                            $main_topic = $e['name'];
                            $single_entity_boosted = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$single_entity_boosted && $best_combo) {
                $main_topic = $best_combo;
            }
        }
        // Add sub-entities from multi-word entities if present in title/meta/headings
        $entity_set = array_map('strtolower', $entities);
        foreach ($entities as $entity) {
            $words = explode(' ', $entity);
            if (count($words) > 1) {
                for ($i = 0; $i < count($words); $i++) {
                    for ($j = $i+1; $j <= count($words); $j++) {
                        $sub = trim(implode(' ', array_slice($words, $i, $j-$i)));
                        if (strlen($sub) > 2 && !in_array(strtolower($sub), $entity_set)) {
                            foreach ($search_fields as $field) {
                                if (strpos($field, strtolower($sub)) !== false) {
                                    $entities[] = $sub;
                                    $entity_set[] = strtolower($sub);
                                }
                            }
                        }
                    }
                }
            }
        }
        // Add n-gram (2-3 word) capitalized phrases from title/meta/headings/URL as entities if not present
        $sources = [$text_parts['title'], $text_parts['meta']];
        foreach ($text_parts['headings'] as $h) { $sources[] = $h; }
        // Add URL path as a source
        if (!empty($url)) {
            $parsed_url = parse_url($url);
            if (!empty($parsed_url['path'])) {
                $sources[] = str_replace(['-', '_', '/'], ' ', $parsed_url['path']);
            }
        }
        $entity_set = array_map('strtolower', $entities);
        $forced_ngrams = [];
        foreach ($sources as $src) {
            preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,2})\b/', $src, $matches);
            foreach ($matches[1] as $ngram) {
                $ngram_lc = strtolower($ngram);
                if (!in_array($ngram_lc, $entity_set)) {
                    $entities[] = $ngram;
                    $entity_set[] = $ngram_lc;
                    $forced_ngrams[] = $ngram;
                }
            }
        }
        if (!empty($forced_ngrams)) {
            error_log('Ontologizer: Force-added n-grams: ' . implode(', ', $forced_ngrams));
        }
        $irrelevant_entities = [];
        foreach ($enriched_entities as $entity) {
            $entity_lc = strtolower($entity['name']);
            $in_title = strpos(strtolower($text_parts['title']), $entity_lc) !== false;
            $in_headings = false;
            foreach ($text_parts['headings'] as $h) { if (strpos(strtolower($h), $entity_lc) !== false) $in_headings = true; }
            $in_body = substr_count(strtolower($text_parts['body']), $entity_lc) > 1;
            if (!$in_title && !$in_headings && !$in_body) {
                $irrelevant_entities[] = $entity['name'];
            }
        }
        $salience_tips = $this->get_salience_improvement_tips($main_topic, $irrelevant_entities);
        $result = array(
            'url' => $url,
            'entities' => $enriched_entities,
            'json_ld' => $json_ld,
            'recommendations' => $recommendations,
            'topical_salience' => $topical_salience,
            'primary_topic' => $main_topic,
            'main_topic_confidence' => !empty($enriched_entities) ? $enriched_entities[0]['confidence_score'] : 0,
            'salience_tips' => $salience_tips,
            'irrelevant_entities' => $irrelevant_entities,
            'processing_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            'entities_count' => count($enriched_entities),
            'enriched_count' => count(array_filter($enriched_entities, function($e) {
                return !empty($e['wikipedia_url']) || !empty($e['wikidata_url']) || 
                       !empty($e['google_kg_url']) || !empty($e['productontology_url']);
            })),
            'cached' => false,
            'timestamp' => time(),
            'page_title' => $text_parts['title'],
            'openai_token_usage' => $this->openai_token_usage,
            'openai_cost_usd' => round($this->openai_cost_usd, 6),
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
        $text_parts = $this->extract_text_from_html($html_content);
        $title = strtolower($text_parts['title']);
        $meta = strtolower($text_parts['meta']);
        $headings = array_map('strtolower', $text_parts['headings']);
        $body = strtolower($text_parts['body']);
        // Use OpenAI API for entity extraction if available
        if (!empty($this->api_keys['openai'])) {
            $entities = $this->extract_entities_openai($text_parts['title'] . '. ' . $text_parts['meta'] . '. ' . implode('. ', $text_parts['headings']) . '. ' . $text_parts['body']);
            if (!empty($entities)) {
                return $entities;
            }
        }
        // Fallback to improved basic entity extraction
        $entities = $this->extract_entities_basic($text_parts['title'] . '. ' . $text_parts['meta'] . '. ' . implode('. ', $text_parts['headings']) . '. ' . $text_parts['body']);
        // Score entities
        $scored = [];
        $domain_keywords = ['application security','cloud security','information security','infrastructure security','network security','end-user education','disaster recovery','business continuity','identity and access management','data security'];
        $threat_keywords = ['phishing','ransomware','malware','social engineering','advanced persistent threat','denial of service','ddos','sql injection','botnet'];
        $solution_keywords = ['zero trust','bot protection','fraud protection','ddos protection','app security','api security'];
        foreach ($entities as $entity) {
            $entity_lc = strtolower($entity);
            $score = 0;
            if (strpos($title, $entity_lc) !== false) $score += 30;
            if (strpos($meta, $entity_lc) !== false) $score += 15;
            foreach ($headings as $h) { if (strpos($h, $entity_lc) !== false) $score += 10; }
            $score += substr_count($body, $entity_lc) * 2;
            // Grouping
            $group = 'other';
            foreach ($domain_keywords as $k) if (strpos($entity_lc, $k) !== false) $group = 'domain';
            foreach ($threat_keywords as $k) if (strpos($entity_lc, $k) !== false) $group = 'threat';
            foreach ($solution_keywords as $k) if (strpos($entity_lc, $k) !== false) $group = 'solution';
            $scored[] = [ 'name' => $entity, 'score' => $score, 'group' => $group ];
        }
        // Sort by score desc
        usort($scored, function($a, $b) { return $b['score'] - $a['score']; });
        // Return just the names, sorted
        return array_column($scored, 'name');
    }
    
    private function extract_headings($dom) {
        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            foreach ($nodes as $node) {
                $headings[] = trim($node->nodeValue);
            }
        }
        return $headings;
    }

    private function extract_text_from_html($html) {
        if (empty($html)) {
            return [
                'title' => '',
                'meta' => '',
                'headings' => [],
                'body' => ''
            ];
        }
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        // Extract <title>
        $title = '';
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->nodeValue);
        }
        // Extract meta description
        $metaDesc = '';
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('name')) === 'description') {
                $metaDesc = trim($meta->getAttribute('content'));
                break;
            }
        }
        // Extract headings
        $headings = $this->extract_headings($dom);
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
        
        return [
            'title' => $title,
            'meta' => $metaDesc,
            'headings' => $headings,
            'body' => trim($text)
        ];
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
                // Track token usage and cost
                if (isset($data['usage'])) {
                    $tokens = $data['usage']['total_tokens'] ?? 0;
                    $prompt_tokens = $data['usage']['prompt_tokens'] ?? 0;
                    $completion_tokens = $data['usage']['completion_tokens'] ?? 0;
                    $this->openai_token_usage += $tokens;
                    // GPT-4o pricing (June 2024): $0.000005/input, $0.000015/output
                    $cost = ($prompt_tokens * 0.000005) + ($completion_tokens * 0.000015);
                    $this->openai_cost_usd += $cost;
                }
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
                'confidence_score' => round($relevance_score),
                'type' => null // Add type field
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
            
            // Try to determine type (Person, Organization, etc.)
            $enriched_entity['type'] = $this->detect_entity_type($enriched_entity);
            
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
            $count = substr_count(strtolower($text_content['body']), strtolower($entity));
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
        " . substr($text_content['body'], 0, 2500) . "...

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
                // Track token usage and cost
                if (isset($data['usage'])) {
                    $tokens = $data['usage']['total_tokens'] ?? 0;
                    $prompt_tokens = $data['usage']['prompt_tokens'] ?? 0;
                    $completion_tokens = $data['usage']['completion_tokens'] ?? 0;
                    $this->openai_token_usage += $tokens;
                    $cost = ($prompt_tokens * 0.000005) + ($completion_tokens * 0.000015);
                    $this->openai_cost_usd += $cost;
                }
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
            $count = substr_count(strtolower($text_content['body']), strtolower($entity));
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

    public function get_cache_summary($cache_data) {
        // Returns a summary for admin listing
        return array(
            'url' => $cache_data['url'] ?? '',
            'primary_topic' => $cache_data['primary_topic'] ?? '',
            'main_topic_confidence' => $cache_data['entities'][0]['confidence_score'] ?? 0,
            'topical_salience' => $cache_data['topical_salience'] ?? 0,
            'timestamp' => $cache_data['timestamp'] ?? '',
        );
    }

    public function identify_irrelevant_entities($enriched_entities, $main_topic) {
        // Improved: Do not flag as irrelevant any entity present in the title, headings, or more than once in the body
        $irrelevant = array();
        $main_topic_lc = strtolower($main_topic);
        $main_topic_type = null;
        $text_parts = isset($this->last_text_parts) ? $this->last_text_parts : null;
        $title = $text_parts ? strtolower($text_parts['title']) : '';
        $headings = $text_parts ? array_map('strtolower', $text_parts['headings']) : array();
        $body = $text_parts ? strtolower($text_parts['body']) : '';
        foreach ($enriched_entities as $entity) {
            $entity_lc = strtolower($entity['name']);
            $in_title = strpos($title, $entity_lc) !== false;
            $in_headings = false;
            foreach ($headings as $h) { if (strpos($h, $entity_lc) !== false) $in_headings = true; }
            $in_body = substr_count($body, $entity_lc) > 1;
            if ($in_title || $in_headings || $in_body) {
                continue; // Never flag as irrelevant
            }
            if ($entity['confidence_score'] < 40 || (strcasecmp($entity['name'], $main_topic) !== 0 && $entity['confidence_score'] < 60)) {
                $irrelevant[] = $entity['name'];
            }
        }
        return $irrelevant;
    }

    public function get_salience_improvement_tips($main_topic, $irrelevant_entities) {
        $tips = array();
        $tips[] = "Increase the frequency and contextual relevance of your main topic ('{$main_topic}') throughout the content.";
        // Determine if main topic is a Person and if there are contextually relevant entities
        $contextual_types = ['cuisine', 'city', 'organization', 'restaurant', 'place', 'location', 'region', 'creative work', 'book', 'tv show'];
        $main_topic_type = null;
        if (isset($this->last_enriched_entities)) {
            foreach ($this->last_enriched_entities as $entity) {
                if (strcasecmp($entity['name'], $main_topic) === 0 && !empty($entity['type'])) {
                    $main_topic_type = $entity['type'];
                    break;
                }
            }
        }
        $contextual_entities = array();
        if (isset($this->last_enriched_entities)) {
            foreach ($this->last_enriched_entities as $entity) {
                $entity_type = strtolower($entity['type'] ?? '');
                if ($main_topic_type === 'person' && in_array($entity_type, $contextual_types)) {
                    $contextual_entities[] = $entity['name'];
                }
            }
        }
        if ($main_topic_type === 'person' && !empty($contextual_entities)) {
            $tips[] = "Strengthen the narrative connection to related entities like: " . implode(', ', $contextual_entities) . ". These entities provide essential context and support topical authority.";
        } elseif (!empty($irrelevant_entities)) {
            $tips[] = "Align or integrate related entities with your main topic where possible. Only consider removing content if it is truly irrelevant or off-topic: " . implode(', ', $irrelevant_entities) . ".";
        }
        $tips[] = "Add more detailed sections, examples, or FAQs about '{$main_topic}' to boost topical authority.";
        return $tips;
    }

    public function get_enriched_entities_with_irrelevance($enriched_entities, $main_topic) {
        $irrelevant = $this->identify_irrelevant_entities($enriched_entities, $main_topic);
        foreach ($enriched_entities as &$entity) {
            $entity['irrelevant'] = in_array($entity['name'], $irrelevant);
        }
        return $enriched_entities;
    }

    public function process_pasted_content($content, $main_topic_strategy = 'strict') {
        // Step 1: Use provided content as HTML/text
        $html_content = $content;
        // Step 2: Extract named entities
        $start_time = microtime(true);
        $text_parts = $this->extract_text_from_html($html_content);
        $entities = $this->extract_entities($html_content);
        error_log(sprintf('Ontologizer: Entity extraction (pasted) took %.2f seconds.', microtime(true) - $start_time));
        // Step 3: Enrich entities with external data
        $start_time = microtime(true);
        $enriched_entities = $this->enrich_entities($entities);
        error_log(sprintf('Ontologizer: Entity enrichment (pasted) took %.2f seconds.', microtime(true) - $start_time));
        // Step 4: Generate JSON-LD schema
        $json_ld = $this->generate_json_ld($enriched_entities, '');
        // Step 5: Analyze content and provide recommendations
        $start_time = microtime(true);
        $recommendations = $this->analyze_content($html_content, $enriched_entities);
        error_log(sprintf('Ontologizer: Recommendation generation (pasted) took %.2f seconds.', microtime(true) - $start_time));
        // Step 6: Calculate Topical Salience Score
        $topical_salience = $this->calculate_topical_salience_score($enriched_entities);
        $main_topic = $entities[0] ?? null;
        $search_fields = [strtolower($text_parts['title']), strtolower($text_parts['meta'])];
        $top_entities = array_slice($entities, 0, 5);
        $combos = self::get_entity_combinations($top_entities, 3);
        $best_score = 0;
        $best_combo = '';
        $best_combo_type = '';
        $entity_types = [];
        foreach ($enriched_entities as $e) {
            $entity_types[strtolower($e['name'])] = $e['type'] ?? '';
        }
        // Main topic selection strategy
        // Always prefer the longest capitalized phrase in the title that ends with Course/Program/Certificate/Workshop/Seminar
        $title_phrase = null;
        if (preg_match_all('/([A-Z][a-zA-Z]*(?: [A-Z][a-zA-Z]*)* (Course|Program|Certificate|Workshop|Seminar))/i', $text_parts['title'], $matches)) {
            if (!empty($matches[0])) {
                usort($matches[0], function($a, $b) { return strlen($b) - strlen($a); });
                $title_phrase = trim($matches[0][0]);
            }
        }
        if ($title_phrase) {
            $main_topic = $title_phrase;
        } else if ($main_topic_strategy === 'title') {
            // Use the longest entity/phrase that appears in the title
            $title_lc = strtolower($text_parts['title']);
            $candidates = array_filter($entities, function($e) use ($title_lc) {
                return strpos($title_lc, strtolower($e)) !== false;
            });
            if (!empty($candidates)) {
                usort($candidates, function($a, $b) { return strlen($b) - strlen($a); });
                $main_topic = $candidates[0];
            }
        } else if ($main_topic_strategy === 'frequent') {
            // Use the most frequent entity/phrase in the body
            $body_lc = strtolower($text_parts['body']);
            $freqs = [];
            foreach ($entities as $e) {
                $freqs[$e] = substr_count($body_lc, strtolower($e));
            }
            arsort($freqs);
            $main_topic = key($freqs);
        } else if ($main_topic_strategy === 'pattern') {
            // Use the page title if it matches a pattern (e.g., multi-word noun phrase)
            if (preg_match('/([A-Z][a-z]+( [A-Z][a-z]+)+)/', $text_parts['title'], $matches)) {
                $main_topic = $matches[1];
            }
        } else { // strict (default)
            // Prefer exact phrase matches for combos (must appear in title and body)
            foreach ($combos as $combo_arr) {
                $variant_lists = array_map([self::class, 'get_entity_variants'], $combo_arr);
                $all_variants = [[]];
                foreach ($variant_lists as $variants) {
                    $new = [];
                    foreach ($all_variants as $prefix) {
                        foreach ($variants as $v) {
                            $new[] = array_merge($prefix, [$v]);
                        }
                    }
                    $all_variants = $new;
                }
                foreach ($all_variants as $variant_combo) {
                    $phrase = implode(' ', $variant_combo);
                    $in_title = strpos(strtolower($text_parts['title']), strtolower($phrase)) !== false;
                    $in_body = strpos(strtolower($text_parts['body']), strtolower($phrase)) !== false;
                    if ($in_title && $in_body) {
                        $score = 200 + strlen($phrase);
                        $types = array_map(function($v) use ($entity_types) { return $entity_types[strtolower($v)] ?? ''; }, $variant_combo);
                        if (count(array_unique($types)) === 1 && in_array($types[0], ['person','organization'])) {
                            $score += 100;
                        }
                        if ($score > $best_score) {
                            $best_score = $score;
                            $best_combo = $phrase;
                            $best_combo_type = $types[0] ?? '';
                        }
                    }
                }
            }
            $single_entity_boosted = false;
            foreach ($enriched_entities as $e) {
                $name_lc = strtolower($e['name']);
                $type = $e['type'] ?? '';
                if (in_array($type, ['person','organization']) && $e['confidence_score'] > 60) {
                    foreach ($search_fields as $field) {
                        if (strpos($field, $name_lc) !== false) {
                            $main_topic = $e['name'];
                            $single_entity_boosted = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$single_entity_boosted && $best_combo) {
                $main_topic = $best_combo;
            }
        }
        $irrelevant_entities = [];
        foreach ($enriched_entities as $entity) {
            $entity_lc = strtolower($entity['name']);
            $in_title = strpos(strtolower($text_parts['title']), $entity_lc) !== false;
            $in_headings = false;
            foreach ($text_parts['headings'] as $h) { if (strpos(strtolower($h), $entity_lc) !== false) $in_headings = true; }
            $in_body = substr_count(strtolower($text_parts['body']), $entity_lc) > 1;
            if (!$in_title && !$in_headings && !$in_body) {
                $irrelevant_entities[] = $entity['name'];
            }
        }
        $salience_tips = $this->get_salience_improvement_tips($main_topic, $irrelevant_entities);
        $result = array(
            'url' => '',
            'entities' => $enriched_entities,
            'json_ld' => $json_ld,
            'recommendations' => $recommendations,
            'topical_salience' => $topical_salience,
            'primary_topic' => $main_topic,
            'main_topic_confidence' => !empty($enriched_entities) ? $enriched_entities[0]['confidence_score'] : 0,
            'salience_tips' => $salience_tips,
            'irrelevant_entities' => $irrelevant_entities,
            'processing_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            'entities_count' => count($enriched_entities),
            'enriched_count' => count(array_filter($enriched_entities, function($e) {
                return !empty($e['wikipedia_url']) || !empty($e['wikidata_url']) || 
                       !empty($e['google_kg_url']) || !empty($e['productontology_url']);
            })),
            'cached' => false,
            'timestamp' => time(),
            'pasted_content' => true,
            'openai_token_usage' => $this->openai_token_usage,
            'openai_cost_usd' => round($this->openai_cost_usd, 6),
        );
        return $result;
    }

    // Helper: Generate all unique pairs/triples of entities (orderings included)
    private static function get_entity_combinations($entities, $max_n = 3) {
        $results = [];
        $n = count($entities);
        for ($size = 2; $size <= $max_n; $size++) {
            $indexes = range(0, $n - 1);
            $combos = self::combinations($indexes, $size);
            foreach ($combos as $combo) {
                $perms = self::permutations($combo);
                foreach ($perms as $perm) {
                    $results[] = array_map(function($i) use ($entities) { return $entities[$i]; }, $perm);
                }
            }
        }
        return $results;
    }
    // Helper: All k-combinations of an array
    private static function combinations($arr, $k) {
        $result = [];
        $n = count($arr);
        if ($k == 0) return [[]];
        for ($i = 0; $i <= $n - $k; $i++) {
            $head = [$arr[$i]];
            $tail = self::combinations(array_slice($arr, $i + 1), $k - 1);
            foreach ($tail as $t) {
                $result[] = array_merge($head, $t);
            }
        }
        return $result;
    }
    // Helper: All permutations of an array
    private static function permutations($arr) {
        if (count($arr) <= 1) return [$arr];
        $result = [];
        foreach ($arr as $i => $v) {
            $rest = $arr;
            unset($rest[$i]);
            foreach (self::permutations(array_values($rest)) as $p) {
                array_unshift($p, $v);
                $result[] = $p;
            }
        }
        return $result;
    }

    // Add this new helper function to detect entity type
    private function detect_entity_type($enriched_entity) {
        // Try to infer type from URLs or names
        if (!empty($enriched_entity['wikidata_url'])) {
            $wikidata_id = basename($enriched_entity['wikidata_url']);
            // Use Wikidata API to get type (instance of)
            $api_url = 'https://www.wikidata.org/w/api.php?action=wbgetclaims&entity=' . urlencode($wikidata_id) . '&property=P31&format=json';
            $response = wp_remote_get($api_url, array('timeout' => 10));
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                if (isset($data['claims']['P31'][0]['mainsnak']['datavalue']['value']['id'])) {
                    $instance_id = $data['claims']['P31'][0]['mainsnak']['datavalue']['value']['id'];
                    // Map some common Wikidata types
                    $type_map = [
                        'Q5' => 'person', // human
                        'Q43229' => 'organization',
                        'Q4830453' => 'business',
                        'Q3918' => 'university',
                        'Q95074' => 'company',
                        'Q16521' => 'taxon',
                        'Q571' => 'book',
                        'Q11424' => 'film',
                        'Q13442814' => 'scholarly article',
                        'Q12737077' => 'course',
                        // Add more as needed
                    ];
                    if (isset($type_map[$instance_id])) {
                        return $type_map[$instance_id];
                    }
                }
            }
        }
        // Fallback: guess from name
        if (preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $enriched_entity['name'])) {
            return 'person';
        }
        if (stripos($enriched_entity['name'], 'university') !== false || stripos($enriched_entity['name'], 'school') !== false) {
            return 'organization';
        }
        if (stripos($enriched_entity['name'], 'course') !== false) {
            return 'course';
        }
        return null;
    }
} 