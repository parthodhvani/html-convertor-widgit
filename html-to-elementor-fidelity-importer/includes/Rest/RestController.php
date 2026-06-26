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

			$overrides = array();
			if (null !== $request->get_param('mode')) {
				$overrides['conversion_mode'] = sanitize_text_field((string) $request->get_param('mode'));
			}
			if (null !== $request->get_param('confidence')) {
				$overrides['widget_confidence'] = (int) $request->get_param('confidence');
			}
			if (null !== $request->get_param('debug')) {
				$overrides['debug'] = (bool) $request->get_param('debug');
			}

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
			Logger::error('Convert request failed', array('error' => $e->getMessage()));
			return new \WP_Error('h2e_convert_failed', $e->getMessage(), array('status' => 500));
		}
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
		Settings::update($update);
		return new \WP_REST_Response(Settings::all(), 200);
	}
}
