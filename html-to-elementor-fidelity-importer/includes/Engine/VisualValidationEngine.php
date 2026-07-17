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

		// Geometry / paint reconstruction is the primary fidelity signal.
		// Widget coverage is reported separately and must not dominate the score
		// (native widgets can still look wrong if spacing/paint is lost).
		$colour = $this->colour_similarity($sections, $elementor_data);
		$fidelity = (int) round(
			$geometry_similarity * 0.35
			+ $layout * 0.20
			+ $spacing * 0.15
			+ $typography * 0.10
			+ $colour * 0.10
			+ $responsive * 0.05
			+ $screenshot * 0.05
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
			'colour' => $colour,
			'widget_coverage' => $widget_coverage,
			'html_widget_pct' => (int) round($html / $total * 100),
			'native_widget_pct' => $widget_coverage,
			'structural_similarity' => (int) round(
				$geometry_similarity * 0.45 + $layout * 0.30 + $spacing * 0.15 + $typography * 0.10
			),
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
	 * Paint / colour fidelity: share of source painted nodes that retained a
	 * background colour or gradient in the emitted Elementor tree.
	 *
	 * @param array<int,array<string,mixed>> $sections       Source sections.
	 * @param array<int,array<string,mixed>> $elementor_data Emitted elements.
	 */
	private function colour_similarity(array $sections, array $elementor_data): int
	{
		$painted = 0;
		$gradients = 0;
		foreach ($sections as $section) {
			$this->count_paint($section['tree'] ?? null, $painted, $gradients);
		}
		if ($painted <= 0) {
			return 70;
		}

		$emitted_bgs = 0;
		$emitted_grads = 0;
		$this->count_emitted_paint($elementor_data, $emitted_bgs, $emitted_grads);

		$bg_ratio = min(1.0, $emitted_bgs / max(1, $painted));
		$grad_ratio = $gradients > 0 ? min(1.0, $emitted_grads / $gradients) : 1.0;

		return (int) round(40 + ($bg_ratio * 35) + ($grad_ratio * 25));
	}

	/**
	 * @param array<string,mixed>|null $node       Node.
	 * @param int                      $painted    Painted node count.
	 * @param int                      $gradients  Gradient node count.
	 */
	private function count_paint($node, int &$painted, int &$gradients): void
	{
		if (!is_array($node)) {
			return;
		}
		$s = $node['s'] ?? array();
		$bg = (string) ($s['bg'] ?? '');
		$bg_img = (string) ($s['bgImg'] ?? '');
		$has_solid = '' !== $bg && 'transparent' !== strtolower($bg) && false === stripos($bg, 'rgba(0, 0, 0, 0)');
		$has_grad = false !== stripos($bg_img, 'gradient') || false !== stripos($bg, 'gradient');
		$has_img = (bool) preg_match('/url\(/i', $bg_img);
		if ($has_solid || $has_grad || $has_img) {
			++$painted;
			if ($has_grad) {
				++$gradients;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_paint($child, $painted, $gradients);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param int                            $bgs      Background count.
	 * @param int                            $grads    Gradient count.
	 */
	private function count_emitted_paint(array $elements, int &$bgs, int &$grads): void
	{
		foreach ($elements as $el) {
			if (!is_array($el)) {
				continue;
			}
			$settings = $el['settings'] ?? array();
			$type = (string) ($settings['background_background'] ?? '');
			$has = '' !== $type
				|| !empty($settings['background_color'])
				|| !empty($settings['background_image']['url'])
				|| !empty($settings['background_overlay_background']);
			if ($has) {
				++$bgs;
			}
			if ('gradient' === $type || 'gradient' === ($settings['background_overlay_background'] ?? '')) {
				++$grads;
			}
			$kids = $el['elements'] ?? array();
			if (is_array($kids) && $kids) {
				$this->count_emitted_paint($kids, $bgs, $grads);
			}
		}
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
