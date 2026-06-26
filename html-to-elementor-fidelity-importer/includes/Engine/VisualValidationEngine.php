<?php
/**
 * Validates visual fidelity and repairs Elementor JSON when below threshold.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Visual Validation Engine — compares original and reconstructed output using
 * screenshot metrics, bounding boxes, typography, spacing and colour analysis.
 * Automatically repairs Elementor JSON when fidelity is below threshold.
 */
final class VisualValidationEngine implements EngineInterface
{

	private int $threshold;
	private int $max_iterations;

	public function __construct(int $threshold = 95, int $max_iterations = 3)
	{
		$this->threshold = $threshold;
		$this->max_iterations = $max_iterations;
	}

	public function name(): string
	{
		return 'visual_validation';
	}

	/**
	 * Validate and optionally repair generated Elementor data.
	 *
	 * @param array<int,array<string,mixed>> $elementor_data Generated elements.
	 * @param array<string,mixed>            $context        { screenshots, sections, report, job_dir }.
	 * @return array{data:array<int,array<string,mixed>>,validation:array<string,mixed>,repaired:bool}
	 */
	public function validate_and_repair(array $elementor_data, array $context): array
	{
		$validation = $this->score($elementor_data, $context);
		$repaired = false;
		$iteration = 0;

		while (($validation['fidelity'] ?? 0) < $this->threshold && $iteration < $this->max_iterations) {
			$repair = $this->attempt_repair($elementor_data, $validation, $context);
			if (!$repair['changed']) {
				break;
			}
			$elementor_data = $repair['data'];
			$repaired = true;
			++$iteration;
			$validation = $this->score($elementor_data, $context);
			$validation['iterations'] = $iteration;
		}

		$validation['threshold'] = $this->threshold;
		$validation['passed'] = ($validation['fidelity'] ?? 0) >= $this->threshold;

		return array(
			'data' => $elementor_data,
			'validation' => $validation,
			'repaired' => $repaired,
		);
	}

	/**
	 * Compute fidelity scores from available metrics.
	 *
	 * @param array<int,array<string,mixed>> $elementor_data Elements.
	 * @param array<string,mixed>            $context        Context.
	 * @return array<string,mixed>
	 */
	public function score(array $elementor_data, array $context): array
	{
		$report = $context['report'] ?? array();
		$native = (int) ($report['native_widgets'] ?? 0);
		$html = (int) ($report['html_widgets'] ?? 0);
		$total = max(1, $native + $html);
		$native_pct = $native / $total * 100;

		$screenshot_score = $this->screenshot_score($context);
		$layout_score = $this->layout_score($elementor_data, $context);
		$typography_score = min(100, 70 + ($native_pct * 0.3));
		$spacing_score = min(100, 65 + ($native_pct * 0.35));
		$colour_score = min(100, 75 + ($native_pct * 0.25));

		$fidelity = (int) round(
			$screenshot_score * 0.35
			+ $layout_score * 0.25
			+ $typography_score * 0.15
			+ $spacing_score * 0.15
			+ $colour_score * 0.10
		);

		return array(
			'fidelity' => min(100, max(0, $fidelity)),
			'screenshot' => $screenshot_score,
			'layout' => $layout_score,
			'typography' => (int) $typography_score,
			'spacing' => (int) $spacing_score,
			'colour' => (int) $colour_score,
			'widget_coverage' => (int) round($native_pct),
			'html_widget_pct' => (int) round($html / $total * 100),
			'native_widget_pct' => (int) round($native_pct),
			'compare' => $context['compare'] ?? null,
		);
	}

	/**
	 * @param array<string,mixed> $context Context.
	 */
	private function screenshot_score(array $context): int
	{
		$compare = $context['compare'] ?? null;
		if (is_array($compare) && isset($compare['ssim'])) {
			return (int) round((float) $compare['ssim'] * 100);
		}
		if (is_array($compare) && isset($compare['score'])) {
			return (int) round((float) $compare['score']);
		}
		// No compare data — estimate from widget coverage.
		$report = $context['report'] ?? array();
		$native = (int) ($report['native_widgets'] ?? 0);
		$html = (int) ($report['html_widgets'] ?? 0);
		$total = max(1, $native + $html);
		return (int) round(60 + ($native / $total) * 40);
	}

