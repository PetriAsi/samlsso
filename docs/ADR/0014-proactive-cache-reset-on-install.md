# ADR 0014: Proactive Cache Reset on Install

## Status
Proposed

## Context
During plugin installation or upgrade, GLPI can use stale cache data (e.g., configurations, routing tables, templates, or class structures). This cached state may not align with the new schema or updated code, resulting in errors or unexpected behavior. Clearing the cache proactively during installation avoids these issues.

## Decision
We decided to:
1. Call `(new \Glpi\Cache\CacheManager())->resetAllCaches()` at the end of the `plugin_samlsso_install()` hook.
2. Wrap the call in a `class_exists()` guard to maintain compatibility with any environment or core versions where the class might not exist.
3. Shim `Glpi\Cache\CacheManager` in the testing framework to ensure that tests run in a sandbox environment do not fail due to a missing bootstrapped Symfony Kernel.

## Consequences
- **Positive**:
  - Proactively clears the GLPI cache upon plugin installation or upgrade, ensuring the system immediately loads updated configurations, schemas, and templates.
  - Keeps compatibility with all supported environments.
- **Negative**:
  - Slight, transient execution overhead during installation as the caches are purged and rebuilt on the next request.
