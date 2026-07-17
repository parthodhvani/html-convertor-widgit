<?php
/**
 * Compares source layout geometry against emitted Elementor structure.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Geometry Comparator — primary fidelity metric for v4.
 *
 * Extracts layout frames from Chromium trees and estimates frames from emitted
 * Elementor JSON, then computes position/size RMSE and similarity scores.
 */
final class GeometryComparator implements EngineInterface
{

	private const POSITION_TOLERANCE = 6.0;
	private const SIZE_TOLERANCE = 0.12;

	public function name(): string
	{
		return 'geometry_comparator';
	}

	/**
	 * Compare source sections against generated Elementor data.
	 *
	 * @param array<int,array<string,mixed>> $sections       Source sections.
	 * @param array<int,array<string,mixed>> $elementor_data Emitted elements.
	 * @return array<string,mixed>
	 */
	public function compare(array $sections, array $elementor_data): array
	{
		$all_position = array();
		$all_size = array();
		$alignment_hits = 0;
		$spacing_hits = 0;
		$spacing_total = 0;
		$matched_total = 0;
		$source_total = 0;

		$section_count = max(count($sections), count($elementor_data));
		for ($i = 0; $i < $section_count; ++$i) {
			$section = $sections[$i] ?? array();
			$element = $elementor_data[$i] ?? null;
			if (!is_array($element)) {
				continue;
			}

			$source = $this->extract_source_frames(array($section));
			$emitted = $this->estimate_emitted_frames(array($element), array($section));
			$matched = $this->match_frames($source, $emitted);

			$source_total += count($source);
			$matched_total += count($matched);

			foreach ($matched as $pair) {
				$s = $pair['source'];
				$e = $pair['emitted'];
				$all_position[] = $this->position_delta($s, $e);
				$all_size[] = $this->size_delta($s, $e);
				if ($this->edges_aligned($s, $e)) {
					++$alignment_hits;
				}
				if (!empty($s['gap']) || !empty($e['gap'])) {
					++$spacing_total;
					if ($this->gap_close($s, $e)) {
						++$spacing_hits;
					}
				}
			}
		}

		$position_rmse = $this->rmse($all_position);
		$size_rmse = $this->rmse($all_size);
		$bbox_delta = round(($position_rmse + $size_rmse) / 2, 2);
		$match_ratio = $source_total > 0 ? $matched_total / $source_total : 1.0;

		$geometry_similarity = $this->similarity_from_rmse($position_rmse, $size_rmse, $match_ratio);
		$layout_similarity = $this->layout_structure_similarity($sections, $elementor_data, $geometry_similarity);
		$spacing_similarity = $spacing_total > 0
			? (int) round(($spacing_hits / $spacing_total) * 100)
			: (int) round(min(100, $geometry_similarity * 0.9 + $match_ratio * 10));

		return array(
			'geometry_similarity' => $geometry_similarity,
			'layout_similarity' => $layout_similarity,
			'spacing_similarity' => $spacing_similarity,
			'bbox_delta' => $bbox_delta,
			'position_rmse' => round($position_rmse, 2),
			'size_rmse' => round($size_rmse, 2),
			'matched_frames' => $matched_total,
			'source_frames' => $source_total,
			'emitted_frames' => count($this->estimate_emitted_frames($elementor_data, $sections)),
			'alignment_score' => $matched_total > 0
				? (int) round($alignment_hits / $matched_total * 100)
				: 0,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public function extract_source_frames(array $sections): array
	{
		$frames = array();

		foreach ($sections as $si => $section) {
			$tree = $section['tree'] ?? null;
			if (!is_array($tree)) {
				continue;
			}
			$root = Geometry::bbox($tree);
			$this->walk_source_frames(
				$tree,
				$frames,
				(string) $si,
				-(float) $root['x'],
				-(float) $root['y'],
				true
			);
		}

		return $frames;
	}

	/**
	 * @param array<string,mixed>              $node    Node.
	 * @param array<int,array<string,mixed>>   $frames  Frames (by ref).
	 * @param string                           $section Section key.
	 * @param float                            $base_x  Section base X.
	 * @param float                            $base_y  Section base Y.
	 * @param bool                             $is_root Root flag.
	 */
	private function walk_source_frames(array $node, array &$frames, string $section, float $base_x, float $base_y, bool $is_root): void
	{
		$box = $this->normalized_bbox($node, $base_x, $base_y);
		$significant = $is_root
			|| !empty($node['layoutConstraint'])
			|| !empty($node['layoutRole'])
			|| !empty($node['atomic'])
			|| ($box['width'] > 0 && $box['height'] > 0 && $this->has_visual_weight($node));

		if ($significant && $box['width'] > 0 && $box['height'] > 0) {
			$constraint = $node['layoutConstraint'] ?? array();
			$whitespace = $node['whitespace'] ?? array();
			$frames[] = array(
				'key' => $section . ':' . ($node['cls'] ?? '') . ':' . ($node['id'] ?? '') . ':' . count($frames),
				'cls' => (string) ($node['cls'] ?? ''),
				'id' => (string) ($node['id'] ?? ''),
				'type' => !empty($node['atomic']) ? 'leaf' : 'container',
				'x' => $box['x'],
				'y' => $box['y'],
				'width' => $box['width'],
				'height' => $box['height'],
				'direction' => (string) ($constraint['direction'] ?? ''),
				// Atomic leaves may carry flex gap (icon+label buttons); that is
				// widget-internal, not a layout-container spacing signal.
				'gap' => !empty($node['atomic'])
					? 0.0
					: $this->source_gap_px($node, $constraint, $whitespace),
				'role' => (string) ($node['layoutRole'] ?? ''),
			);
		}

		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$this->walk_source_frames($child, $frames, $section, $base_x, $base_y, false);
		}
	}

	/**
	 * Estimate layout frames from Elementor JSON using flex simulation.
	 *
	 * @param array<int,array<string,mixed>> $elements Elementor elements.
	 * @param array<int,array<string,mixed>> $sections Source sections (for widths).
	 * @return array<int,array<string,mixed>>
	 */
	public function estimate_emitted_frames(array $elements, array $sections): array
	{
		$frames = array();
		$viewport = 1200.0;
		foreach ($sections as $section) {
			$w = (float) ($section['bbox']['width'] ?? 0);
			if ($w > $viewport) {
				$viewport = $w;
			}
		}

		$y = 0.0;
		foreach ($elements as $el) {
			$h = $this->simulate_element($el, $frames, 0.0, $y, $viewport, true, 0.0, 0.0);
			$y += max(1.0, $h);
		}

		return $frames;
	}

	/**
	 * @param array<string,mixed>            $el       Element.
	 * @param array<int,array<string,mixed>> $frames   Frames (by ref).
	 * @param float                            $x        X.
	 * @param float                            $y        Y.
	 * @param float                            $parent_w Parent width.
	 * @param bool                             $is_root  Root flag.
	 * @param float                            $origin_x Section origin X to subtract from stored bboxes.
	 * @param float                            $origin_y Section origin Y to subtract from stored bboxes.
	 */
	private function simulate_element(
		array $el,
		array &$frames,
		float $x,
		float $y,
		float $parent_w,
		bool $is_root,
		float $origin_x = 0.0,
		float $origin_y = 0.0
	): float {
		$settings = $el['settings'] ?? array();
		$cls = (string) ($settings['_css_classes'] ?? '');
		$type = (string) ($el['elType'] ?? '');
		$raw_bbox = isset($settings['_h2e_bbox']) && is_array($settings['_h2e_bbox'])
			? $settings['_h2e_bbox']
			: null;

		// Align stored source bboxes to section-local coords (same as extract_source_frames).
		if ($is_root && $raw_bbox) {
			$origin_x = (float) ($raw_bbox['x'] ?? 0);
			$origin_y = (float) ($raw_bbox['y'] ?? 0);
		}

		$bbox = null;
		if ($raw_bbox) {
			$bbox = array(
				'x' => (float) ($raw_bbox['x'] ?? 0) - $origin_x,
				'y' => (float) ($raw_bbox['y'] ?? 0) - $origin_y,
				'width' => (float) ($raw_bbox['width'] ?? 0),
				'height' => (float) ($raw_bbox['height'] ?? 0),
			);
		}

		if ('widget' === $type) {
			$wx = $bbox ? (float) $bbox['x'] : $x;
			$wy = $bbox ? (float) $bbox['y'] : $y;
			$ww = $bbox ? (float) $bbox['width'] : $parent_w;
			$wh = $bbox ? (float) $bbox['height'] : 0.0;
			if ($wh <= 0) {
				$wh = $this->estimate_widget_height($el);
			}
			$frames[] = array(
				'key' => 'w:' . $cls . ':' . ($el['widgetType'] ?? '') . ':' . count($frames),
				'cls' => $cls,
				'type' => 'leaf',
				'x' => $wx,
				'y' => $wy,
				'width' => $ww > 0 ? $ww : $parent_w,
				'height' => $wh,
				'gap' => 0.0,
				'direction' => '',
			);
			return $wh;
		}

		$direction = (string) ($settings['flex_direction'] ?? 'column');
		$gap = $this->flex_gap_px($settings);
		$min_h = (float) ($settings['min_height']['size'] ?? 0);
		$kids = (array) ($el['elements'] ?? array());

		$cx = $bbox ? (float) $bbox['x'] : $x;
		$cy = $bbox ? (float) $bbox['y'] : $y;
		$cw = $bbox ? (float) $bbox['width'] : $parent_w;
		$ch = $bbox ? (float) $bbox['height'] : 0.0;

		$frames[] = array(
			'key' => 'c:' . $cls . ':' . count($frames),
			'cls' => $cls,
			'type' => 'container',
			'x' => $cx,
			'y' => $cy,
			'width' => $cw > 0 ? $cw : $parent_w,
			'height' => max($min_h, $ch, 1.0),
			'gap' => $gap,
			'direction' => $direction,
		);

		$cursor_x = $cx;
		$cursor_y = $cy;
		$max_extent = 0.0;
		$sim_parent_w = $cw > 0 ? $cw : $parent_w;

		foreach ($kids as $child) {
			$child_w = $this->child_width($child, $sim_parent_w, $direction);
			$child_h = $this->simulate_element($child, $frames, $cursor_x, $cursor_y, $child_w, false, $origin_x, $origin_y);
			if ('row' === $direction) {
				$cursor_x += $child_w + $gap;
				$max_extent = max($max_extent, $child_h);
			} else {
				$cursor_y += $child_h + $gap;
				$max_extent += $child_h + $gap;
			}
		}

		if ($ch > 0) {
			return max($min_h, $ch);
		}
		if ('row' === $direction) {
			return max($min_h, $max_extent, 1.0);
		}
		return max($min_h, $max_extent - $gap, 1.0);
	}

	/**
	 * @param array<int,array<string,mixed>> $source  Source frames.
	 * @param array<int,array<string,mixed>> $emitted Emitted frames.
	 * @return array<int,array{source:array<string,mixed>,emitted:array<string,mixed>}>
	 */
	private function match_frames(array $source, array $emitted): array
	{
		$matched = array();
		$used = array();

		foreach ($source as $s) {
			$best = null;
			$best_score = PHP_FLOAT_MAX;
			$best_ei = -1;

			// Same CSS class: pick the nearest instance (not the first).
			if ('' !== ($s['cls'] ?? '')) {
				foreach ($emitted as $ei => $e) {
					if (isset($used[$ei])) {
						continue;
					}
					if ($s['cls'] !== ($e['cls'] ?? '')) {
						continue;
					}
					$score = $this->position_delta($s, $e) + $this->size_delta($s, $e) * 0.25;
					if ($score < $best_score) {
						$best_score = $score;
						$best = $e;
						$best_ei = $ei;
					}
				}
			}

			// Fall back to nearest same-type frame when class match is absent/far.
			if (null === $best || $best_score > 480.0) {
				$fallback_best = null;
				$fallback_score = PHP_FLOAT_MAX;
				$fallback_ei = -1;
				foreach ($emitted as $ei => $e) {
					if (isset($used[$ei])) {
						continue;
					}
					if (($s['type'] ?? '') !== ($e['type'] ?? '')) {
						continue;
					}
					// Empty-class or cross-class fallbacks must be spatially close.
					$s_cls = (string) ($s['cls'] ?? '');
					$e_cls = (string) ($e['cls'] ?? '');
					if ('' !== $s_cls && '' !== $e_cls && $s_cls !== $e_cls) {
						// Distinct classes are not interchangeable via type fallback.
						continue;
					}
					$score = $this->position_delta($s, $e) + $this->size_delta($s, $e) * 0.5;
					if ('' === $s_cls || '' === $e_cls) {
						// Anonymous nodes: require proximity to avoid random pairing.
						if ($score > 160.0) {
							continue;
						}
					}
					if ($score < $fallback_score) {
						$fallback_score = $score;
						$fallback_best = $e;
						$fallback_ei = $ei;
					}
				}
				if (null !== $fallback_best && (null === $best || $fallback_score < $best_score)) {
					$best = $fallback_best;
					$best_ei = $fallback_ei;
					$best_score = $fallback_score;
				}
			}

			// Reject absurd pairings that would dominate RMSE with noise.
			if (null !== $best && $best_score > 420.0) {
				continue;
			}

			if (null !== $best && $best_ei >= 0) {
				$used[$best_ei] = true;
				$matched[] = array('source' => $s, 'emitted' => $best);
			}
		}

		return $matched;
	}

	/**
	 * @param array<string,mixed> $node   Node.
	 * @param float               $base_x Base X.
	 * @param float               $base_y Base Y.
	 * @return array{x:float,y:float,width:float,height:float}
	 */
	private function normalized_bbox(array $node, float $base_x, float $base_y): array
	{
		$box = Geometry::bbox($node);
		if (!empty($node['visual']['bbox']) && is_array($node['visual']['bbox'])) {
			$box = $node['visual']['bbox'];
		}
		return array(
			'x' => (float) ($box['x'] ?? 0) + $base_x,
			'y' => (float) ($box['y'] ?? 0) + $base_y,
			'width' => (float) ($box['width'] ?? 0),
			'height' => (float) ($box['height'] ?? 0),
		);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function has_visual_weight(array $node): bool
	{
		$signals = VisualSignals::analyze($node);
		return $signals['has_background'] || $signals['has_border'] || $signals['has_padding']
			|| !empty($node['layoutRole']);
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	private function tree_height(array $node): float
	{
		$box = Geometry::bbox($node);
		$max = (float) ($box['height'] ?? 0);
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (is_array($child)) {
				$max = max($max, $this->tree_height($child));
			}
		}
		return $max;
	}

	/**
	 * @param array<string,mixed> $a Frame A.
	 * @param array<string,mixed> $b Frame B.
	 */
	private function position_delta(array $a, array $b): float
	{
		$dx = abs($a['x'] - $b['x']);
		$dy = abs($a['y'] - $b['y']);
		return sqrt($dx * $dx + $dy * $dy);
	}

	/**
	 * @param array<string,mixed> $a Frame A.
	 * @param array<string,mixed> $b Frame B.
	 */
	private function size_delta(array $a, array $b): float
	{
		$dw = abs($a['width'] - $b['width']);
		$dh = abs($a['height'] - $b['height']);
		return sqrt($dw * $dw + $dh * $dh);
	}

	/**
	 * @param array<string,mixed> $a Frame A.
	 * @param array<string,mixed> $b Frame B.
	 */
	private function edges_aligned(array $a, array $b): bool
	{
		return abs($a['x'] - $b['x']) <= self::POSITION_TOLERANCE
			&& abs(($a['x'] + $a['width']) - ($b['x'] + $b['width'])) <= self::POSITION_TOLERANCE;
	}

	/**
	 * @param array<string,mixed> $a Frame A.
	 * @param array<string,mixed> $b Frame B.
	 */
	private function gap_close(array $a, array $b): bool
	{
		$ga = (float) ($a['gap'] ?? 0);
		$gb = (float) ($b['gap'] ?? 0);
		if ($ga <= 0 && $gb <= 0) {
			return true;
		}
		return abs($ga - $gb) <= max(4.0, $ga * 0.25);
	}

	/**
	 * @param array<int,float> $errors Errors.
	 */
	private function rmse(array $errors): float
	{
		if (empty($errors)) {
			return 0.0;
		}
		$sum = 0.0;
		foreach ($errors as $e) {
			$sum += $e * $e;
		}
		return sqrt($sum / count($errors));
	}

	/**
	 * @param float $position_rmse Position RMSE.
	 * @param float $size_rmse     Size RMSE.
	 * @param float $match_ratio   Match ratio.
	 */
	private function similarity_from_rmse(float $position_rmse, float $size_rmse, float $match_ratio): int
	{
		$pos_score = max(0, 100 - ($position_rmse / 2.5));
		$size_score = max(0, 100 - ($size_rmse / 4.0));
		$match_score = $match_ratio * 100;
		return (int) round(min(100, $pos_score * 0.35 + $size_score * 0.35 + $match_score * 0.30));
	}

	/**
	 * @param array<int,array<string,mixed>> $sections       Sections.
	 * @param array<int,array<string,mixed>> $elementor_data Elements.
	 * @param int                            $geometry_base  Base geometry score.
	 */
	private function layout_structure_similarity(array $sections, array $elementor_data, int $geometry_base): int
	{
		$source_containers = 0;
		foreach ($sections as $section) {
			$this->count_layout_nodes($section['tree'] ?? null, $source_containers);
		}
		$emitted_containers = $this->count_el_containers($elementor_data);
		if ($source_containers <= 0) {
			return $geometry_base;
		}
		$ratio = min($emitted_containers, $source_containers) / max(1, $source_containers);
		$structure = (int) round($ratio * 100);
		return (int) round($geometry_base * 0.7 + $structure * 0.3);
	}

	/**
	 * @param array<string,mixed>|null $node  Node.
	 * @param int                      $count Count (by ref).
	 */
	private function count_layout_nodes($node, int &$count): void
	{
		if (!is_array($node)) {
			return;
		}
		if ($this->is_significant_layout_node($node)) {
			++$count;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_layout_nodes($child, $count);
		}
	}

	/**
	 * Nodes that warrant an Elementor container — not every inferred constraint.
	 *
	 * @param array<string,mixed> $node Node.
	 */
	private function is_significant_layout_node(array $node): bool
	{
		if (!empty($node['atomic'])) {
			return false;
		}
		if (!empty($node['layoutRole'])) {
			return true;
		}

		$s = $node['s'] ?? array();
		$disp = strtolower((string) ($s['disp'] ?? ''));
		$child_count = count((array) ($node['children'] ?? array()));
		$is_flex_or_grid = false !== strpos($disp, 'flex') || false !== strpos($disp, 'grid');

		// Single-cell centering grids / empty flex wrappers are not layout frames.
		if ($is_flex_or_grid && $child_count >= 2) {
			return true;
		}

		$signals = VisualSignals::analyze($node);
		$w = (float) ($s['w'] ?? 0);
		$h = (float) ($s['h'] ?? 0);
		$substantial = ($w >= 120.0 || $h >= 80.0);
		if (($signals['has_background'] || $signals['has_border'] || $signals['has_shadow']) && $substantial) {
			return true;
		}
		if ($signals['has_padding'] && $substantial && $child_count >= 1) {
			return true;
		}

		$gap = $s['gap'] ?? null;
		if ($child_count >= 2 && null !== $gap && '' !== $gap && '0' !== $gap && '0px' !== $gap
			&& empty($s['_gap_geometry']) && empty($s['_gap_whitespace'])) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function count_el_containers(array $elements): int
	{
		$n = 0;
		foreach ($elements as $el) {
			if ('container' === ($el['elType'] ?? '')) {
				++$n;
			}
			$n += $this->count_el_containers((array) ($el['elements'] ?? array()));
		}
		return $n;
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	private function flex_gap_px(array $settings): float
	{
		$gap = $settings['flex_gap'] ?? null;
		if (is_array($gap)) {
			return (float) ($gap['size'] ?? $gap['row'] ?? 0);
		}
		return 0.0;
	}

	/**
	 * Authoritative gap for a source frame: constraint → whitespace → CSS gap.
	 *
	 * @param array<string,mixed> $node        Node.
	 * @param array<string,mixed> $constraint  Layout constraint.
	 * @param array<string,mixed> $whitespace  Whitespace analysis.
	 */
	private function source_gap_px(array $node, array $constraint, array $whitespace): float
	{
		if (isset($constraint['gap']) && is_numeric($constraint['gap']) && (float) $constraint['gap'] > 0) {
			return (float) $constraint['gap'];
		}
		if (isset($whitespace['gap']) && is_numeric($whitespace['gap']) && (float) $whitespace['gap'] > 0) {
			return (float) $whitespace['gap'];
		}

		$s = $node['s'] ?? array();
		// Skip gaps stamped from geometry inference — those are not CSS.
		if (!empty($s['_gap_geometry']) || !empty($s['_gap_whitespace'])) {
			return 0.0;
		}
		// CSS gap only matters when multiple element children are spaced.
		// Icon+label buttons often expose gap with a single element child.
		if (count((array) ($node['children'] ?? array())) < 2) {
			return 0.0;
		}
		$raw = $s['gap'] ?? $s['rowGap'] ?? $s['columnGap'] ?? $s['colGap'] ?? null;
		if (is_numeric($raw)) {
			return (float) $raw;
		}
		if (is_string($raw) && preg_match('/^(-?\d+(?:\.\d+)?)\s*px/i', trim($raw), $m)) {
			return (float) $m[1];
		}
		return 0.0;
	}

	/**
	 * @param array<string,mixed> $el         Element.
	 * @param float               $parent_w   Parent width.
	 * @param string              $direction  Flex direction.
	 */
	private function child_width(array $el, float $parent_w, string $direction): float
	{
		if ('row' !== $direction) {
			return $parent_w;
		}
		$settings = $el['settings'] ?? array();
		$w = $settings['width'] ?? null;
		if (is_array($w) && isset($w['size'])) {
			if ('%' === ($w['unit'] ?? '')) {
				return $parent_w * ((float) $w['size'] / 100);
			}
			return (float) $w['size'];
		}
		$kids = (array) ($el['elements'] ?? array());
		return $kids ? $parent_w / count($kids) : $parent_w;
	}

	/**
	 * @param array<string,mixed> $el Element.
	 */
	private function estimate_widget_height(array $el): float
	{
		$type = (string) ($el['widgetType'] ?? '');
		return match ($type) {
			'heading' => 48.0,
			'button' => 44.0,
			'image' => 200.0,
			'spacer' => (float) ($el['settings']['space']['size'] ?? 40),
			default => 24.0,
		};
	}
}
