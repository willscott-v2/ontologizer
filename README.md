# Ontologizer WordPress Plugin

**Version: 1.1.0**

A powerful WordPress plugin that automatically extracts named entities and key topics from webpages, enriching them with structured identifiers from Wikipedia, Wikidata, Google's Knowledge Graph, and ProductOntology. Generate SEO-optimized JSON-LD structured data and receive content optimization recommendations.

For a detailed list of changes, see the [CHANGELOG.md](CHANGELOG.md) file.

## Features

- **Entity Extraction**: Automatically identify named entities (people, organizations, locations, products, etc.) from webpage content
- **Multi-Source Enrichment**: Enrich entities with data from:
  - Wikipedia
  - Wikidata
  - Google Knowledge Graph
  - ProductOntology
- **JSON-LD Generation**: Create structured data markup for improved SEO
- **Content Analysis**: Receive recommendations for content optimization
- **Interactive Interface**: User-friendly frontend with tabbed results
- **WordPress Integration**: Easy installation and shortcode usage
- **Cache Management**: Clear cached results directly from the admin dashboard.

## Installation

1. **Download the Plugin**
   - Download all plugin files to your WordPress site
   - Place them in `/wp-content/plugins/ontologizer/`

2. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Find "Ontologizer" and click "Activate"

3. **Configure API Keys (Optional)**
   - Go to WordPress Admin → Ontologizer
   - Enter your OpenAI API key for improved entity extraction
   - Enter your Google Knowledge Graph API key for enhanced entity enrichment

## Usage

### Frontend Usage

Use the shortcode `[ontologizer]` in any post or page:

```
[ontologizer]
```

**Shortcode Options:**
- `title` - Custom title for the form (default: "Ontologizer")
- `placeholder` - Custom placeholder text for the URL input

**Example:**
```
[ontologizer title="Entity Extractor" placeholder="Enter webpage URL..."]
```

### How It Works

1. **Input URL**: Enter a webpage URL to analyze
2. **Entity Extraction**: The system extracts named entities using NLP techniques
3. **Entity Enrichment**: Each entity is enriched with data from multiple sources
4. **JSON-LD Generation**: Structured data markup is created for SEO
5. **Content Analysis**: Recommendations are provided for content improvement

## API Configuration

### OpenAI API (Optional)
- Used for improved entity extraction
- Get your API key from [OpenAI Platform](https://platform.openai.com/api-keys)
- Without this key, the plugin uses basic entity extraction

### Google Knowledge Graph API (Optional)
- Used for enhanced entity enrichment
- Get your API key from [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
- Without this key, the plugin generates simulated Knowledge Graph URLs

## Output

The plugin generates:

1. **Enriched Entities**: List of entities with links to external knowledge bases
2. **JSON-LD Schema**: Structured data markup ready for webpage integration
3. **Content Recommendations**: Suggestions for improving entity coverage and content quality

### JSON-LD Example

```json
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "about": [
    {
      "@type": "Thing",
      "name": "Motorcycle",
      "additionalType": "http://www.productontology.org/id/Motorcycle",
      "sameAs": [
        "https://en.wikipedia.org/wiki/Motorcycle",
        "https://www.wikidata.org/wiki/Q34493",
        "https://www.google.com/search?kgmid=/m/04_sv"
      ]
    }
  ],
  "mentions": [...]
}
```

## File Structure

```
ontologizer/
├── ontologizer.php                 # Main plugin file
├── includes/
│   └── class-ontologizer-processor.php  # Core processing logic
├── templates/
│   ├── frontend-form.php           # Frontend form template
│   └── admin-page.php              # Admin settings page
├── assets/
│   ├── js/
│   │   └── frontend.js             # Frontend JavaScript
│   └── css/
│       └── frontend.css            # Frontend styles
└── README.md                       # This file
```

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- cURL support enabled
- JSON extension enabled

## Browser Support

- Chrome 60+
- Firefox 55+
- Safari 12+
- Edge 79+

## Troubleshooting

### Common Issues

1. **"Failed to fetch webpage content"**
   - Check if the URL is accessible
   - Ensure the website allows external requests
   - Verify your server has cURL enabled

2. **"No entities found"**
   - Try a different webpage with more named entities
   - Check if the content is properly structured
   - Consider adding an OpenAI API key for better extraction

3. **API errors**
   - Verify your API keys are correct
   - Check API usage limits
   - Ensure proper permissions are set

### Debug Mode

Enable WordPress debug mode to see detailed error messages:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## License

This plugin is licensed under the GPL v2 or later.

## Support

For support and questions:
- Create an issue on GitHub
- Check the troubleshooting section above
- Review WordPress error logs

## Changelog

For a detailed list of changes, please refer to the [CHANGELOG.md](CHANGELOG.md) file.

### Version 1.1.0
- Added cache management, improved topical entity extraction, and integrated the real Google Knowledge Graph API.
- Added confidence scoring and enhanced the UI with progress indicators and stats.
- Fixed admin page UI bugs.

### Version 1.0.0
- Initial release
- Entity extraction and enrichment
- JSON-LD generation
- Content analysis and recommendations
- WordPress admin interface
- Frontend shortcode integration 