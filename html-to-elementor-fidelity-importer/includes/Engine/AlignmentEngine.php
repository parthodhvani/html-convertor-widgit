<?php
/**
 * Infers alignment from geometry.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Alignment Engine — detects shared baselines, edges and centers from bounding
 * boxes and maps them to Elementor flex alignment controls.
 */
final class AlignmentEngine implements EngineInterface
{

	public function name(): string
	{
		return 'alignment_engine';
	}

	/**
	 * Annotate section trees with alignment constraints.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function apply(array $sections): array
	{
		$out = array();
		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->annotate_node($tree);
				$section['tree'] = $tree;
			}
			$out[] = $section;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function annotate_node(array &$node): void
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) >= 2) {
			$node['alignment'] = $this->detect_alignment($children, $node);
			$this->apply_to_styles($node);
		}

		foreach ($children as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->annotate_node($child);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $children Children.
	 * @param array<string,mixed>            $parent   Parent node.
	 * @return array<string,mixed>
	 */
	private function detect_alignment(array $children, array $parent): array
	{
		$boxes = array_map(array(Geometry::class, 'bbox'), $children);
		$lefts = array_map(fn($b) => $b['x'], $boxes);
		$rights = array_map(fn($b) => $b['x'] + $b['width'], $boxes);
		$tops = array_map(fn($b) => $b['y'], $boxes);
		$centers_x = array_map(fn($b) => $b['x'] + $b['width'] / 2, $boxes);
		$centers_y = array_map(fn($b) => $b['y'] + $b['height'] / 2, $boxes);

		$constraint = $parent['layoutConstraint'] ?? array();
		$direction = (string) ($constraint['direction'] ?? 'column');

		$align = array(
			'shared_left' => Geometry::aligned($lefts, 6.0),
			'shared_right' => Geometry::aligned($rights, 6.0),
			'shared_top' => Geometry::aligned($tops, 6.0),
			'shared_center_x' => Geometry::aligned($centers_x, 8.0),
			'shared_center_y' => Geometry::aligned($centers_y, 8.0),
			'shared_baseline' => Geometry::aligned(array_map(fn($b) => $b['y'] + $b['height'], $boxes), 6.0),
		);

		if ('row' === $direction) {
			$align['justify'] = $align['shared_center_x'] ? 'center' : ($align['shared_left'] ? 'flex-start' : ($align['shared_right'] ? 'flex-end' : 'space-between'));
			$align['align_items'] = $align['shared_center_y'] ? 'center' : ($align['shared_baseline'] ? 'baseline' : 'stretch');
		} else {
			$align['justify'] = $align['shared_top'] ? 'flex-start' : ($align['shared_center_y'] ? 'center' : 'space-between');
			$align['align_items'] = $align['shared_left'] ? 'flex-start' : ($align['shared_center_x'] ? 'center' : ($align['shared_right'] ? 'flex-end' : 'stretch'));
		}

		// Parent text-align hint.
		$ta = strtolower((string) ($parent['s']['ta'] ?? ''));
		if ('center' === $ta) {
			$align['justify'] = 'center';
		} elseif ('right' === $ta || 'end' === $ta) {
			$align['justify'] = 'flex-end';
		}

		return $align;
	}

	/**
	 * Write alignment into computed style hints for CssMapper.
	 *
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function apply_to_styles(array &$node): void
	{
		$align = $node['alignment'] ?? array();
		if (empty($align)) {
			return;
		}
		if (!empty($align['justify'])) {
			$node['s']['jc'] = $align['justify'];
		}
		if (!empty($align['align_items'])) {
			$node['s']['ai'] = $align['align_items'];
		}
	}
}
