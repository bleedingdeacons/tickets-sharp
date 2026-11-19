<?php

/**
 * Plugin Name:       Tickets Sharp
 * Description:       Sell Event Tickets tickets through WooCommerce. Each ticket is a WooCommerce product paid through the normal cart and checkout, with attendees, shared capacity, attendee information fields, QR check-in and refund/cancel sync.
 * Version:           0.4.3
 * Requires at least: 6.9
 * Requires PHP:      8.4
 * Requires Plugins:  event-tickets, woocommerce
 * GitHub Plugin URI: https://github.com/bleedingdeacons/tickets-sharp
 * GitHub Branch:     main
 * Author:            The Bleeding Deacons
 * Author URI:        https://github.com/bleedingdeacons/tickets-sharp
 * Contact:           thebleedingdeacons@gmail.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tickets-sharp
 * Domain Path:       /languages
 * WC requires at least: 9.0
 *
 * @package TicketsSharp
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

// Kill switch: define( 'TICKETS_SHARP_KILL', true ); in wp-config.php to stand the plugin down without deactivating it.
if (defined('TICKETS_SHARP_KILL') && TICKETS_SHARP_KILL) {
    return;
}

// The Version: header above is the single source of truth; CI's release job raises it on merge.
define('TICKETS_SHARP_VERSION', (string) ( get_file_data(__FILE__, [ 'Version' => 'Version' ])['Version'] ?? '0.0.0' ));
define('TICKETS_SHARP_FILE', __FILE__);
define('TICKETS_SHARP_DIR', plugin_dir_path(__FILE__));
define('TICKETS_SHARP_URL', plugin_dir_url(__FILE__));

// Composer autoloader when present (dev checkouts), otherwise a minimal PSR-4 loader so a
// release zip does not need vendor/.
if (is_readable(TICKETS_SHARP_DIR . 'vendor/autoload.php')) {
    require_once TICKETS_SHARP_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(
        static function (string $class): void {
            if (! str_starts_with($class, 'TicketsSharp\\')) {
                return;
            }
            $file = TICKETS_SHARP_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen('TicketsSharp\\'))) . '.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
    );
}

register_activation_hook(__FILE__, [ TicketsSharp\Lifecycle::class, 'activate' ]);

( new TicketsSharp\Plugin() )->boot();