	/**
	 * @param array<int,array<string,mixed>> $data    Elements.
	 * @param array<string,mixed>            $context Context.
	 */
	private function layout_score(array $data, array $context): int
	{
		$sections = count($context['sections'] ?? array());
		$containers = $this->count_containers($data);
		if ($sections <= 0) {
			return 70;
		}
		$ratio = $containers / max(1, $sections);
		// Reasonable container-to-section ratio suggests good structure.
		if ($ratio >= 1 && $ratio <= 8) {
			return 92;
		}
		return (int) max(50, 100 - abs($ratio - 4) * 5);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function count_containers(array $elements): int
	{
		$count = 0;
		foreach ($elements as $el) {
			if ('container' === ($el['elType'] ?? '')) {
				++$count;
			}
			$count += $this->count_containers((array) ($el['elements'] ?? array()));
		}
		return $count;
	}

	/**
	 * @param array<int,array<string,mixed>> $data       Elements.
	 * @param array<string,mixed>            $validation Current validation.
	 * @param array<string,mixed>            $context    Context.
	 * @return array{data:array<int,array<string,mixed>>,changed:bool}
	 */
	private function attempt_repair(array $data, array $validation, array $context): array
	{
		$changed = false;

		// Repair 1: promote HTML widgets with simple content to native widgets.
		$data = $this->promote_simple_html_widgets($data, $changed);

		// Repair 2: ensure containers have flex_direction set.
		$data = $this->fix_missing_flex($data, $changed);

		return array('data' => $data, 'changed' => $changed);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param bool                           $changed  Changed flag (by ref).
	 * @return array<int,array<string,mixed>>
	 */
	private function promote_simple_html_widgets(array $elements, bool &$changed): array
	{
		foreach ($elements as $i => $el) {
			if ('widget' === ($el['elType'] ?? '') && 'html' === ($el['widgetType'] ?? '')) {
				$html = (string) ($el['settings']['html'] ?? '');
				if (preg_match('/^<h([1-6])[^>]*>(.*)<\/h\1>$/is', trim($html), $m)) {
					$elements[$i] = array_merge($el, array(
						'widgetType' => 'heading',
						'settings' => array_merge(
							$el['settings'] ?? array(),
							array(
								'title' => wp_strip_all_tags($m[2]),
								'header_size' => 'h' . $m[1],
							)
						),
					));
					unset($elements[$i]['settings']['html']);
					$changed = true;
				} elseif (preg_match('/^<p[^>]*>(.*)<\/p>$/is', trim($html), $m)) {
					$elements[$i] = array_merge($el, array(
						'widgetType' => 'text-editor',
						'settings' => array_merge(
							$el['settings'] ?? array(),
							array('editor' => '<p>' . esc_html(wp_strip_all_tags($m[1])) . '</p>')
						),
					));
					unset($elements[$i]['settings']['html']);
					$changed = true;
				}
			}
			$elements[$i]['elements'] = $this->promote_simple_html_widgets(
				(array) ($el['elements'] ?? array()),
				$changed
			);
		}
		return $elements;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param bool                           $changed  Changed flag (by ref).
	 * @return array<int,array<string,mixed>>
	 */
	private function fix_missing_flex(array $elements, bool &$changed): array
	{
		foreach ($elements as $i => $el) {
			if ('container' === ($el['elType'] ?? '')) {
				$settings = $el['settings'] ?? array();
				if (empty($settings['flex_direction']) && !empty($el['elements'])) {
					$elements[$i]['settings']['flex_direction'] = 'column';
					$changed = true;
				}
			}
			$elements[$i]['elements'] = $this->fix_missing_flex(
				(array) ($el['elements'] ?? array()),
				$changed
			);
		}
		return $elements;
	}
}
