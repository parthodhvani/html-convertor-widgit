<?php
/**
 * Plugin deactivation routine.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles tasks that run once on plugin deactivation.
 */
final class Deactivator
{

	/**
	 * Flush rewrite rules. Options and imported data are intentionally preserved.
	 */
	public static function deactivate(): void
	{
		flush_rewrite_rules();
	}
}
