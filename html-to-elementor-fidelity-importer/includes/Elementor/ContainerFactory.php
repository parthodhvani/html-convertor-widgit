<?php
/**
 * Builds Elementor container element arrays from extracted section data.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Factory for Elementor flexbox container nodes. Container settings are derived
 * from the section's computed styles to approximate the rendered layout without
 * ever modifying the section's internal HTML.
 */
final class ContainerFactory
{

	/**
	 * Build a top-level section container.
	 *
	 * @param array<string,mixed>      $section  Extracted section data.
	 * @param array<int,array<string,mixed>> $children Child elements (widgets/containers).
	 * @return array<string,mixed>
	 */
	public function section(array $section, array $children): array
	{
		$styles = is_array($section['styles'] ?? null) ? $section['styles'] : array();
		$bbox = is_array($section['bbox'] ?? null) ? $section['bbox'] : array();
		$settings = array(
			'content_width' => 'full',
			'flex_direction' => 'column',
		);

		// Background colour, if Chromium reported a non-transparent one.
		$bg = (string) ($styles['backgroundColor'] ?? '');
		if ('' !== $bg && !$this->is_transparent($bg)) {
			$settings['background_background'] = 'classic';
			$settings['background_color'] = $bg;
		}

		// Minimum height from the rendered bounding box keeps vertical rhythm.
		$height = (float) ($bbox['height'] ?? 0);
		if ($height > 0) {
			$settings['min_height'] = array(
				'unit' => 'px',
				'size' => round($height, 2),
			);
		}

		// Vertical padding from computed styles.
		$pad_top = $this->px($styles['paddingTop'] ?? null);
		$pad_bottom = $this->px($styles['paddingBottom'] ?? null);
		if (null !== $pad_top || null !== $pad_bottom) {
			$settings['padding'] = array(
				'unit' => 'px',
				'top' => (string) ($pad_top ?? 0),
				'right' => '0',
				'bottom' => (string) ($pad_bottom ?? 0),
				'left' => '0',
				'isLinked' => false,
			);
		}

		return array(
			'id' => ElementId::generate(),
			'elType' => 'container',
			'settings' => $settings,
			'elements' => array_values($children),
			'isInner' => false,
		);
	}

	/**
	 * Whether a CSS colour string is fully transparent / absent.
	 *
	 * @param string $color CSS colour.
	 */
	private function is_transparent(string $color): bool
	{
		$color = strtolower(trim($color));
		if ('transparent' === $color || '' === $color) {
			return true;
		}
		if (preg_match('/rgba?\(([^)]+)\)/', $color, $m)) {
			$parts = array_map('trim', explode(',', $m[1]));
			if (4 === count($parts) && (float) $parts[3] === 0.0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a CSS pixel value into a number.
	 *
	 * @param mixed $value Raw value such as "24px".
	 */
	private function px($value): ?float
	{
		if (null === $value) {
			return null;
		}
		if (is_numeric($value)) {
			return (float) $value;
		}
		if (is_string($value) && preg_match('/(-?\d+(\.\d+)?)/', $value, $m)) {
			return (float) $m[1];
		}
		return null;
	}
}
