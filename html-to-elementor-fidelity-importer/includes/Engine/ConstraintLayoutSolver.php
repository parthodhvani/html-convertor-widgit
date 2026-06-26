<?php
/**
 * Infers Figma-style layout constraints from geometry.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Constraint Layout Solver — detects vertical/horizontal stacks, equal sizing,
 * alignment anchors and gap from bounding boxes. Never recreates CSS literally.
 */
final class ConstraintLayoutSolver implements EngineInterface
{

	/** @var array<string,mixed> */
	private array $spacing_tokens = array();

	public function name(): string
	{
		return 'constraint_layout_solver';
	}

	/**
	 * @return array<string,mixed> Spacing tokens from last solve.
	 */
	public function spacing_tokens(): array
	{
		return $this->spacing_tokens;
	}

	/**
	 * Solve constraints for all section trees.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function solve(array $sections): array
	{
		$spacing_values = array();
		$out = array();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->solve_node($tree, $spacing_values);
				$section['tree'] = $tree;
			}
			$out[] = $section;
		}

		$this->spacing_tokens = $this->build_scale($spacing_values);
		return $out;
	}

	/**
	 * @param array<string,mixed> $node           Node (by ref).
	 * @param array<string,int>   $spacing_values Tally.
	 */
	private function solve_node(array &$node, array &$spacing_values): void
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) >= 2) {
			$constraint = $this->infer_constraint($children, $node);
			$node['layoutConstraint'] = $constraint;

			if ($constraint['gap'] > 0) {
				$node['s']['gap'] = $constraint['gap'] . 'px';
				$node['s']['_gap_geometry'] = true;
				$spacing_values[(string) $constraint['gap']] = ($spacing_values[(string) $constraint['gap']] ?? 0) + 1;
				$this->strip_child_margins($node);
			}
		}

		foreach ($children as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->solve_node($child, $spacing_values);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $children Child nodes.
	 * @param array<string,mixed>            $parent   Parent node.
	 * @return array<string,mixed>
	 */
	private function infer_constraint(array $children, array $parent): array
	{
		$boxes = array();
		foreach ($children as $child) {
			$boxes[] = Geometry::bbox($child);
		}

		$direction = $this->infer_direction($children, $boxes, $parent);
		$gap = 'row' === $direction
			? $this->infer_horizontal_gaps($boxes)
			: $this->infer_vertical_gaps($boxes);

		$widths = array_map(fn($b) => $b['width'], $boxes);
		$heights = array_map(fn($b) => $b['height'], $boxes);
		$lefts = array_map(fn($b) => $b['x'], $boxes);
		$rights = array_map(fn($b) => $b['x'] + $b['width'], $boxes);
		$tops = array_map(fn($b) => $b['y'], $boxes);

		return array(
			'type' => 'row' === $direction ? 'horizontal_stack' : 'vertical_stack',
			'direction' => $direction,
			'gap' => round($gap, 0),
			'equal_width' => Geometry::aligned($widths, 8.0),
			'equal_height' => Geometry::aligned($heights, 8.0),
			'shared_left' => Geometry::aligned($lefts, 6.0),
			'shared_right' => Geometry::aligned($rights, 6.0),
			'shared_top' => Geometry::aligned($tops, 6.0),
			'fill' => $this->children_fill_parent($boxes),
		);
	}

	/**
	 * @param array<int,array<string,mixed>>                            $children Children.
	 * @param array<int,array{x:float,y:float,width:float,height:float}> $boxes    Bboxes.
	 */
	/**
	 * @param array<int,array<string,mixed>>                            $children Children.
	 * @param array<int,array{x:float,y:float,width:float,height:float}> $boxes    Bboxes.
	 * @param array<string,mixed>                                       $parent   Parent node.
	 */
	private function infer_direction(array $children, array $boxes, array $parent): string
	{
		$visual = (string) ($children[0]['visualSiblingLayout'] ?? '');
		if ('row' === $visual) {
			return 'row';
		}
		if ('column' === $visual) {
			return 'column';
		}

		$group = (string) ($parent['visualGroup'] ?? '');
		if ('row' === $group) {
			return 'row';
		}

		// Explicit CSS flex/grid direction when geometry is unavailable.
		$parent_s = $parent['s'] ?? array();
		$disp = (string) ($parent_s['disp'] ?? '');
		if (false !== strpos($disp, 'flex')) {
			$fd = strtolower((string) ($parent_s['fd'] ?? 'row'));
			return (false !== strpos($fd, 'column')) ? 'column' : 'row';
		}
		if (false !== strpos($disp, 'grid')) {
			return 'row';
		}

		// Skip geometry when boxes lack positional data.
		$positioned = array_filter($boxes, fn($b) => $b['width'] > 0 && ($b['x'] > 0 || $b['y'] > 0));
		if (count($positioned) < 2) {
			return 'column';
		}

		$row_votes = 0;
		$col_votes = 0;
		for ($i = 0; $i < count($boxes) - 1; ++$i) {
			if (Geometry::overlaps_y($boxes[$i], $boxes[$i + 1])) {
				++$row_votes;
			}
			if (Geometry::vertical_gap($boxes[$i], $boxes[$i + 1]) > 4) {
				++$col_votes;
			}
		}

		if (false !== strpos($disp, 'grid')) {
			++$row_votes;
		}

		return $row_votes > $col_votes ? 'row' : 'column';
	}

	/**
	 * @param array<int,array{x:float,y:float,width:float,height:float}> $boxes Boxes.
	 */
	private function infer_horizontal_gaps(array $boxes): float
	{
		$gaps = array();
		for ($i = 0; $i < count($boxes) - 1; ++$i) {
			$g = Geometry::horizontal_gap($boxes[$i], $boxes[$i + 1]);
			if ($g > 0) {
				$gaps[] = $g;
			}
		}
		return Geometry::median($gaps);
	}

	/**
	 * @param array<int,array{x:float,y:float,width:float,height:float}> $boxes Boxes.
	 */
	private function infer_vertical_gaps(array $boxes): float
	{
		$gaps = array();
		for ($i = 0; $i < count($boxes) - 1; ++$i) {
			$g = Geometry::vertical_gap($boxes[$i], $boxes[$i + 1]);
			if ($g > 0) {
				$gaps[] = $g;
			}
		}
		return Geometry::median($gaps);
	}

	/**
	 * @param array<int,array{x:float,y:float,width:float,height:float}> $boxes Boxes.
	 */
	private function children_fill_parent(array $boxes): bool
	{
		if (count($boxes) < 2) {
			return false;
		}
		$widths = array_map(fn($b) => $b['width'], $boxes);
		$total = array_sum($widths);
		$max = max($widths);
		return $total > 0 && ($max / $total) < 0.65;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function strip_child_margins(array &$node): void
	{
		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			unset($child['s']['mt'], $child['s']['mb'], $child['s']['ml'], $child['s']['mr']);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param array<string,int> $values Tally.
	 * @return array<string,mixed>
	 */
	private function build_scale(array $values): array
	{
		if (empty($values)) {
			return array();
		}
		arsort($values);
		$scale = array_slice(array_map('floatval', array_keys($values)), 0, 8);
		sort($scale);
		return array('scale' => $scale, 'base' => $scale[(int) floor(count($scale) / 2)] ?? 16.0);
	}
}
