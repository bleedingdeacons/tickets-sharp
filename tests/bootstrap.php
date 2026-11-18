<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Order matters, in one direction (see wp-mocks' README): Patchwork first, so
 * every function defined after it — wp-mocks' WordPress stubs and the Event
 * Tickets, The Events Calendar and WooCommerce stubs in tests/stubs — can be
 * redefined per test with Brain Monkey (`Functions\when('wc_get_order')`).
 *
 * The stubs only make the third-party symbols exist, so the plugin's classes
 * can be loaded, extended and mocked. Behaviour is always set by the test.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

// Pest's launcher does not define this, and PHPUnit's separate-process template
// needs it to load Composer in the child.
if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__) . '/vendor/autoload.php');
}

Bootstrap::loadPatchwork();

if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/tickets-sharp-tests/');
}

define('TICKETS_SHARP_VERSION', '0.0.0-test');
define('TICKETS_SHARP_FILE', dirname(__DIR__) . '/tickets-sharp.php');
define('TICKETS_SHARP_DIR', dirname(__DIR__) . '/');
define('TICKETS_SHARP_URL', 'https://example.test/wp-content/plugins/tickets-sharp/');

Bootstrap::load(['wordpress', 'rest']);
WpState::$pluginSlug = 'tickets-sharp';

require_once __DIR__ . '/stubs/event-tickets.php';
require_once __DIR__ . '/stubs/the-events-calendar.php';
require_once __DIR__ . '/stubs/woocommerce.php';
