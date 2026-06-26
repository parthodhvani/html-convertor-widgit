<?php
/**
 * Shared geometry helpers for visual reconstruction engines.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Bounding-box utilities used across layout, whitespace and alignment engines.
 */
final class Geometry
{

	/**
	 * Resolve a node's bounding box from captured data.
	 *
	 * @param array<string,mixed> $node Tree node.
	 * @return array{x:float,y:float,width:float,height:float}
	 */
	public static function bbox(array $node): array
	{
		if (!empty($node['bbox']) && is_array($node['bbox'])) {
			return array(
				'x' => (float) ($node['bbox']['x'] ?? 0),
				'y' => (float) ($node['bbox']['y'] ?? 0),
				'width' => (float) ($node['bbox']['width'] ?? 0),
				'height' => (float) ($node['bbox']['height'] ?? 0),
			);
		}
		if (!empty($node['visual']['bbox']) && is_array($node['visual']['bbox'])) {
			$b = $node['visual']['bbox'];
			return array(
				'x' => (float) ($b['x'] ?? 0),
				'y' => (float) ($b['y'] ?? 0),
				'width' => (float) ($b['width'] ?? 0),
				'height' => (float) ($b['height'] ?? 0),
			);
		}
		$s = $node['s'] ?? array();
		return array(
			'x' => 0.0,
			'y' => 0.0,
			'width' => (float) ($s['w'] ?? 0),
			'height' => (float) ($s['h'] ?? 0),
		);
	}

	/**
	 * @param array{x:float,y:float,width:float,height:float} $a Box A.
	 * @param array{x:float,y:float,width:float,height:float} $b Box B.
	 */
	public static function horizontal_gap(array $a, array $b): float
	{
		if ($a['x'] + $a['width'] <= $b['x']) {
			return $b['x'] - ($a['x'] + $a['width']);
		}
		if ($b['x'] + $b['width'] <= $a['x']) {
			return $a['x'] - ($b['x'] + $b['width']);
		}
		return 0.0;
	}

	/**
	 * @param array{x:float,y:float,width:float,height:float} $a Box A.
	 * @param array{x:float,y:float,width:float,height:float} $b Box B.
	 */
	public static function vertical_gap(array $a, array $b): float
	{
		$top = $a['y'] <= $b['y'] ? $a : $b;
		$bottom = $top === $a ? $b : $a;
		if ($top['y'] + $top['height'] <= $bottom['y']) {
			return $bottom['y'] - ($top['y'] + $top['height']);
		}
		return 0.0;
	}

	/**
	 * Whether boxes overlap on the horizontal axis.
	 *
	 * @param array{x:float,y:float,width:float,height:float} $a Box A.
	 * @param array{x:float,y:float,width:float,height:float} $b Box B.
	 */
	public static function overlaps_y(array $a, array $b): bool
	{
		return !($a['y'] + $a['height'] <= $b['y'] || $b['y'] + $b['height'] <= $a['y']);
	}

	/**
	 * Whether boxes overlap on the vertical axis.
	 *
	 * @param array{x:float,y:float,width:float,height:float} $a Box A.
	 * @param array{x:float,y:float,width:float,height:float} $b Box B.
	 */
	public static function overlaps_x(array $a, array $b): bool
	{
		return !($a['x'] + $a['width'] <= $b['x'] || $b['x'] + $b['width'] <= $a['x']);
	}

	/**
	 * Median of numeric values (0 if empty).
	 *
	 * @param array<int,float> $values Values.
	 */
	public static function median(array $values): float
	{
		if (empty($values)) {
			return 0.0;
		}
		sort($values);
		$n = count($values);
		$mid = (int) floor($n / 2);
		return $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
	}

	/**
	 * Whether all values are within tolerance of each other.
	 *
	 * @param array<int,float> $values    Values.
	 * @param float            $tolerance Max deviation in px.
	 */
	public static function aligned(array $values, float $tolerance = 4.0): bool
	{
		if (count($values) < 2) {
			return true;
		}
		$min = min($values);
		$max = max($values);
		return ($max - $min) <= $tolerance;
	}
}
