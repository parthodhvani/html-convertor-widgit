<?php
/**
 * PHPUnit bootstrap with minimal WordPress shims so the pure-logic classes
 * (generator, widget detector, container factory) can be unit tested without
 * a full WordPress install.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
if (!defined('H2E_PLUGIN_DIR')) {
	define('H2E_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
}

if (!function_exists('esc_url')) {
	function esc_url(string $url): string
	{
		return filter_var($url, FILTER_SANITIZE_URL) ?: '';
	}
}
if (!function_exists('wp_rand')) {
	function wp_rand(int $min = 0, int $max = 0): int
	{
		return random_int($min, $max);
	}
}
if (!function_exists('esc_html')) {
	function esc_html(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES);
	}
}
if (!function_exists('wp_strip_all_tags')) {
	function wp_strip_all_tags(string $text): string
	{
		return trim(strip_tags($text));
	}
}
if (!function_exists('sanitize_html_class')) {
	function sanitize_html_class(string $class): string
	{
		return preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
	}
}

$composer = H2E_PLUGIN_DIR . 'vendor/autoload.php';
if (is_readable($composer)) {
	require $composer;
} else {
	require H2E_PLUGIN_DIR . 'includes/Support/Autoloader.php';
	\HtmlToElementor\Support\Autoloader::register();
}
