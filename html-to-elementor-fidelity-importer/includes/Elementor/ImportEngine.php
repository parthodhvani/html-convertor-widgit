<?php
/**
 * Imports generated Elementor data into a WordPress page.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

use HtmlToElementor\Support\Logger;
use HtmlToElementor\Support\Settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Creates or updates a WordPress page carrying Elementor container data,
 * sideloads referenced media into the library, stores the source stylesheet for
 * front-end fidelity, and registers global colours.
 */
final class ImportEngine
{

	/**
	 * Cache of sideloaded media keyed by source URL.
	 *
	 * @var array<string,array{id:int,url:string}>
	 */
	private array $media_cache = array();

	/**
	 * Last media import counters for the conversion report.
	 *
	 * @var array{attempted:int,imported:int,failed:int,skipped:int}
	 */
	private array $media_stats = array(
		'attempted' => 0,
		'imported' => 0,
		'failed' => 0,
		'skipped' => 0,
	);

	/**
	 * Create / update an Elementor page from generated data.
	 *
	 * @param array<int,array<string,mixed>> $data Elementor _elementor_data array.
	 * @param array<string,mixed>            $args { title, status, page_id, assets, tokens, base_dir, import_media, inject_source_assets, inject_source_js, apply_global_colors }.
	 * @return int Post ID.
	 *
	 * @throws \RuntimeException When the page cannot be created.
	 */
	public function import(array $data, array $args = array()): int
	{
		$this->media_stats = array(
			'attempted' => 0,
			'imported' => 0,
			'failed' => 0,
			'skipped' => 0,
		);

		$title = (string) ($args['title'] ?? 'Imported Page');
		$status = (string) ($args['status'] ?? 'draft');
		$existing = (int) ($args['page_id'] ?? 0);

		$postarr = array(
			'post_title' => $title,
			'post_status' => $status,
			'post_type' => 'page',
			'post_content' => '',
		);
		if ($existing > 0) {
			$postarr['ID'] = $existing;
			$post_id = wp_update_post($postarr, true);
		} else {
			$post_id = wp_insert_post($postarr, true);
		}
		if (is_wp_error($post_id)) {
			throw new \RuntimeException('Failed to create page: ' . $post_id->get_error_message());
		}
		$post_id = (int) $post_id;

		// Honor per-request override from the admin form; fall back to saved settings.
		$import_media = array_key_exists('import_media', $args)
			? (bool) $args['import_media']
			: (bool) Settings::get('import_media', true);
		if ($import_media) {
			$data = $this->import_media($data, $post_id, (string) ($args['base_dir'] ?? ''));
		}

		$data = $this->resolve_nav_menus($data, $title);   // NEW


		$this->apply_elementor_meta($post_id, $data);

		$inject_assets = array_key_exists('inject_source_assets', $args)
			? (bool) $args['inject_source_assets']
			: (bool) Settings::get('inject_source_assets', true);
		$inject_js = array_key_exists('inject_source_js', $args)
			? (bool) $args['inject_source_js']
			: (bool) Settings::get('inject_source_js', false);
		$this->store_source_assets(
			$post_id,
			(array) ($args['assets'] ?? array()),
			$data,
			$inject_assets,
			$inject_js
		);

		$apply_colors = array_key_exists('apply_global_colors', $args)
			? (bool) $args['apply_global_colors']
			: (bool) Settings::get('apply_global_colors', true);
		if ($apply_colors) {
			$this->apply_global_colors((array) ($args['tokens'] ?? array()));
		}

		Logger::debug(
			'Imported page',
			array(
				'post_id' => $post_id,
				'elements' => count($data),
				'media' => $this->media_stats,
			)
		);
		return $post_id;
	}

	/**
	 * Recursively replace placeholder nav-menu widgets (produced by
	 * CompositePatternBuilder::try_nav_menu) with a real WP menu id.
	 *
	 * @param array<int,array<string,mixed>> $elements    Elementor elements tree.
	 * @param string                          $page_title Fallback menu name.
	 * @return array<int,array<string,mixed>>
	 */
	private function resolve_nav_menus(array $elements, string $page_title): array
	{
		foreach ($elements as &$el) {
			if (!is_array($el)) {
				continue;
			}
			if ('nav-menu' === ($el['widgetType'] ?? '') && !empty($el['settings']['_h2e_nav_items'])) {
				$items = (array) $el['settings']['_h2e_nav_items'];
				$el['settings']['menu'] = (string) $this->get_or_create_nav_menu($page_title . ' Menu', $items);
				unset($el['settings']['_h2e_nav_items']);
			}
			if (!empty($el['elements']) && is_array($el['elements'])) {
				$el['elements'] = $this->resolve_nav_menus($el['elements'], $page_title);
			}
		}
		unset($el);
		return $elements;
	}

