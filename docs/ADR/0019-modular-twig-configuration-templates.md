# ADR 0019: Modular Twig Configuration Templates and External Stylesheet

## Status
Accepted

## Context
The main Twig template for the configuration form (`configForm.html.twig`) grew to over 2200 lines as new tabs, settings groups, inline Javascript scripts, and CSS styling blocks were added. This monolithic file made code edits difficult, increased the likelihood of merge conflicts, and slowed down development.

To improve codebase maintainability and clean up the structure, we decided to modularize this template.

## Decision
1. We extract the HTML structure of each of the 7 configuration tabs (General, Transit, Service Provider, Identity Provider, Security, Claim Mapping, and Logging) into its own child template file.
2. These child templates are placed in a sub-folder named `configForm` (matching the master template's name) under the templates directory: `templates/configForm/`.
3. The main `templates/configForm.html.twig` template remains as the master skeleton, including each child template via Twig's `{% include %}` directive.
4. All CSS styles from the inline `<style>` tags are consolidated into the external stylesheet `css/samlSSO.css` (located outside `templates/` to comply with GLPI's public asset path policies and prevent MIME/access blocks).
5. The stylesheet is registered in GLPI via the `add_css` hook in `setup.php` to ensure it is loaded correctly by the platform.

## Consequences
* **Positive**:
  * Significantly cleaner and more maintainable template layout.
  * Consolidated CSS styling prevents repetition and separates presentation from markup.
  * Easier development and troubleshooting when editing specific tabs.
* **Negative**:
  * Requires managing multiple files rather than a single file.
  * Must ensure child templates correctly reference the shared parent context variables.
