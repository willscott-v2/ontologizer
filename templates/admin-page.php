<div class="wrap">
    <h1><?php _e('Ontologizer Settings', 'ontologizer'); ?></h1>
    
    <div class="ontologizer-admin-container">
        <div class="ontologizer-admin-form">
            <form method="post" action="options.php">
                <?php
                settings_fields('ontologizer_options');
                do_settings_sections('ontologizer_options');
                submit_button(__('Save Settings', 'ontologizer'));
                ?>
            </form>
        </div>
        
        <div class="ontologizer-admin-usage">
            <h2><?php _e('Usage', 'ontologizer'); ?></h2>
            <p><?php _e('Use the shortcode <code>[ontologizer]</code> in any post or page to display the Ontologizer form.', 'ontologizer'); ?></p>
            
            <h3><?php _e('Shortcode Options', 'ontologizer'); ?></h3>
            <ul>
                <li><code>title</code> - <?php _e('Custom title for the form (default: "Ontologizer")', 'ontologizer'); ?></li>
                <li><code>placeholder</code> - <?php _e('Custom placeholder text for the URL input', 'ontologizer'); ?></li>
            </ul>
            
            <h3><?php _e('Example', 'ontologizer'); ?></h3>
            <pre><code>[ontologizer title="Entity Extractor" placeholder="Enter webpage URL..."]</code></pre>
            
            <h2><?php _e('How It Works', 'ontologizer'); ?></h2>
            <ol>
                <li><?php _e('Enter a URL to analyze', 'ontologizer'); ?></li>
                <li><?php _e('The system extracts named entities from the webpage', 'ontologizer'); ?></li>
                <li><?php _e('Entities are enriched with data from Wikipedia, Wikidata, Google Knowledge Graph, and ProductOntology', 'ontologizer'); ?></li>
                <li><?php _e('JSON-LD structured data is generated for SEO optimization', 'ontologizer'); ?></li>
                <li><?php _e('Content recommendations are provided for improvement', 'ontologizer'); ?></li>
            </ol>
        </div>
        
        <div class="ontologizer-admin-cache-management">
            <h2><?php _e('Cache Management', 'ontologizer'); ?></h2>
            <p><?php _e('The plugin caches results to improve performance and avoid excessive API calls. If you are not seeing updated results from a URL you have recently analyzed, you can clear the cache manually.', 'ontologizer'); ?></p>
            <button id="ontologizer-clear-cache" class="button button-secondary">
                <span class="dashicons dashicons-trash"></span>
                <?php _e('Clear All Cached Results', 'ontologizer'); ?>
            </button>
            <span class="spinner"></span>
            <p id="ontologizer-cache-feedback" class="ontologizer-cache-feedback" style="display:none;"></p>
        </div>
    </div>
    
    <div class="ontologizer-admin-footer">
        <p>
            <?php
            printf(
                /* translators: %s: Plugin version number */
                esc_html__('Ontologizer Version %s', 'ontologizer'),
                esc_html(ONTOLOGIZER_VERSION)
            );
            ?>
        </p>
    </div>
</div> 