	/**
	 * Reuse an existing WP menu with this name, or create one from items.
	 *
	 * @param string                                       $name  Menu name.
	 * @param array<int,array{title:string,url:string}>     $items Link items.
	 */
	private function get_or_create_nav_menu(string $name, array $items): int
	{
		$existing = wp_get_nav_menu_object($name);
		if ($existing instanceof \WP_Term) {
			return (int) $existing->term_id;
		}
		$menu_id = wp_create_nav_menu($name);
		if (is_wp_error($menu_id)) {
			return 0;
		}
		foreach ($items as $i => $item) {
			wp_update_nav_menu_item(
				(int) $menu_id,
				0,
				array(
					'menu-item-title' => (string) ($item['title'] ?? ''),
					'menu-item-url' => (string) ($item['url'] ?? ''),
					'menu-item-status' => 'publish',
					'menu-item-position' => $i + 1,
				)
			);
		}
		return (int) $menu_id;
	}
	/**
	 * Media sideload counters from the last import().
	 *
	 * @return array{attempted:int,imported:int,failed:int,skipped:int}
	 */
	public function media_stats(): array
	{
		return $this->media_stats;
	}

	/**
	 * Persist the Elementor meta and flush its CSS cache.
	 *
	 * @param int                            $post_id Target post.
	 * @param array<int,array<string,mixed>> $data    Elementor data.
	 */
	private function apply_elementor_meta(int $post_id, array $data): void
	{
		$json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		update_post_meta($post_id, '_elementor_data', wp_slash($json));
		update_post_meta($post_id, '_elementor_edit_mode', 'builder');
		update_post_meta($post_id, '_elementor_template_type', 'wp-page');
		update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
		update_post_meta($post_id, '_wp_page_template', 'elementor_canvas');
		update_post_meta($post_id, '_h2e_imported', 1);
		update_post_meta($post_id, '_h2e_imported_at', gmdate('c'));

		if (did_action('elementor/loaded') && class_exists('\Elementor\Plugin')) {
			try {
				$document = \Elementor\Plugin::$instance->documents->get($post_id);
				if ($document) {
					$document->save_type();
				}
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch (\Throwable $e) {
				Logger::error('Elementor cache flush failed', array('error' => $e->getMessage()));
			}
		}
	}

	/**
	 * Store the source CSS/JS/links so the front end can reproduce styling the
	 * native controls do not cover (Phase: fidelity safety net, no HTML widget).
	 *
	 * Also emits per-element `_h2e_custom_css` declarations scoped to
	 * `.elementor-element-{id}` so transforms/object-fit/grid tracks are not lost.
	 *
	 * @param int                            $post_id        Target post.
	 * @param array<string,mixed>            $assets         Asset bundle from the renderer.
	 * @param array<int,array<string,mixed>> $data           Elementor tree (optional).
	 * @param bool                           $inject_assets  Whether to store CSS.
	 * @param bool                           $inject_js      Whether to store JS.
	 */
	private function store_source_assets(
		int $post_id,
		array $assets,
		array $data = array(),
		bool $inject_assets = true,
		bool $inject_js = false
	): void {
		if (!$inject_assets) {
			return;
		}
		$css = (string) ($assets['combinedCss'] ?? '');
		$generated = $this->collect_element_custom_css($data);
		if ('' !== $generated) {
			$css = rtrim($css) . "\n\n/* h2e element custom css */\n" . $generated;
		}
		update_post_meta($post_id, '_h2e_source_css', $css);
		update_post_meta(
			$post_id,
			'_h2e_source_links',
			array_values($this->filter_icon_font_stylesheets((array) ($assets['stylesheets'] ?? array())))
		);		if ($inject_js) {
			update_post_meta($post_id, '_h2e_source_js', (string) ($assets['combinedJs'] ?? ''));
		}
	}
	/**
	 * Drop icon-font stylesheets (Font Awesome, etc.) from the preserved
	 * source <link> list. Elementor's Icon widget renders icons natively
	 * (inline SVG); re-including the original icon-font CSS only causes
	 * its unscoped `.fa-xxx:before` rules to double-paint on top of
	 * Elementor's own icon markup, which also carries the fa-* classes.
	 *
	 * @param array<int,string> $links Stylesheet URLs.
	 * @return array<int,string>
	 */
	private function filter_icon_font_stylesheets(array $links): array
	{
		return array_filter(
			$links,
			static function ($href): bool {
				$href = strtolower((string) $href);
				return '' !== $href
					&& !preg_match('/font-?awesome|fontawesome|\/fa-|fa[- ]?icons?/i', $href);
			}
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elementor elements.
	 */
	private function collect_element_custom_css(array $elements): string
	{
		$rules = array();
		$this->walk_custom_css($elements, $rules);
		return implode("\n", $rules);
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param array<int,string>              $rules    CSS rules (by ref).
	 */
	private function walk_custom_css(array $elements, array &$rules): void
	{
		foreach ($elements as $el) {
			if (!is_array($el)) {
				continue;
			}
			$id = (string) ($el['id'] ?? '');
			$css = trim((string) ($el['settings']['_h2e_custom_css'] ?? ''), " \t\n\r\0\x0B;");
			if ('' !== $id && '' !== $css) {
				// Image object-fit targets the img inside the widget wrapper.
				$selector = '.elementor-element-' . $id;
				if ('image' === ($el['widgetType'] ?? '')) {
					$selector .= ' img';
				}
				$rules[] = $selector . '{' . $css . ';}';
			}
			if (!empty($el['elements']) && is_array($el['elements'])) {
				$this->walk_custom_css($el['elements'], $rules);
			}
		}
	}

	/**
	 * Recursively sideload image / background-image URLs into the media library.
	 *
	 * @param array<int,array<string,mixed>> $data     Elementor data.
	 * @param int                            $post_id  Parent post.
	 * @param string                         $base_dir Directory of the source HTML (for local refs).
	 * @return array<int,array<string,mixed>>
	 */
	private function import_media(array $data, int $post_id, string $base_dir): array
	{
		$this->require_media_libs();
		foreach ($data as &$element) {
			$element = $this->import_media_element($element, $post_id, $base_dir);
		}
		unset($element);
		return $data;
	}

	/**
	 * @param array<string,mixed> $element  Element.
	 * @param int                 $post_id  Parent post.
	 * @param string              $base_dir Source dir.
	 * @return array<string,mixed>
	 */
	private function import_media_element(array $element, int $post_id, string $base_dir): array
	{
		if (isset($element['settings']) && is_array($element['settings'])) {
			$s = $element['settings'];

			if (isset($s['image']['url']) && '' !== (string) $s['image']['url']) {
				++$this->media_stats['attempted'];
				$media = $this->sideload((string) $s['image']['url'], $post_id, $base_dir);
				if (null !== $media) {
					$s['image']['url'] = $media['url'];
					$s['image']['id'] = $media['id'];
					++$this->media_stats['imported'];
				} elseif (0 === strpos((string) $s['image']['url'], 'data:')) {
					++$this->media_stats['skipped'];
				} else {
					++$this->media_stats['failed'];
				}
			}
			if (isset($s['background_image']['url']) && '' !== (string) $s['background_image']['url']) {
				++$this->media_stats['attempted'];
				$media = $this->sideload((string) $s['background_image']['url'], $post_id, $base_dir);
				if (null !== $media) {
					$s['background_image']['url'] = $media['url'];
					$s['background_image']['id'] = $media['id'];
					++$this->media_stats['imported'];
				} elseif (0 === strpos((string) $s['background_image']['url'], 'data:')) {
					++$this->media_stats['skipped'];
				} else {
					++$this->media_stats['failed'];
				}
			}
			$element['settings'] = $s;
		}

		if (isset($element['elements']) && is_array($element['elements'])) {
			foreach ($element['elements'] as &$child) {
				$child = $this->import_media_element($child, $post_id, $base_dir);
			}
			unset($child);
		}
		return $element;
	}

	/**
	 * Sideload a single asset, returning its new URL + attachment id.
	 *
	 * @param string $url      Source URL or local path.
	 * @param int    $post_id  Parent post.
	 * @param string $base_dir Source dir for relative/local refs.
	 * @return array{id:int,url:string}|null
	 */
	private function sideload(string $url, int $post_id, string $base_dir): ?array
	{
		if (isset($this->media_cache[$url])) {
			return $this->media_cache[$url];
		}
		// Skip data URIs and already-local media.
		if (0 === strpos($url, 'data:')) {
			return null;
		}
		$uploads = wp_get_upload_dir();
		if (false !== strpos($url, $uploads['baseurl'])) {
			return null;
		}

		try {
			$attachment_id = 0;

			if (preg_match('#^https?://#i', $url)) {
				$tmp = download_url($url, 30);
				if (is_wp_error($tmp)) {
					return null;
				}
				$attachment_id = $this->handle_file($url, $tmp, $post_id);
			} else {
				// Local path (file:// or relative inside an uploaded package).
				$path = $this->resolve_local($url, $base_dir);
				if (null === $path) {
					return null;
				}
				$tmp = wp_tempnam(basename($path));
				if (!@copy($path, $tmp)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return null;
				}
				$attachment_id = $this->handle_file($path, $tmp, $post_id);
			}

			if ($attachment_id <= 0) {
				return null;
			}
			$result = array(
				'id' => $attachment_id,
				'url' => (string) wp_get_attachment_url($attachment_id),
			);
			$this->media_cache[$url] = $result;
			return $result;
		} catch (\Throwable $e) {
			Logger::error('Media sideload failed', array('url' => $url, 'error' => $e->getMessage()));
			return null;
		}
	}

	/**
	 * Move a downloaded temp file into the media library.
	 *
	 * @param string $name_source Source path/URL (for the filename + extension).
	 * @param string $tmp         Temp file path.
	 * @param int    $post_id     Parent post.
	 * @return int Attachment ID (0 on failure).
	 */
	private function handle_file(string $name_source, string $tmp, int $post_id): int
	{
		$name = preg_replace('/\?.*$/', '', basename(parse_url($name_source, PHP_URL_PATH) ?: $name_source));
		if ('' === (string) $name) {
			$name = 'image-' . substr(md5($name_source), 0, 8) . '.jpg';
		}
		$file_array = array(
			'name' => sanitize_file_name((string) $name),
			'tmp_name' => $tmp,
		);
		$id = media_handle_sideload($file_array, $post_id);
		if (is_wp_error($id)) {
			@unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Resolve a local/relative asset reference to an absolute path within base.
	 *
	 * @param string $url      URL/path.
	 * @param string $base_dir Source HTML directory.
	 * @return string|null
	 */
	private function resolve_local(string $url, string $base_dir): ?string
	{
		$raw = trim($url);
		if ('' === $raw) {
			return null;
		}

		// Chromium emits absolute file:// URLs for package-relative assets.
		if (0 === stripos($raw, 'file:')) {
			$raw = preg_replace('#^file://#i', '', $raw) ?? $raw;
			// file:///path → /path; file://localhost/path → /path
			$raw = preg_replace('#^localhost#i', '', $raw) ?? $raw;
			$raw = rawurldecode($raw);
		}

		if ('' !== $raw && '/' === $raw[0] && is_readable($raw)) {
			return $raw;
		}

		if ('' !== $base_dir) {
			$base_real = realpath($base_dir);
			if (false === $base_real) {
				return null;
			}
			$candidate = trailingslashit($base_dir) . ltrim(str_replace('\\', '/', $raw), '/');
			$real = realpath($candidate);
			if (false !== $real && 0 === strpos($real, $base_real) && is_readable($real)) {
				return $real;
			}
		}
		return null;
	}

	/**
	 * Register extracted brand colours as Elementor global custom colours.
	 *
	 * @param array<string,mixed> $tokens Design tokens.
	 */
	private function apply_global_colors(array $tokens): void
	{
		$palette = array_values(array_filter((array) ($tokens['palette'] ?? array())));
		if (empty($palette) || !class_exists('\Elementor\Plugin')) {
			return;
		}
		try {
			$kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();
			if (!$kit_id) {
				return;
			}
			$settings = get_post_meta($kit_id, '_elementor_page_settings', true);
			$settings = is_array($settings) ? $settings : array();
			$custom = isset($settings['custom_colors']) && is_array($settings['custom_colors']) ? $settings['custom_colors'] : array();

			$existing = array_column($custom, 'color');
			$labels = array('Imported Primary', 'Imported Secondary', 'Imported Accent', 'Imported Extra');
			foreach ($palette as $i => $color) {
				if (in_array($color, $existing, true)) {
					continue;
				}
				$custom[] = array(
					'_id' => substr(md5($color . $i), 0, 7),
					'title' => $labels[$i] ?? ('Imported ' . ($i + 1)),
					'color' => $color,
				);
			}
			$settings['custom_colors'] = $custom;
			update_post_meta($kit_id, '_elementor_page_settings', $settings);
		} catch (\Throwable $e) {
			Logger::error('Global colour apply failed', array('error' => $e->getMessage()));
		}
	}

	/**
	 * Ensure WordPress media-handling functions are loaded.
	 */
	private function require_media_libs(): void
	{
		if (!function_exists('media_handle_sideload')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
