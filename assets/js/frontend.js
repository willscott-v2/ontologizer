jQuery(document).ready(function($) {
    
    // Form submission
    $('#ontologizer-form').on('submit', function(e) {
        e.preventDefault();
        
        const url = $('#ontologizer-url').val().trim();
        if (!url) {
            showError('Please enter a valid URL');
            return;
        }
        
        // Validate URL format
        if (!isValidUrl(url)) {
            showError('Please enter a valid URL format (e.g., https://example.com)');
            return;
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
                nonce: ontologizer_ajax.nonce
            },
            timeout: 170000, // 170 second timeout, just under the server's 180s limit
            success: function(response) {
                if (response.success) {
                    displayResults(response.data);
                } else {
                    showError(response.data || 'An error occurred while processing the URL');
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = 'Network error. Please try again.';
                
                if (status === 'timeout') {
                    errorMessage = 'Request timed out. The URL might be too complex or the server is slow.';
                } else if (xhr.status === 403) {
                    errorMessage = 'Access denied. The website might be blocking external requests.';
                } else if (xhr.status === 404) {
                    errorMessage = 'Page not found. Please check the URL and try again.';
                } else if (xhr.status === 500) {
                    errorMessage = 'Server error. Please try again later.';
                }
                
                showError(errorMessage);
            },
            complete: function() {
                // Reset button state
                button.prop('disabled', false);
                buttonText.text('Analyze URL');
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
        
        // Display recommendations
        displayRecommendations(data.recommendations);
        
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
    
    function displayRecommendations(recommendations) {
        const container = $('#recommendations-list');
        container.empty();
        
        if (!recommendations || recommendations.length === 0) {
            container.html('<p class="no-recommendations">No recommendations available.</p>');
            return;
        }
        
        const recommendationsHtml = recommendations.map(function(rec, index) {
            let recommendationText = '';
            if (typeof rec === 'object' && rec !== null && rec.advice) {
                const category = rec.category ? `<strong class="rec-category">${rec.category}:</strong> ` : '';
                recommendationText = `${category}${rec.advice}`;
            } else {
                // Fallback for older format or simple strings
                recommendationText = rec;
            }

            return `<div class="recommendation-item">
                <span class="recommendation-number">${index + 1}.</span>
                <span class="recommendation-text">${recommendationText}</span>
            </div>`;
        }).join('');
        
        container.html(recommendationsHtml);
    }
    
    function displayStats(data) {
        const statsHtml = `
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
            </div>
        `;
        
        // Add stats to results header
        $('.results-header').append(statsHtml);
    }
    
    function displaySalienceScore(score, topic) {
        const container = $('#ontologizer-salience-score-container');
        const rotation = ((score / 100) * 180) - 90; // Map score (0-100) to rotation (-90 to +90 degrees)
        
        const scoreHtml = `
            <div class="ontologizer-salience-score">
                <div class="salience-score-gauge">
                    <div class="gauge-background"></div>
                    <div class="gauge-arc" style="transform: rotate(${rotation}deg)"></div>
                    <div class="gauge-mask"></div>
                    <div class="gauge-center-dot"></div>
                    <div class="gauge-value">${score}</div>
                </div>
                <div class="salience-primary-topic">
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
}); 