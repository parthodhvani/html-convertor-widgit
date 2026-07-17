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

		$content_elements = array();
		foreach ((array) ($layers['content'] ?? array()) as $content_node) {
			foreach ($convert_content($content_node) as $el) {
				$content_elements[] = $el;
			}
		}
		foreach ((array) ($layers['in_flow'] ?? array()) as $flow_node) {
			foreach ($convert_content($flow_node) as $el) {
				$content_elements[] = $el;
			}
		}

		$box = Geometry::bbox($node);
		$settings = array_merge(
			array(
				'content_width' => 'full',
				'flex_direction' => 'column',
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
			} elseif (!empty($bg['s']['bgImg']) && preg_match('/gradient\s*\(/i', (string) $bg['s']['bgImg'])) {
				// Nested gradient backgrounds — map via CssMapper.
				$settings = array_merge($settings, $this->css->background($bg));
			} elseif (!empty($bg['s']['bgImg'])) {
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
		if (is_array($overlay) && !empty($overlay['s']['bgImg'])) {
			$overlay_img = (string) $overlay['s']['bgImg'];
			if (preg_match('/gradient\s*\(/i', $overlay_img)) {
				$parsed = $this->css->parse_gradient($overlay_img);
				if (null !== $parsed) {
					$grad = $this->css->elementor_gradient_settings($parsed);
					$settings['background_overlay_background'] = 'gradient';
					$settings['background_overlay_color'] = $grad['background_color'] ?? '';
					$settings['background_overlay_color_b'] = $grad['background_color_b'] ?? '';
					$settings['background_overlay_gradient_type'] = $grad['background_gradient_type'] ?? 'linear';
					if (isset($grad['background_gradient_angle'])) {
						$settings['background_overlay_gradient_angle'] = $grad['background_gradient_angle'];
					}
					if (isset($grad['background_gradient_position'])) {
						$settings['background_overlay_gradient_position'] = $grad['background_gradient_position'];
					}
				}
			} else {
				$settings['_h2e_layer_overlay'] = $overlay_img;
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
