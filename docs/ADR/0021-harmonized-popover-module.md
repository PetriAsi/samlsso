# ADR 0021: Harmonized Popover Module

## Status
Accepted

## Context
The configuration UI displays various help tips, JIT rule mapping descriptions, and validation notifications (errors, warnings, success messages) across multiple tabs. To prevent styling inconsistencies and layout squishing:
1. We require a unified Popover/Tooltip user experience across all tabs.
2. We need to distinguish between validation message types (errors, warnings, success/info alerts) in the UI.
3. The underlying `ConfigItem` structure only exposes a single `errors` field, rather than separate keys for errors and warnings.

## Decision
We choose to implement a **unified popover manager module** (`SamlSsoPopoverManager`) in Javascript, accompanied by distinct CSS helper classes, classifying warnings versus errors dynamically using prefix indicators in the `errors` string.

### Design Details
- **Unified Life-cycle**: All Bootstrap popovers inside the form are managed, initialized, and destroyed through a single Javascript manager (`SamlSsoPopoverManager`), preventing conflicting event handlers from interfering with active popovers.
- **Prefix-Based Classification**: We base the message classification and visual styling (e.g. yellow warning rows and borders vs. red error rows) on prefix symbols inside the `errors` string value:
  - Messages containing `⚠️` are treated as **warnings** (styled with `color:rgb(180, 120, 8)` in Twig).
  - Messages containing `⭕` (or other validation failures) are treated as **errors** (styled with `color:red`).
- **No Separate PHP Keys**: We explicitly choose NOT to create a separate `warnings` or `types` key in the underlying `ConfigItem` arrays or database layer. Doing so would require:
  - Rewriting `evaluateItem()`, database serialization schemas, template arrays, and the form submission controllers.
  - Modifying the extensive automated test suite, which relies on the `errors` array structure to assert validation failures.
  By styling the message using prefix symbols and parsing them dynamically in the Twig/Javascript layers, we keep the PHP backend simple, robust, and backward-compatible while providing rich visual styling.

## Consequences

* **Positive**:
  * Harmonized tooltips, popovers, and warning states across all configuration tabs.
  * Preserves the existing, well-tested `ConfigItem` array structure, avoiding complex refactoring in the backend.
  * Low risk of regression and full compatibility with existing config backups/imports.
* **Negative**:
  * Couples the visual styling classification in the frontend template to character/prefix checks (e.g. checking for `⚠️` in the string).
