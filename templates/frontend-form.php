<div class="ontologizer-container">
    <div class="ontologizer-form">
        <h3><?php echo esc_html($atts['title']); ?></h3>
        <p class="ontologizer-description">
            Enter a URL to automatically extract named entities and generate structured data markup.
        </p>
        
        <form id="ontologizer-form">
            <div class="form-group">
                <input type="url" 
                       id="ontologizer-url" 
                       name="url" 
                       placeholder="<?php echo esc_attr($atts['placeholder']); ?>" 
                       required 
                       class="ontologizer-input">
            </div>
            
            <button type="submit" class="ontologizer-button">
                <span class="button-text">Analyze URL</span>
                <span class="loading-spinner" style="display: none;">
                    <svg width="20" height="20" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none" stroke-dasharray="31.416" stroke-dashoffset="31.416">
                            <animate attributeName="stroke-dasharray" dur="2s" values="0 31.416;15.708 15.708;0 31.416" repeatCount="indefinite"/>
                            <animate attributeName="stroke-dashoffset" dur="2s" values="0;-15.708;-31.416" repeatCount="indefinite"/>
                        </circle>
                    </svg>
                </span>
            </button>
        </form>
    </div>
    
    <div id="ontologizer-results" class="ontologizer-results" style="display: none;">
        <div id="ontologizer-salience-score-container" style="display: none;"></div>
        <div class="results-header">
            <h4>Analysis Results</h4>
            <button id="ontologizer-copy-json" class="copy-button">Copy JSON-LD</button>
        </div>
        
        <div class="results-tabs">
            <button class="tab-button active" data-tab="entities">Entities</button>
            <button class="tab-button" data-tab="json-ld">JSON-LD</button>
            <button class="tab-button" data-tab="recommendations">Recommendations</button>
        </div>
        
        <div class="tab-content">
            <div id="tab-entities" class="tab-pane active">
                <div id="entities-list"></div>
            </div>
            
            <div id="tab-json-ld" class="tab-pane">
                <pre id="json-ld-output"></pre>
            </div>
            
            <div id="tab-recommendations" class="tab-pane">
                <div id="recommendations-list"></div>
            </div>
        </div>
    </div>
    
    <div id="ontologizer-error" class="ontologizer-error" style="display: none;">
        <p id="error-message"></p>
    </div>
</div> 