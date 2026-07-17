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
			$node['layoutConstraint'] = $this->resolve_gap($node, $constraint, $spacing_values);
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
	 * Prefer Chromium CSS gap. Never treat space-between free space as gap.
	 *
	 * @param array<string,mixed> $node            Parent node (by ref via s mutation).
	 * @param array<string,mixed> $constraint      Inferred constraint.
	 * @param array<string,int>   $spacing_values  Tally.
	 * @return array<string,mixed>
	 */
	private function resolve_gap(array &$node, array $constraint, array &$spacing_values): array
	{
		$css_gap = $this->css_gap_px($node);
		$jc = strtolower((string) ($node['s']['jc'] ?? ''));
		$distributed = in_array($jc, array('space-between', 'space-around', 'space-evenly'), true);
		$geometry_gap = (float) ($constraint['gap'] ?? 0);

		if ($distributed) {
			// justify-content distributes free space — that is not CSS gap.
			$constraint['gap'] = $css_gap;
			$constraint['gap_source'] = 'css';
			unset($node['s']['_gap_geometry']);
			if ($css_gap > 0) {
				$node['s']['gap'] = $css_gap . 'px';
				$spacing_values[(string) $css_gap] = ($spacing_values[(string) $css_gap] ?? 0) + 1;
			} elseif (isset($node['s']['gap']) && !empty($node['s']['_gap_geometry'])) {
				unset($node['s']['gap']);
			}
			return $constraint;
		}

		if ($css_gap > 0) {
			// Browser-computed gap is authoritative; do not overwrite with geometry.
			$constraint['gap'] = $css_gap;
			$constraint['gap_source'] = 'css';
			$node['s']['gap'] = $css_gap . 'px';
			unset($node['s']['_gap_geometry']);
			$spacing_values[(string) $css_gap] = ($spacing_values[(string) $css_gap] ?? 0) + 1;
			return $constraint;
		}

		// Geometry gap is only safe for flex/grid parents. On block/flow layouts the
		// sibling distance is usually child margin — converting it to flex_gap and
		// stripping margins invents spacing (often ~48px section rhythm).
		if ($geometry_gap > 0 && $this->is_flex_or_grid($node)) {
			$constraint['gap'] = $geometry_gap;
			$constraint['gap_source'] = 'geometry';
			$node['s']['gap'] = $geometry_gap . 'px';
			$node['s']['_gap_geometry'] = true;
			$spacing_values[(string) $geometry_gap] = ($spacing_values[(string) $geometry_gap] ?? 0) + 1;
			$this->strip_child_margins($node);
		} elseif ($geometry_gap > 0) {
			$constraint['gap'] = 0;
			$constraint['gap_source'] = 'none';
			if (!empty($node['s']['_gap_geometry'])) {
				unset($node['s']['gap'], $node['s']['_gap_geometry']);
			}
		}

		return $constraint;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function is_flex_or_grid(array $node): bool
	{
		$disp = strtolower((string) ($node['s']['disp'] ?? ''));
		return false !== strpos($disp, 'flex') || false !== strpos($disp, 'grid');
	}

	/**
	 * Read gap / row-gap / column-gap from Chromium computed styles.
	 *
	 * @param array<string,mixed> $node Node.
	 */
	private function css_gap_px(array $node): float
	{
		$s = $node['s'] ?? array();
		foreach (array('gap', 'rowGap', 'colGap', 'columnGap') as $key) {
			if (!isset($s[$key]) || '' === $s[$key] || null === $s[$key]) {
				continue;
			}
			// Ignore previously stamped geometry gaps when re-solving.
			if ('gap' === $key && !empty($s['_gap_geometry'])) {
				continue;
			}
			$value = $s[$key];
			if (is_numeric($value)) {
				$px = (float) $value;
			} elseif (is_string($value) && preg_match('/^(-?\d+(?:\.\d+)?)\s*px/i', trim($value), $m)) {
				$px = (float) $m[1];
			} else {
				continue;
			}
			if ($px > 0) {
				return round($px, 0);
			}
		}
		return 0.0;
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
		$bottoms = array_map(fn($b) => $b['y'] + $b['height'], $boxes);
		$centers_x = array_map(fn($b) => $b['x'] + ($b['width'] / 2), $boxes);
		$centers_y = array_map(fn($b) => $b['y'] + ($b['height'] / 2), $boxes);
		$baseline = $bottoms;

		$equal_width = Geometry::aligned($widths, 8.0);
		$equal_height = Geometry::aligned($heights, 8.0);
		$shared_left = Geometry::aligned($lefts, 6.0);
		$shared_right = Geometry::aligned($rights, 6.0);
		$shared_top = Geometry::aligned($tops, 6.0);
		$shared_bottom = Geometry::aligned($bottoms, 6.0);
		$shared_center_x = Geometry::aligned($centers_x, 8.0);
		$shared_center_y = Geometry::aligned($centers_y, 8.0);
		$shared_baseline = Geometry::aligned($baseline, 6.0);

		return array(
			'type' => 'row' === $direction ? 'horizontal_stack' : 'vertical_stack',
			'direction' => $direction,
			'gap' => round($gap, 0),
			'equal_width' => $equal_width,
			'equal_height' => $equal_height,
			'shared_left' => $shared_left,
			'shared_right' => $shared_right,
			'shared_top' => $shared_top,
			'shared_bottom' => $shared_bottom,
			'shared_center_x' => $shared_center_x,
			'shared_center_y' => $shared_center_y,
			'shared_baseline' => $shared_baseline,
			'auto_width' => !$equal_width && 'column' === $direction,
			'auto_height' => !$equal_height && 'row' === $direction,
			'stretch' => ('row' === $direction && !$equal_width) || ('column' === $direction && !$equal_height),
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
