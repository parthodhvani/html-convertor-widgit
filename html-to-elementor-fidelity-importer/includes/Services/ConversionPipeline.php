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
use HtmlToElementor\Support\Settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Glue layer wiring rendering, generation, reporting and import together:
 *
 *   entry HTML -> ChromiumService -> ElementorJsonGenerator -> (ImportEngine).
 */
final class ConversionPipeline
{

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
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>,result:RenderResult}
	 */
	public function convert(string $entry_html, string $job_dir, array $overrides = array()): array
	{
		$settings = array_merge(Settings::all(), $overrides);
		$result = $this->chromium->render($entry_html, $job_dir, $settings);

		$generated = $this->generator->generate(
			$result,
			array(
				'mode' => $settings['conversion_mode'] ?? 'native',
				'confidence' => (int) ($settings['widget_confidence'] ?? 95),
			)
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

		return array(
			'data' => $generated['data'],
			'report' => $report,
			'result' => $result,
			'tokens' => $generated['tokens'] ?? array(),
			'assets' => $generated['assets'] ?? array(),
		);
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
		$post_id = (new ImportEngine())->import(
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

		$report = $converted['report'];
		$report['post_id'] = $post_id;
		$report['edit_url'] = admin_url('post.php?post=' . $post_id . '&action=elementor');

		return array(
			'post_id' => $post_id,
			'report' => $report,
		);
	}
}
