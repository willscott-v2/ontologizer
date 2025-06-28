jQuery(document).ready(function($) {
    
    // Form submission
    $('#ontologizer-form').on('submit', function(e) {
        e.preventDefault();
        const inputMode = $('input[name="input_mode"]:checked').val();
        let url = '';
        let pasteContent = '';
        if (inputMode === 'url') {
            url = $('#ontologizer-url').val().trim();
            if (!url) {
                showError('Please enter a valid URL');
                return;
            }
            if (!isValidUrl(url)) {
                showError('Please enter a valid URL format (e.g., https://example.com)');
                return;
            }
        } else {
            pasteContent = $('#ontologizer-paste').val().trim();
            if (!pasteContent) {
                showError('Please paste some content to analyze.');
                return;
            }
        }
        
        // Show loading state
        const button = $(this).find('button[type="submit"]');
        const buttonText = button.find('.button-text');
        const spinner = button.find('.loading-spinner');
        
        button.prop('disabled', true);
        buttonText.text('Analyzing...');
        spinner.show();
        
        // Hide previous results and errors
        $('#ontologizer-results').hide();
        $('#ontologizer-error').hide();
        
        // Show progress indicator
        showProgress();
        
        // Make AJAX request
        $.ajax({
            url: ontologizer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ontologizer_process_url',
                url: url,
                paste_content: pasteContent,
                nonce: ontologizer_ajax.nonce,
                main_topic_strategy: $('#main-topic-strategy').val(),
                clear_cache: $('#ontologizer-clear-cache').is(':checked') ? 1 : 0
            },
            timeout: 170000, // 170 second timeout, just under the server's 180s limit
            success: function(response) {
                if (response.success) {
                    displayResults(response.data);
                } else {
                    showError(response.data || 'An error occurred while processing the request');
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = 'Network error. Please try again.';
                
                if (status === 'timeout') {
                    errorMessage = 'Request timed out. The content might be too complex or the server is slow.';
                } else if (xhr.status === 403) {
                    errorMessage = 'Access denied.';
                } else if (xhr.status === 404) {
                    errorMessage = 'Not found.';
                } else if (xhr.status === 500) {
                    errorMessage = 'Server error. Please try again later.';
                }
                
                showError(errorMessage);
                
                // If in URL mode, offer paste fallback
                if (inputMode === 'url') {
                    showPasteFallback();
                }
            },
            complete: function() {
                // Reset button state
                button.prop('disabled', false);
                buttonText.text('Analyze');
                spinner.hide();
                
                // Hide progress indicator
                hideProgress();
            }
        });
    });
    
    // Tab switching
    $('.tab-button').on('click', function() {
        const tab = $(this).data('tab');
        
        // Update active tab button
        $('.tab-button').removeClass('active');
        $(this).addClass('active');
        
        // Update active tab content
        $('.tab-pane').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });
    
    // Copy JSON-LD button
    $('#ontologizer-copy-json').on('click', function() {
        const jsonText = $('#json-ld-output').text();
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(jsonText).then(function() {
                showCopySuccess();
            }).catch(function() {
                fallbackCopyTextToClipboard(jsonText);
            });
        } else {
            fallbackCopyTextToClipboard(jsonText);
        }
    });
    
    // Entity link click tracking
    $(document).on('click', '.entity-link', function() {
        const linkType = $(this).hasClass('wikipedia') ? 'Wikipedia' :
                        $(this).hasClass('wikidata') ? 'Wikidata' :
                        $(this).hasClass('google-kg') ? 'Google KG' : 'ProductOntology';
        
        // Track link clicks (you can integrate with analytics here)
        console.log('Entity link clicked:', linkType, $(this).attr('href'));
    });
    
    // Auto-resize textarea for JSON-LD
    function autoResizeTextarea() {
        const textarea = $('#json-ld-output');
        textarea.css('height', 'auto');
        textarea.css('height', textarea[0].scrollHeight + 'px');
    }
    
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }
    
    function showProgress() {
        // Create progress indicator if it doesn't exist
        if ($('#ontologizer-progress').length === 0) {
            $('.ontologizer-form').after(`
                <div id="ontologizer-progress" class="ontologizer-progress">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="progress-text">Processing URL...</div>
                </div>
            `);
        }
        
        $('#ontologizer-progress').show();
        
        // Animate progress bar
        $('.progress-fill').css('width', '0%');
        setTimeout(() => {
            $('.progress-fill').css('width', '30%');
        }, 500);
        setTimeout(() => {
            $('.progress-fill').css('width', '60%');
        }, 2000);
        setTimeout(() => {
            $('.progress-fill').css('width', '90%');
        }, 4000);
    }
    
    function hideProgress() {
        $('#ontologizer-progress').hide();
    }
    
    function displayResults(data) {
        // Clear previous stats before adding new ones
        $('.processing-stats, .ontologizer-cache-indicator').remove();
        $('#ontologizer-salience-score-container').empty().hide();

        // Display entities
        displayEntities(data.entities);
        
        // Display JSON-LD
        displayJsonLd(data.json_ld);
        
        // New: get tips and irrelevantEntities from data if present
        const tips = data.salience_tips || [];
        const irrelevantEntities = data.irrelevant_entities || [];
        displayRecommendations(data.recommendations, tips, irrelevantEntities);
        
        // Display processing stats and salience score
        displayStats(data);
        if (typeof data.topical_salience !== 'undefined') {
            displaySalienceScore(data.topical_salience, data.primary_topic);
        }
        
        // Show results
        $('#ontologizer-results').show();
        
        // Add cache indicator if necessary
        if (data.cached) {
            $('.results-header').append('<div class="ontologizer-cache-indicator"><span class="dashicons dashicons-database"></span> Cached Result</div>');
        }
        
        // Scroll to results
        $('html, body').animate({
            scrollTop: $('#ontologizer-results').offset().top - 50
        }, 500);
        
        // Store last data for download
        window.lastOntologizerData = data;
        const origDisplayResults = displayResults;
        window.displayResults = function(data) {
            window.lastOntologizerData = data;
            origDisplayResults(data);
            // Add Download as Markdown button only after results are shown
            if ($('#ontologizer-download-md').length === 0) {
                $('.results-header').append('<button id="ontologizer-download-md" class="copy-button" style="background:#007cba;margin-left:10px;">Download as Markdown</button>');
            }
            $('#ontologizer-download-md').prop('disabled', false);
            // Add or update debug section
            if ($('#ontologizer-md-debug').length === 0) {
                $('.results-header').append('<pre id="ontologizer-md-debug" style="background:#f8f8f8;border:1px solid #ccc;padding:10px;margin-top:10px;max-width:100%;overflow-x:auto;font-size:12px;"></pre>');
            }
            $('#ontologizer-md-debug').text(JSON.stringify(data, null, 2));
            // Debug log
            console.log('Ontologizer Markdown Export Data:', data);
        };
    }
    
    function displayEntities(entities) {
        const container = $('#entities-list');
        container.empty();
        
        if (entities.length === 0) {
            container.html('<p class="no-entities">No entities found.</p>');
            return;
        }
        
        const entitiesHtml = entities.map(function(entity, index) {
            const links = [];
            const confidenceClass = entity.confidence_score >= 70 ? 'high-confidence' : 
                                   entity.confidence_score >= 40 ? 'medium-confidence' : 'low-confidence';
            
            if (entity.wikipedia_url) {
                links.push(`<a href="${entity.wikipedia_url}" target="_blank" class="entity-link wikipedia">Wikipedia</a>`);
            }
            if (entity.wikidata_url) {
                links.push(`<a href="${entity.wikidata_url}" target="_blank" class="entity-link wikidata">Wikidata</a>`);
            }
            if (entity.google_kg_url) {
                links.push(`<a href="${entity.google_kg_url}" target="_blank" class="entity-link google-kg">Google KG</a>`);
            }
            if (entity.productontology_url) {
                links.push(`<a href="${entity.productontology_url}" target="_blank" class="entity-link productontology">ProductOntology</a>`);
            }
            
            const linksHtml = links.length > 0 ? `<div class="entity-links">${links.join('')}</div>` : '';
            const confidenceHtml = `<div class="confidence-score ${confidenceClass}">${entity.confidence_score}% confidence</div>`;
            
            return `
                <div class="entity-item ${confidenceClass}">
                    <div class="entity-header">
                        <div class="entity-name">${entity.name}</div>
                        ${confidenceHtml}
                    </div>
                    ${linksHtml}
                </div>
            `;
        }).join('');
        
        container.html(entitiesHtml);
    }
    
    function displayJsonLd(jsonLd) {
        const container = $('#json-ld-output');
        container.text(JSON.stringify(jsonLd, null, 2));
        
        // Auto-resize after content is loaded
        setTimeout(autoResizeTextarea, 100);
    }
    
    function displayRecommendations(recommendations, tips = [], irrelevantEntities = []) {
        const container = $('#recommendations-list');
        container.empty();
        let html = '';
        if (tips && tips.length > 0) {
            html += '<div class="salience-tips"><strong>How to improve topical salience:</strong><ul>';
            tips.forEach(function(tip) { html += '<li>' + tip + '</li>'; });
            html += '</ul></div>';
        }
        if (irrelevantEntities && irrelevantEntities.length > 0) {
            html += '<div class="irrelevant-entities"><strong>Irrelevant entities/content to consider removing:</strong> ' + irrelevantEntities.join(', ') + '</div>';
        }
        if (!recommendations || recommendations.length === 0) {
            html += '<p class="no-recommendations">No recommendations available.</p>';
        } else {
            const recommendationsHtml = recommendations.map(function(rec, index) {
                let recommendationText = '';
                if (typeof rec === 'object' && rec !== null && rec.advice) {
                    const category = rec.category ? `<strong class="rec-category">${rec.category}:</strong> ` : '';
                    recommendationText = `${category}${rec.advice}`;
                } else {
                    recommendationText = rec;
                }
                return `<div class="recommendation-item"><span class="recommendation-number">${index + 1}.</span><span class="recommendation-text">${recommendationText}</span></div>`;
            }).join('');
            html += recommendationsHtml;
        }
        container.html(html);
    }
    
    function displayStats(data) {
        let statsHtml = `
            <div class="processing-stats">
                <div class="stat-item">
                    <span class="stat-label">Processing Time:</span>
                    <span class="stat-value">${(data.processing_time || 0).toFixed(2)}s</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Entities Found:</span>
                    <span class="stat-value">${data.entities_count || 0}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Enriched Entities:</span>
                    <span class="stat-value">${data.enriched_count || 0}</span>
                </div>
        `;
        if (data.openai_token_usage) {
            statsHtml += `
                <div class="stat-item">
                    <span class="stat-label">OpenAI Tokens:</span>
                    <span class="stat-value">${data.openai_token_usage}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">OpenAI Cost:</span>
                    <span class="stat-value">$${data.openai_cost_usd}</span>
                </div>
            `;
        }
        statsHtml += '</div>';
        $('.results-header').append(statsHtml);
    }
    
    function displaySalienceScore(score, topic) {
        const container = $('#ontologizer-salience-score-container');
        // Shaded background based on score (green for high, yellow for medium, red for low)
        let bgColor = '#e74c3c';
        if (score >= 80) bgColor = '#27ae60';
        else if (score >= 50) bgColor = '#f1c40f';
        const scoreHtml = `
            <div class="ontologizer-salience-score" style="background: linear-gradient(135deg, ${bgColor}22 0%, #fff 100%); border-radius: 1.5rem; padding: 1.5rem 0; text-align: center;">
                <div class="salience-score-number" style="font-size: 3.5rem; font-weight: 800; color: ${bgColor}; line-height: 1;">${score}%</div>
                <div class="salience-primary-topic" style="margin-top: 0.5rem;">
                    <span class="topic-label">Primary Topic:</span>
                    <span class="topic-name">${topic || 'Not identified'}</span>
                </div>
            </div>
        `;
        container.html(scoreHtml).show();
    }
    
    function showError(message) {
        $('#error-message').text(message);
        $('#ontologizer-error').show();
        
        // Scroll to error
        $('html, body').animate({
            scrollTop: $('#ontologizer-error').offset().top - 50
        }, 500);
    }
    
    function showCopySuccess() {
        const button = $('#ontologizer-copy-json');
        const originalText = button.text();
        
        button.text('Copied!');
        button.addClass('copied');
        
        setTimeout(function() {
            button.text(originalText);
            button.removeClass('copied');
        }, 2000);
    }
    
    function fallbackCopyTextToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.position = 'fixed';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            showCopySuccess();
        } catch (err) {
            console.error('Fallback: Oops, unable to copy', err);
            showError('Failed to copy to clipboard. Please select and copy manually.');
        }
        
        document.body.removeChild(textArea);
    }
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + Enter to submit form
        if ((e.ctrlKey || e.metaKey) && e.keyCode === 13) {
            $('#ontologizer-form').submit();
        }
        
        // Escape to clear form
        if (e.keyCode === 27) {
            $('#ontologizer-url').val('').focus();
        }
    });
    
    // Auto-focus URL input
    $('#ontologizer-url').focus();
    
    // URL input validation
    $('#ontologizer-url').on('input', function() {
        const url = $(this).val().trim();
        if (url && !isValidUrl(url)) {
            $(this).addClass('invalid-url');
        } else {
            $(this).removeClass('invalid-url');
        }
    });
    
    // Input mode toggle
    $('input[name="input_mode"]').on('change', function() {
        if ($(this).val() === 'url') {
            $('#url-input-group').show();
            $('#paste-input-group').hide();
        } else {
            $('#url-input-group').hide();
            $('#paste-input-group').show();
        }
    });
    
    function showPasteFallback() {
        $('input[name="input_mode"][value="paste"]').prop('checked', true).trigger('change');
        $('#ontologizer-paste').focus();
        showError('We couldn\'t fetch the page automatically. Please copy and paste the HTML or visible content of the page below to analyze it.');
    }
    
    // Add Download as Markdown button
    if ($('#ontologizer-download-md').length === 0) {
        $('.results-header').append('<button id="ontologizer-download-md" class="copy-button" style="background:#007cba;margin-left:10px;">Download as Markdown</button>');
    }
    $(document).on('click', '#ontologizer-download-md', function() {
        const data = window.lastOntologizerData || {};
        if (!data || !data.entities || !data.json_ld) {
            alert('No analysis data available. Please run an analysis first.');
            return;
        }
        let md = '';
        // Page title
        if (data.page_title) {
            md += `# ${data.page_title}\n`;
        } else {
            md += '# (No page title found)\n';
        }
        md += `\n## Ontologizer Analysis\n`;
        if (data.url) md += `**URL:** ${data.url}\n`;
        if (data.primary_topic) md += `**Primary Topic:** ${data.primary_topic}\n`;
        if (typeof data.topical_salience !== 'undefined') md += `**Salience:** ${data.topical_salience}%\n`;
        if (typeof data.processing_time !== 'undefined') md += `**Processing Time:** ${data.processing_time.toFixed(2)}s\n`;
        if (typeof data.entities_count !== 'undefined') md += `**Entities Found:** ${data.entities_count}\n`;
        if (typeof data.enriched_count !== 'undefined') md += `**Enriched Entities:** ${data.enriched_count}\n`;
        md += '\n---\n';
        // Entities
        md += `\n## Entities\n`;
        if (data.entities && data.entities.length > 0) {
            md += '| Entity | Confidence | Wikipedia | Wikidata | Google KG | ProductOntology |\n';
            md += '|--------|------------|-----------|----------|----------|-----------------|\n';
            data.entities.forEach(function(entity) {
                md += `| ${entity.name || '(missing)'} | ${entity.confidence_score || ''}% | ` +
                    (entity.wikipedia_url ? `[Wikipedia](${entity.wikipedia_url})` : '') + ' | ' +
                    (entity.wikidata_url ? `[Wikidata](${entity.wikidata_url})` : '') + ' | ' +
                    (entity.google_kg_url ? `[Google KG](${entity.google_kg_url})` : '') + ' | ' +
                    (entity.productontology_url ? `[ProductOntology](${entity.productontology_url})` : '') + ' |\n';
            });
        } else {
            md += '_No entities found._\n';
        }
        // JSON-LD
        md += `\n## JSON-LD\n`;
        if (data.json_ld) {
            md += '```json\n' + JSON.stringify(data.json_ld, null, 2) + '\n```\n';
        } else {
            md += '_No JSON-LD generated._\n';
        }
        // Recommendations
        md += `\n## Recommendations\n`;
        if (data.salience_tips && data.salience_tips.length > 0) {
            md += '**How to improve topical salience:**\n';
            data.salience_tips.forEach(function(tip) { md += '- ' + tip + '\n'; });
        }
        if (data.irrelevant_entities && data.irrelevant_entities.length > 0) {
            md += '\n**Irrelevant entities/content to consider removing:** ' + data.irrelevant_entities.join(', ') + '\n';
        }
        if (data.recommendations && data.recommendations.length > 0) {
            md += '\n';
            data.recommendations.forEach(function(rec, i) {
                if (typeof rec === 'object' && rec.advice) {
                    md += `${i+1}. **${rec.category || ''}**: ${rec.advice}\n`;
                } else {
                    md += `${i+1}. ${rec}\n`;
                }
            });
        } else {
            md += '_No recommendations available._\n';
        }
        // Sanitize filename
        let filename = 'ontologizer-analysis.md';
        if (data.page_title) {
            filename = data.page_title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') + '.md';
        }
        // Download
        const blob = new Blob([md], {type: 'text/markdown'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(function() { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
    });
}); 