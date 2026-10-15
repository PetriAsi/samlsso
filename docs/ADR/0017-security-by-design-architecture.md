# ADR 0017: Security-by-Design Architecture & Threat Mitigation

## Status
Accepted

## Context
Standard SAML Service Provider (SP) implementations are often stateless, validating SAML Responses dynamically from browser-submitted inputs. This design exposes authentication integrations to several classes of attacks, including Unsolicited Response injection, Replay attacks, and race conditions. For a core infrastructure application like GLPI, authentication stability and security are paramount.

## Decision
We decided to enforce a stateful, database-backed state-machine architecture for the authentication lifecycle of the `samlsso` plugin. Instead of trusting assertions dynamically, we strictly bind authentication attempts to verified database state transitions.

### 1. State-Machine Enforcement (Phase Control)
We track the lifecycle of a login through 4 primary phases:
* **Phase 1 (Initial)**: New visitor.
* **Phase 2 (SAML_ACS)**: Redirected to Identity Provider (IdP), waiting for a response.
* **Phase 3 (SAML_AUTH)**: Response received, validating signature.
* **Phase 4 (GLPI_AUTH)**: Handing over to GLPI core.

This structure prevents **Unsolicited Assertions**. An attacker cannot simply POST a SAML Response to the ACS endpoint; the incoming payload is rejected unless a matching `InResponseTo` ID is registered in our database in "Phase 2".

### 2. Replay Protection
Every `SAMLResponse` contains a unique ID. We store this ID in the `samlsso_loginstates` table.
* If a response ID is presented more than once, it is blocked immediately.
* This mitigates "man-in-the-middle" and "browser-history" replay attacks.

### 3. XML Security & Signature Validation
The plugin utilizes the industry-standard `onelogin/php-saml` library to handle:
* **Signature Verification**: Ensuring the assertion is signed by the trusted IdP.
* **XXE Protection**: Preventing XML External Entity attacks during parsing.
* **Message Integrity**: Validating that the XML has not been tampered with.

---

## Hardened Threat Mitigation Matrix

| Attack Type | Prevention Method |
| :--- | :--- |
| **Unsolicited Response** | State machine rejects any assertion without a corresponding `InResponseTo` ID in the database. |
| **Replay Attack** | Database check for unique `SAML_RESPONSE_ID`. |
| **XML Signature Wrapping** | Strict validation of XML structure via the OneLogin library. |
| **Race Conditions** | Database-level phase locks prevent parallel processing of the same authentication session. |
| **Open Redirect** | Validation of the `redirect` parameter to ensure it maps exclusively to a local path. |

---

## Implementation Guidelines (Harden your Setup)
To achieve the highest level of security, the following parameters are expected:
1. **Enforce Signed Assertions**: Ensure that both the SAML Response and the Assertion inside are signed.
2. **SHA-256 or Higher**: Configure the IdP to use at least RSA-SHA256 for signatures.
3. **Strict NameID Matching**: Map the `NameID` to a unique, immutable GLPI attribute (like email or UUID).
4. **Short Timeouts**: Set clock drift and session timeout to the minimum viable values (2-3 minutes).
5. **Disable Debug in Production**: Keep debug logging disabled in production to prevent technical details leakage.

---

## Testing & Core Integrity Requirements
To ensure code quality and prevent security regression:
* **Test Suite Coverage**: Every function included in the main `LoginFlow` class and its subclasses must be mapped to and covered by the automated test suite.
* **No Core Mocking / Pseudo Functions**: We do not implement shim or pseudo functions that mimic GLPI core behaviors. Test environments must bootstrap the actual GLPI framework to execute real core behaviors rather than stubbing them out.
* **No Core Feature Manipulation**: We do not intervene, modify, or manipulate core GLPI features that the plugin utilizes. Core operations must remain untouched to respect a strict separation of duties between the authentication plugin and the main GLPI platform.

## Consequences
* **Positive**:
  - Out-of-the-box protection against unsolicited logins, token replays, and race conditions.
  - Transparent state tracking allows for security log auditing and SIEM integration.
  - Strict testing guidelines guarantee that any functional changes to login flows undergo regression checks.
  - Safe maintenance boundaries: no risk of breaking core GLPI security hooks due to dynamic code injection.
* **Negative**:
  - Requires database write capability during login initiation (storing the state), introducing small database overhead.
  - Developers must spend additional effort writing full-coverage integration tests for any edits to the `LoginFlow` routines.
