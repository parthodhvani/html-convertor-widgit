<?php
/**
 * Extracts global design tokens from the visual tree.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

use HtmlToElementor\Elementor\DesignTokens;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Design Token Extractor — detects colour palettes, typography scales, spacing,
 * radius, shadows and container widths for Elementor Global Styles.
 */
final class DesignTokenExtractor implements EngineInterface
{

	private DesignTokens $legacy;

	public function __construct(?DesignTokens $legacy = null)
	{
		$this->legacy = $legacy ?? new DesignTokens();
	}

	public function name(): string
	{
		return 'design_tokens';
	}

	/**
	 * Extract comprehensive design tokens from sections.
	 *
	 * @param array<int,array<string,mixed>> $sections       Sections.
	 * @param array<string,mixed>            $spacing_tokens From ConstraintLayoutEngine.
	 * @return array<string,mixed>
	 */
	public function extract(array $sections, array $spacing_tokens = array()): array
	{
		$base = $this->legacy->extract($sections);

		$font_sizes = array();
		$radii = array();
		$shadows = array();
		$widths = array();

		foreach ($sections as $section) {
			$this->walk($section['tree'] ?? null, $font_sizes, $radii, $shadows, $widths);
		}

		arsort($font_sizes);
		arsort($radii);

		return array_merge(
			$base,
			array(
				'typography_scale' => $this->scale_from_tally($font_sizes),
				'spacing_scale' => $spacing_tokens['scale'] ?? array(),
				'spacing_base' => $spacing_tokens['base'] ?? 16,
				'radius_scale' => array_values(array_unique(array_map('floatval', array_keys($radii)))),
				'shadow_scale' => array_slice(array_keys($shadows), 0, 4),
				'container_widths' => $this->container_widths($widths),
				'neutral_palette' => $this->neutral_colors($sections),
			)
		);
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 * @param array<string,int>        $font_sizes Tally.
	 * @param array<string,int>        $radii Tally.
	 * @param array<string,int>        $shadows Tally.
	 * @param array<float,int>         $widths Tally.
	 */
	private function walk($node, array &$font_sizes, array &$radii, array &$shadows, array &$widths): void
	{
		if (!is_array($node)) {
			return;
		}
		$s = $node['s'] ?? array();

		$fs = (string) ($s['fs'] ?? '');
		if ($fs && preg_match('/([\d.]+)/', $fs, $m)) {
			$font_sizes[$m[1] . 'px'] = ($font_sizes[$m[1] . 'px'] ?? 0) + 1;
		}
		if (!empty($s['br'])) {
			$key = (string) $s['br'];
			$radii[$key] = ($radii[$key] ?? 0) + 1;
		}
		if (!empty($s['sh'])) {
			$shadows[(string) $s['sh']] = ($shadows[(string) $s['sh']] ?? 0) + 1;
		}
		$w = (float) ($s['w'] ?? 0);
		if ($w > 200) {
			$bucket = (string) (int) round($w / 10) * 10;
			$widths[$bucket] = ($widths[$bucket] ?? 0) + 1;
		}

		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->walk($child, $font_sizes, $radii, $shadows, $widths);
		}
	}

	/**
	 * @param array<string,int> $tally Value tally.
	 * @return array<int,string>
	 */
	private function scale_from_tally(array $tally): array
	{
		$sizes = array_map('floatval', array_map(fn($k) => rtrim($k, 'px'), array_keys($tally)));
		sort($sizes);
		return array_map(fn($v) => $v . 'px', array_values(array_unique($sizes)));
	}

	/**
	 * @param array<float,int> $widths Width tally.
	 * @return array<int,float>
	 */
	private function container_widths(array $widths): array
	{
		arsort($widths);
		return array_slice(array_map('floatval', array_keys($widths)), 0, 5);
	}

	/**
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,string>
	 */
	private function neutral_colors(array $sections): array
	{
		$neutrals = array();
		foreach ($sections as $section) {
			$this->collect_neutrals($section['tree'] ?? null, $neutrals);
		}
		return array_slice(array_keys($neutrals), 0, 6);
	}

	/**
	 * @param array<string,mixed>|null $node Node.
	 * @param array<string,int>        $neutrals Tally.
	 */
	private function collect_neutrals($node, array &$neutrals): void
	{
		if (!is_array($node)) {
			return;
		}
		$s = $node['s'] ?? array();
		foreach (array('color', 'bg') as $key) {
			$c = (string) ($s[$key] ?? '');
			if ($c && $this->looks_neutral($c)) {
				$neutrals[$c] = ($neutrals[$c] ?? 0) + 1;
			}
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			$this->collect_neutrals($child, $neutrals);
		}
	}

	/**
	 * @param string $color CSS colour.
	 */
	private function looks_neutral(string $color): bool
	{
		if (!preg_match('/rgba?\(([^)]+)\)/', $color, $m)) {
			return false;
		}
		$p = array_map('trim', explode(',', $m[1]));
		if (count($p) < 3) {
			return false;
		}
		$r = (int) $p[0];
		$g = (int) $p[1];
		$b = (int) $p[2];
		return (max($r, $g, $b) - min($r, $g, $b)) <= 15;
	}
}
