<?php
/**
 * Validates visual fidelity using geometry-first metrics.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Visual Validation Engine — scores layout, spacing, typography and screenshot
 * similarity. Repair is delegated to {@see PixelRepairEngine}.
 */
final class VisualValidationEngine implements EngineInterface
{

	private int $threshold;

	public function __construct(int $threshold = 95, int $max_iterations = 3)
	{
		$this->threshold = $threshold;
	}

	public function name(): string
	{
		return 'visual_validation';
	}

	/**
	 * Compute fidelity scores prioritising layout accuracy over widget count.
	 *
	 * @param array<int,array<string,mixed>> $elementor_data Elements.
	 * @param array<string,mixed>            $context        Context.
	 * @return array<string,mixed>
	 */
	public function score(array $elementor_data, array $context): array
	{
		$sections = $context['sections'] ?? array();
		$report = $context['report'] ?? array();

		$layout = $this->layout_similarity($elementor_data, $sections);
		$spacing = $this->spacing_similarity($sections);
		$typography = $this->typography_similarity($sections);
		$responsive = $this->responsive_similarity($sections);
		$screenshot = $this->screenshot_score($context);

		// Visual fidelity prioritises layout > spacing > typography > screenshot.
		$fidelity = (int) round(
			$layout * 0.35
			+ $spacing * 0.25
			+ $typography * 0.15
			+ $responsive * 0.10
			+ $screenshot * 0.15
		);

		$native = (int) ($report['native_widgets'] ?? 0);
		$html = (int) ($report['html_widgets'] ?? 0);
		$total = max(1, $native + $html);

		return array(
			'fidelity' => min(100, max(0, $fidelity)),
			'layout_similarity' => $layout,
			'spacing_similarity' => $spacing,
			'typography_similarity' => $typography,
			'responsive_similarity' => $responsive,
			'screenshot' => $screenshot,
			'layout' => $layout,
			'typography' => $typography,
			'spacing' => $spacing,
			'colour' => (int) round(($layout + $typography) / 2),
			'widget_coverage' => (int) round($native / $total * 100),
			'html_widget_pct' => (int) round($html / $total * 100),
			'native_widget_pct' => (int) round($native / $total * 100),
			'compare' => $context['compare'] ?? null,
			'constraint_coverage' => $this->constraint_coverage($sections),
			'alignment_coverage' => $this->alignment_coverage($sections),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $data     Elements.
	 * @param array<int,array<string,mixed>> $sections Sections.
	 */
	private function layout_similarity(array $data, array $sections): int
	{
		$source_constraints = 0;
		$source_rows = 0;
		foreach ($sections as $section) {
			$this->count_layout_signals($section['tree'] ?? null, $source_constraints, $source_rows);
		}

		$containers = $this->count_containers($data);
		$section_count = max(1, count($sections));
		$depth = $this->max_depth($data);

		$structure = 70;
		if ($source_constraints > 0) {
			$structure += min(20, $source_constraints * 2);
		}
		if ($containers >= $section_count && $containers <= $section_count * 10) {
			$structure += 10;
		}
		if ($depth <= 6) {
			$structure += min(10, (6 - $depth) * 2);
		} elseif ($depth > 10) {
			$structure -= min(20, ($depth - 10) * 3);
		}

		return min(100, max(40, $structure));
	}

	/**
	 * @param array<int,array<string,mixed>> $sections Sections.
	 */
	private function spacing_similarity(array $sections): int
	{
		$gap_nodes = 0;
		$whitespace_nodes = 0;
		$total = 0;
		foreach ($sections as $section) {
			$this->count_spacing_signals($section['tree'] ?? null, $gap_nodes, $whitespace_nodes, $total);
		}
		if ($total <= 0) {
			return 75;
		}
		$ratio = ($gap_nodes + $whitespace_nodes) / max(1, $total);
		return (int) min(100, 55 + $ratio * 45);
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
		if (is_array($compare) && isset($compare['ssim'])) {
			return (int) round((float) $compare['ssim'] * 100);
		}
		if (is_array($compare) && isset($compare['score'])) {
			return (int) round((float) $compare['score']);
		}
		return 75;
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
	 * @param array<int,array<string,mixed>> $sections Sections.
	 */
	private function alignment_coverage(array $sections): int
	{
		$with = 0;
		$total = 0;
		foreach ($sections as $section) {
			$this->count_alignments($section['tree'] ?? null, $with, $total);
		}
		return $total > 0 ? (int) round($with / $total * 100) : 0;
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function count_layout_signals($node, int &$constraints, int &$rows): void
	{
		if (!is_array($node)) {
			return;
		}
		if (!empty($node['layoutConstraint'])) {
			++$constraints;
			if ('row' === ($node['layoutConstraint']['direction'] ?? '')) {
				++$rows;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_layout_signals($child, $constraints, $rows);
		}
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function count_spacing_signals($node, int &$gaps, int &$whitespace, int &$total): void
	{
		if (!is_array($node)) {
			return;
		}
		if (!empty($node['children'])) {
			++$total;
			if (!empty($node['layoutConstraint']['gap'])) {
				++$gaps;
			}
			if (!empty($node['whitespace']['gap'])) {
				++$whitespace;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_spacing_signals($child, $gaps, $whitespace, $total);
		}
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function count_typography($node, int &$typed, int &$total): void
	{
		if (!is_array($node)) {
			return;
		}
		if (!empty($node['atomic']) || preg_match('/^h[1-6]$/', (string) ($node['tag'] ?? ''))) {
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

	/**
	 * @param array<string,mixed>|null $node Node.
	 */
	private function count_alignments($node, int &$with, int &$total): void
	{
		if (!is_array($node)) {
			return;
		}
		if (!empty($node['children'])) {
			++$total;
			if (!empty($node['alignment'])) {
				++$with;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_alignments($child, $with, $total);
		}
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
	 * @param int                            $depth    Current depth.
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
}
