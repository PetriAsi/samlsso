# GEMINI.md - samlSSO GLPI Plugin

## Project Overview
`samlSSO` is a comprehensive SAML Single Sign-On (SSO) plugin for GLPI (IT Asset Management). It is a complete rewrite and redesign of the initial `PHPSAML` plugin, optimized for GLPI 11.x.

### Key Features
- **Multiple IdP Support:** Configure and use multiple SAML Identity Providers.
- **SCIM Provisioning:** Support for System for Cross-domain Identity Management (SCIM) to allow external IdPs to provision users and groups.
- **User Right Rules:** Implement complex user right mapping using GLPI's rule engine.
- **Security by Design:** Developed with a security-first approach, including session security checks and SIEM monitoring capabilities.
- **PSR-4 Compliant:** Modern PHP architecture with clean namespacing and autoloading.
- **UI Configurable:** Fully manageable via the GLPI interface without requiring manual code changes.

### Main Technologies
- **PHP:** >= 8.0.0
- **GLPI:** 11.0.0 - 11.9.99
- **SAML Library:** `onelogin/php-saml`
- **Templating:** Twig (via GLPI core)
- **Dependency Management:** Composer

## Building and Running

### Prerequisites
- A working GLPI 11.x installation.
- PHP `simplexml` and `openssl` extensions.
- Properly configured PHP session cookie settings (see GLPI documentation).

### Installation
1.  **Download/Clone:** Place the `samlsso` directory into your GLPI `plugins/` or `marketplace/` folder.
2.  **Dependencies:** Run the following command in the plugin directory:
    ```bash
    composer install --no-dev
    ```
3.  **Enable Plugin:** Log in to GLPI as a super-admin, go to **Setup > Plugins**, and click **Install** then **Enable** for `samlSSO`.
4.  **Configure:** Go to **Setup > samlSSO** to configure your Identity Providers and general settings.

### Testing
- Manual testing is performed by attempting to log in via the SAML buttons on the GLPI login screen.
- Debug mode can be enabled in the plugin configuration to log SAML exchanges.
- TODO: Investigate automated testing suite (PHPUnit is used by dependencies, but not explicitly present for the plugin itself).

## Development Conventions

### Coding Style
- **PSR Standards:** Adhere to [PSR best practices](https://www.php-fig.org/psr/) (PSR-1, PSR-2/PSR-12, PSR-4).
- **Namespacing:** All classes should be under the `GlpiPlugin\Samlsso` namespace.
- **Type Safety:** Use `strict_types=1` and proper type hinting for parameters and return values.

### Architecture
- **CommonDBTM:** Extend GLPI's `CommonDBTM` for any class that requires database persistence (e.g., `Config`, `RuleSaml`).
- **Hooks:** Use `setup.php` for hook registration and `hook.php` for implementation.
- **Templates:** Use Twig files in the `templates/` directory for all UI components.
- **Controllers:** Complex routing and logic should be handled by controllers in `src/Controller/`.

### Contribution Guidelines
- Fork the repository and create pull requests.
- Ensure all new code follows the established namespacing and security patterns.
- Keep the `samlsso.xml` and `composer.json` metadata up to date with version changes.
