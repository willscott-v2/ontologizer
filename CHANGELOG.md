# Ontologizer Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.14.0] - 2024-06-28

### Added
- **Enhanced Knowledge Graph Validation**: Comprehensive error checking to prevent incorrect Wikipedia/Wikidata links
  - Multi-result search with confidence scoring for better entity matching
  - Content verification to ensure Wikipedia pages actually mention the entity
  - Disambiguation page detection and avoidance
  - Improved error logging for debugging
- **Advanced JSON-LD Schema Generation**: Automatic detection and inclusion of additional schema types
  - Author detection from meta tags, CSS classes, and text patterns
  - Organization/publisher identification from site branding elements
  - FAQ schema extraction with question-answer pairing
  - HowTo schema detection for tutorials and guides
  - Comprehensive structured data for better SEO
- **Improved Confidence Scoring**: More accurate entity confidence assessment
  - Multi-source validation bonuses when multiple knowledge bases agree
  - Entity type-specific scoring (Person, Organization, Place, etc.)
  - Better validation of entity matches across all sources

### Changed
- **Wikipedia API Integration**: Now searches 5 results instead of 1 and picks the best match
- **Wikidata Integration**: Enhanced with multi-result search and label matching
- **Google Knowledge Graph**: Improved with better name matching and description relevance
- **JSON-LD Output**: Now includes author, publisher, FAQ, and HowTo schemas when detected

### Fixed
- **Entity Validation**: Prevents incorrect entity matches that could lead to wrong Wikipedia pages
- **Schema Accuracy**: Ensures generated structured data accurately represents page content
- **Error Handling**: Better logging and error messages for debugging

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

## [1.6.0] - 2024-06-21

### Added
- Paste Content option: Users can now paste HTML or visible content for analysis if URL fetch fails or for protected sites.
- Automatic fallback: If a URL cannot be fetched, the UI prompts the user to paste content.
- Admin cache log: View and delete individual cached runs from the admin page.

### Changed
- Main topic/entity extraction now includes page <title> and meta description for improved accuracy.
- Improved error handling and user prompts for fetch failures.

### Fixed
- Various UI and UX improvements for fallback and cache management.

## [1.6.1] - 2024-06-21

### Added
- OpenAI token usage and estimated cost are now tracked and displayed in the Analysis Results section.

### Changed
- Main topic and entity extraction logic now prioritizes title, meta description, and headings for more accurate topic detection and salience.
- Only truly off-topic entities are marked as irrelevant; subdomains and solutions are now correctly grouped and scored.

### Fixed
- Improved consistency between entity relevance and recommendations.

## [1.7.0] - 2024-06-21

### Added
- Combined main topic logic: If two top entities appear together in the title, meta, or headings, the plugin will use the combined phrase as the main topic (e.g., 'Higher Education Digital Marketing').

### Changed
- Improved main topic detection for intersectional/compound topics.

## [1.7.1] - 2024-06-21

### Added
- Improved combined entity detection: Finds the longest relevant phrase from top entities in title/meta/headings for main topic.
- Sub-entity inclusion: Ensures important sub-entities (e.g., 'Higher Education') are included if present in title/meta/headings.

### Changed
- More robust main topic and entity extraction for intersectional/compound topics.

## [1.7.2] - 2024-06-21

### Fixed
- Always includes capitalized n-grams (e.g., 'Higher Education') from title/meta/headings/URL as entities, ensuring core topics are never missed.

## [1.7.4] - Improved entity identification, main topic extraction, and developer attribution to Will Scott

## [1.8.0] - Added front-end cache override option for users to force fresh analysis of a URL. Minor improvements to main topic selection flexibility.

## [1.9.0] - Improved main topic extraction: Now automatically detects course/program names from page titles (e.g., "AI Marketing Course"). Enhanced phrase detection for better topic identification.

## [1.10.0] - Improved contextual entity handling for Person topics: cuisine, city, organization, restaurant, place, location, and region are no longer flagged as off-topic.

## [1.11.0] - Improved salience tips for Person topics: now recommends strengthening connections to contextually relevant entities (cuisine, city, organization, restaurant, place, location, region, book, TV show) instead of removing them.

## [1.12.0] - Recommendations now default to aligning/integrating related entities with the main topic, only suggesting removal for truly irrelevant content.

## [1.13.0] - Entities present in the title, headings, or more than once in the body are never flagged as irrelevant.

## [Unreleased]
- Improved entity identification and main topic extraction logic
- Main topic now prefers exact phrase matches and boosts Person/Organization entities
- Entities are enriched with type information (Person, Organization, etc.) for better topic selection 