<?php
/**
 * Plugin activation routine.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor;

use HtmlToElementor\Support\Settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles tasks that run once on plugin activation.
 */
final class Activator
{

	/**
	 * Create default options and the private upload directory.
	 */
	public static function activate(): void
	{
		Settings::install_defaults();

		$dirs = wp_upload_dir();
		$base = trailingslashit($dirs['basedir']) . 'h2e-imports';

		if (!file_exists($base)) {
			wp_mkdir_p($base);
		}

		// Protect the working directory from public listing/access.
		$htaccess = trailingslashit($base) . '.htaccess';
		if (!file_exists($htaccess)) {
			file_put_contents($htaccess, "Options -Indexes\nDeny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$index = trailingslashit($base) . 'index.php';
		if (!file_exists($index)) {
			file_put_contents($index, "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		flush_rewrite_rules();
	}
}
