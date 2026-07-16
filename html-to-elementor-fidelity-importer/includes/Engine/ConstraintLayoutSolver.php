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

			if ($constraint['gap'] > 0) {
				$disp = (string) (($node['s']['disp'] ?? ''));
				$is_flex_grid = false !== strpos($disp, 'flex') || false !== strpos($disp, 'grid');
				$had_css_gap = !empty($node['s']['cgap'])
					|| !empty($node['s']['rgap'])
					|| (!empty($node['s']['gap']) && empty($node['s']['_gap_geometry']) && empty($node['s']['_gap_whitespace']));

				// Collapse margins → flex_gap only for horizontal tracks or author CSS gap.
				// Vertical stacks (esp. those later promoted to composites) keep margins.
				$collapse = $is_flex_grid && ('row' === ($constraint['direction'] ?? '') || $had_css_gap);
				if ($collapse) {
					$node['s']['gap'] = $constraint['gap'] . 'px';
					$node['s']['_gap_geometry'] = true;
					$spacing_values[(string) $constraint['gap']] = ($spacing_values[(string) $constraint['gap']] ?? 0) + 1;
					$this->strip_child_margins($node);
				} else {
					$constraint['gap_from_margins'] = $constraint['gap'];
					$constraint['gap'] = 0;
				}
			}

			$node['layoutConstraint'] = $constraint;
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

		$parent_box = Geometry::bbox($parent);
		$intents = $this->infer_intents(
			$children,
			$boxes,
			$parent,
			$parent_box,
			$direction,
			array(
				'equal_width' => $equal_width,
				'equal_height' => $equal_height,
				'shared_center_x' => $shared_center_x,
				'shared_left' => $shared_left,
				'shared_right' => $shared_right,
			)
		);

		$justify = 'flex-start';
		if (!empty($intents['space_between'])) {
			$justify = 'space-between';
		} elseif (!empty($intents['centered'])) {
			$justify = 'center';
		} elseif ($shared_right && !$shared_left) {
			$justify = 'flex-end';
		}

		$align_items = 'stretch';
		if (!empty($intents['align_center'])) {
			$align_items = 'center';
		} elseif ($shared_top && !$equal_height) {
			$align_items = 'flex-start';
		} elseif ($shared_bottom && !$equal_height) {
			$align_items = 'flex-end';
		}

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
			// Phase 9 — layout intents for Elementor generation.
			'intents' => $intents,
			'justify' => $justify,
			'align_items' => $align_items,
			'centered' => !empty($intents['centered']),
			'space_between' => !empty($intents['space_between']),
			'overlay' => !empty($intents['overlay']),
			'aspect_locked' => !empty($intents['aspect_locked']),
		);
	}

	/**
	 * Infer high-level layout intents from geometry + position.
	 *
	 * @param array<int,array<string,mixed>> $children Children.
	 * @param array<int,array<string,float>> $boxes    Boxes.
	 * @param array<string,mixed>            $parent   Parent.
	 * @param array<string,float>            $parent_box Parent bbox.
	 * @param string                         $direction row|column.
	 * @param array<string,bool>             $flags    Alignment flags.
	 * @return array<string,bool>
	 */
	private function infer_intents(array $children, array $boxes, array $parent, array $parent_box, string $direction, array $flags): array
	{
		$intents = array(
			'centered' => false,
			'space_between' => false,
			'align_start' => !empty($flags['shared_left']) || !empty($flags['shared_top']),
			'align_end' => !empty($flags['shared_right']),
			'align_center' => !empty($flags['shared_center_x']) && 'column' === $direction,
			'equal_width' => !empty($flags['equal_width']),
			'equal_height' => !empty($flags['equal_height']),
			'stretch' => false,
			'overlay' => false,
			'background_layer' => false,
			'floating' => false,
			'sticky' => false,
			'absolute_layer' => false,
			'aspect_locked' => false,
			'pinned' => false,
		);

		if (!empty($flags['shared_center_x']) && 'column' === $direction) {
			$intents['centered'] = true;
		}

		// Space-between: first near start edge, last near end edge, uneven gaps.
		if ('row' === $direction && count($boxes) >= 2 && $parent_box['width'] > 0) {
			$first = $boxes[0];
			$last = $boxes[count($boxes) - 1];
			$start_inset = abs($first['x'] - $parent_box['x']);
			$end_inset = abs(($parent_box['x'] + $parent_box['width']) - ($last['x'] + $last['width']));
			if ($start_inset <= 12 && $end_inset <= 12 && empty($flags['equal_width'])) {
				$intents['space_between'] = true;
			}
		}

		$absolute = 0;
		$sticky = 0;
		foreach ($children as $i => $child) {
			$pos = strtolower((string) ($child['s']['pos'] ?? 'static'));
			if (in_array($pos, array('absolute', 'fixed'), true)) {
				++$absolute;
				$intents['absolute_layer'] = true;
				$intents['floating'] = true;
			}
			if ('sticky' === $pos) {
				++$sticky;
				$intents['sticky'] = true;
				$intents['pinned'] = true;
			}
			$ar = trim((string) ($child['s']['ar'] ?? ''));
			if ('' !== $ar && 'auto' !== $ar) {
				$intents['aspect_locked'] = true;
			}
		}
		if ($absolute >= 1) {
			$intents['overlay'] = VisualSignals::is_layered($parent);
			$intents['background_layer'] = $intents['overlay'];
		}

		$intents['stretch'] = ('row' === $direction && empty($flags['equal_width']))
			|| ('column' === $direction && empty($flags['equal_height']));

		return $intents;
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
		// 1) Explicit CSS flex/grid is authoritative over visualSiblingLayout.
		// Bootstrap/Tailwind rows often have flush columns (hgap=0) that the
		// visual tree mis-labels as column.
		$parent_s = $parent['s'] ?? array();
		$disp = (string) ($parent_s['disp'] ?? '');
		if (false !== strpos($disp, 'flex')) {
			$fd = strtolower((string) ($parent_s['fd'] ?? 'row'));
			return (false !== strpos($fd, 'column')) ? 'column' : 'row';
		}
		if (false !== strpos($disp, 'grid')) {
			return 'row';
		}

		$layout_type = (string) ($parent['layoutType'] ?? '');
		if (in_array($layout_type, array('row', 'grid'), true)) {
			return 'row';
		}
		if ('stack' === $layout_type) {
			return 'column';
		}

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

		// Skip geometry when boxes lack positional data.
		$positioned = array_filter($boxes, fn($b) => $b['width'] > 0 && ($b['x'] > 0 || $b['y'] > 0));
		if (count($positioned) < 2) {
			return 'column';
		}

		$row_votes = 0;
		$col_votes = 0;
		for ($i = 0; $i < count($boxes) - 1; ++$i) {
			$a = $boxes[$i];
			$b = $boxes[$i + 1];
			$stacked = ($b['y'] >= $a['y'] + $a['height'] - 1) || ($a['y'] >= $b['y'] + $b['height'] - 1);
			if ($stacked) {
				++$col_votes;
				continue;
			}
			if (Geometry::overlaps_y($a, $b)) {
				++$row_votes;
			}
			if (Geometry::vertical_gap($a, $b) > 4) {
				++$col_votes;
			}
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
