<?php
/**
 * Plugin Name: Swish Migrate and Backup
 * Plugin URI: https://denis.swishfolio.com/swish-migrate-and-backup
 * Description: A WordPress backup and migration plugin with cloud storage support & no limits.
 * Version: 1.0.1
 * Author: Fortisthemes
 * Author URI: https://denis.swishfolio.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: swish-migrate-and-backup
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package SwishMigrateAndBackup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup;

// Prevent direct access.
if (! defined('ABSPATH')) {
    exit;
}

// Plugin constants.
define('SWISH_BACKUP_VERSION', '1.0.1');
define('SWISH_BACKUP_PLUGIN_FILE', __FILE__);
define('SWISH_BACKUP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SWISH_BACKUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SWISH_BACKUP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Require Composer autoloader.
if (file_exists(SWISH_BACKUP_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once SWISH_BACKUP_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    // Fallback autoloader for development without Composer.
    spl_autoload_register(
        function (string $class): void {
            $prefix   = 'SwishMigrateAndBackup\\';
            $base_dir = SWISH_BACKUP_PLUGIN_DIR . 'src/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file           = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        }
    );
}

// Initialize the plugin.
add_action(
    'plugins_loaded',
    function (): void {
        // Load text domain for translations.
        load_plugin_textdomain(
            'swish-migrate-and-backup',
            false,
            dirname(SWISH_BACKUP_PLUGIN_BASENAME) . '/languages'
        );

        // Boot the plugin.
        $container = Core\Container::get_instance();
        $plugin    = new Core\Plugin($container);
        $plugin->boot();
    }
);

// Activation hook.
register_activation_hook(
    __FILE__,
    function (): void {
        $activator = new Core\Activator();
        $activator->activate();
    }
);

// Deactivation hook.
register_deactivation_hook(
    __FILE__,
    function (): void {
        $deactivator = new Core\Deactivator();
        $deactivator->deactivate();
    }
);
