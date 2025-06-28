# Ontologizer WordPress Plugin

**Version: 1.13.0**

A powerful WordPress plugin that automatically extracts named entities and key topics from webpages, enriching them with structured identifiers from Wikipedia, Wikidata, Google's Knowledge Graph, and ProductOntology. Generate SEO-optimized JSON-LD structured data and receive content optimization recommendations.

For a detailed list of changes, see the [CHANGELOG.md](CHANGELOG.md) file.

## Author

Will Scott

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
- **New**: Users can now override the cache for a URL from the front-end form (force fresh analysis)
- **Improved main topic extraction**: Now automatically detects course/program names from page titles (e.g., "AI Marketing Course")
- **Improved contextual entity handling**: For Person topics, related entities like cuisine, city, organization, restaurant, place, location, and region are no longer flagged as off-topic.
- **Improved salience tips for Person topics**: Now recommends strengthening connections to contextually relevant entities (cuisine, city, organization, restaurant, place, location, region, book, TV show) instead of removing them.
- **Entities present in the title, headings, or more than once in the body are never flagged as irrelevant.**

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

**Latest Features:**
- Improved main topic and entity extraction logic (title, meta, headings prioritized)
- OpenAI token usage and cost tracking, displayed in the UI
- More accurate salience and entity relevance
- Combined main topic logic: Detects and displays combined topics (e.g., 'Higher Education Digital Marketing') when appropriate
- Improved combined entity detection: Finds the longest relevant phrase from top entities in title/meta/headings
- Sub-entity inclusion: Ensures important sub-entities (e.g., 'Higher Education') are included if present in title/meta/headings
- Always includes capitalized n-grams (e.g., 'Higher Education') from title/meta/headings/URL as entities
- Markdown export now includes the page title as a heading

## Changelog

- Improved entity identification and main topic extraction logic
- Main topic now prefers exact phrase matches and boosts Person/Organization entities
- Entities are enriched with type information (Person, Organization, etc.) for better topic selection
- Recommendations now default to aligning/integrating related entities with the main topic, only suggesting removal for truly irrelevant content.