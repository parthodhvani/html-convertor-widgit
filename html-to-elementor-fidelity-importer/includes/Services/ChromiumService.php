<?php
/**
 * Bridge to the Node.js / Puppeteer Chromium rendering service.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Services;

use HtmlToElementor\Support\Logger;
use HtmlToElementor\Support\Settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Renders a source page in headless Chromium and returns the layout document.
 *
 * Two transports are supported:
 *  - "cli":  spawn `node chromium-service/cli.js` as a subprocess (default, zero-config).
 *  - "http": POST to a long-running `chromium-service/server.js` instance.
 */
final class ChromiumService
{

	/**
	 * Render the entry HTML file and return the parsed layout document.
	 *
	 * @param string               $entry_html Absolute path to the entry .html file.
	 * @param string               $job_dir    Working directory for outputs.
	 * @param array<string,mixed>  $overrides  Per-request setting overrides.
	 * @return RenderResult
	 *
	 * @throws \RuntimeException When rendering fails.
	 */
	public function render(string $entry_html, string $job_dir, array $overrides = array()): RenderResult
	{
		$settings = array_merge(Settings::all(), $overrides);
		$mode = (string) ($settings['render_mode'] ?? 'cli');

		Logger::debug('Chromium render start', array('entry' => $entry_html, 'mode' => $mode));

		$data = 'http' === $mode
			? $this->render_http($entry_html, $job_dir, $settings)
			: $this->render_cli($entry_html, $job_dir, $settings);

		if (!is_array($data) || empty($data['sections'])) {
			throw new \RuntimeException('Chromium service returned no sections.');
		}

		Logger::debug('Chromium render done', array('sections' => count($data['sections'])));
		return RenderResult::from_array($data);
	}

	/**
	 * Resolve the absolute path to the bundled CLI script.
	 */
	public function service_script(): string
	{
		$configured = (string) Settings::get('service_script', '');
		if ('' !== $configured && is_readable($configured)) {
			return $configured;
		}
		return H2E_PLUGIN_DIR . 'chromium-service/cli.js';
	}

	/**
	 * Spawn the Node CLI and decode its JSON output.
	 *
	 * @param string              $entry_html Entry file.
	 * @param string              $job_dir    Working directory.
	 * @param array<string,mixed> $settings   Effective settings.
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException On failure.
	 */
	private function render_cli(string $entry_html, string $job_dir, array $settings): array
	{
		$node = (string) ($settings['node_binary'] ?? 'node');
		$script = $this->service_script();
		$output = trailingslashit($job_dir) . 'layout.json';

		if (!is_readable($script)) {
			throw new \RuntimeException('Chromium service script not found at ' . $script);
		}

		$cmd = sprintf(
			'%s %s --input %s --out %s --config %s 2>&1',
			escapeshellarg($node),
			escapeshellarg($script),
			escapeshellarg($entry_html),
			escapeshellarg($output),
			escapeshellarg($this->write_config($job_dir, $settings))
		);

		Logger::debug('Spawn CLI', array('cmd' => $cmd));

		$descriptor = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$process = proc_open($cmd, $descriptor, $pipes, $job_dir, $this->child_env($settings));
		if (!is_resource($process)) {
			throw new \RuntimeException('Unable to start Node process.');
		}
		fclose($pipes[0]); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$code = proc_close($process);

		if (0 !== $code) {
			throw new \RuntimeException('Chromium CLI exited with code ' . $code . ': ' . $stdout . $stderr);
		}

		if (!is_readable($output)) {
			throw new \RuntimeException('Chromium CLI did not write output: ' . $stdout);
		}

		$json = json_decode((string) file_get_contents($output), true); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if (!is_array($json)) {
			throw new \RuntimeException('Invalid JSON from Chromium CLI.');
		}
		return $json;
	}

