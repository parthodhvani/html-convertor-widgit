<?php
/**
 * PSR-4 fallback autoloader used when Composer's autoloader is not present.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Minimal PSR-4 autoloader for the HtmlToElementor namespace.
 */
final class Autoloader
{

	private const PREFIX = 'HtmlToElementor\\';

	/**
	 * Register the autoloader on the SPL stack.
	 */
	public static function register(): void
	{
		spl_autoload_register(array(self::class, 'autoload'));
	}

	/**
	 * Resolve a fully-qualified class name to a file inside includes/.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function autoload(string $class): void
	{
		if (0 !== strpos($class, self::PREFIX)) {
			return;
		}

		$relative = substr($class, strlen(self::PREFIX));
		$relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
		$file = H2E_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

		if (is_readable($file)) {
			require $file;
		}
	}
}
