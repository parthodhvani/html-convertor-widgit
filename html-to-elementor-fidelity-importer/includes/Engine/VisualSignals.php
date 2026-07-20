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
			'has_clip_shape' => self::has_clip_shape($s),
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
	 * Organic / clipped media frames (elliptical radius, overflow clip, clip-path).
	 *
	 * @param array<string,mixed> $s Computed styles.
	 */
	public static function has_clip_shape(array $s): bool
	{
		$br_raw = trim((string) ($s['brRaw'] ?? ''));
		if ('' !== $br_raw && '0px' !== $br_raw
			&& (false !== strpos($br_raw, '/') || false !== strpos($br_raw, '%'))
		) {
			return true;
		}
		$ov = strtolower((string) ($s['ov'] ?? $s['ovX'] ?? $s['ovY'] ?? 'visible'));
		if (in_array($ov, array('hidden', 'clip'), true)
			&& ((float) ($s['br'] ?? 0) > 0 || !empty($s['brad']))
		) {
			return true;
		}
		$clip = trim((string) ($s['clip'] ?? ''));
		return '' !== $clip && 'none' !== strtolower($clip);
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
		$absolute = 0;
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$p = strtolower((string) ($child['s']['pos'] ?? ''));
			if (in_array($p, array('absolute', 'fixed'), true)) {
				++$absolute;
			}
		}
		return $absolute >= 1;
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
		$s = $node['s'] ?? array();
		$box = Geometry::bbox($node);
		$h = $box['height'] ?: (float) ($s['h'] ?? 0);
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

		// Match btn/button, or a standalone "cta" token — but NOT cta-banner /
		// call-to-action wrappers (hyphen breaks \b so "cta" matched inside them).
		if (preg_match('/\b(btn|button)\b/', $cls)
			|| preg_match('/(?:^|[\s_])cta(?:[\s_]|$)/', $cls)
		) {
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
