<?php
/**
 * High-level orchestration of the full HTML -> Elementor conversion pipeline.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Services;

use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Elementor\ImportEngine;
use HtmlToElementor\Report\ConversionReport;
use HtmlToElementor\Support\Logger;
use HtmlToElementor\Support\Settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Glue layer wiring rendering, generation, reporting and import together:
 *
 *   entry HTML -> ChromiumService -> ElementorJsonGenerator -> (ImportEngine).
 *
 * Conversion is always native-widget-first ("widgets" path). Legacy "preserve"
 * (raw HTML passthrough) mode has been permanently removed.
 */
final class ConversionPipeline
{

	/** Option key for the last conversion stage summary (always captured). */
	public const LAST_RUN_OPTION = 'h2e_last_conversion_log';

	public function __construct(
		private ?ChromiumService $chromium = null,
		private ?ElementorJsonGenerator $generator = null
	) {
		$this->chromium = $chromium ?? new ChromiumService();
		$this->generator = $generator ?? new ElementorJsonGenerator();
	}

	/**
	 * Render and convert a source entry into Elementor data (without importing).
	 *
	 * @param string              $entry_html Absolute path to entry HTML.
	 * @param string              $job_dir    Working directory.
	 * @param array<string,mixed> $overrides  Setting overrides for this run.
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>,result:RenderResult,stages:array<int,array<string,mixed>>}
	 */
	public function convert(string $entry_html, string $job_dir, array $overrides = array()): array
	{
		$settings = array_merge(Settings::all(), $overrides);
		$debug = (bool) ($settings['debug'] ?? false);
		Logger::begin_request($debug);

		$stages = array();
		$fail_stage = null;

		try {
			$fail_stage = 'render';
			$result = $this->run_stage(
				$stages,
				'render',
				'Chromium render',
				function () use ($entry_html, $job_dir, $settings) {
					return $this->chromium->render($entry_html, $job_dir, $settings);
				}
			);

			$fail_stage = 'widget_mapping';
			$generated = $this->run_stage(
				$stages,
				'widget_mapping',
				'Recognition / layout solve / widget mapping / JSON generation',
				function () use ($result, $settings) {
					// Always widgets-only; conversion_mode setting was removed.
					return $this->generator->generate(
						$result,
						array(
							'confidence' => (int) ($settings['widget_confidence'] ?? 95),
							'threshold' => (int) ($settings['fidelity_threshold'] ?? 95),
						)
					);
				}
			);

			$report = (new ConversionReport(
				$generated['report'],
				array(
					'job' => basename($job_dir),
					'title' => $result->title(),
					'screenshots' => $result->screenshots(),
					'tokens' => $generated['tokens'] ?? array(),
					'quality' => $generated['quality'] ?? array(),
					'validation' => $generated['validation'] ?? array(),
				)
			))->to_array();

			$report['stages'] = $stages;
			$report['mode'] = 'widgets';

			if ($debug) {
				$report['debug_log'] = Logger::buffer_lines();
			}

			$this->persist_last_run($stages, true, null, $report);

			return array(
				'data' => $generated['data'],
				'report' => $report,
				'result' => $result,
				'tokens' => $generated['tokens'] ?? array(),
				'assets' => $generated['assets'] ?? array(),
				'stages' => $stages,
			);
		} catch (\Throwable $e) {
			Logger::error(
				'Conversion pipeline failed',
				array(
					'stage' => $fail_stage,
					'error' => $e->getMessage(),
				)
			);
			$this->persist_last_run($stages, false, $fail_stage, array('error' => $e->getMessage()));
			throw $e;
		}
	}

	/**
	 * Render, convert and import into a WordPress page.
	 *
	 * @param string              $entry_html Absolute path to entry HTML.
	 * @param string              $job_dir    Working directory.
	 * @param array<string,mixed> $args       { title, status, page_id, ...overrides }.
	 * @return array{post_id:int,report:array<string,mixed>}
	 */
	public function convert_and_import(string $entry_html, string $job_dir, array $args = array()): array
	{
		$converted = $this->convert($entry_html, $job_dir, $args);
		$stages = $converted['stages'] ?? array();

		try {
			$post_id = $this->run_stage(
				$stages,
				'import',
				'Elementor import',
				function () use ($converted, $args, $entry_html) {
					return (new ImportEngine())->import(
						$converted['data'],
						array(
							'title' => $args['title'] ?? ($converted['report']['title'] ?: 'Imported Page'),
							'status' => $args['status'] ?? 'draft',
							'page_id' => (int) ($args['page_id'] ?? 0),
							'assets' => $converted['assets'] ?? array(),
							'tokens' => $converted['tokens'] ?? array(),
							'base_dir' => dirname($entry_html),
						)
					);
				}
			);
		} catch (\Throwable $e) {
			Logger::error('Import stage failed', array('error' => $e->getMessage()));
			$this->persist_last_run($stages, false, 'import', array('error' => $e->getMessage()));
			throw $e;
		}

		$report = $converted['report'];
		$report['post_id'] = $post_id;
		$report['edit_url'] = admin_url('post.php?post=' . $post_id . '&action=elementor');
		$report['stages'] = $stages;
		if (!empty($args['debug']) || (bool) Settings::get('debug', false)) {
			$report['debug_log'] = Logger::buffer_lines();
		}

		$this->persist_last_run($stages, true, null, $report);

		return array(
			'post_id' => $post_id,
			'report' => $report,
		);
	}

	/**
	 * Last conversion stage summary (always available, even without debug).
	 *
	 * @return array<string,mixed>
	 */
	public static function last_run(): array
	{
		$stored = get_option(self::LAST_RUN_OPTION, array());
		return is_array($stored) ? $stored : array();
	}

	/**
	 * @param array<int,array<string,mixed>> $stages Stage records (by ref).
	 * @param string                         $id     Machine stage id.
	 * @param string                         $label  Human label.
	 * @param callable                       $fn     Work.
	 * @return mixed
	 */
	private function run_stage(array &$stages, string $id, string $label, callable $fn)
	{
		$started = microtime(true);
		Logger::info('Stage start: ' . $label, array('stage' => $id));
		try {
			$out = $fn();
			$ms = (int) round((microtime(true) - $started) * 1000);
			$stages[] = array(
				'id' => $id,
				'label' => $label,
				'status' => 'pass',
				'duration_ms' => $ms,
			);
			Logger::info('Stage done: ' . $label, array('stage' => $id, 'ms' => $ms));
			return $out;
		} catch (\Throwable $e) {
			$ms = (int) round((microtime(true) - $started) * 1000);
			$stages[] = array(
				'id' => $id,
				'label' => $label,
				'status' => 'fail',
				'duration_ms' => $ms,
				'error' => $e->getMessage(),
			);
			throw $e;
		}
	}

	/**
	 * Persist a compact last-run summary for the admin "View last conversion log" UI.
	 *
	 * @param array<int,array<string,mixed>> $stages Stage records.
	 * @param bool                           $ok     Overall success.
	 * @param string|null                    $failed Failed stage id.
	 * @param array<string,mixed>            $extra  Extra payload.
	 */
	private function persist_last_run(array $stages, bool $ok, ?string $failed, array $extra = array()): void
	{
		update_option(
			self::LAST_RUN_OPTION,
			array(
				'ok' => $ok,
				'failed_stage' => $failed,
				'stages' => $stages,
				'at' => gmdate('c'),
				'title' => $extra['title'] ?? null,
				'fidelity_score' => $extra['fidelity_score'] ?? null,
				'error' => $extra['error'] ?? null,
			),
			false
		);
	}
}
