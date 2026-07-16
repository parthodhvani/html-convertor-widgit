<?php
/**
 * Measures whitespace and infers spacing systems from geometry.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Whitespace Analyzer — measures inter-node whitespace from bounding boxes and
 * converts repeated gaps into Elementor container gap/padding (not margins).
 */
final class WhitespaceAnalyzer implements EngineInterface
{

	/** @var array<float,int> */
	private array $measured_gaps = array();

	public function name(): string
	{
		return 'whitespace_analyzer';
	}

	/**
	 * @return array<float,int> Gaps measured in the last pass.
	 */
	public function measured_gaps(): array
	{
		return $this->measured_gaps;
	}

	/**
	 * Analyze whitespace for all section trees.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function analyze(array $sections): array
	{
		$this->measured_gaps = array();
		$out = array();

		foreach ($sections as $section) {
			$tree = $section['tree'] ?? null;
			if (is_array($tree)) {
				$this->analyze_node($tree);
				$section['tree'] = $tree;
				$section['whitespace'] = array(
					'gaps' => $this->measured_gaps,
					'scale' => array_keys($this->measured_gaps),
				);
			}
			$out[] = $section;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function analyze_node(array &$node): void
	{
		$children = (array) ($node['children'] ?? array());
		if (count($children) >= 2) {
			$whitespace = $this->measure_sibling_whitespace($children, $node);
			$node['whitespace'] = $whitespace;

			// ConstraintLayoutSolver is the gap authority when present.
			// Whitespace fills in measured gap only when the solver left it empty.
			$constraint_gap = (float) ($node['layoutConstraint']['gap'] ?? 0);
			$measured_gap = (float) ($whitespace['gap'] ?? 0);

			if ($constraint_gap > 0) {
				$gap = round($constraint_gap, 0);
				$this->measured_gaps[$gap] = ($this->measured_gaps[$gap] ?? 0) + 1;
				$node['s']['gap'] = $gap . 'px';
				$node['s']['_gap_source'] = 'constraint';
				$this->clear_child_margins($node);
			} elseif ($measured_gap > 0) {
				$gap = round($measured_gap, 0);
				$this->measured_gaps[$gap] = ($this->measured_gaps[$gap] ?? 0) + 1;
				$node['s']['gap'] = $gap . 'px';
				$node['s']['_gap_source'] = 'whitespace';
				$node['s']['_gap_whitespace'] = true;
				if (empty($node['layoutConstraint'])) {
					$node['layoutConstraint'] = array();
				}
				if (empty($node['layoutConstraint']['direction'])) {
					$node['layoutConstraint']['direction'] = $whitespace['direction'];
				}
				$node['layoutConstraint']['gap'] = $gap;
				$this->clear_child_margins($node);
			}

			if ($whitespace['padding']['top'] > 0 || $whitespace['padding']['left'] > 0
				|| $whitespace['padding']['right'] > 0 || $whitespace['padding']['bottom'] > 0) {
				$node['s']['pt'] = max((float) ($node['s']['pt'] ?? 0), $whitespace['padding']['top']);
				$node['s']['pl'] = max((float) ($node['s']['pl'] ?? 0), $whitespace['padding']['left']);
				$node['s']['pr'] = max((float) ($node['s']['pr'] ?? 0), $whitespace['padding']['right']);
				$node['s']['pb'] = max((float) ($node['s']['pb'] ?? 0), $whitespace['padding']['bottom']);
			}
		}

		foreach ($children as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->analyze_node($child);
			$node['children'][$i] = $child;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $children Siblings.
	 * @return array<string,mixed>
	 */
	private function measure_sibling_whitespace(array $children, array $parent): array
	{
		$boxes = array_map(array(Geometry::class, 'bbox'), $children);
		$direction = $this->resolve_direction($parent, $boxes);

		$gaps = array();
		for ($i = 0; $i < count($boxes) - 1; ++$i) {
			$g = 'row' === $direction
				? Geometry::horizontal_gap($boxes[$i], $boxes[$i + 1])
				: Geometry::vertical_gap($boxes[$i], $boxes[$i + 1]);
			if ($g > 0) {
				$gaps[] = $g;
			}
		}

		$parent_box = Geometry::bbox($parent);
		$min_x = min(array_map(fn($b) => $b['x'], $boxes));
		$min_y = min(array_map(fn($b) => $b['y'], $boxes));
		$max_r = max(array_map(fn($b) => $b['x'] + $b['width'], $boxes));
		$max_b = max(array_map(fn($b) => $b['y'] + $b['height'], $boxes));

		return array(
			'gap' => Geometry::median($gaps),
			'gaps' => $gaps,
			'direction' => $direction,
			'padding' => array(
				'top' => max(0, $min_y - $parent_box['y']),
				'left' => max(0, $min_x - $parent_box['x']),
				'right' => max(0, $parent_box['x'] + $parent_box['width'] - $max_r),
				'bottom' => max(0, $parent_box['y'] + $parent_box['height'] - $max_b),
			),
		);
	}

	/**
	 * Resolve row vs column from the parent constraint / flex direction / geometry.
	 *
	 * @param array<string,mixed>            $parent Parent node.
	 * @param array<int,array<string,float>> $boxes  Child boxes.
	 */
	private function resolve_direction(array $parent, array $boxes): string
	{
		$constraint = $parent['layoutConstraint'] ?? array();
		$dir = strtolower((string) ($constraint['direction'] ?? ''));
		if ('row' === $dir || 'column' === $dir) {
			return $dir;
		}

		$layout_type = strtolower((string) ($parent['layoutType'] ?? ''));
		if ('row' === $layout_type || 'grid' === $layout_type) {
			return 'row';
		}
		if ('stack' === $layout_type || 'column' === $layout_type) {
			return 'column';
		}

		$fd = strtolower((string) ($parent['s']['fd'] ?? ''));
		if (false !== strpos($fd, 'column')) {
			return 'column';
		}
		if (false !== strpos($fd, 'row')) {
			return 'row';
		}

		// Geometry fallback: dominant axis of sibling offsets.
		if (count($boxes) >= 2) {
			$dx = abs(($boxes[1]['x'] ?? 0) - ($boxes[0]['x'] ?? 0));
			$dy = abs(($boxes[1]['y'] ?? 0) - ($boxes[0]['y'] ?? 0));
			if ($dx > $dy + 4) {
				return 'row';
			}
		}

		return 'column';
	}

	/**
	 * @param array<string,mixed> $node Node (by ref).
	 */
	private function clear_child_margins(array &$node): void
	{
		foreach ((array) ($node['children'] ?? array()) as $i => $child) {
			if (!is_array($child)) {
				continue;
			}
			unset($child['s']['mt'], $child['s']['mb'], $child['s']['ml'], $child['s']['mr']);
			$node['children'][$i] = $child;
		}
	}
}
