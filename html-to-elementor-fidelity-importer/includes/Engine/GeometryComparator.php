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
				'gap' => (float) ($constraint['gap'] ?? $whitespace['gap'] ?? 0),
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
			$h = $this->simulate_element($el, $frames, 0.0, $y, $viewport, true);
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
	 */
	private function simulate_element(array $el, array &$frames, float $x, float $y, float $parent_w, bool $is_root): float
	{
		$settings = $el['settings'] ?? array();
		$cls = (string) ($settings['_css_classes'] ?? '');
		$type = (string) ($el['elType'] ?? '');

		if ('widget' === $type) {
			$h = $this->estimate_widget_height($el);
			$frames[] = array(
				'key' => 'w:' . $cls . ':' . ($el['widgetType'] ?? '') . ':' . count($frames),
				'cls' => $cls,
				'type' => 'leaf',
				'x' => $x,
				'y' => $y,
				'width' => $parent_w,
				'height' => $h,
				'gap' => 0.0,
				'direction' => '',
			);
			return $h;
		}

		$direction = (string) ($settings['flex_direction'] ?? 'column');
		$gap = $this->flex_gap_px($settings);
		$min_h = (float) ($settings['min_height']['size'] ?? 0);
		$kids = (array) ($el['elements'] ?? array());

		$frames[] = array(
			'key' => 'c:' . $cls . ':' . count($frames),
			'cls' => $cls,
			'type' => 'container',
			'x' => $x,
			'y' => $y,
			'width' => $parent_w,
			'height' => max($min_h, 1.0),
			'gap' => $gap,
			'direction' => $direction,
		);

		$cursor_x = $x;
		$cursor_y = $y;
		$max_extent = 0.0;

		foreach ($kids as $child) {
			$child_w = $this->child_width($child, $parent_w, $direction);
			$child_h = $this->simulate_element($child, $frames, $cursor_x, $cursor_y, $child_w, false);
			if ('row' === $direction) {
				$cursor_x += $child_w + $gap;
				$max_extent = max($max_extent, $child_h);
			} else {
				$cursor_y += $child_h + $gap;
				$max_extent += $child_h + $gap;
			}
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

		foreach ($source as $si => $s) {
			$best = null;
			$best_score = PHP_FLOAT_MAX;
			$best_ei = -1;

			foreach ($emitted as $ei => $e) {
				if (isset($used[$ei])) {
					continue;
				}
				if ('' !== $s['cls'] && $s['cls'] === $e['cls']) {
					$best = $e;
					$best_ei = $ei;
					$best_score = 0;
					break;
				}
			}

			if (null === $best) {
				foreach ($emitted as $ei => $e) {
					if (isset($used[$ei])) {
						continue;
					}
					if ($s['type'] !== $e['type']) {
						continue;
					}
					$score = $this->position_delta($s, $e) + $this->size_delta($s, $e) * 0.5;
					if ($score < $best_score) {
						$best_score = $score;
						$best = $e;
						$best_ei = $ei;
					}
				}
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
		if (!empty($node['layoutConstraint']) || !empty($node['layoutRole'])) {
			++$count;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->count_layout_nodes($child, $count);
		}
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
