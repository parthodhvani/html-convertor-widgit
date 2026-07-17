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
			$constraint_gap = (float) ($node['layoutConstraint']['gap'] ?? 0);

			if ($distributed) {
				// Free space from justify-content must not become Elementor flex_gap.
				$whitespace['gap'] = $css_gap;
				$whitespace['gap_source'] = 'css';
				$node['whitespace'] = $whitespace;
				unset($node['s']['_gap_whitespace'], $node['s']['_gap_source']);
				if ($css_gap > 0) {
					$node['s']['gap'] = $css_gap . 'px';
					$node['s']['_gap_source'] = 'css';
					$this->measured_gaps[$css_gap] = ($this->measured_gaps[$css_gap] ?? 0) + 1;
				}
			} elseif ($css_gap > 0) {
				$whitespace['gap'] = $css_gap;
				$whitespace['gap_source'] = 'css';
				$node['whitespace'] = $whitespace;
				$node['s']['gap'] = $css_gap . 'px';
				$node['s']['_gap_source'] = 'css';
				unset($node['s']['_gap_whitespace']);
				$this->measured_gaps[$css_gap] = ($this->measured_gaps[$css_gap] ?? 0) + 1;
			} elseif ($constraint_gap > 0) {
				$gap = round($constraint_gap, 0);
				$whitespace['gap'] = $gap;
				$whitespace['gap_source'] = 'constraint';
				$node['whitespace'] = $whitespace;
				$node['s']['gap'] = $gap . 'px';
				$node['s']['_gap_source'] = 'constraint';
				unset($node['s']['_gap_whitespace']);
				$this->measured_gaps[$gap] = ($this->measured_gaps[$gap] ?? 0) + 1;
				$this->clear_child_margins($node);
			} elseif ($whitespace['gap'] > 0) {
				$disp = strtolower((string) ($node['s']['disp'] ?? ''));
				$is_flex_or_grid = false !== strpos($disp, 'flex') || false !== strpos($disp, 'grid');
				if ($is_flex_or_grid) {
					$gap = round((float) $whitespace['gap'], 0);
					$this->measured_gaps[$gap] = ($this->measured_gaps[$gap] ?? 0) + 1;
					$node['s']['gap'] = $gap . 'px';
					$node['s']['_gap_whitespace'] = true;
					$node['s']['_gap_source'] = 'geometry';
					$whitespace['gap_source'] = 'geometry';
					$node['whitespace'] = $whitespace;
					$this->clear_child_margins($node);
				} else {
					// Block/flow: sibling distance is margin, not gap.
					$whitespace['gap'] = 0;
					$whitespace['gap_source'] = 'none';
					$node['whitespace'] = $whitespace;
				}
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
		$flow = array_values(array_filter($children, function ($child) {
			if (!is_array($child)) {
				return false;
			}
			$pos = strtolower((string) ($child['s']['pos'] ?? ''));
			return !in_array($pos, array('absolute', 'fixed'), true);
		}));
		$measure = count($flow) >= 2 ? $flow : array_values(array_filter($children, 'is_array'));
		$boxes = array_map(array(Geometry::class, 'bbox'), $measure);
		$constraint = $parent['layoutConstraint'] ?? array();
		$direction = (string) ($constraint['direction'] ?? (($parent['s']['fd'] ?? '') === 'row' ? 'row' : 'column'));
		if (false !== strpos(strtolower((string) ($parent['s']['fd'] ?? '')), 'row')) {
			$direction = 'row';
		} elseif (false !== strpos(strtolower((string) ($parent['s']['fd'] ?? '')), 'column')) {
			$direction = 'column';
		}

		$gaps = array();
		// Only invent geometry gap from in-flow siblings.
		if (count($flow) >= 2) {
			$flow_boxes = array_map(array(Geometry::class, 'bbox'), $flow);
			for ($i = 0; $i < count($flow_boxes) - 1; ++$i) {
				$g = 'row' === $direction
					? Geometry::horizontal_gap($flow_boxes[$i], $flow_boxes[$i + 1])
					: Geometry::vertical_gap($flow_boxes[$i], $flow_boxes[$i + 1]);
				if ($g > 0) {
					$gaps[] = $g;
				}
			}
		}

		if (empty($boxes)) {
			return array(
				'gap' => 0.0,
				'gaps' => array(),
				'direction' => $direction,
				'padding' => array('top' => 0, 'left' => 0, 'right' => 0, 'bottom' => 0),
			);
		}

		$parent_box = Geometry::bbox($parent);
		$min_x = min(array_map(fn($b) => $b['x'], $boxes));
		$min_y = min(array_map(fn($b) => $b['y'], $boxes));
		$max_r = max(array_map(fn($b) => $b['x'] + $b['width'], $boxes));
		$max_b = max(array_map(fn($b) => $b['y'] + $b['height'], $boxes));

		$pad_top = max(0, $min_y - $parent_box['y']);
		$pad_left = max(0, $min_x - $parent_box['x']);
		$pad_right = max(0, $parent_box['x'] + $parent_box['width'] - $max_r);
		$pad_bottom = max(0, $parent_box['y'] + $parent_box['height'] - $max_b);

		// Centering free-space (margin:auto / justify-center / max-width columns)
		// must NOT become Elementor padding — that collapses content width and
		// roughly doubles page height under box-sizing:border-box.
		if ($this->looks_like_horizontal_centering($pad_left, $pad_right, $parent, $parent_box, $boxes)) {
			$pad_left = (float) ($parent['s']['pl'] ?? 0);
			$pad_right = (float) ($parent['s']['pr'] ?? 0);
		}

		// Equal-height grid/flex stretch leaves empty space under short columns —
		// that is alignment free-space, not padding-bottom.
		if ($this->looks_like_stretch_gutter($pad_bottom, $parent, $boxes, $parent_box, 'bottom')) {
			$pad_bottom = (float) ($parent['s']['pb'] ?? 0);
		}
		if ($this->looks_like_stretch_gutter($pad_top, $parent, $boxes, $parent_box, 'top')) {
			$pad_top = (float) ($parent['s']['pt'] ?? 0);
		}

		// One-sided L/R remainder (flex-start row / short logo in wide cell).
		if ($this->looks_like_one_sided_gutter($pad_left, $pad_right, $parent)) {
			$css_pl = (float) ($parent['s']['pl'] ?? 0);
			$css_pr = (float) ($parent['s']['pr'] ?? 0);
			if ($pad_left > max(24.0, $css_pl * 2 + 8) && $pad_left > $pad_right + 24) {
				$pad_left = $css_pl;
			}
			if ($pad_right > max(24.0, $css_pr * 2 + 8) && $pad_right > $pad_left + 24) {
				$pad_right = $css_pr;
			}
		}

		return array(
			'gap' => Geometry::median($gaps),
			'gaps' => $gaps,
			'direction' => $direction,
			'padding' => array(
				'top' => max(0, $pad_top),
				'left' => max(0, $pad_left),
				'right' => max(0, $pad_right),
				'bottom' => max(0, $pad_bottom),
			),
		);
	}

	/**
	 * True when a large inset is stretch free-space inside an equal-size track.
	 *
	 * @param float                            $inset      Measured inset.
	 * @param array<string,mixed>              $parent     Parent.
	 * @param array<int,array<string,float>>   $boxes      Child boxes.
	 * @param array<string,float>              $parent_box Parent bbox.
	 * @param string                           $side       top|bottom.
	 */
	private function looks_like_stretch_gutter(
		float $inset,
		array $parent,
		array $boxes,
		array $parent_box,
		string $side
	): bool {
		if ($inset < 24) {
			return false;
		}
		$css_key = 'bottom' === $side ? 'pb' : 'pt';
		$css = (float) ($parent['s'][$css_key] ?? 0);
		if ($inset <= max(16.0, $css * 2 + 8)) {
			return false;
		}

		$disp = strtolower((string) ($parent['s']['disp'] ?? ''));
		$ai = strtolower((string) ($parent['s']['ai'] ?? ''));
		$parent_is_grid = false !== strpos($disp, 'grid');
		$parent_is_flex = false !== strpos($disp, 'flex');
		if (!$parent_is_grid && !$parent_is_flex) {
			// Child of a grid/flex equal-height track: still drop large pb/pt
			// when CSS padding is ~0 and content does not fill the box.
			if ($css > 8) {
				return false;
			}
			$content_h = 0.0;
			foreach ($boxes as $b) {
				$content_h = max($content_h, (float) ($b['height'] ?? 0));
			}
			return $parent_box['height'] > $content_h + 24;
		}

		if ($parent_is_flex && !in_array($ai, array('', 'stretch', 'normal'), true)) {
			return false;
		}

		return true;
	}

	/**
	 * True when L/R free space is a one-sided start-aligned gutter.
	 *
	 * @param float               $left   Left inset.
	 * @param float               $right  Right inset.
	 * @param array<string,mixed> $parent Parent.
	 */
	private function looks_like_one_sided_gutter(float $left, float $right, array $parent): bool
	{
		if ($left < 24 && $right < 24) {
			return false;
		}
		$jc = strtolower((string) ($parent['s']['jc'] ?? ''));
		$ai = strtolower((string) ($parent['s']['ai'] ?? ''));
		$fd = strtolower((string) ($parent['s']['fd'] ?? ''));
		if (in_array($jc, array('center', 'space-around', 'space-evenly'), true)) {
			return false;
		}
		// Row flex-start / space-between with a short cluster leaves a large right gutter.
		if (false !== strpos($fd, 'row') || false !== strpos(strtolower((string) ($parent['s']['disp'] ?? '')), 'grid')) {
			return abs($left - $right) > 24;
		}
		if (in_array($ai, array('flex-start', 'start', 'left'), true)) {
			return abs($left - $right) > 24;
		}
		return abs($left - $right) > 48;
	}

	/**
	 * True when L/R free space is centering gutter, not real padding.
	 *
	 * @param float                            $left       Left inset.
	 * @param float                            $right      Right inset.
	 * @param array<string,mixed>              $parent     Parent node.
	 * @param array<string,float>              $parent_box Parent bbox.
	 * @param array<int,array<string,float>>   $boxes      Child boxes.
	 */
	private function looks_like_horizontal_centering(
		float $left,
		float $right,
		array $parent,
		array $parent_box,
		array $boxes
	): bool {
		if ($left < 16 && $right < 16) {
			return false;
		}

		$jc = strtolower((string) ($parent['s']['jc'] ?? ''));
		if (in_array($jc, array('center', 'space-between', 'space-around', 'space-evenly'), true)
			&& abs($left - $right) <= max(8.0, 0.15 * max($left, $right, 1.0))
		) {
			return true;
		}

		$css_pl = (float) ($parent['s']['pl'] ?? 0);
		$css_pr = (float) ($parent['s']['pr'] ?? 0);
		// Geometry L/R dwarfs declared CSS padding → free space, not padding.
		if ($left > max(24.0, $css_pl * 2 + 8) && $right > max(24.0, $css_pr * 2 + 8)
			&& abs($left - $right) <= max(12.0, 0.2 * max($left, $right, 1.0))
		) {
			return true;
		}

		$max_w = $this->css_px($parent['s']['maxW'] ?? null);
		$content_w = 0.0;
		foreach ($boxes as $b) {
			$content_w = max($content_w, (float) ($b['width'] ?? 0));
		}
		if ($max_w > 0 && $parent_box['width'] > $max_w + 16
			&& $content_w <= $max_w + 8
			&& abs($left - $right) <= max(12.0, 0.25 * max($left, $right, 1.0))
		) {
			return true;
		}

		return false;
	}

	/**
	 * @param mixed $value CSS size.
	 */
	private function css_px($value): float
	{
		if (null === $value || '' === $value) {
			return 0.0;
		}
		if (is_numeric($value)) {
			return (float) $value;
		}
		if (is_string($value) && preg_match('/^(-?\d+(?:\.\d+)?)\s*px/i', trim($value), $m)) {
			return (float) $m[1];
		}
		return 0.0;
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
