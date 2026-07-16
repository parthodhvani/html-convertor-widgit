<?php
/**
 * Reconstructs layered (absolute-positioned) layouts generically.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

use HtmlToElementor\Elementor\CssMapper;
use HtmlToElementor\Elementor\ElementId;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Layered Layout Solver — converts any layered visual group (hero, banner, card
 * with overlay) into a single Elementor container using geometry, not class names.
 */
final class LayeredLayoutSolver
{

	public function __construct(private CssMapper $css)
	{
	}

	/**
	 * Build an Elementor container from a layered node.
	 *
	 * @param array<string,mixed>             $node            Source node.
	 * @param callable                          $convert_content fn(node): array<elements>.
	 * @param callable                          $on_container    fn(role): void stats callback.
	 * @return array<string,mixed>|null
	 */
	public function to_container(array $node, callable $convert_content, callable $on_container): ?array
	{
		$layers = $node['layeredLayout'] ?? null;
		if (!is_array($layers)) {
			if (!VisualSignals::is_layered($node)) {
				return null;
			}
			$graph = new SemanticComponentGraph();
			$copy = $node;
			$graph->build(array(array('tree' => &$copy)));
			$layers = $copy['layeredLayout'] ?? null;
		}
		if (!is_array($layers)) {
			return null;
		}

		$box = Geometry::bbox($node);
		$content_elements = array();
		foreach ((array) ($layers['content'] ?? array()) as $content_node) {
			foreach ($convert_content($content_node) as $el) {
				// Preserve absolute insets relative to the layered containing block.
				$content_elements[] = $this->apply_absolute_insets($el, $content_node, $box);
			}
		}
		foreach ((array) ($layers['in_flow'] ?? array()) as $flow_node) {
			foreach ($convert_content($flow_node) as $el) {
				$content_elements[] = $el;
			}
		}

		$settings = array_merge(
			array(
				'content_width' => 'full',
				'flex_direction' => 'column',
				'position' => 'relative',
				'min_height' => array(
					'unit' => 'px',
					'size' => max(120, (float) ($box['height'] ?: ($node['s']['h'] ?? 0))),
				),
			),
			$this->css->background($node),
			$this->css->sizing($node)
		);

		$bg = $layers['background'] ?? null;
		if (is_array($bg)) {
			$src = $this->resolve_image_src($bg);
			if ('' !== $src) {
				$settings['background_background'] = 'classic';
				$settings['background_image'] = array('url' => $src, 'id' => '');
				$settings['background_position'] = 'center center';
				$settings['background_size'] = 'cover';
			} elseif (!empty($bg['s']['bgImg']) && !preg_match('/gradient\s*\(/i', (string) $bg['s']['bgImg'])) {
				$settings['_h2e_layer_bg'] = (string) $bg['s']['bgImg'];
			} else {
				// Keep media frames (e.g. founder photo wrappers) as nested content
				// when they are not a usable background URL.
				foreach ($convert_content($bg) as $el) {
					array_unshift($content_elements, $el);
				}
			}
		}

		$overlay = $layers['overlay'] ?? null;
		if (is_array($overlay)) {
			$overlay_img = (string) ($overlay['s']['bgImg'] ?? '');
			if ('' !== $overlay_img && !preg_match('/gradient\s*\(/i', $overlay_img)) {
				$settings['_h2e_layer_overlay'] = $overlay_img;
			}
			// Absolute decorative layers often carry the hero gradient / fill.
			$grad = (string) ($overlay['s']['bgGrad'] ?? '');
			if ('' === $grad && preg_match('/gradient\s*\(/i', $overlay_img)) {
				$grad = $overlay_img;
			}
			$overlay_bg = strtolower(trim((string) ($overlay['s']['bg'] ?? '')));
			if ('' === $grad && '' !== $overlay_bg && !preg_match('/rgba?\([^)]*,\s*0\s*\)|transparent/i', $overlay_bg)) {
				// Solid overlay wash.
				$settings['background_overlay_background'] = 'classic';
				$settings['background_overlay_color'] = (string) $overlay['s']['bg'];
			}
			if ('' !== $grad) {
				$settings['background_background'] = $settings['background_background'] ?? 'classic';
				$existing = trim((string) ($settings['_h2e_custom_css'] ?? ''), " \t\n\r\0\x0B;");
				$settings['_h2e_custom_css'] = trim($existing . ';background-image:' . $grad, " \t\n\r\0\x0B;");
			}
		}

		// Background layer may also be a gradient div (no <img>).
		if (is_array($bg)) {
			$grad = (string) ($bg['s']['bgGrad'] ?? '');
			$bg_img = (string) ($bg['s']['bgImg'] ?? '');
			if ('' === $grad && preg_match('/gradient\s*\(/i', $bg_img)) {
				$grad = $bg_img;
			}
			if ('' !== $grad && empty($settings['background_image']['url'])) {
				$existing = trim((string) ($settings['_h2e_custom_css'] ?? ''), " \t\n\r\0\x0B;");
				$settings['_h2e_custom_css'] = trim($existing . ';background-image:' . $grad, " \t\n\r\0\x0B;");
			}
		}

		$on_container('layered_block');

		return array(
			'id' => ElementId::generate(),
			'elType' => 'container',
			'settings' => $settings,
			'elements' => array_values($content_elements),
			'isInner' => false,
		);
	}

