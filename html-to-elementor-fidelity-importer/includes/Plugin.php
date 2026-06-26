<?php
/**
 * Main plugin orchestrator.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor;

use HtmlToElementor\Admin\AdminPage;
use HtmlToElementor\Rest\RestController;
use HtmlToElementor\Cli\ConvertCommand;
use HtmlToElementor\Frontend\Frontend;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Wires together the admin UI, REST API and CLI commands.
 */
final class Plugin
{

	private static ?Plugin $instance = null;

	private bool $booted = false;

	private function __construct()
	{
	}

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Safe to call multiple times.
	 */
	public function run(): void
	{
		if ($this->booted) {
			return;
		}
		$this->booted = true;

		add_action('init', array($this, 'load_textdomain'));

		if (is_admin()) {
			(new AdminPage())->register();
		}

		add_action('rest_api_init', array(new RestController(), 'register_routes'));

		(new Frontend())->register();

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('h2e', ConvertCommand::class);
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void
	{
		load_plugin_textdomain(
			'html-to-elementor-fidelity-importer',
			false,
			dirname(H2E_PLUGIN_BASENAME) . '/languages'
		);
	}
}
