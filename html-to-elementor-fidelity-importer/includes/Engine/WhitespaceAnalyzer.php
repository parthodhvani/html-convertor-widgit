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

			// Constraint gap is authoritative when present. Never invent a gap from
			// pure margin geometry and wipe child margins — that is the earliest
			// cause of spacing → wrap → height cascade failures.
			$had_css_gap = $this->node_has_css_gap($node);
			$margins_already_collapsed = !empty($node['s']['_gap_geometry']) && $constraint_gap > 0;
			$direction = (string) ($node['layoutConstraint']['direction'] ?? $whitespace['direction'] ?? 'column');

			if ($constraint_gap > 0) {
				$gap = round($constraint_gap, 0);
				$this->measured_gaps[$gap] = ($this->measured_gaps[$gap] ?? 0) + 1;
				$node['s']['gap'] = $gap . 'px';
				$node['s']['_gap_source'] = 'constraint';
				// Only strip margins when CSS gap existed or a horizontal collapse ran.
				if ($had_css_gap || $margins_already_collapsed || 'row' === $direction) {
					$this->clear_child_margins($node);
				}
			} elseif ($measured_gap > 0 && $had_css_gap) {
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
			} elseif ($measured_gap > 0) {
				// Diagnostic only — preserve child margins as the spacing model.
				$node['whitespace']['gap_from_margins'] = round($measured_gap, 0);
			}

			// Geometry residual (parent box minus children union) is diagnostic only.
			// Chromium computed padding is authoritative — writing residual into s.pr/pt
			// invented 1000px+ "padding" on under-filled flex/list rows and blew width.
			if ($this->computed_padding_absent($node)) {
				foreach (array('top' => 'pt', 'right' => 'pr', 'bottom' => 'pb', 'left' => 'pl') as $side => $key) {
					$residual = (float) ($whitespace['padding'][$side] ?? 0);
					if ($residual > 0 && $residual <= 160) {
						$node['s'][$key] = $residual;
					}
				}
			} else {
				$node['whitespace']['padding_residual_ignored'] = true;
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
	 * True when the browser computed style already declared a gap.
	 *
	 * @param array<string,mixed> $node Node.
	 */
	private function node_has_css_gap(array $node): bool
	{
		$s = $node['s'] ?? array();
		if (!empty($s['cgap']) || !empty($s['rgap'])) {
			return true;
		}
		$gap = trim((string) ($s['gap'] ?? ''));
		if ('' === $gap || 'normal' === $gap || '0px' === $gap || '0' === $gap) {
			return false;
		}
		// Ignore gaps we ourselves invented earlier in the pipeline.
		if (!empty($s['_gap_geometry']) || !empty($s['_gap_whitespace']) || !empty($s['_gap_source'])) {
			return false;
		}
		return true;
	}

	/**
	 * True when Chromium padding keys were never extracted (legacy / synthetic nodes).
	 *
	 * @param array<string,mixed> $node Node.
	 */
	private function computed_padding_absent(array $node): bool
	{
		$s = $node['s'] ?? array();
		foreach (array('pt', 'pr', 'pb', 'pl') as $key) {
			if (array_key_exists($key, $s)) {
				return false;
			}
		}
		return true;
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
