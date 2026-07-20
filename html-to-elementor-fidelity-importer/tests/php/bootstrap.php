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
if (!function_exists('wp_json_encode')) {
	/**
	 * @param mixed $data Data.
	 */
	function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
	{
		return json_encode($data, $options | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, $depth);
	}
}
if (!function_exists('trailingslashit')) {
	function trailingslashit(string $path): string
	{
		return rtrim($path, "/\\") . '/';
	}
}
if (!function_exists('wp_mkdir_p')) {
	function wp_mkdir_p(string $path): bool
	{
		return is_dir($path) || mkdir($path, 0777, true);
	}
}
if (!function_exists('sanitize_file_name')) {
	function sanitize_file_name(string $name): string
	{
		$name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? $name;
		return trim($name, '.-');
	}
}
if (!function_exists('get_post_meta')) {
	/**
	 * @param mixed $post_id Post id.
	 * @param string $key Meta key.
	 * @param bool $single Single.
	 * @return mixed
	 */
	function get_post_meta($post_id, string $key = '', bool $single = false): mixed
	{
		$store = $GLOBALS['h2e_test_post_meta'] ?? array();
		$val = $store[(int) $post_id][$key] ?? '';
		return $single ? $val : array($val);
	}
}
if (!function_exists('is_singular')) {
	function is_singular(): bool
	{
		return (bool) ($GLOBALS['h2e_test_is_singular'] ?? false);
	}
}
if (!function_exists('get_queried_object_id')) {
	function get_queried_object_id(): int
	{
		return (int) ($GLOBALS['h2e_test_queried_id'] ?? 0);
	}
}
if (!function_exists('wp_enqueue_style')) {
	/**
	 * @param string $handle Handle.
	 * @param string $src Src.
	 * @param array<int,string> $deps Deps.
	 * @param mixed $ver Version.
	 * @param string $media Media.
	 */
	function wp_enqueue_style(string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all'): void
	{
		$GLOBALS['h2e_test_enqueued_styles'][] = array($handle, $src);
	}
}

$composer = H2E_PLUGIN_DIR . 'vendor/autoload.php';
if (is_readable($composer)) {
	require $composer;
} else {
	require H2E_PLUGIN_DIR . 'includes/Support/Autoloader.php';
	\HtmlToElementor\Support\Autoloader::register();
}
