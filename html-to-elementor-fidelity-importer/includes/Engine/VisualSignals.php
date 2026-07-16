<?php
/**
 * Visual signal analysis shared across reconstruction engines.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Derives visual signals from geometry and computed styles — never from class names.
 */
final class VisualSignals
{

	/**
	 * @param array<string,mixed> $node Tree node.
	 * @return array<string,mixed>
	 */
	public static function analyze(array $node): array
	{
		$box = Geometry::bbox($node);
		$s = $node['s'] ?? array();
		$children = (array) ($node['children'] ?? array());

		return array(
			'bbox' => $box,
			'area' => $box['width'] * $box['height'],
			'has_background' => self::has_background($s),
			'has_border' => !empty($s['bdw']),
			'has_shadow' => !empty($s['sh']),
			'has_padding' => self::padding_sum($s) > 0,
			'is_layered' => self::is_layered($node),
			'child_count' => count($children),
			'atomic_child_count' => self::count_atomic($children),
			'input_like_children' => self::count_input_like($children),
			'image_child' => self::find_cover_image($children),
			'font_size_px' => self::font_size_px($s),
			'font_weight' => (int) preg_replace('/\D/', '', (string) ($s['fw'] ?? '400')),
			'position' => strtolower((string) ($s['pos'] ?? 'static')),
			'aria_role' => strtolower((string) ($node['ariaRole'] ?? $node['role'] ?? '')),
		);
	}

	/**
	 * @param array<string,mixed> $s Computed styles.
	 */
	public static function has_background(array $s): bool
	{
		return !empty($s['bg']) || !empty($s['bgImg']);
	}

	/**
	 * @param array<string,mixed> $s Computed styles.
	 */
	public static function padding_sum(array $s): float
	{
		return (float) ($s['pt'] ?? 0) + (float) ($s['pb'] ?? 0)
			+ (float) ($s['pl'] ?? 0) + (float) ($s['pr'] ?? 0);
	}

