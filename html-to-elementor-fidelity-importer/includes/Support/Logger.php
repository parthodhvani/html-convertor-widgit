<?php
/**
 * Lightweight logger with per-request in-memory buffer + optional file output.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Debug logger. Always buffers entries for the current request; file writes
 * happen for errors always and for debug lines when debug mode is enabled.
 */
final class Logger
{

	/** @var list<array{level:string,message:string,context:array<string,mixed>,time:string}> */
	private static array $buffer = array();

	/** @var bool Whether this request opted into verbose debug capture. */
	private static bool $request_debug = false;

	/**
	 * Absolute path to the working directory for imports.
	 */
	public static function work_dir(): string
	{
		$dirs = wp_upload_dir();
		return trailingslashit($dirs['basedir']) . 'h2e-imports';
	}

	/**
	 * Start (or reset) a per-request log buffer for a conversion job.
	 *
	 * @param bool $debug Whether verbose debug lines should be captured/persisted.
	 */
	public static function begin_request(bool $debug = false): void
	{
		self::$buffer = array();
		self::$request_debug = $debug;
	}

	/**
	 * Return buffered log entries for this request only.
	 *
	 * @return list<array{level:string,message:string,context:array<string,mixed>,time:string}>
	 */
	public static function buffer(): array
	{
		return self::$buffer;
	}

	/**
	 * Flatten the buffer into human-readable lines for the admin UI.
	 *
	 * @return list<string>
	 */
	public static function buffer_lines(): array
	{
		$lines = array();
		foreach (self::$buffer as $entry) {
			$ctx = $entry['context'] ? ' ' . wp_json_encode($entry['context']) : '';
			$lines[] = sprintf('[%s] %s: %s%s', $entry['time'], $entry['level'], $entry['message'], $ctx);
		}
		return $lines;
	}

	/**
	 * Clear the per-request buffer.
	 */
	public static function clear_buffer(): void
	{
		self::$buffer = array();
		self::$request_debug = false;
	}

	/**
	 * Informational line (always buffered; file write when debug is on).
	 *
	 * @param string              $message Message to record.
	 * @param array<string,mixed> $context Extra context.
	 */
	public static function info(string $message, array $context = array()): void
	{
		self::write('INFO', $message, $context, self::$request_debug || (bool) Settings::get('debug', false));
	}

	/**
	 * Write a log line when debug mode is enabled.
	 *
	 * @param string              $message Message to record.
	 * @param array<string,mixed> $context Extra context.
	 */
	public static function debug(string $message, array $context = array()): void
	{
		$enabled = self::$request_debug || (bool) Settings::get('debug', false);
		self::write('DEBUG', $message, $context, $enabled);
	}

	/**
	 * Always-on error logging (buffered + file).
	 *
	 * @param string              $message Message to record.
	 * @param array<string,mixed> $context Extra context.
	 */
	public static function error(string $message, array $context = array()): void
	{
		self::write('ERROR', $message, $context, true);
	}

	/**
	 * @param string              $level      Log level label.
	 * @param string              $message    Message.
	 * @param array<string,mixed> $context    Context.
	 * @param bool                $write_file Whether to append to debug.log.
	 */
	private static function write(string $level, string $message, array $context, bool $write_file): void
	{
		$time = gmdate('Y-m-d H:i:s');
		self::$buffer[] = array(
			'level' => $level,
			'message' => $message,
			'context' => $context,
			'time' => $time,
		);

		if (!$write_file) {
			return;
		}

		$dir = self::work_dir();
		if (!file_exists($dir)) {
			wp_mkdir_p($dir);
		}
		$line = sprintf(
			"[%s] %s: %s %s\n",
			$time,
			$level,
			$message,
			$context ? wp_json_encode($context) : ''
		);
		file_put_contents(trailingslashit($dir) . 'debug.log', $line, FILE_APPEND); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
