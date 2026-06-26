<?php
/**
 * Elementor element ID generator.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Produces 7-character hexadecimal IDs in the same shape Elementor uses.
 */
final class ElementId
{

	/**
	 * Generate a unique 7-char hex id.
	 */
	public static function generate(): string
	{
		// Elementor uses dechex( rand() ) truncated to 7 chars.
		try {
			$rand = random_int(0x1000000, 0xfffffff);
		} catch (\Throwable $e) {
			$rand = wp_rand(0x1000000, 0xfffffff);
		}
		return substr(dechex($rand), 0, 7);
	}
}
