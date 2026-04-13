<?php
declare(strict_types=1);
/**
 * Bootstrap for samlSSO plugin PHPUnit tests.
 *
 * This file is intended to be run with the plugin installed inside a GLPI
 * instance (e.g. glpi/plugins/samlsso/).  It bootstraps GLPI's test
 * environment so that GLPI globals ($DB, Session, etc.) are available.
 *
 * Usage:
 *   # From the GLPI root directory:
 *   GLPI_ENVIRONMENT_TYPE=testing ./plugins/samlsso/vendor/bin/phpunit \
 *       --configuration plugins/samlsso/phpunit.xml
 *
 * Alternatively, set GLPI_ROOT in the environment to override auto-detection.
 */

// ---------------------------------------------------------------------------
// 1. Locate GLPI root
// ---------------------------------------------------------------------------

$glpiRoot = getenv('GLPI_ROOT') ?: null;

if ($glpiRoot === null) {
    // When installed at  <glpi>/plugins/samlsso/  the root is three levels up
    // (tests/ → samlsso/ → plugins/ → <glpi>/).
    $candidate = dirname(__DIR__, 3);
    if (file_exists($candidate . '/inc/based_config.php') ||
        file_exists($candidate . '/src/GLPI.php')) {
        $glpiRoot = $candidate;
    }
}

if ($glpiRoot === null || !is_dir($glpiRoot)) {
    fwrite(STDERR, <<<MSG

    [samlSSO tests] Could not locate the GLPI root directory.

    Place the plugin in <glpi>/plugins/samlsso/ or set the GLPI_ROOT
    environment variable to the absolute path of your GLPI installation.

MSG);
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Bootstrap GLPI (loads $DB, Session, all GLPI classes, etc.)
// ---------------------------------------------------------------------------

$glpiBootstrap = $glpiRoot . '/tests/bootstrap.php';

if (!file_exists($glpiBootstrap)) {
    fwrite(STDERR, <<<MSG

    [samlSSO tests] GLPI test bootstrap not found at:
        $glpiBootstrap

    Make sure you are running against a GLPI development installation that
    has its test infrastructure present (i.e. not a production zip release).

MSG);
    exit(1);
}

require_once $glpiBootstrap;

// ---------------------------------------------------------------------------
// 3. Load the plugin's own composer autoloader (onelogin/php-saml, etc.)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// 4. Define plugin constants that setup.php would normally provide.
//    setup.php cannot be included standalone because it expects GLPI globals
//    that may not be fully ready during bootstrap; we define only the
//    constants that the test classes need.
// ---------------------------------------------------------------------------

if (!defined('PLUGIN_NAME')) {
    define('PLUGIN_NAME', 'samlsso');
}
if (!defined('PLUGIN_SAMLSSO_VERSION')) {
    define('PLUGIN_SAMLSSO_VERSION', '1.2.8');
}
if (!defined('PLUGIN_SAMLSSO_SRCDIR')) {
    define('PLUGIN_SAMLSSO_SRCDIR', dirname(__DIR__) . '/src');
}
