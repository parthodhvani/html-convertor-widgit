<?php
/**
 * Self-improving import quality report.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Generates v3 quality metrics prioritising visual fidelity over widget count.
 */
final class ImportQualityReport
{

	/**
	 * Build a full quality report.
	 *
	 * @param array<string,mixed> $generator_report Generator stats.
	 * @param array<string,mixed> $validation       Validation scores.
	 * @param array<string,mixed> $meta             Extra metadata.
	 * @return array<string,mixed>
	 */
	public function build(array $generator_report, array $validation, array $meta = array()): array
	{
		$native = (int) ($generator_report['native_widgets'] ?? 0);
		$html = (int) ($generator_report['html_widgets'] ?? 0);
		$total = max(1, $native + $html);
		$data = (array) ($meta['elementor_data'] ?? array());

		return array(
			// Primary visual metrics (v4 geometry-first).
			'visual_fidelity_score' => (int) ($validation['fidelity'] ?? 0),
			'geometry_similarity' => (int) ($validation['geometry_similarity'] ?? 0),
			'bbox_delta' => (float) ($validation['bbox_delta'] ?? 0),
			'position_rmse' => (float) ($validation['position_rmse'] ?? 0),
			'size_rmse' => (float) ($validation['size_rmse'] ?? 0),
			'layout_similarity' => (int) ($validation['layout_similarity'] ?? $validation['layout'] ?? 0),
			'spacing_similarity' => (int) ($validation['spacing_similarity'] ?? $validation['spacing'] ?? 0),
			'typography_similarity' => (int) ($validation['typography_similarity'] ?? $validation['typography'] ?? 0),
			'responsive_similarity' => (int) ($validation['responsive_similarity'] ?? 0),
			'pixel_similarity' => (int) ($validation['pixel_similarity'] ?? $validation['screenshot'] ?? 0),
			// Secondary widget metrics.
			'native_widget_ratio' => round($native / $total * 100, 1),
			'html_widget_ratio' => round($html / $total * 100, 1),
			'widget_coverage' => (int) ($validation['widget_coverage'] ?? round($native / $total * 100)),
			// Structure metrics.
			'container_count' => $this->count_containers($data),
			'max_nesting_depth' => $this->max_depth($data),
			'average_container_depth' => (float) ($meta['container_compression']['average_container_depth'] ?? $this->average_container_depth($data)),
			'max_container_depth' => (int) ($meta['container_compression']['max_container_depth'] ?? $this->max_container_depth($data)),
			'redundant_containers_removed' => (int) ($meta['container_compression']['redundant_containers_removed'] ?? 0),
			'compression_ratio' => (float) ($meta['container_compression']['compression_ratio'] ?? 0),
			'containers_before_compression' => (int) ($meta['container_compression']['containers_before'] ?? 0),
			'constraint_coverage' => (int) ($validation['constraint_coverage'] ?? 0),
			'alignment_coverage' => (int) ($validation['alignment_coverage'] ?? 0),
			// Confidence and fallbacks.
			'average_confidence' => round((float) ($meta['average_confidence'] ?? 0), 1),
			'html_fallback_reasons' => $meta['fallback_reasons'] ?? array(),
			'remaining_html_fallback_reasons' => $meta['fallback_reasons'] ?? array(),
			// Operational.
			'import_duration_ms' => (int) ($meta['import_duration_ms'] ?? 0),
			'repair_iterations' => (int) ($validation['iterations'] ?? 0),
			'repairs_applied' => $validation['repairs'] ?? array(),
			'validation_passed' => (bool) ($validation['passed'] ?? false),
			// Legacy keys for admin UI compatibility.
			'layout_score' => (int) ($validation['layout_similarity'] ?? $validation['layout'] ?? 0),
			'typography_score' => (int) ($validation['typography_similarity'] ?? $validation['typography'] ?? 0),
			'spacing_score' => (int) ($validation['spacing_similarity'] ?? $validation['spacing'] ?? 0),
			'html_widget_percentage' => (int) round($html / $total * 100),
			'native_widget_percentage' => (int) round($native / $total * 100),
			'missing_assets' => $meta['missing_assets'] ?? array(),
			'unsupported_css' => $meta['unsupported_css'] ?? array(),
			'engines' => $meta['engines'] ?? array(),
			'wrappers_eliminated' => (int) ($meta['wrappers_eliminated'] ?? 0),
			'visual_restructured' => (int) ($meta['visual_restructured'] ?? 0),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function count_containers(array $elements): int
	{
		$n = 0;
		foreach ($elements as $el) {
			if ('container' === ($el['elType'] ?? '')) {
				++$n;
			}
			$n += $this->count_containers((array) ($el['elements'] ?? array()));
		}
		return $n;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param int                            $depth    Depth.
	 */
	private function max_depth(array $elements, int $depth = 1): int
	{
		$max = $depth;
		foreach ($elements as $el) {
			$kids = (array) ($el['elements'] ?? array());
			if (!empty($kids)) {
				$max = max($max, $this->max_depth($kids, $depth + 1));
			}
		}
		return $max;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function max_container_depth(array $elements, int $depth = 0): int
	{
		$max = $depth;
		foreach ($elements as $el) {
			if ('container' !== ($el['elType'] ?? '')) {
				continue;
			}
			$next = $depth + 1;
			$max = max($max, $next, $this->max_container_depth((array) ($el['elements'] ?? array()), $next));
		}

		return $max;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function average_container_depth(array $elements): float
	{
		$depths = array();
		$this->collect_container_depths($elements, 1, $depths);
		if (empty($depths)) {
			return 0.0;
		}

		return round(array_sum($depths) / count($depths), 2);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param int                            $depth    Depth.
	 * @param array<int,int>                 $depths   Collector.
	 */
	private function collect_container_depths(array $elements, int $depth, array &$depths): void
	{
		foreach ($elements as $el) {
			if ('container' !== ($el['elType'] ?? '')) {
				continue;
			}
			$depths[] = $depth;
			$this->collect_container_depths((array) ($el['elements'] ?? array()), $depth + 1, $depths);
		}
	}
}
