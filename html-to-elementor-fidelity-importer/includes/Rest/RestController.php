<?php
/**
 * REST API endpoints powering the admin UI.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Rest;

use HtmlToElementor\Services\ConversionPipeline;
use HtmlToElementor\Services\UploadHandler;
use HtmlToElementor\Export\ExportEngine;
use HtmlToElementor\Support\Logger;
use HtmlToElementor\Support\Settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Registers /h2e/v1 routes for upload, conversion, import, preview and export.
 */
final class RestController
{

	private const NS = 'h2e/v1';

	/** Human labels for pipeline failure stages. */
	private const STAGE_LABELS = array(
		'render' => 'Chromium render',
		'extraction' => 'Layout extraction',
		'recognition' => 'Component recognition',
		'layout_solve' => 'Layout solve',
		'widget_mapping' => 'Widget mapping / JSON generation',
		'import' => 'Elementor import',
	);

	/**
	 * Register all routes.
	 */
	public function register_routes(): void
	{
		register_rest_route(
			self::NS,
			'/convert',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'convert'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			self::NS,
			'/export/(?P<id>\d+)',
			array(
				'methods' => 'GET',
				'callback' => array($this, 'export'),
				'permission_callback' => array($this, 'can_manage'),
				'args' => array(
					'id' => array('sanitize_callback' => 'absint'),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods' => 'GET',
					'callback' => array($this, 'get_settings'),
					'permission_callback' => array($this, 'can_manage'),
				),
				array(
					'methods' => 'POST',
					'callback' => array($this, 'update_settings'),
					'permission_callback' => array($this, 'can_manage'),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/last-log',
			array(
				'methods' => 'GET',
				'callback' => array($this, 'last_log'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);
	}

	/**
	 * Capability check for all routes.
	 */
	public function can_manage(): bool
	{
		return current_user_can('manage_options');
	}

	/**
	 * Handle an upload + conversion (+ optional import) request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function convert(\WP_REST_Request $request)
	{
		$uploads = new UploadHandler();
		$job = $uploads->create_job();
		$debug = false;

		try {
			$files = $request->get_file_params();
			$raw_html = (string) $request->get_param('html');

			if (isset($files['file'])) {
				$stored = $uploads->store($files['file'], $job['dir']);
			} elseif ('' !== trim($raw_html)) {
				$stored = $uploads->store_raw_html($raw_html, $job['dir']);
			} else {
				return new \WP_Error('h2e_no_input', 'No HTML file or markup provided.', array('status' => 400));
			}

			// 'mode' / conversion_mode is intentionally ignored — widgets-only.
			$overrides = $this->collect_overrides($request);
			$debug = (bool) ($overrides['debug'] ?? false);
			Logger::begin_request($debug);

			$pipeline = new ConversionPipeline();
			$do_import = (bool) ($request->get_param('import') ?? true);

			if ($do_import) {
				$result = $pipeline->convert_and_import(
					$stored['entry'],
					$job['dir'],
					array_merge(
						$overrides,
						array(
							'title' => sanitize_text_field((string) ($request->get_param('title') ?: 'Imported Page')),
							'status' => 'draft',
							'page_id' => (int) ($request->get_param('page_id') ?? 0),
						)
					)
				);
				return new \WP_REST_Response(
					array(
						'success' => true,
						'imported' => true,
						'post_id' => $result['post_id'],
						'report' => $result['report'],
					),
					200
				);
			}

			$converted = $pipeline->convert($stored['entry'], $job['dir'], $overrides);
			return new \WP_REST_Response(
				array(
					'success' => true,
					'imported' => false,
					'data' => $converted['data'],
					'report' => $converted['report'],
				),
				200
			);
		} catch (\Throwable $e) {
			$last = ConversionPipeline::last_run();
			$stage = (string) ($last['failed_stage'] ?? 'unknown');
			$stage_label = self::STAGE_LABELS[$stage] ?? $stage;
			Logger::error(
				'Convert request failed',
				array(
					'stage' => $stage,
					'error' => $e->getMessage(),
				)
			);

			$data = array(
				'status' => 500,
				'stage' => $stage,
				'stage_label' => $stage_label,
				'stages' => $last['stages'] ?? array(),
			);
			if ($debug) {
				$data['debug_log'] = Logger::buffer_lines();
				$trace = $e->getTraceAsString();
				$data['stack'] = strlen($trace) > 4000 ? substr($trace, 0, 4000) . '…' : $trace;
			}

			$message = sprintf('Failed at: %s — %s', $stage_label, $e->getMessage());
			if ($debug) {
				$message .= ' See debug log.';
			}

			return new \WP_Error('h2e_convert_failed', $message, $data);
		}
	}

	/**
	 * Collect per-conversion setting overrides from the request (same pattern as confidence/debug).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function collect_overrides(\WP_REST_Request $request): array
	{
		$overrides = array();

		if (null !== $request->get_param('confidence')) {
			$overrides['widget_confidence'] = (int) $request->get_param('confidence');
		}
		if (null !== $request->get_param('widget_confidence')) {
			$overrides['widget_confidence'] = (int) $request->get_param('widget_confidence');
		}
		if (null !== $request->get_param('fidelity_threshold')) {
			$overrides['fidelity_threshold'] = (int) $request->get_param('fidelity_threshold');
		}
		if (null !== $request->get_param('validation_max_iterations')) {
			$overrides['validation_max_iterations'] = (int) $request->get_param('validation_max_iterations');
		}
		if (null !== $request->get_param('render_mode')) {
			$overrides['render_mode'] = sanitize_text_field((string) $request->get_param('render_mode'));
		}
		if (null !== $request->get_param('node_binary')) {
			$overrides['node_binary'] = sanitize_text_field((string) $request->get_param('node_binary'));
		}
		if (null !== $request->get_param('service_url')) {
			$overrides['service_url'] = esc_url_raw((string) $request->get_param('service_url'));
		}
		if (null !== $request->get_param('service_token')) {
			$overrides['service_token'] = sanitize_text_field((string) $request->get_param('service_token'));
		}
		if (null !== $request->get_param('node_strip_env')) {
			$overrides['node_strip_env'] = (bool) $request->get_param('node_strip_env');
		}
		if (null !== $request->get_param('node_ld_library_path')) {
			$overrides['node_ld_library_path'] = sanitize_text_field((string) $request->get_param('node_ld_library_path'));
		}
		if (null !== $request->get_param('wait_until')) {
			$overrides['wait_until'] = sanitize_text_field((string) $request->get_param('wait_until'));
		}
		if (null !== $request->get_param('render_timeout_ms')) {
			$overrides['render_timeout_ms'] = (int) $request->get_param('render_timeout_ms');
		}
		if (null !== $request->get_param('capture_screenshots')) {
			$overrides['capture_screenshots'] = (bool) $request->get_param('capture_screenshots');
		}
		if (null !== $request->get_param('import_media')) {
			$overrides['import_media'] = (bool) $request->get_param('import_media');
		}
		if (null !== $request->get_param('inject_source_assets')) {
			$overrides['inject_source_assets'] = (bool) $request->get_param('inject_source_assets');
		}
		if (null !== $request->get_param('inject_source_js')) {
			$overrides['inject_source_js'] = (bool) $request->get_param('inject_source_js');
		}
		if (null !== $request->get_param('apply_global_colors')) {
			$overrides['apply_global_colors'] = (bool) $request->get_param('apply_global_colors');
		}
		if (null !== $request->get_param('debug')) {
			$overrides['debug'] = (bool) $request->get_param('debug');
		}

		$bp = $request->get_param('breakpoints');
		if (null !== $bp) {
			if (is_string($bp)) {
				$decoded = json_decode($bp, true);
				$bp = is_array($decoded) ? $decoded : array();
			}
			if (is_array($bp)) {
				$clean = array();
				foreach ($bp as $key => $val) {
					$clean[sanitize_key((string) $key)] = (int) $val;
				}
				$overrides['breakpoints'] = $clean;
			}
		}

		return $overrides;
	}

	/**
	 * Export a page's Elementor data as JSON.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export(\WP_REST_Request $request)
	{
		try {
			$payload = (new ExportEngine())->export_page((int) $request['id']);
			return new \WP_REST_Response($payload, 200);
		} catch (\Throwable $e) {
			Logger::error('Export failed', array('error' => $e->getMessage()));
			return new \WP_Error('h2e_export_failed', $e->getMessage(), array('status' => 404));
		}
	}

	/**
	 * Return current settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response
	{
		return new \WP_REST_Response(Settings::all(), 200);
	}

	/**
	 * Last conversion stage timing/status (always available).
	 *
	 * @return \WP_REST_Response
	 */
	public function last_log(): \WP_REST_Response
	{
		return new \WP_REST_Response(ConversionPipeline::last_run(), 200);
	}

	/**
	 * Update settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_settings(\WP_REST_Request $request): \WP_REST_Response
	{
		$body = $request->get_json_params();
		if (!is_array($body)) {
			$body = $request->get_params();
		}
		$allowed = array_keys(Settings::defaults());
		$update = array();
		foreach ($allowed as $key) {
			if (array_key_exists($key, $body)) {
				$update[$key] = $body[$key];
			}
		}
		// Silently drop legacy conversion_mode if a client still sends it.
		unset($update['conversion_mode']);
		Settings::update($update);
		return new \WP_REST_Response(Settings::all(), 200);
	}
}
