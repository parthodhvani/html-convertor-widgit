<?php
/**
 * Closed-loop Chromium screenshot validation + iterative repair.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Closed Loop Validation Engine — original screenshot vs Elementor preview
 * screenshot. When Chromium is unavailable, falls back to geometry repair only.
 */
final class ClosedLoopValidationEngine implements EngineInterface
{

	private ElementorPreviewRenderer $preview;
	private PixelRepairEngine $repair;
	private VisualValidationEngine $validation;
	private int $max_iterations;
	private int $threshold;

	public function __construct(int $threshold = 95, int $max_iterations = 3)
	{
		$this->threshold = $threshold;
		$this->max_iterations = $max_iterations;
		$this->preview = new ElementorPreviewRenderer();
		$this->repair = new PixelRepairEngine($max_iterations);
		$this->validation = new VisualValidationEngine($threshold, $max_iterations);
	}

	public function name(): string
	{
		return 'closed_loop_validation';
	}

	/**
	 * Validate, optionally screenshot-compare, and repair until improvement stops.
	 *
	 * @param array<int,array<string,mixed>> $elementor_data Elements.
	 * @param array<string,mixed>            $context        Pipeline context.
	 * @return array{data:array<int,array<string,mixed>>,validation:array<string,mixed>,preview_html?:string,closed_loop?:array<string,mixed>,repaired:bool}
	 */
	public function run(array $elementor_data, array $context): array
	{
		$work_dir = (string) ($context['work_dir'] ?? sys_get_temp_dir() . '/h2e-closed-loop');
		if (!is_dir($work_dir)) {
			@mkdir($work_dir, 0775, true);
		}

		$iterations = 0;
		$repaired = false;
		$all_repairs = array();
		$closed_loop = null;
		$preview_html = '';
		$best_score = -1;
		$best_data = $elementor_data;

		while ($iterations < $this->max_iterations) {
			$preview_html = $this->preview->render(
				$elementor_data,
				array(
					'title' => (string) ($context['title'] ?? 'H2E Preview'),
					'width' => (int) ($context['viewport_width'] ?? 1440),
					'css' => (string) ($context['source_css'] ?? ''),
					'page' => is_array($context['page'] ?? null) ? $context['page'] : array(),
				)
			);
			$preview_path = $work_dir . '/preview-' . $iterations . '.html';
			file_put_contents($preview_path, $preview_html);

			$compare = null;
			$original = $this->resolve_original_screenshot($context);
			if (null !== $original) {
				$closed_loop = $this->run_chromium_compare($original, $preview_path, $work_dir, (int) ($context['viewport_width'] ?? 1440));
				if (is_array($closed_loop) && isset($closed_loop['compare'])) {
					$compare = $closed_loop['compare'];
				}
			}

			$validation = $this->validation->score(
				$elementor_data,
				array_merge($context, array('compare' => $compare))
			);
			$score = (int) ($validation['fidelity'] ?? 0);
			if ($score > $best_score) {
				$best_score = $score;
				$best_data = $elementor_data;
			}

			if ($score >= $this->threshold) {
				$validation['iterations'] = $iterations;
				$validation['repairs'] = $all_repairs;
				$validation['closed_loop'] = $closed_loop;
				$validation['passed'] = true;
				$validation['threshold'] = $this->threshold;
				return array(
					'data' => $elementor_data,
					'validation' => $validation,
					'preview_html' => $preview_html,
					'closed_loop' => $closed_loop,
					'repaired' => $repaired,
				);
			}

			$repair = $this->repair->repair(
				$elementor_data,
				array_merge($context, array(
					'validation' => array_merge($validation, array('threshold' => $this->threshold)),
					'compare' => $compare,
				))
			);
			$compare_changed = false;
			$elementor_data = $this->apply_compare_driven_repairs(
				$repair['data'],
				$compare,
				$context,
				$compare_changed,
				$all_repairs
			);
			if ($repair['changed'] || $compare_changed) {
				$repaired = true;
				$all_repairs = array_merge($all_repairs, $repair['repairs'] ?? array());
				++$iterations;
				continue;
			}
			break;
		}

		$validation = $this->validation->score(
			$best_data,
			array_merge($context, array(
				'compare' => is_array($closed_loop) ? ($closed_loop['compare'] ?? null) : null,
			))
		);
		$validation['iterations'] = $iterations;
		$validation['repairs'] = $all_repairs;
		$validation['closed_loop'] = $closed_loop;
		$validation['threshold'] = $this->threshold;
		$validation['passed'] = ((int) ($validation['fidelity'] ?? 0)) >= $this->threshold;

		return array(
			'data' => $best_data,
			'validation' => $validation,
			'preview_html' => $preview_html,
			'closed_loop' => $closed_loop,
			'repaired' => $repaired,
		);
	}

