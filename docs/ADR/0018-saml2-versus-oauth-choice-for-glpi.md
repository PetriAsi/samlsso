# ADR 0018: SAML2 versus OAuth 2.0 / OIDC Authentication for GLPI Monolith

## Status
Accepted

## Context
When implementing Single Sign-On (SSO) authentication for GLPI, we need to choose between SAML2 and OAuth 2.0 / OpenID Connect (OIDC). Choosing between them depends heavily on the specific architecture of the application being secured.

### Modernity and Microservices
OAuth/OIDC was designed for modern, distributed architectures. It uses lightweight JSON Web Tokens (JWTs) that are easy for different programming languages to parse, making OIDC ideal for Single Page Applications (SPAs), mobile apps, and microservice meshes where a stateless token is passed between services. SAML, by contrast, relies on heavy XML protocols, which are complex and CPU-heavy to parse.

### Flexibility versus Rigidity
OAuth is a **flexible** framework offering various implementation "flows" (Authorization Code, Implicit, Client Credentials, etc.). This flexibility is powerful but introduces risk: choosing or implementing the wrong flow for a use case can create major security vulnerabilities. SAML is a **rigid protocol**. It dictates a strict, formalized structure for authentication exchange. While initial configuration is complex (requiring exchange of XML metadata, encryption keys, and certificates), this rigidity means that once configured, the process is robust and offers fewer opportunities to deviate from a secure path.

### Client-Side vs Server-Side Security
OAuth/OIDC historically suffered from token theft risks in client-side browsers via XSS attacks. While modern patterns (like Backend-for-Frontend) mitigate this, high-security scenarios often require advanced, optional configurations like mutual TLS (mTLS) to bind a token to a specific client certificate (PKI) so that stolen tokens cannot be replayed.
SAML inherently processes its heavy XML assertions on the backend, reducing client-side surface area. It also has built-in, mandatory mechanisms against replay attacks, such as strict timestamp windows and unique assertion IDs that the Service Provider tracks in the database.

## Decision
We choose **SAML2** as the primary authentication protocol for the GLPI Single Sign-On plugin.

GLPI is fundamentally a classic, server-side monolithic PHP application, not a distributed microservice architecture. It does not require the lightweight JSON token passing that makes OIDC shine in modern applications. Because GLPI handles its logic and sessions entirely on the backend, the server-side processing model of SAML is a natural fit.

Furthermore, for an internal IT service management (ITSM) tool where stability and strict security controls are paramount, the rigidity of the SAML protocol is an asset. It provides a mature, "locked-down" authentication mechanism that aligns with GLPI's monolithic architecture and use cases, without the complexity of navigating modern OAuth security flows or managing advanced mTLS configurations.

## Consequences
* **Positive**:
  - Out-of-the-box backend security processing with built-in replay protection.
  - Formally defined, rigid communication structure reduces implementation configuration errors once metadata exchange succeeds.
  - Native fit with GLPI's monolithic server-side session lifecycle.
* **Negative**:
  - Complex initial configuration due to rigid XML metadata and certificate exchanges.
  - Less suitable if GLPI transitions in the future to a headless SPA front-end relying heavily on microservices.
