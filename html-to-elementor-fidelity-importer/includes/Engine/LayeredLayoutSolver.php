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
		$has_absolute_layer = false;
		foreach ((array) ($layers['content'] ?? array()) as $content_node) {
			if (!is_array($content_node)) {
				continue;
			}
			$inner = array();
			foreach ($convert_content($content_node) as $el) {
				$inner[] = $el;
			}
			if ($this->is_absolute_layer($content_node)) {
				// Preserve the browser-solved absolute wrapper. Flattening children
				// into the parent flex column drops left/top (e.g. hero .content at
				// left:10% / top:35%) and collapses layered heroes to ~60% pixels.
				$has_absolute_layer = true;
				$content_elements[] = $this->absolute_layer_container($content_node, $node, $inner);
				continue;
			}
			foreach ($inner as $el) {
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
		// Absolute children need a positioned containing block (browser default
		// for position:relative ancestors). Elementor containers are relative in
		// preview CSS, but native editor requires an explicit position.
		if ($has_absolute_layer && empty($settings['position'])) {
			$settings['position'] = 'relative';
		}

		$bg = $layers['background'] ?? null;
		if (is_array($bg)) {
			$src = $this->resolve_image_src($bg);
			// Framed / organic media (asymmetric radius, overflow clip) must stay
			// nested content — promoting to background_image loses shape + badges.
			if ('' !== $src && !$this->is_framed_media($bg)) {
				$settings['background_background'] = 'classic';
				$settings['background_image'] = array('url' => $src, 'id' => '');
				$settings['background_position'] = 'center center';
				$settings['background_size'] = 'cover';
			} elseif (!empty($bg['s']['bgImg']) && preg_match('/gradient\s*\(/i', (string) $bg['s']['bgImg'])) {
				// Nested gradient backgrounds — map via CssMapper.
				$settings = array_merge($settings, $this->css->background($bg));
			} elseif (!empty($bg['s']['bgImg']) && '' === $src) {
				$settings['_h2e_layer_bg'] = (string) $bg['s']['bgImg'];
			} else {
				// Keep media frames (e.g. founder photo wrappers) as nested
				// containers so elliptical radius / overflow clip survive —
				// convert_content alone would hoist the bare <img>.
				$frame_children = array();
				foreach ($convert_content($bg) as $el) {
					$frame_children[] = $el;
				}
				if ($this->is_framed_media($bg) || VisualSignals::has_clip_shape($bg['s'] ?? array())) {
					$frame_settings = $this->css->combine(
						array(
							'content_width' => 'full',
							'flex_direction' => 'column',
						),
						$this->css->background($bg),
						$this->css->border($bg),
						$this->css->box_shadow($bg),
						$this->css->sizing($bg),
						$this->css->effects($bg)
					);
					$cls = trim((string) ($bg['cls'] ?? ''));
					if ('' !== $cls) {
						$frame_settings['_css_classes'] = sanitize_html_class(
							preg_replace('/\s+/', ' ', $cls) ?? $cls
						);
						// Preserve multi-class tokens Elementor can still use.
						$frame_settings['_css_classes'] = implode(
							' ',
							array_filter(array_map('sanitize_html_class', preg_split('/\s+/', $cls) ?: array()))
						);
					}
					array_unshift($content_elements, array(
						'id' => ElementId::generate(),
						'elType' => 'container',
						'settings' => $frame_settings,
						'elements' => array_values($frame_children),
						'isInner' => true,
					));
				} else {
					foreach ($frame_children as $el) {
						array_unshift($content_elements, $el);
					}
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
	 * Whether a content layer is browser-absolute / fixed.
	 *
	 * @param array<string,mixed> $node Layer node.
	 */
	private function is_absolute_layer(array $node): bool
	{
		$pos = strtolower((string) ($node['s']['pos'] ?? ''));
		return in_array($pos, array('absolute', 'fixed'), true);
	}

	/**
	 * Emit an absolute/fixed content layer as a positioned Elementor container.
	 *
	 * Offsets come from browser bbox relative to the layered parent — not from
	 * reconstructing flex alignment. getComputedStyle fills all four insets when
	 * only two were authored; using bbox left/top avoids over-constraint.
	 *
	 * @param array<string,mixed>            $layer    Absolute content node.
	 * @param array<string,mixed>            $parent   Layered parent node.
	 * @param array<int,array<string,mixed>> $children Already-converted children.
	 * @return array<string,mixed>
	 */
	private function absolute_layer_container(array $layer, array $parent, array $children): array
	{
		$pos = strtolower((string) ($layer['s']['pos'] ?? 'absolute'));
		$settings = $this->css->combine(
			array(
				'content_width' => 'full',
				'flex_direction' => 'column',
				'position' => 'fixed' === $pos ? 'fixed' : 'absolute',
			),
			$this->css->background($layer),
			$this->css->border($layer),
			$this->css->box_shadow($layer),
			$this->css->sizing($layer),
			$this->css->spacing($layer, true),
			$this->css->effects($layer)
		);

		$parent_box = Geometry::bbox($parent);
		$box = Geometry::bbox($layer);
		$left = round($box['x'] - $parent_box['x'], 2);
		$top = round($box['y'] - $parent_box['y'], 2);

		$settings['left'] = array('unit' => 'px', 'size' => $left);
		$settings['top'] = array('unit' => 'px', 'size' => $top);
		$settings['offset_x'] = $settings['left'];
		$settings['offset_y'] = $settings['top'];
		$settings['_offset_orientation_h'] = 'start';
		$settings['_offset_orientation_v'] = 'start';
		unset($settings['right'], $settings['bottom']);

		if ($box['width'] > 0) {
			$settings['width'] = array('unit' => 'px', 'size' => round($box['width'], 2));
			$settings['flex_grow'] = 0;
			$settings['flex_shrink'] = 0;
		}
		if ($box['height'] > 0 && empty($settings['min_height']['size'])) {
			$settings['min_height'] = array('unit' => 'px', 'size' => round($box['height'], 2));
		}

		$z = $layer['s']['z'] ?? $layer['s']['zIndex'] ?? null;
		if (is_numeric($z)) {
			$settings['z_index'] = (int) $z;
		}

		$cls = trim((string) ($layer['cls'] ?? ''));
		if ('' !== $cls) {
			$settings['_css_classes'] = implode(
				' ',
				array_filter(array_map('sanitize_html_class', preg_split('/\s+/', $cls) ?: array()))
			);
		}

		// Children already carry leaf paint; strip duplicate absolute offsets so
		// the wrapper is the single positioning context.
		$children = $this->strip_absolute_offsets($children);

		return array(
			'id' => ElementId::generate(),
			'elType' => 'container',
			'settings' => $settings,
			'elements' => array_values($children),
			'isInner' => true,
		);
	}

	/**
	 * Remove absolute offsets from nested widgets/containers (wrapper owns them).
	 *
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<int,array<string,mixed>>
	 */
	private function strip_absolute_offsets(array $elements): array
	{
		foreach ($elements as $i => $el) {
			if (!is_array($el)) {
				continue;
			}
			$settings = (array) ($el['settings'] ?? array());
			$pos = strtolower((string) ($settings['position'] ?? ''));
			if (in_array($pos, array('absolute', 'fixed'), true)) {
				unset(
					$settings['position'],
					$settings['left'],
					$settings['right'],
					$settings['top'],
					$settings['bottom'],
					$settings['offset_x'],
					$settings['offset_y'],
					$settings['_offset_orientation_h'],
					$settings['_offset_orientation_v']
				);
				$el['settings'] = $settings;
			}
			if (!empty($el['elements']) && is_array($el['elements'])) {
				$el['elements'] = $this->strip_absolute_offsets($el['elements']);
			}
			$elements[$i] = $el;
		}
		return $elements;
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

	/**
	 * Media wrapped in a decorative frame should not become a section background.
	 *
	 * @param array<string,mixed> $node Background candidate.
	 */
	private function is_framed_media(array $node): bool
	{
		$cls = strtolower((string) ($node['cls'] ?? ''));
		if (preg_match('/\b(founder-frame|media-frame|image-frame|avatar-frame|photo-frame)\b/', $cls)) {
			return true;
		}

		if (VisualSignals::has_clip_shape($node['s'] ?? array())) {
			return true;
		}

		$brad = $node['s']['brad'] ?? null;
		if (is_array($brad)) {
			$vals = array_map('floatval', array_values($brad));
			if (count($vals) >= 2) {
				$max = max($vals);
				$min = min($vals);
				// Organic / blob radii differ by corner.
				if ($max >= 24 && ($max - $min) >= 8) {
					return true;
				}
			}
		}

		$w = (float) ($node['s']['w'] ?? 0);
		// Distinct portrait frames are usually narrower than a full hero column.
		if ($w > 0 && $w <= 520 && preg_match('/\b(frame|portrait|founder)\b/', $cls)) {
			return true;
		}

		return false;
	}
}
