<?php
/**
 * Contract for Visual Reconstruction Engine subsystems.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Minimal interface shared by all reconstruction engines.
 */
interface EngineInterface
{

	/**
	 * Human-readable engine name (for logging and reports).
	 */
	public function name(): string;
}
