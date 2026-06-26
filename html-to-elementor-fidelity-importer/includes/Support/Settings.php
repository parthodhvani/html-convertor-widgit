<?php
/**
 * Plugin settings store.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Thin wrapper around a single options row holding all plugin settings.
 */
final class Settings
{

	public const OPTION_KEY = 'h2e_settings';

	/**
	 * Default settings used on first install and as fallbacks.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array
	{
		return array(
			// How to reach the Node Chromium service: "cli" (spawn node) or "http".
			'render_mode' => 'cli',
			'node_binary' => 'node',
			'service_script' => '', // Absolute path to chromium-service/cli.js. Auto-detected when empty.
			'service_url' => 'http://127.0.0.1:8745',
			'service_token' => '',
			// When PHP runs under XAMPP/LAMPP/MAMP, the inherited LD_LIBRARY_PATH
			// points at bundled libraries that are too old for the system Node
			// binary (e.g. "libstdc++.so.6: version GLIBCXX_3.4.21 not found").
			// Stripping these loader variables from the spawned Node process lets
			// it use the system libraries instead.
			'node_strip_env' => true,
			'node_ld_library_path' => '', // Optional explicit LD_LIBRARY_PATH for Node.
			// Conversion behaviour.
			'conversion_mode' => 'native',   // "native" (containers + widgets) | "preserve" (raw HTML).
			'widget_confidence' => 95,         // Minimum % confidence to convert a node to a widget.
			'breakpoints' => array(
				'wide' => 1920,
				'desktop' => 1440,
				'laptop' => 1280,
				'tablet_landscape' => 1024,
				'tablet' => 768,
				'mobile_landscape' => 480,
				'mobile' => 375,
			),
			'fidelity_threshold' => 95,
			'validation_max_iterations' => 3,
			'wait_until' => 'networkidle0',
			'render_timeout_ms' => 60000,
			'capture_screenshots' => true,
			// Visual reconstruction.
			'import_media' => true, // Download images into the media library.
			'inject_source_assets' => true, // Re-apply original CSS on imported pages (fidelity safety net).
			'inject_source_js' => false, // Re-run original inline scripts (opt-in).
			'apply_global_colors' => true, // Register extracted brand colours as Elementor globals.
			'debug' => false,
		);
	}

	/**
	 * Install defaults without overwriting an existing configuration.
	 */
	public static function install_defaults(): void
	{
		$existing = get_option(self::OPTION_KEY, null);
		if (null === $existing) {
			add_option(self::OPTION_KEY, self::defaults());
		} else {
			update_option(self::OPTION_KEY, array_merge(self::defaults(), (array) $existing));
		}
	}

	/**
	 * Read the full settings array merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array
	{
		$stored = get_option(self::OPTION_KEY, array());
		return array_merge(self::defaults(), is_array($stored) ? $stored : array());
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get(string $key, $default = null)
	{
		$all = self::all();
		return $all[$key] ?? $default;
	}

	/**
	 * Persist a partial settings update.
	 *
	 * @param array<string,mixed> $values Values to merge.
	 */
	public static function update(array $values): void
	{
		update_option(self::OPTION_KEY, array_merge(self::all(), $values));
	}
}
