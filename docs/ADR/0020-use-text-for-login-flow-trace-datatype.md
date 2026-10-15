# ADR 0020: Use TEXT instead of LONGTEXT (JSON) for Login Flow Trace

## Status
Accepted

## Context
When troubleshooting SAML single sign-on flows, the plugin records tracing information (e.g., rules matching, claim mapping decisions, group/profile assignments, and timeouts) in the `loginFlowTrace` column of the `glpi_plugin_samlsso_loginstates` table.

A review of MariaDB string data type documentation indicates that for JSON data structures, MariaDB recommends using the `JSON` data type, which is a synonym/alias for `LONGTEXT`. We need to evaluate whether we should upgrade `loginFlowTrace` from `TEXT` to `LONGTEXT` (or the `JSON` alias).

## Decision
We choose to stick with the **`TEXT`** datatype for the `loginFlowTrace` column in the database schema.

### Rationale

1. **PHP Serialization Format vs. Raw JSON**:
   The `loginFlowTrace` column is populated in `LoginState::addLoginFlowTrace()` by PHP's `serialize()` function. It does not store raw JSON strings at the column level. 
   If we defined the column using MariaDB's `JSON` data type alias, modern MariaDB servers automatically append a `JSON_VALID()` check constraint. Attempting to insert PHP-serialized data into a `JSON`-constrained column would cause write operations to fail.

2. **Data Size Requirements**:
   - The trace records high-level transition phases and debug messages during a single authentication attempt.
   - A typical trace is small (usually 1 KB to 5 KB).
   - The standard `TEXT` data type allows up to **64 KB** (65,535 bytes) of storage, which provides a massive buffer for even the most complex JIT claim mapping traces.

3. **Storage and Memory Overhead**:
   - `TEXT` uses a 2-byte length prefix, whereas `LONGTEXT` uses a 4-byte length prefix.
   - Using `LONGTEXT` (which allows up to 4 GB of data) can cause MariaDB to allocate larger memory buffers for query execution, sorting, and temporary tables.
   - Sticking to `TEXT` ensures optimal memory consumption and minimizes disk/buffer footprint.

## Consequences
* **Positive**:
  - Perfect compatibility with PHP serialization.
  - Efficient storage and minimal memory footprint.
  - Zero risk of schema constraints rejecting trace records.
* **Negative**:
  - Limit of 64 KB per trace entry (extremely unlikely to be reached in practice).