	/**
	 * Apply absolute positioning offsets from the source IR onto an emitted element.
	 *
	 * @param array<string,mixed>            $el          Emitted Elementor element.
	 * @param array<string,mixed>            $source      Source IR node.
	 * @param array{x:float,y:float,width:float,height:float} $parent_box Parent bbox.
	 * @return array<string,mixed>
	 */
	private function apply_absolute_insets(array $el, array $source, array $parent_box): array
	{
		$pos = strtolower((string) ($source['s']['pos'] ?? ''));
		if (!in_array($pos, array('absolute', 'fixed'), true)) {
			return $el;
		}
		$settings = (array) ($el['settings'] ?? array());
		$settings['position'] = 'absolute' === $pos ? 'absolute' : 'fixed';

		$child = Geometry::bbox($source);
		$top = $child['y'] - $parent_box['y'];
		$left = $child['x'] - $parent_box['x'];
		$width = $child['width'];
		$height = $child['height'];

		if ($top >= 0) {
			$settings['top'] = array('unit' => 'px', 'size' => round($top));
		}
		if ($left >= 0) {
			$settings['_offset_x'] = array('unit' => 'px', 'size' => round($left));
			$settings['left'] = array('unit' => 'px', 'size' => round($left));
		}
		if ($width > 0) {
			$settings['width'] = array('unit' => 'px', 'size' => round($width));
		}
		if ($height > 0) {
			$settings['min_height'] = array('unit' => 'px', 'size' => round($height));
		}
		if (isset($source['s']['z']) && is_numeric($source['s']['z'])) {
			$settings['z_index'] = (int) $source['s']['z'];
		}

		$el['settings'] = $settings;
		return $el;
	}

	/**
	 * Resolve an image URL from a node or its descendants.
	 *
	 * @param array<string,mixed> $node Node.
	 */
	private function resolve_image_src(array $node): string
	{
		$src = (string) ($node['src'] ?? '');
		if ('' !== $src) {
			return $src;
		}
		foreach ((array) ($node['children'] ?? array()) as $child) {
			if (!is_array($child)) {
				continue;
			}
			$found = $this->resolve_image_src($child);
			if ('' !== $found) {
				return $found;
			}
		}
		$bg = (string) ($node['s']['bgImg'] ?? '');
		if ('' !== $bg && !preg_match('/gradient\s*\(/i', $bg) && preg_match('/url\(["\']?([^"\')]+)["\']?\)/', $bg, $m)) {
			return $m[1];
		}
		return '';
	}
}
