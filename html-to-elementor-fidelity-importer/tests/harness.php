<?php
/**
 * Standalone harness: turn a Node layout.json into Elementor data WITHOUT a full
 * WordPress install. Useful for local verification and CI of the JSON generator.
 *
 * Usage: php tests/harness.php <layout.json> [preserve|widgets]
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

// Minimal WordPress shims so the generator classes can run standalone.
define('ABSPATH', __DIR__ . '/');
define('H2E_PLUGIN_DIR', dirname(__DIR__) . '/');

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

require H2E_PLUGIN_DIR . 'includes/Support/Autoloader.php';
\HtmlToElementor\Support\Autoloader::register();

use HtmlToElementor\Services\RenderResult;
use HtmlToElementor\Elementor\ElementorJsonGenerator;

$layout_path = $argv[1] ?? '';
$mode = $argv[2] ?? 'preserve';

if ('' === $layout_path || !is_readable($layout_path)) {
	fwrite(STDERR, "Usage: php tests/harness.php <layout.json> [preserve|widgets]\n");
	exit(2);
}

$layout = json_decode((string) file_get_contents($layout_path), true);
if (!is_array($layout)) {
	fwrite(STDERR, "Invalid layout JSON.\n");
	exit(1);
}

$result = RenderResult::from_array($layout);
$generator = new ElementorJsonGenerator();
$generated = $generator->generate($result, array('mode' => $mode, 'confidence' => 95));

// Validate the generated structure looks like Elementor data.
$errors = array();
foreach ($generated['data'] as $i => $el) {
	if (($el['elType'] ?? '') !== 'container') {
		$errors[] = "Element $i is not a container.";
	}
	if (empty($el['id'])) {
		$errors[] = "Element $i has no id.";
	}
}

echo "=== Conversion report ===\n";
echo json_encode($generated['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
echo '=== Elementor data (' . count($generated['data']) . " top-level containers) ===\n";
echo json_encode($generated['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

if ($errors) {
	fwrite(STDERR, "\nVALIDATION ERRORS:\n - " . implode("\n - ", $errors) . "\n");
	exit(1);
}
fwrite(STDERR, "\nOK: generated valid Elementor data.\n");
