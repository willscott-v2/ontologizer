# Ontologizer Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2024-06-21

### Added
- **Cache Management**: Added a "Clear Cache" button on the admin page to manually clear all cached analysis results.
- **Topical Entity Extraction**: Improved the OpenAI prompt to extract key topics and concepts (e.g., "digital marketing", "SEO") in addition to traditional named entities.
- **Google Knowledge Graph API Integration**: The plugin now uses the official Google KG API when a key is provided, generating valid entity links.
- **Google Search Fallback**: If no KG API key is present, links now fall back to a standard Google search for the entity, ensuring all links are functional.
- **Confidence Scoring**: Entities are now assigned a confidence score based on the number and quality of data sources found.
- **Enhanced Admin UI**: Added a dedicated Cache Management section and improved the layout of the settings page.
- **Frontend Progress Indicators**: The UI now shows progress bars and more detailed loading states during analysis.
- **Processing Stats**: The results page now displays key metrics like processing time, total entities found, and number of enriched entities.
- **Versioning**: Implemented versioning for the plugin files and assets.

### Changed
- **Improved Error Handling**: More specific and user-friendly error messages for API timeouts and other issues.
- **Simulated KGMID Removed**: Replaced the non-functional simulated Google Knowledge Graph IDs.
- **UI/UX Polish**: Improved styling for entity lists, confidence scores, and recommendations for better readability and user experience.

### Fixed
- **Admin Page Duplication**: Corrected a bug that caused settings fields to be duplicated on the admin page.

## [1.0.0] - 2024-06-21

### Added
- Initial release of the Ontologizer plugin.
- Core functionality for URL processing and entity extraction.
- Enrichment from Wikipedia, Wikidata, ProductOntology, and a simulated Google Knowledge Graph.
- Generation of JSON-LD `about` and `mentions` schema.
- Basic content analysis and recommendations.
- WordPress admin page for API key configuration.
- Frontend shortcode `[ontologizer]` for easy embedding.
- Caching of results using WordPress transients.
- AJAX-powered form for interactive analysis.
- Basic styling for frontend and admin components. 