	/**
	 * Call the long-running HTTP service.
	 *
	 * @param string              $entry_html Entry file.
	 * @param string              $job_dir    Working directory.
	 * @param array<string,mixed> $settings   Effective settings.
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException On failure.
	 */
	private function render_http(string $entry_html, string $job_dir, array $settings): array
	{
		$url = trailingslashit((string) ($settings['service_url'] ?? '')) . 'convert';
		$token = (string) ($settings['service_token'] ?? '');

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => max(30, (int) ($settings['render_timeout_ms'] ?? 60000) / 1000 + 15),
				'headers' => array(
					'Content-Type' => 'application/json',
					'Authorization' => $token ? 'Bearer ' . $token : '',
				),
				'body' => wp_json_encode(
					array(
						'inputPath' => $entry_html,
						'outDir' => $job_dir,
						'config' => $this->config_payload($settings),
					)
				),
			)
		);

		if (is_wp_error($response)) {
			throw new \RuntimeException('HTTP render failed: ' . $response->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		if ($code < 200 || $code >= 300) {
			throw new \RuntimeException('HTTP render returned ' . $code . ': ' . $body);
		}
		$json = json_decode($body, true);
		if (!is_array($json)) {
			throw new \RuntimeException('Invalid JSON from HTTP render service.');
		}
		return $json;
	}

	/**
	 * Build a sanitized environment for the spawned Node process.
	 *
	 * Under XAMPP/LAMPP/MAMP the PHP process inherits an LD_LIBRARY_PATH (and
	 * sometimes LD_PRELOAD) that points at bundled libraries which are older
	 * than the system Node binary requires, producing errors such as:
	 *   "node: .../libstdc++.so.6: version `GLIBCXX_3.4.21' not found".
	 * Removing these loader variables lets Node load the correct system
	 * libraries. The behaviour is configurable via settings.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @return array<string,string>|null Environment for proc_open (null = inherit).
	 */
	private function child_env(array $settings): ?array
	{
		$strip = (bool) ($settings['node_strip_env'] ?? true);
		$ld_library_path = trim((string) ($settings['node_ld_library_path'] ?? ''));

		// Nothing to change: inherit the parent environment unmodified.
		if (!$strip && '' === $ld_library_path) {
			return null;
		}

		$env = getenv();
		if (!is_array($env)) {
			$env = array();
		}

		if ($strip) {
			$dynamic_loader_vars = array(
				'LD_LIBRARY_PATH',
				'LD_PRELOAD',
				'DYLD_LIBRARY_PATH',
				'DYLD_INSERT_LIBRARIES',
				'DYLD_FALLBACK_LIBRARY_PATH',
			);
			foreach ($dynamic_loader_vars as $var) {
				unset($env[$var]);
			}
		}

		// Optional explicit override (takes precedence over stripping).
		if ('' !== $ld_library_path) {
			$env['LD_LIBRARY_PATH'] = $ld_library_path;
		}

		// Ensure PATH is always present so the `node` binary can be resolved.
		if (empty($env['PATH'])) {
			$path = getenv('PATH');
			$env['PATH'] = false !== $path && '' !== $path ? $path : '/usr/local/bin:/usr/bin:/bin';
		}

		// Cast all values to strings for proc_open.
		$clean = array();
		foreach ($env as $key => $value) {
			$clean[(string) $key] = (string) $value;
		}
		return $clean;
	}

	/**
	 * Build the config payload passed to the Node service.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @return array<string,mixed>
	 */
	private function config_payload(array $settings): array
	{
		return array(
			'breakpoints' => $settings['breakpoints'] ?? array(),
			'waitUntil' => $settings['wait_until'] ?? 'networkidle0',
			'timeout' => (int) ($settings['render_timeout_ms'] ?? 60000),
			'captureScreenshots' => (bool) ($settings['capture_screenshots'] ?? true),
			'conversionMode' => $settings['conversion_mode'] ?? 'preserve',
			'widgetConfidence' => (int) ($settings['widget_confidence'] ?? 95),
			'debug' => (bool) ($settings['debug'] ?? false),
		);
	}

	/**
	 * Write the config payload to disk for the CLI transport.
	 *
	 * @param string              $job_dir  Working directory.
	 * @param array<string,mixed> $settings Effective settings.
	 * @return string Absolute path to the config file.
	 */
	private function write_config(string $job_dir, array $settings): string
	{
		$path = trailingslashit($job_dir) . 'config.json';
		file_put_contents($path, wp_json_encode($this->config_payload($settings))); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $path;
	}
}
