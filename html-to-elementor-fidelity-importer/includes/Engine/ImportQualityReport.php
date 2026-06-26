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
 * Generates a comprehensive quality report after every import, used to drive
 * iterative reconstruction improvements.
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

		return array(
			'visual_fidelity_score' => (int) ($validation['fidelity'] ?? 0),
			'layout_score' => (int) ($validation['layout'] ?? 0),
			'typography_score' => (int) ($validation['typography'] ?? 0),
			'spacing_score' => (int) ($validation['spacing'] ?? 0),
			'widget_coverage' => (int) ($validation['widget_coverage'] ?? round($native / $total * 100)),
			'html_widget_percentage' => (int) ($validation['html_widget_pct'] ?? round($html / $total * 100)),
			'native_widget_percentage' => (int) ($validation['native_widget_pct'] ?? round($native / $total * 100)),
			'missing_assets' => $meta['missing_assets'] ?? array(),
			'unsupported_css' => $meta['unsupported_css'] ?? array(),
			'engines' => $meta['engines'] ?? array(),
			'wrappers_eliminated' => (int) ($meta['wrappers_eliminated'] ?? 0),
			'validation_passed' => (bool) ($validation['passed'] ?? false),
			'repair_iterations' => (int) ($validation['iterations'] ?? 0),
		);
	}
}
