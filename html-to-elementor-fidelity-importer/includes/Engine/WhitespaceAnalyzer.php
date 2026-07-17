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

			$css_gap = $this->css_gap_px($node);
			$jc = strtolower((string) ($node['s']['jc'] ?? ''));
			$distributed = in_array($jc, array('space-between', 'space-around', 'space-evenly'), true);

			if ($distributed) {
				// Free space from justify-content must not become Elementor flex_gap.
				$whitespace['gap'] = $css_gap;
				$whitespace['gap_source'] = 'css';
				$node['whitespace'] = $whitespace;
				unset($node['s']['_gap_whitespace']);
				if ($css_gap > 0) {
					$node['s']['gap'] = $css_gap . 'px';
					$this->measured_gaps[$css_gap] = ($this->measured_gaps[$css_gap] ?? 0) + 1;
				}
			} elseif ($css_gap > 0) {
				$whitespace['gap'] = $css_gap;
				$whitespace['gap_source'] = 'css';
				$node['whitespace'] = $whitespace;
				$node['s']['gap'] = $css_gap . 'px';
				unset($node['s']['_gap_whitespace']);
				$this->measured_gaps[$css_gap] = ($this->measured_gaps[$css_gap] ?? 0) + 1;
			} elseif ($whitespace['gap'] > 0) {
				$gap = round((float) $whitespace['gap'], 0);
				$this->measured_gaps[$gap] = ($this->measured_gaps[$gap] ?? 0) + 1;
				$node['s']['gap'] = $gap . 'px';
				$node['s']['_gap_whitespace'] = true;
				$whitespace['gap_source'] = 'geometry';
				$node['whitespace'] = $whitespace;
				$this->clear_child_margins($node);
			}

			if ($whitespace['padding']['top'] > 0 || $whitespace['padding']['left'] > 0) {
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
	 * @param array<string,mixed> $node Node.
	 */
	private function css_gap_px(array $node): float
	{
		$s = $node['s'] ?? array();
		foreach (array('gap', 'rowGap', 'colGap', 'columnGap') as $key) {
			if (!isset($s[$key]) || '' === $s[$key] || null === $s[$key]) {
				continue;
			}
			if ('gap' === $key && (!empty($s['_gap_geometry']) || !empty($s['_gap_whitespace']))) {
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
	 * @param array<int,array<string,mixed>> $children Siblings.
	 * @return array<string,mixed>
	 */
	private function measure_sibling_whitespace(array $children, array $parent): array
	{
		$boxes = array_map(array(Geometry::class, 'bbox'), $children);
		$constraint = $parent['layoutConstraint'] ?? array();
		$direction = (string) ($constraint['direction'] ?? (($parent['s']['fd'] ?? '') === 'row' ? 'row' : 'column'));
		if (false !== strpos(strtolower((string) ($parent['s']['fd'] ?? '')), 'row')) {
			$direction = 'row';
		} elseif (false !== strpos(strtolower((string) ($parent['s']['fd'] ?? '')), 'column')) {
			$direction = 'column';
		}

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
