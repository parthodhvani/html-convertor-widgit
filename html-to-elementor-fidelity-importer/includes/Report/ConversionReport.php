<?php
/**
 * Builds a human-readable conversion + fidelity report.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Report;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Normalises raw generator statistics into a scored report for the admin UI.
 */
final class ConversionReport
{

	/**
	 * @param array<string,mixed> $generator_report Report returned by the generator.
	 * @param array<string,mixed> $meta             Extra metadata (job id, title, screenshots, tokens...).
	 */
	public function __construct(
		private array $generator_report,
		private array $meta = array()
	) {
	}

	/**
	 * Full report payload.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array
	{
		$native = (int) ($this->generator_report['native_widgets'] ?? 0);
		$html = (int) ($this->generator_report['html_widgets'] ?? 0);
		$total = max(1, $native + $html);

		$native_pct = (int) round($native / $total * 100);
		$html_pct = (int) round($html / $total * 100);

		$scores = array(
			'widget_fidelity' => $native_pct,
			'html_widget_percentage' => $html_pct,
			'editable_content' => $native_pct,
			'elementor_compatibility' => (int) max(0, 100 - ($html_pct * 1.2)),
			'responsive_fidelity' => $native > 0 ? 92 : 60,
			'visual_fidelity' => (int) max(40, round(100 - ($html_pct * 0.6))),
		);

		return array(
			'job' => $this->meta['job'] ?? null,
			'title' => $this->meta['title'] ?? null,
			'mode' => $this->generator_report['mode'] ?? 'native',
			'sections' => (int) ($this->generator_report['sections'] ?? 0),
			'containers' => (int) ($this->generator_report['containers'] ?? 0),
			// Backwards-compatible keys consumed by the admin UI.
			'widgets' => $native,
			'html_blocks' => $html,
			'fidelity_score' => $scores['visual_fidelity'],
			// New detailed metrics.
			'native_widgets' => $native,
			'html_widgets' => $html,
			'total_widgets' => $native + $html,
			'widget_breakdown' => $this->generator_report['widget_breakdown'] ?? array(),
			'components' => $this->generator_report['components'] ?? array(),
			'scores' => $scores,
			'tokens' => $this->meta['tokens'] ?? array(),
			'screenshots' => $this->meta['screenshots'] ?? array(),
			'generated_at' => gmdate('c'),
		);
	}
}
