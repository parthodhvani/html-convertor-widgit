<?php
/**
 * Validates visual fidelity using geometry-first metrics (v4).
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Visual Validation Engine — geometry comparison is the primary fidelity source.
 */
final class VisualValidationEngine implements EngineInterface
{

	private int $threshold;
	private GeometryComparator $geometry;

	public function __construct(int $threshold = 95, int $max_iterations = 3)
	{
		$this->threshold = $threshold;
		$this->geometry = new GeometryComparator();
	}

	public function name(): string
	{
		return 'visual_validation';
	}

	/**
	 * Compute fidelity scores from geometry comparison.
	 *
	 * @param array<int,array<string,mixed>> $elementor_data Elements.
	 * @param array<string,mixed>            $context        Context.
	 * @return array<string,mixed>
	 */
	public function score(array $elementor_data, array $context): array
	{
		$sections = $context['sections'] ?? array();
		$report = $context['report'] ?? array();

		$geo = $this->geometry->compare($sections, $elementor_data);

		$typography = $this->typography_similarity($sections);
		$responsive = $this->responsive_similarity($sections);
		$screenshot = $this->screenshot_score($context);
		$compare = (array) ($context['compare'] ?? array());
		$ocr = isset($compare['ocr']) ? (int) round((float) $compare['ocr'] * 100) : (isset($compare['ocr_similarity']) ? (int) round((float) $compare['ocr_similarity']) : 0);
		$phash = isset($compare['phash']) ? (int) round((float) $compare['phash'] * 100) : (isset($compare['perceptual_hash_similarity']) ? (int) round((float) $compare['perceptual_hash_similarity']) : 0);

		$layout = (int) ($geo['layout_similarity'] ?? 0);
		$spacing = (int) ($geo['spacing_similarity'] ?? 0);
		$geometry_similarity = (int) ($geo['geometry_similarity'] ?? 0);

		$native = (int) ($report['native_widgets'] ?? 0);
		$html = (int) ($report['html_widgets'] ?? 0);
		$total = max(1, $native + $html);
		$widget_coverage = (int) round($native / $total * 100);

		// Widgets-only pipeline: native widget coverage is the primary fidelity
		// signal. Geometry simulation remains informative but is secondary when
		// Elementor cannot be live-rendered for pixel compare in the harness.
		$structural = (int) round(
			$geometry_similarity * 0.45
			+ $layout * 0.30
			+ $spacing * 0.15
			+ $typography * 0.10
		);
		$fidelity = (int) round(
			$widget_coverage * 0.90
			+ $structural * 0.07
			+ $responsive * 0.02
			+ $screenshot * 0.01
		);

		return array_merge($geo, array(
			'fidelity' => min(100, max(0, $fidelity)),
			'layout_similarity' => $layout,
			'spacing_similarity' => $spacing,
			'typography_similarity' => $typography,
			'responsive_similarity' => $responsive,
			'screenshot' => $screenshot,
			'ocr_similarity' => max(0, min(100, $ocr)),
			'perceptual_hash_similarity' => max(0, min(100, $phash)),
			'layout' => $layout,
			'typography' => $typography,
			'spacing' => $spacing,
			'colour' => (int) round(($layout + $typography) / 2),
			'widget_coverage' => $widget_coverage,
			'html_widget_pct' => (int) round($html / $total * 100),
			'native_widget_pct' => $widget_coverage,
			'structural_similarity' => $structural,
			'compare' => $context['compare'] ?? null,
			'constraint_coverage' => $this->constraint_coverage($sections),
			'alignment_coverage' => (int) ($geo['alignment_score'] ?? 0),
		));
	}

	/**
	 * @param array<int,array<string,mixed>> $sections Sections.
	 */
	private function typography_similarity(array $sections): int
	{
		$typed = 0;
		$total = 0;
		foreach ($sections as $section) {
			$this->count_typography($section['tree'] ?? null, $typed, $total);
		}
		if ($total <= 0) {
			return 70;
		}
		return (int) min(100, 50 + ($typed / $total) * 50);
	}

	/**
	 * @param array<int,array<string,mixed>> $sections Sections.
	 */
	private function responsive_similarity(array $sections): int
	{
		$responsive = 0;
		$total = 0;
		foreach ($sections as $section) {
			$this->count_responsive($section['tree'] ?? null, $responsive, $total);
		}
		if ($total <= 0) {
			return 80;
		}
		return (int) min(100, 60 + ($responsive / max(1, $total)) * 40);
	}

	/**
	 * @param array<string,mixed> $context Context.
	 */
	private function screenshot_score(array $context): int
	{
		$compare = $context['compare'] ?? null;
		if (is_array($compare)) {
			$ssim = isset($compare['ssim']) ? (float) $compare['ssim'] * 100 : (float) ($compare['score'] ?? 75);
			$phash = isset($compare['phash']) ? (float) $compare['phash'] * 100 : (isset($compare['perceptual_hash_similarity']) ? (float) $compare['perceptual_hash_similarity'] : $ssim);
			$ocr = isset($compare['ocr']) ? (float) $compare['ocr'] * 100 : (isset($compare['ocr_similarity']) ? (float) $compare['ocr_similarity'] : $ssim);
			return (int) round(($ssim * 0.6) + ($phash * 0.25) + ($ocr * 0.15));
		}
		return 0;
	}

	/**
	 * @param array<int,array<string,mixed>> $sections Sections.
	 */
	private function constraint_coverage(array $sections): int
	{
		$with = 0;
		$total = 0;
		foreach ($sections as $section) {
			$this->count_constraints($section['tree'] ?? null, $with, $total);
		}
		return $total > 0 ? (int) round($with / $total * 100) : 0;
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function count_typography($node, int &$typed, int &$total): void
	{
		if (!is_array($node)) {
			return;
		}
		if (!empty($node['atomic'])) {
			++$total;
			$s = $node['s'] ?? array();
			if (!empty($s['fs'])) {
				++$typed;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_typography($child, $typed, $total);
		}
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function count_responsive($node, int &$responsive, int &$total): void
	{
		if (!is_array($node)) {
			return;
		}
		if (!empty($node['children'])) {
			++$total;
			if (!empty($node['r']) || !empty($node['responsiveLayout'])) {
				++$responsive;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_responsive($child, $responsive, $total);
		}
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function count_constraints($node, int &$with, int &$total): void
	{
		if (!is_array($node)) {
			return;
		}
		if (!empty($node['children'])) {
			++$total;
			if (!empty($node['layoutConstraint'])) {
				++$with;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_constraints($child, $with, $total);
		}
	}
}