	/**
	 * @param array<string,mixed> $context Context.
	 */
	private function resolve_original_screenshot(array $context): ?string
	{
		$shots = $context['screenshots'] ?? array();
		if (!is_array($shots)) {
			return null;
		}
		foreach (array('desktop', 'wide', 'laptop') as $key) {
			$path = (string) ($shots[$key] ?? '');
			if ('' !== $path && is_file($path)) {
				return $path;
			}
		}
		foreach ($shots as $path) {
			if (is_string($path) && is_file($path)) {
				return $path;
			}
		}
		return null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function run_chromium_compare(string $original, string $preview_html, string $out_dir, int $width): ?array
	{
		$script = dirname(__DIR__, 2) . '/chromium-service/lib/closedLoop.js';
		if (!is_file($script)) {
			return null;
		}
		$node = 'node';
		$cmd = escapeshellarg($node) . ' ' . escapeshellarg($script)
			. ' --original ' . escapeshellarg($original)
			. ' --preview ' . escapeshellarg($preview_html)
			. ' --out-dir ' . escapeshellarg($out_dir)
			. ' --width ' . (int) $width
			. ' --json';
		$out = array();
		$code = 0;
		@exec($cmd . ' 2>/dev/null', $out, $code);
		$json = implode("\n", $out);
		$data = json_decode($json, true);
		return is_array($data) ? $data : null;
	}

	/**
	 * Apply additional repairs driven by screenshot compare payload.
	 *
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param array<string,mixed>|null       $compare  Compare payload.
	 * @param array<string,mixed>            $context  Context.
	 * @param bool                           $changed  Changed flag (by ref).
	 * @param array<int,string>              $repairs  Repair log (by ref).
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_compare_driven_repairs(array $elements, ?array $compare, array $context, bool &$changed, array &$repairs): array
	{
		$changed = false;
		if (!is_array($compare)) {
			return $elements;
		}
		$score = (int) ($compare['score'] ?? 0);
		// When dimensions mismatch heavily, force full-width roots.
		$dim = (float) ($compare['dimension_match'] ?? 1);
		if ($dim < 0.9) {
			foreach ($elements as $i => $el) {
				if ('container' !== ($el['elType'] ?? '')) {
					continue;
				}
				if (empty($el['settings']['content_width']) || 'full' !== $el['settings']['content_width']) {
					$elements[$i]['settings']['content_width'] = 'full';
					$changed = true;
					$repairs[] = 'closed_loop:content_width_full';
				}
			}
		}
		if ($score > 0 && $score < 80) {
			// Zero accidental container gaps that inflate vertical distance.
			foreach ($elements as $i => $el) {
				if ('container' !== ($el['elType'] ?? '')) {
					continue;
				}
				$gap = (float) ($el['settings']['flex_gap']['size'] ?? -1);
				if ($gap > 80) {
					$elements[$i]['settings']['flex_gap']['size'] = 24;
					$elements[$i]['settings']['flex_gap']['column'] = '24';
					$elements[$i]['settings']['flex_gap']['row'] = '24';
					$changed = true;
					$repairs[] = 'closed_loop:clamp_gap';
				}
			}
		}
		return $elements;
	}
}
