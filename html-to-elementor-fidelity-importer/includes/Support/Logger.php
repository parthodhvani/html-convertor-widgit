<?php
/**
 * Lightweight logger writing to the plugin upload directory in debug mode.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Debug logger. No-op unless the "debug" setting is enabled.
 */
final class Logger
{

	/**
	 * Absolute path to the working directory for imports.
	 */
	public static function work_dir(): string
	{
		$dirs = wp_upload_dir();
		return trailingslashit($dirs['basedir']) . 'h2e-imports';
	}

	/**
	 * Write a log line when debug mode is enabled.
	 *
	 * @param string              $message Message to record.
	 * @param array<string,mixed> $context Extra context.
	 */
	public static function debug(string $message, array $context = array()): void
	{
		if (!Settings::get('debug', false)) {
			return;
		}
		self::write('DEBUG', $message, $context);
	}

	/**
	 * Always-on error logging.
	 *
	 * @param string              $message Message to record.
	 * @param array<string,mixed> $context Extra context.
	 */
	public static function error(string $message, array $context = array()): void
	{
		self::write('ERROR', $message, $context);
	}

	/**
	 * @param string              $level   Log level label.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	private static function write(string $level, string $message, array $context): void
	{
		$dir = self::work_dir();
		if (!file_exists($dir)) {
			wp_mkdir_p($dir);
		}
		$line = sprintf(
			"[%s] %s: %s %s\n",
			gmdate('Y-m-d H:i:s'),
			$level,
			$message,
			$context ? wp_json_encode($context) : ''
		);
		file_put_contents(trailingslashit($dir) . 'debug.log', $line, FILE_APPEND); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