	/**
	 * @param array<string,mixed> $node Tree node.
	 */
	public static function is_layered(array $node): bool
	{
		$pos = strtolower((string) ($node['s']['pos'] ?? ''));
		if (in_array($pos, array('absolute', 'fixed', 'relative'), true) && 'relative' !== $pos) {
			return false;
		}
		$parent = Geometry::bbox($node);
		$parent_area = max(1.0, $parent['width'] * $parent['height']);
		$cover_layers = 0;
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$p = strtolower((string) ($child['s']['pos'] ?? ''));
			if (!in_array($p, array('absolute', 'fixed'), true)) {
				continue;
			}
			// Ignore fixed/absolute chrome (theme toggles, cookies, FABs).
			// Only true cover/overlay layers should trigger layered reconstruction —
			// otherwise the whole page becomes layered_block and flex rows flatten.
			$cls = strtolower((string) ($child['cls'] ?? '') . ' ' . (string) ($child['id'] ?? ''));
			if (preg_match('/\b(visually-hidden|sr-only|screen-reader-only|u-hidden)\b/', $cls)) {
				continue;
			}
			$box = Geometry::bbox($child);
			// Unresolved/synthetic bboxes (0×0) still count for unit fixtures.
			// Clipped 1×1 offscreen nodes are accessibility chrome — ignore.
			if ($box['width'] <= 0 || $box['height'] <= 0) {
				++$cover_layers;
				continue;
			}
			if ($box['width'] <= 2 && $box['height'] <= 2) {
				continue;
			}
			$area = $box['width'] * $box['height'];
			$width_ratio = $box['width'] / max(1.0, $parent['width']);
			$area_ratio = $area / $parent_area;
			// Tiny fixed/absolute chrome (theme toggles, FABs, cookie chips).
			if ($area < 120 * 120 && $area_ratio < 0.12) {
				continue;
			}
			if ('fixed' === $p && $area_ratio < 0.25) {
				continue;
			}
			if ($area_ratio < 0.12 && $width_ratio < 0.45) {
				continue;
			}
			++$cover_layers;
		}
		return $cover_layers >= 1;
	}

	/**
	 * @param array<int,array<string,mixed>> $children Children.
	 */
	public static function count_atomic(array $children): int
	{
		$n = 0;
		foreach ($children as $child) {
			if (!empty($child['atomic'])) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @param array<int,array<string,mixed>> $children Children.
	 */
	public static function count_input_like(array $children): int
	{
		$n = 0;
		foreach ($children as $child) {
			if (self::looks_input_like($child)) {
				++$n;
			}
			$n += self::count_input_like((array) ($child['children'] ?? array()));
		}
		return $n;
	}

	/**
	 * Input-like: bordered box with typical field height, no image.
	 *
	 * @param array<string,mixed> $node Node.
	 */
	public static function looks_input_like(array $node): bool
	{
		$cls = strtolower((string) ($node['cls'] ?? '') . ' ' . (string) ($node['id'] ?? ''));
		if (preg_match('/\b(divider|separator|spacer|vr|hr)\b/', $cls)) {
			return false;
		}
		$s = $node['s'] ?? array();
		$box = Geometry::bbox($node);
		$h = $box['height'] ?: (float) ($s['h'] ?? 0);
		$w = $box['width'] ?: (float) ($s['w'] ?? 0);
		// Full-bleed horizontal rules are dividers, not inputs.
		if ($w >= 600 && $h <= 64) {
			return false;
		}
		$has_border = !empty($s['bdw']);
		$has_text = '' !== trim((string) ($node['text'] ?? ''));
		$role = strtolower((string) ($node['ariaRole'] ?? ''));
		if (in_array($role, array('textbox', 'combobox', 'searchbox'), true)) {
			return true;
		}
		return $has_border && $h >= 28 && $h <= 80 && !$has_text && empty($node['src']);
	}

	/**
	 * @param array<int,array<string,mixed>> $children Children.
	 * @return array<string,mixed>|null
	 */
	public static function find_cover_image(array $children): ?array
	{
		$best = null;
		$best_area = 0.0;
		foreach ($children as $child) {
			$src = (string) ($child['src'] ?? '');
			if ('' === $src && empty($child['s']['bgImg'])) {
				continue;
			}
			$box = Geometry::bbox($child);
			$area = $box['width'] * $box['height'];
			if ($area > $best_area) {
				$best_area = $area;
				$best = $child;
			}
		}
		return $best;
	}

	/**
	 * @param array<string,mixed> $s Styles.
	 */
	public static function font_size_px(array $s): float
	{
		$fs = (string) ($s['fs'] ?? '');
		if (preg_match('/([\d.]+)/', $fs, $m)) {
			return (float) $m[1];
		}
		return 0.0;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	public static function looks_button(array $node): bool
	{
		$s = $node['s'] ?? array();
		$box = Geometry::bbox($node);
		$text = trim((string) ($node['text'] ?? ''));
		$cls = strtolower((string) ($node['cls'] ?? ''));
		$tag = strtolower((string) ($node['tag'] ?? ''));
		$role = strtolower((string) ($node['ariaRole'] ?? ''));

		if ('button' === $role || 'button' === $tag) {
			return '' !== $text || preg_match('/\b(btn|button)\b/', $cls);
		}

		if ('' === $text && '' !== (string) ($node['html'] ?? '')) {
			$text = trim(wp_strip_all_tags((string) $node['html']));
		}
		if ('' === $text) {
			return false;
		}

		if (preg_match('/\b(btn|button|cta)\b/', $cls)) {
			return true;
		}

		$h = $box['height'] ?: (float) ($s['h'] ?? 0);
		$w = $box['width'] ?: (float) ($s['w'] ?? 0);
		$has_bg = self::has_background($s);
		$has_pad = self::padding_sum($s) >= 6;
		return $has_bg && $has_pad && $h >= 28 && $h <= 72 && $w >= 60 && $w <= 400;
	}

	/**
	 * @param array<string,mixed> $node Node.
	 */
	public static function looks_heading(array $node): bool
	{
		$s = $node['s'] ?? array();
		$fs = self::font_size_px($s);
		$fw = (int) preg_replace('/\D/', '', (string) ($s['fw'] ?? '400'));
		$text = trim((string) ($node['text'] ?? ''));
		if ('' === $text) {
			return false;
		}
		$role = strtolower((string) ($node['ariaRole'] ?? ''));
		if (in_array($role, array('heading'), true)) {
			return true;
		}
		return $fs >= 22 || ($fs >= 18 && $fw >= 600);
	}
}
