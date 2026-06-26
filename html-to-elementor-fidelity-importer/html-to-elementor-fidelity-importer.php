<?php
/**
 * Plugin Name:       HTML To Elementor Fidelity Importer
 * Plugin URI:        https://github.com/html-to-elementor/fidelity-importer
 * Description:       Import arbitrary HTML/CSS/JS pages into Elementor while maximizing Chromium-rendered visual fidelity. Renders the source in headless Chromium, extracts the computed layout, segments it into sections and produces valid Elementor container data.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            HTML To Elementor
 * Author URI:        https://github.com/html-to-elementor
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       html-to-elementor-fidelity-importer
 * Domain Path:       /languages
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor;

if (!defined('ABSPATH')) {
	exit; // No direct access.
}

define('H2E_VERSION', '1.0.0');
define('H2E_PLUGIN_FILE', __FILE__);
define('H2E_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('H2E_PLUGIN_URL', plugin_dir_url(__FILE__));
define('H2E_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Composer autoloader (preferred) with a PSR-4 fallback so the plugin works
 * even when `composer install` has not been run yet.
 */
$h2e_autoload = H2E_PLUGIN_DIR . 'vendor/autoload.php';
if (is_readable($h2e_autoload)) {
	require $h2e_autoload;
} else {
	require H2E_PLUGIN_DIR . 'includes/Support/Autoloader.php';
	Support\Autoloader::register();
}

register_activation_hook(__FILE__, array(Activator::class, 'activate'));
register_deactivation_hook(__FILE__, array(Deactivator::class, 'deactivate'));

/**
 * Boot the plugin once all plugins are loaded so Elementor (if present) is available.
 */
function bootstrap(): Plugin
{
	$plugin = Plugin::instance();
	$plugin->run();
	return $plugin;
}

add_action('plugins_loaded', __NAMESPACE__ . '\\bootstrap', 20);
