<?php
/**
 * Admin menu, settings registration and asset loading.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Admin;

use HtmlToElementor\Support\Settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Registers the "HTML → Elementor" admin screen and enqueues its assets.
 */
final class AdminPage
{

	private const SLUG = 'h2e-importer';

	/**
	 * Hook into the admin.
	 */
	public function register(): void
	{
		add_action('admin_menu', array($this, 'add_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));
	}

	/**
	 * Add the top-level menu entry.
	 */
	public function add_menu(): void
	{
		add_menu_page(
			__('HTML to Elementor', 'html-to-elementor-fidelity-importer'),
			__('HTML → Elementor', 'html-to-elementor-fidelity-importer'),
			'manage_options',
			self::SLUG,
			array($this, 'render'),
			'dashicons-download',
			58
		);
	}

	/**
	 * Enqueue admin assets only on our screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue(string $hook): void
	{
		if ('toplevel_page_' . self::SLUG !== $hook) {
			return;
		}

		wp_enqueue_style(
			'h2e-admin',
			H2E_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			H2E_VERSION
		);

		wp_enqueue_script(
			'h2e-admin',
			H2E_PLUGIN_URL . 'assets/js/admin.js',
			array('wp-api-fetch'),
			H2E_VERSION,
			true
		);

		wp_localize_script(
			'h2e-admin',
			'H2E_DATA',
			array(
				'restUrl' => esc_url_raw(rest_url('h2e/v1')),
				'nonce' => wp_create_nonce('wp_rest'),
				'settings' => Settings::all(),
				'i18n' => array(
					'converting' => __('Rendering in Chromium and converting…', 'html-to-elementor-fidelity-importer'),
					'done' => __('Conversion complete.', 'html-to-elementor-fidelity-importer'),
					'failed' => __('Conversion failed.', 'html-to-elementor-fidelity-importer'),
					'needZip' => __('For images to show, upload a ZIP that includes your HTML and assets folder.', 'html-to-elementor-fidelity-importer'),
				),
			)
		);
	}

	/**
	 * Render the admin screen template.
	 */
	public function render(): void
	{
		$settings = Settings::all();
		require H2E_PLUGIN_DIR . 'templates/admin-page.php';
	}
}
