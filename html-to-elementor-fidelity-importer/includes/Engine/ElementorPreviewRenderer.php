<?php
/**
 * Renders Elementor JSON as approximate HTML for closed-loop Chromium compare.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Engine;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Elementor Preview Renderer — produces a flex-based HTML approximation of
 * generated Elementor data so Chromium can screenshot it without WordPress.
 *
 * This is intentionally a fidelity oracle, not a full Elementor runtime.
 */
final class ElementorPreviewRenderer implements EngineInterface
{

	public function name(): string
	{
		return 'elementor_preview_renderer';
	}

	/**
	 * Build a full HTML document from Elementor _elementor_data.
	 *
	 * @param array<int,array<string,mixed>> $elements Elementor tree.
	 * @param array<string,mixed>            $opts     { title, width, css }.
	 */
	public function render(array $elements, array $opts = array()): string
	{
		$title = htmlspecialchars((string) ($opts['title'] ?? 'H2E Preview'), ENT_QUOTES);
		$width = (int) ($opts['width'] ?? 1440);
		$extra_css = (string) ($opts['css'] ?? '');
		$page = is_array($opts['page'] ?? null) ? $opts['page'] : array();
		$body_bg = trim((string) ($page['backgroundColor'] ?? $page['background_color'] ?? ''));
		$body_color = trim((string) ($page['color'] ?? ''));
		if ('' === $body_bg) {
			foreach ($elements as $el) {
				if (!is_array($el)) {
					continue;
				}
				$s = (array) ($el['settings'] ?? array());
				$candidate = (string) ($s['background_color'] ?? '');
				// Skip translucent sticky headers (rgba with alpha < 1) — prefer
				// a solid page canvas so the preview matches meta.page / body.
				if ('' === $candidate || false !== stripos($candidate, 'rgba(0, 0, 0, 0)')) {
					continue;
				}
				if (preg_match('/rgba\s*\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*,\s*(0?\.\d+|0)\s*\)/i', $candidate)) {
					continue;
				}
				$body_bg = $candidate;
				break;
			}
		}
		$body_style = array();
		if ('' !== $body_bg) {
			$body_style[] = 'background:' . $body_bg;
		}
		if ('' !== $body_color) {
			$body_style[] = 'color:' . $body_color;
		}
		$body_attr = empty($body_style) ? '' : ' style="' . htmlspecialchars(implode(';', $body_style), ENT_QUOTES) . '"';
		$body = '';
		foreach ($elements as $el) {
			if (is_array($el)) {
				$body .= $this->render_element($el);
			}
		}

		$fonts = $this->collect_font_families($elements);
		$font_link = $this->google_fonts_link($fonts);
		$font_stack = !empty($fonts)
			? implode(', ', array_map(static fn($f) => '"' . str_replace('"', '', $f) . '"', $fonts)) . ', system-ui, sans-serif'
			: 'system-ui, sans-serif';

		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title}</title>
{$font_link}
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{width:{$width}px;max-width:100%;font-family:{$font_stack}}
/* Neutralize UA heading/body defaults so mapped typography on .e-widget wins. */
h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit;line-height:inherit;letter-spacing:inherit;margin:0;padding:0}
p,ul,ol,figure,blockquote{margin:0}
.e-con{display:flex;flex-direction:column;width:100%;position:relative}
.e-con.e-con-full{width:100%}
.e-con[style*="display:grid"] > .e-con,
.e-con[style*="display: grid"] > .e-con{width:auto !important;max-width:100% !important;min-width:0}
.e-con img{max-width:100%;height:auto;display:block}
.e-widget{max-width:100%}
.e-con[style*="flex-direction:column"] > .e-widget{width:100%}
.e-widget-heading{margin:0}
.e-widget-text p{margin:0}
.e-widget-text a{color:inherit;text-decoration:none}
.e-widget-icon{display:inline-flex;align-items:center;justify-content:center;line-height:1}
.e-widget-button a{
  display:inline-flex;align-items:center;justify-content:center;
  text-decoration:none;padding:0;border-radius:0;background:transparent
}
{$extra_css}
</style>
</head>
<body{$body_attr}>
{$body}
</body>
</html>
HTML;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Tree.
	 * @return array<int,string>
	 */
	private function collect_font_families(array $elements): array
	{
		$fonts = array();
		$walk = function ($els) use (&$walk, &$fonts): void {
			foreach ($els as $el) {
				if (!is_array($el)) {
					continue;
				}
				$s = (array) ($el['settings'] ?? array());
				$family = trim((string) ($s['typography_font_family'] ?? ''));
				if ('' !== $family) {
					$first = trim(explode(',', $family)[0], " \t\"'");
					if ('' !== $first && !in_array($first, $fonts, true)) {
						$fonts[] = $first;
					}
				}
				$walk((array) ($el['elements'] ?? array()));
			}
		};
		$walk($elements);
		return $fonts;
	}

	/**
	 * @param array<int,string> $fonts Font family names.
	 */
	private function google_fonts_link(array $fonts): string
	{
		$allowed = array(
			'Inter' => 'Inter:wght@400;500;600;700',
			'Playfair Display' => 'Playfair+Display:wght@400;600;700',
			'Roboto' => 'Roboto:wght@400;500;700',
			'Open Sans' => 'Open+Sans:wght@400;600;700',
			'Lato' => 'Lato:wght@400;700',
			'Montserrat' => 'Montserrat:wght@400;600;700',
			'Poppins' => 'Poppins:wght@400;500;600;700',
			'Merriweather' => 'Merriweather:wght@400;700',
		);
		$families = array();
		foreach ($fonts as $font) {
			if (isset($allowed[$font])) {
				$families[] = $allowed[$font];
			}
		}
		if (empty($families)) {
			// Petra/marketing pages almost always need these two.
			$families = array($allowed['Inter'], $allowed['Playfair Display']);
		}
		$query = implode('&family=', array_map('rawurlencode', $families));
		// rawurlencode would encode colons — build manually.
		$family_q = implode('&family=', $families);
		$url = 'https://fonts.googleapis.com/css2?family=' . $family_q . '&display=swap';
		return '<link rel="preconnect" href="https://fonts.googleapis.com">'
			. '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
			. '<link href="' . htmlspecialchars($url, ENT_QUOTES) . '" rel="stylesheet">';
	}

	/**
	 * @param array<string,mixed> $el Element.
	 */
	private function render_element(array $el): string
	{
		$type = (string) ($el['elType'] ?? '');
		if ('container' === $type) {
			return $this->render_container($el);
		}
		if ('widget' === $type) {
			return $this->render_widget($el);
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $el Container.
	 */
	private function render_container(array $el): string
	{
		$s = (array) ($el['settings'] ?? array());
		$id = htmlspecialchars((string) ($el['id'] ?? ''), ENT_QUOTES);
		$style = $this->container_style($s);
		$cls = 'e-con e-con-full elementor-element-' . $id;
		if (!empty($s['_css_classes'])) {
			$cls .= ' ' . htmlspecialchars((string) $s['_css_classes'], ENT_QUOTES);
		}
		$inner = '';
		foreach ((array) ($el['elements'] ?? array()) as $child) {
			if (is_array($child)) {
				$inner .= $this->render_element($child);
			}
		}
		$attr_style = $style;
		foreach (array('_h2e_custom_css', 'custom_css') as $key) {
			$custom = $this->flatten_custom_css((string) ($s[$key] ?? ''));
			if ('' !== $custom) {
				$attr_style .= ';' . $custom;
			}
		}
		return '<div class="' . $cls . '" style="' . htmlspecialchars($attr_style, ENT_QUOTES) . '">' . $inner . '</div>';
	}

	/**
	 * Turn Elementor `selector { … }` custom CSS into inline declarations.
	 */
	private function flatten_custom_css(string $css): string
	{
		$css = trim($css, " \t\n\r\0\x0B;");
		if ('' === $css) {
			return '';
		}
		if (preg_match('/selector\s*\{([^}]*)\}/i', $css, $m)) {
			return trim($m[1], " \t\n\r\0\x0B;");
		}
		return $css;
	}

	/**
	 * @param array<string,mixed> $s Settings.
	 */
	private function container_style(array $s): string
	{
		$css = array();
		$is_grid = 'grid' === ($s['_h2e_display'] ?? '')
			|| (is_string($s['custom_css'] ?? null) && false !== stripos((string) $s['custom_css'], 'display: grid'));
		if (!$is_grid) {
			$css[] = 'display:flex';
			$css[] = 'flex-direction:' . ($s['flex_direction'] ?? 'column');
		}
		if (!$is_grid && !empty($s['flex_wrap'])) {
			$css[] = 'flex-wrap:' . $s['flex_wrap'];
		}
		if (!empty($s['flex_justify_content'])) {
			$css[] = 'justify-content:' . $s['flex_justify_content'];
		}
		if (!empty($s['flex_align_items'])) {
			$css[] = 'align-items:' . $s['flex_align_items'];
		}
		$gap = $s['flex_gap']['size'] ?? null;
		if (!$is_grid && null !== $gap && '' !== $gap) {
			$css[] = 'gap:' . (float) $gap . 'px';
		}
		$css = array_merge($css, $this->box_styles($s));
		$css = array_merge($css, $this->background_styles($s));
		if (!empty($s['min_height']['size'])) {
			$css[] = 'min-height:' . (float) $s['min_height']['size'] . ($s['min_height']['unit'] ?? 'px');
		}
		if (!empty($s['width']['size'])) {
			$unit = (string) ($s['width']['unit'] ?? '%');
			$css[] = 'width:' . (float) $s['width']['size'] . $unit;
		}
		if (!empty($s['max_width']['size'])) {
			$max_unit = (string) ($s['max_width']['unit'] ?? 'px');
			$css[] = 'max-width:' . (float) $s['max_width']['size'] . $max_unit;
		} elseif (!empty($s['width']['size'])) {
			$css[] = 'max-width:100%';
		}
		if (!empty($s['align_self'])) {
			$css[] = 'align-self:' . $s['align_self'];
		}
		// Center max-width boxes the way browsers treat margin:auto.
		if (!empty($s['max_width']['size']) && ($s['align_self'] ?? '') === 'center') {
			$css[] = 'margin-left:auto';
			$css[] = 'margin-right:auto';
		}
		// Apply measured height for empty painted leaves (blog thumbs, marks).
		$bbox = $s['_h2e_bbox'] ?? null;
		if (is_array($bbox) && empty($s['min_height']['size'])) {
			$bh = (float) ($bbox['height'] ?? 0);
			$kids = (array) ($el['elements'] ?? array());
			if ($bh >= 24 && empty($kids)) {
				$css[] = 'min-height:' . round($bh) . 'px';
			}
		}
		if (!empty($s['position'])) {
			$css[] = 'position:' . $s['position'];
		}
		foreach (array('top', 'right', 'bottom', 'left') as $side) {
			if (!empty($s[$side]['size'])) {
				$css[] = $side . ':' . (float) $s[$side]['size'] . ($s[$side]['unit'] ?? 'px');
			}
		}
		if (isset($s['_opacity']['size'])) {
			$css[] = 'opacity:' . (float) $s['_opacity']['size'];
		}
		if (!empty($s['z_index'])) {
			$css[] = 'z-index:' . (int) $s['z_index'];
		}
		return implode(';', $css);
	}

	/**
	 * @param array<string,mixed> $el Widget.
	 */
	private function render_widget(array $el): string
	{
		$s = (array) ($el['settings'] ?? array());
		$type = (string) ($el['widgetType'] ?? 'html');
		$id = htmlspecialchars((string) ($el['id'] ?? ''), ENT_QUOTES);
		// Button / icon paint lives on the inner control (anchor / <i>), not the
		// Elementor wrapper — avoid double gradient / glow in preview compares.
		$parts = array_merge($this->typography_styles($s), $this->position_styles($s));
		if (!in_array($type, array('button', 'icon'), true)) {
			$parts = array_merge($this->box_styles($s), $parts, $this->background_styles($s));
		} elseif ('button' === $type) {
			// Keep alignment on the wrapper so left/center/right still layout.
			if (!empty($s['align'])) {
				$parts[] = 'text-align:' . (string) $s['align'];
			}
		}
		$style = implode(';', $parts);
		foreach (array('_h2e_custom_css', 'custom_css') as $key) {
			$custom = $this->flatten_custom_css((string) ($s[$key] ?? ''));
			if ('' !== $custom) {
				$style .= ('' === $style ? '' : ';') . $custom;
			}
		}
		$cls = 'e-widget e-widget-' . preg_replace('/[^a-z0-9_-]/i', '', $type) . ' elementor-element-' . $id;
		if (!empty($s['_css_classes'])) {
			$cls .= ' ' . htmlspecialchars((string) $s['_css_classes'], ENT_QUOTES);
		}
		$inner = $this->widget_inner_html($type, $s);
		return '<div class="' . $cls . '" style="' . htmlspecialchars($style, ENT_QUOTES) . '">' . $inner . '</div>';
	}

	/**
	 * @param array<string,mixed> $s Settings.
	 */
	private function widget_inner_html(string $type, array $s): string
	{
		switch ($type) {
			case 'heading':
				$tag = preg_match('/^h[1-6]$/', (string) ($s['header_size'] ?? 'h2')) ? (string) $s['header_size'] : 'h2';
				$text = htmlspecialchars((string) ($s['title'] ?? ''), ENT_QUOTES);
				$color = !empty($s['title_color']) ? ' style="color:' . htmlspecialchars((string) $s['title_color'], ENT_QUOTES) . '"' : '';
				$align = !empty($s['align']) ? ' style="text-align:' . htmlspecialchars((string) $s['align'], ENT_QUOTES) . '"' : '';
				// Prefer color; merge align into one style attr.
				$st = array();
				if (!empty($s['title_color'])) {
					$st[] = 'color:' . (string) $s['title_color'];
				}
				if (!empty($s['align'])) {
					$st[] = 'text-align:' . (string) $s['align'];
				}
				$attr = empty($st) ? '' : ' style="' . htmlspecialchars(implode(';', $st), ENT_QUOTES) . '"';
				return '<' . $tag . ' class="e-widget-heading"' . $attr . '>' . $text . '</' . $tag . '>';
			case 'text-editor':
				return '<div class="e-widget-text">' . (string) ($s['editor'] ?? '') . '</div>';
			case 'icon':
				$icon_val = trim((string) ($s['selected_icon']['value'] ?? ''));
				if ('' === $icon_val) {
					return '';
				}
				$ic_st = array('line-height:1');
				if (!empty($s['primary_color'])) {
					$ic_st[] = 'color:' . (string) $s['primary_color'];
				}
				$size = $s['size']['size'] ?? null;
				if (null !== $size && '' !== $size) {
					$ic_st[] = 'font-size:' . (float) $size . (string) ($s['size']['unit'] ?? 'px');
				}
				return '<span class="e-widget-icon-inner"><i class="'
					. htmlspecialchars($icon_val, ENT_QUOTES) . '" aria-hidden="true" style="'
					. htmlspecialchars(implode(';', $ic_st), ENT_QUOTES) . '"></i></span>';
			case 'button':
				$text = htmlspecialchars((string) ($s['text'] ?? 'Button'), ENT_QUOTES);
				$url = htmlspecialchars((string) ($s['link']['url'] ?? '#'), ENT_QUOTES);
				$st = array();
				if (!empty($s['button_text_color'])) {
					$st[] = 'color:' . (string) $s['button_text_color'];
				}
				if ('gradient' === ($s['background_background'] ?? '')) {
					$a = (string) ($s['background_color'] ?? '#000');
					$b = (string) ($s['background_color_b'] ?? $a);
					$angle = (float) ($s['background_gradient_angle']['size'] ?? 135);
					$st[] = 'background-image:linear-gradient(' . $angle . 'deg,' . $a . ',' . $b . ')';
				} elseif (!empty($s['background_color'])) {
					$st[] = 'background-color:' . (string) $s['background_color'];
				}
				$pad = $s['text_padding'] ?? $s['padding'] ?? null;
				if (!empty($pad) && is_array($pad)) {
					$u = (string) ($pad['unit'] ?? 'px');
					$st[] = 'padding:' . (float) ($pad['top'] ?? 0) . $u . ' '
						. (float) ($pad['right'] ?? 0) . $u . ' '
						. (float) ($pad['bottom'] ?? 0) . $u . ' '
						. (float) ($pad['left'] ?? 0) . $u;
				}
				if (!empty($s['border_radius']) && is_array($s['border_radius'])) {
					$br = $s['border_radius'];
					$u = (string) ($br['unit'] ?? 'px');
					$st[] = 'border-radius:' . (float) ($br['top'] ?? 0) . $u . ' '
						. (float) ($br['right'] ?? 0) . $u . ' '
						. (float) ($br['bottom'] ?? 0) . $u . ' '
						. (float) ($br['left'] ?? 0) . $u;
				}
				if (!empty($s['border_border']) && !empty($s['border_width'])) {
					$bw = $s['border_width'];
					$u = (string) ($bw['unit'] ?? 'px');
					$st[] = 'border-style:' . (string) $s['border_border'];
					$st[] = 'border-width:' . (float) ($bw['top'] ?? 0) . $u;
					if (!empty($s['border_color'])) {
						$st[] = 'border-color:' . (string) $s['border_color'];
					}
				}
				$box_shadow = $s['button_box_shadow_box_shadow'] ?? $s['box_shadow_box_shadow'] ?? null;
				if (!empty($box_shadow) && is_array($box_shadow)) {
					$st[] = 'box-shadow:'
						. (float) ($box_shadow['horizontal'] ?? 0) . 'px '
						. (float) ($box_shadow['vertical'] ?? 0) . 'px '
						. (float) ($box_shadow['blur'] ?? 0) . 'px '
						. (float) ($box_shadow['spread'] ?? 0) . 'px '
						. (string) ($box_shadow['color'] ?? 'rgba(0,0,0,.2)');
				}
				if (!empty($s['typography_font_size']['size'])) {
					$st[] = 'font-size:' . (float) $s['typography_font_size']['size']
						. (string) ($s['typography_font_size']['unit'] ?? 'px');
				}
				if (!empty($s['typography_font_weight'])) {
					$st[] = 'font-weight:' . (string) $s['typography_font_weight'];
				}
				if (!empty($s['typography_font_family'])) {
					$st[] = 'font-family:' . (string) $s['typography_font_family'];
				}
				$icon_html = '';
				$icon_val = (string) ($s['selected_icon']['value'] ?? '');
				if ('' !== $icon_val) {
					$gap = (float) ($s['icon_indent']['size'] ?? 10);
					$margin = ('right' === ($s['icon_align'] ?? 'left') || 'row-reverse' === ($s['icon_align'] ?? ''))
						? 'margin-inline-start:' . $gap . 'px'
						: 'margin-inline-end:' . $gap . 'px';
					$icon_html = ' <i class="' . htmlspecialchars($icon_val, ENT_QUOTES)
						. '" aria-hidden="true" style="' . $margin . '"></i>';
				}
				$align = (string) ($s['icon_align'] ?? 'left');
				$label = ('right' === $align || 'row-reverse' === $align)
					? ($text . $icon_html)
					: (ltrim($icon_html) . ($icon_html !== '' ? ' ' : '') . $text);
				$attr = empty($st) ? '' : ' style="' . htmlspecialchars(implode(';', $st), ENT_QUOTES) . '"';
				return '<div class="e-widget-button"><a href="' . $url . '"' . $attr . '>' . $label . '</a></div>';
			case 'testimonial':
				$content = htmlspecialchars((string) ($s['testimonial_content'] ?? ''), ENT_QUOTES);
				$name = htmlspecialchars((string) ($s['testimonial_name'] ?? ''), ENT_QUOTES);
				$job = htmlspecialchars((string) ($s['testimonial_job'] ?? ''), ENT_QUOTES);
				$c_color = (string) ($s['content_content_color'] ?? '');
				$n_color = (string) ($s['name_text_color'] ?? '');
				$j_color = (string) ($s['job_text_color'] ?? '');
				$html = '<div class="e-testimonial">';
				if ('' !== $content) {
					$html .= '<p' . ('' !== $c_color ? ' style="color:' . htmlspecialchars($c_color, ENT_QUOTES) . '"' : '') . '>' . $content . '</p>';
				}
				if ('' !== $name || '' !== $job) {
					$html .= '<div class="e-testimonial-meta">';
					if ('' !== $name) {
						$html .= '<strong' . ('' !== $n_color ? ' style="color:' . htmlspecialchars($n_color, ENT_QUOTES) . '"' : '') . '>' . $name . '</strong>';
					}
					if ('' !== $job) {
						$html .= '<span' . ('' !== $j_color ? ' style="color:' . htmlspecialchars($j_color, ENT_QUOTES) . '"' : '') . '>' . $job . '</span>';
					}
					$html .= '</div>';
				}
				return $html . '</div>';
			case 'call-to-action':
				$title = htmlspecialchars((string) ($s['title'] ?? ''), ENT_QUOTES);
				$desc = htmlspecialchars((string) ($s['description'] ?? ''), ENT_QUOTES);
				$btn = htmlspecialchars((string) ($s['button'] ?? ''), ENT_QUOTES);
				$title_c = (string) ($s['title_color'] ?? '');
				$desc_c = (string) ($s['description_color'] ?? '');
				$btn_c = (string) ($s['button_text_color'] ?? '');
				$btn_bg = (string) ($s['button_background_color'] ?? '');
				$html = '<div class="e-cta" style="text-align:center">';
				if ('' !== $title) {
					$tst = array('margin:0 0 .5rem');
					if ('' !== $title_c) {
						$tst[] = 'color:' . $title_c;
					}
					foreach (array(
						'title_typography_font_family' => 'font-family',
						'title_typography_font_size' => 'font-size',
						'title_typography_font_weight' => 'font-weight',
						'title_typography_line_height' => 'line-height',
						'title_typography_letter_spacing' => 'letter-spacing',
					) as $key => $css_prop) {
						$val = $s[$key] ?? null;
						if (is_array($val) && isset($val['size'])) {
							$tst[] = $css_prop . ':' . (float) $val['size'] . (string) ($val['unit'] ?? 'px');
						} elseif (is_string($val) && '' !== $val) {
							$tst[] = $css_prop . ':' . $val;
						}
					}
					$html .= '<h2 style="' . htmlspecialchars(implode(';', $tst), ENT_QUOTES) . '">' . $title . '</h2>';
				}
				if ('' !== $desc) {
					$dst = array('margin:0 0 1.25rem');
					if ('' !== $desc_c) {
						$dst[] = 'color:' . $desc_c;
					}
					foreach (array(
						'description_typography_font_family' => 'font-family',
						'description_typography_font_size' => 'font-size',
						'description_typography_font_weight' => 'font-weight',
						'description_typography_line_height' => 'line-height',
					) as $key => $css_prop) {
						$val = $s[$key] ?? null;
						if (is_array($val) && isset($val['size'])) {
							$dst[] = $css_prop . ':' . (float) $val['size'] . (string) ($val['unit'] ?? 'px');
						} elseif (is_string($val) && '' !== $val) {
							$dst[] = $css_prop . ':' . $val;
						}
					}
					$html .= '<p style="' . htmlspecialchars(implode(';', $dst), ENT_QUOTES) . '">' . $desc . '</p>';
				}
				if ('' !== $btn) {
					$bst = array('display:inline-flex','align-items:center','gap:10px','text-decoration:none','font-weight:600');
					$pad = $s['button_padding'] ?? null;
					if (is_array($pad)) {
						$u = (string) ($pad['unit'] ?? 'px');
						$bst[] = 'padding:' . (float) ($pad['top'] ?? 14) . $u . ' '
							. (float) ($pad['right'] ?? 28) . $u . ' '
							. (float) ($pad['bottom'] ?? 14) . $u . ' '
							. (float) ($pad['left'] ?? 28) . $u;
					} else {
						$bst[] = 'padding:14px 28px';
					}
					$br = $s['button_border_radius'] ?? null;
					if (is_array($br)) {
						$u = (string) ($br['unit'] ?? 'px');
						$bst[] = 'border-radius:' . (float) ($br['top'] ?? 999) . $u;
					} else {
						$bst[] = 'border-radius:999px';
					}
					if ('' !== $btn_c) {
						$bst[] = 'color:' . $btn_c;
					}
					if ('' !== $btn_bg) {
						$bst[] = 'background:' . $btn_bg;
					}
					$box_shadow = $s['button_box_shadow_box_shadow'] ?? null;
					if (!empty($box_shadow) && is_array($box_shadow)) {
						$bst[] = 'box-shadow:'
							. (float) ($box_shadow['horizontal'] ?? 0) . 'px '
							. (float) ($box_shadow['vertical'] ?? 0) . 'px '
							. (float) ($box_shadow['blur'] ?? 0) . 'px '
							. (float) ($box_shadow['spread'] ?? 0) . 'px '
							. (string) ($box_shadow['color'] ?? 'rgba(0,0,0,.2)');
					}
					foreach (array(
						'button_typography_font_family' => 'font-family',
						'button_typography_font_size' => 'font-size',
						'button_typography_font_weight' => 'font-weight',
					) as $key => $css_prop) {
						$val = $s[$key] ?? null;
						if (is_array($val) && isset($val['size'])) {
							$bst[] = $css_prop . ':' . (float) $val['size'] . (string) ($val['unit'] ?? 'px');
						} elseif (is_string($val) && '' !== $val) {
							$bst[] = $css_prop . ':' . $val;
						}
					}
					$icon_html = '';
					$icon_val = (string) ($s['selected_icon']['value'] ?? '');
					if ('' !== $icon_val) {
						$icon_html = '<i class="' . htmlspecialchars($icon_val, ENT_QUOTES) . '" aria-hidden="true"></i>';
					}
					$align = (string) ($s['icon_align'] ?? 'right');
					$label = ('left' === $align)
						? ($icon_html . ($icon_html !== '' ? ' ' : '') . $btn)
						: ($btn . ($icon_html !== '' ? ' ' . $icon_html : ''));
					$html .= '<a href="#" style="' . htmlspecialchars(implode(';', $bst), ENT_QUOTES) . '">' . $label . '</a>';
				}
				return $html . '</div>';
			case 'form':
				return '<form class="e-form" style="display:flex;gap:8px;flex-wrap:wrap">'
					. '<input type="email" placeholder="E-Mail" style="flex:1;min-width:160px;padding:12px 16px;border-radius:999px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.2);color:inherit" />'
					. '<button type="submit" style="padding:12px 22px;border-radius:999px;border:none;background:#C9A227;color:#2a1f00;font-weight:600">Submit</button>'
					. '</form>';
			case 'star-rating':
				$rating = (float) ($s['rating'] ?? 5);
				$scale = max(1, (int) ($s['rating_scale'] ?? 5));
				$full = (int) round(min($scale, max(0, $rating)));
				$stars = str_repeat('★', $full) . str_repeat('☆', max(0, $scale - $full));
				$color = htmlspecialchars((string) ($s['title_color'] ?? $s['text_color'] ?? '#C9A227'), ENT_QUOTES);
				return '<div class="e-star-rating" style="color:' . $color . ';letter-spacing:2px;font-size:1.1em">'
					. htmlspecialchars($stars, ENT_QUOTES) . '</div>';
			case 'image':
				$url = htmlspecialchars((string) ($s['image']['url'] ?? ''), ENT_QUOTES);
				$alt = htmlspecialchars((string) ($s['alt'] ?? ''), ENT_QUOTES);
				$img_style = array();
				if (!empty($s['_h2e_object_fit'])) {
					$img_style[] = 'object-fit:' . (string) $s['_h2e_object_fit'];
				}
				if (!empty($s['_h2e_aspect_ratio'])) {
					$img_style[] = 'aspect-ratio:' . (string) $s['_h2e_aspect_ratio'];
				}
				if (!empty($s['width']['size'])) {
					$img_style[] = 'width:' . (float) $s['width']['size'] . (string) ($s['width']['unit'] ?? 'px');
					$img_style[] = 'max-width:100%';
				}
				if (!empty($s['height']['size'])) {
					$img_style[] = 'height:' . (float) $s['height']['size'] . (string) ($s['height']['unit'] ?? 'px');
				} elseif (!empty($s['_h2e_bbox']['height']) && !empty($s['width']['size'])) {
					$img_style[] = 'height:' . (float) $s['_h2e_bbox']['height'] . 'px';
				}
				$attr = empty($img_style) ? '' : ' style="' . htmlspecialchars(implode(';', $img_style), ENT_QUOTES) . '"';
				return '' !== $url ? '<img src="' . $url . '" alt="' . $alt . '"' . $attr . '>' : '';
			case 'price-table':
				$heading = htmlspecialchars((string) ($s['heading'] ?? ''), ENT_QUOTES);
				$price = htmlspecialchars((string) ($s['currency_symbol'] ?? '') . (string) ($s['price'] ?? ''), ENT_QUOTES);
				$period = htmlspecialchars((string) ($s['period'] ?? ''), ENT_QUOTES);
				$btn = htmlspecialchars((string) ($s['button_text'] ?? ''), ENT_QUOTES);
				$features = '';
				foreach ((array) ($s['features_list'] ?? array()) as $item) {
					$features .= '<li>' . htmlspecialchars((string) ($item['item_text'] ?? ''), ENT_QUOTES) . '</li>';
				}
				return '<div class="e-price-table" style="text-align:center;padding:1rem">'
					. ($heading !== '' ? '<h4 style="margin:0 0 .5rem">' . $heading . '</h4>' : '')
					. '<div style="font-size:2rem;font-weight:700;margin:.5rem 0">' . $price
					. ($period !== '' ? '<small style="font-size:.9rem;opacity:.7">/' . $period . '</small>' : '')
					. '</div>'
					. ($features !== '' ? '<ul style="list-style:none;padding:0;margin:1rem 0;text-align:left">' . $features . '</ul>' : '')
					. ($btn !== '' ? '<div class="e-widget-button"><a href="#">' . $btn . '</a></div>' : '')
					. '</div>';
			case 'divider':
				return '<hr style="border:none;border-top:1px solid #ccc;margin:0">';
			case 'spacer':
				$h = (float) ($s['space']['size'] ?? $s['space'] ?? 50);
				return '<div style="height:' . $h . 'px"></div>';
			case 'html':
				return (string) ($s['html'] ?? '');
			case 'icon-list':
				$items = '';
				foreach ((array) ($s['icon_list'] ?? array()) as $item) {
					$items .= '<li>' . htmlspecialchars((string) ($item['text'] ?? ''), ENT_QUOTES) . '</li>';
				}
				return '<ul style="margin:0;padding-left:1.2em">' . $items . '</ul>';
			case 'video':
				$url = (string) ($s['youtube_url'] ?? $s['vimeo_url'] ?? $s['hosted_url']['url'] ?? '');
				return '<div style="background:#111;color:#fff;padding:40px;text-align:center">Video</div>';
			case 'google_maps':
				$h = (float) ($s['custom_height']['size'] ?? 360);
				$addr = htmlspecialchars((string) ($s['address'] ?? ''), ENT_QUOTES);
				return '<div style="height:' . $h . 'px;background:#e8e8e8;display:flex;align-items:center;justify-content:center">Map: ' . $addr . '</div>';
			case 'accordion':
				$html = '';
				$title_color = (string) ($s['title_color'] ?? '');
				$content_color = (string) ($s['content_color'] ?? '');
				$icon_color = (string) ($s['icon_color'] ?? '');
				$icon_val = (string) ($s['selected_icon']['value'] ?? '');
				$icon_align = (string) ($s['icon_align'] ?? 'left');
				foreach ((array) ($s['tabs'] ?? array()) as $tab) {
					$sum_st = array();
					if ('' !== $title_color) {
						$sum_st[] = 'color:' . $title_color;
					}
					$sum_attr = empty($sum_st) ? '' : ' style="' . htmlspecialchars(implode(';', $sum_st), ENT_QUOTES) . '"';
					$icon_html = '';
					if ('' !== $icon_val) {
						$ic_st = '' !== $icon_color ? ' style="color:' . htmlspecialchars($icon_color, ENT_QUOTES) . '"' : '';
						$icon_html = ' <i class="' . htmlspecialchars($icon_val, ENT_QUOTES) . '"' . $ic_st . ' aria-hidden="true"></i>';
					}
					$title = htmlspecialchars((string) ($tab['tab_title'] ?? ''), ENT_QUOTES);
					$label = ('right' === $icon_align) ? ($title . $icon_html) : ($icon_html . $title);
					$body_st = '' !== $content_color ? ' style="color:' . htmlspecialchars($content_color, ENT_QUOTES) . '"' : '';
					$html .= '<details open><summary' . $sum_attr . '>' . $label
						. '</summary><div' . $body_st . '>' . (string) ($tab['tab_content'] ?? '') . '</div></details>';
				}
				return $html;
			case 'social-icons':
				$items = '';
				$gap = (float) ($s['gap']['size'] ?? 10);
				foreach ((array) ($s['social_icon_list'] ?? array()) as $item) {
					$icon = (string) ($item['social_icon']['value'] ?? 'fa fa-link');
					$items .= '<span style="display:inline-flex;align-items:center;justify-content:center;'
						. 'width:40px;height:40px;border-radius:999px;background:rgba(255,255,255,.08);color:#fff;font-size:16px">'
						. '<i class="' . htmlspecialchars($icon, ENT_QUOTES) . '" aria-hidden="true"></i></span>';
				}
				return '<div class="e-social-icons" style="display:flex;flex-direction:row;flex-wrap:nowrap;gap:'
					. $gap . 'px;align-items:center">' . $items . '</div>';
			default:
				$title = htmlspecialchars((string) ($s['title'] ?? $type), ENT_QUOTES);
				return '<div data-widget="' . htmlspecialchars($type, ENT_QUOTES) . '">' . $title . '</div>';
		}
	}

	/**
	 * @param array<string,mixed> $s Settings.
	 * @return array<int,string>
	 */
	private function position_styles(array $s): array
	{
		$css = array();
		if (!empty($s['position'])) {
			$css[] = 'position:' . $s['position'];
		}
		foreach (array('top', 'right', 'bottom', 'left') as $side) {
			if (isset($s[$side]['size']) && '' !== $s[$side]['size']) {
				$css[] = $side . ':' . (float) $s[$side]['size'] . ($s[$side]['unit'] ?? 'px');
			}
		}
		if (!empty($s['z_index'])) {
			$css[] = 'z-index:' . (int) $s['z_index'];
		}
		return $css;
	}

	/**
	 * @param array<string,mixed> $s Settings.
	 * @return array<int,string>
	 */
	private function box_styles(array $s): array
	{
		$css = array();
		foreach (array('padding', 'margin') as $box) {
			if (!empty($s[$box]) && is_array($s[$box])) {
				$u = (string) ($s[$box]['unit'] ?? 'px');
				$css[] = $box . ':' . (float) ($s[$box]['top'] ?? 0) . $u . ' '
					. (float) ($s[$box]['right'] ?? 0) . $u . ' '
					. (float) ($s[$box]['bottom'] ?? 0) . $u . ' '
					. (float) ($s[$box]['left'] ?? 0) . $u;
			}
		}
		if (!empty($s['border_border']) && !empty($s['border_width'])) {
			$bw = $s['border_width'];
			$u = (string) ($bw['unit'] ?? 'px');
			$css[] = 'border-style:' . (string) $s['border_border'];
			$css[] = 'border-width:' . (float) ($bw['top'] ?? 0) . $u . ' '
				. (float) ($bw['right'] ?? 0) . $u . ' '
				. (float) ($bw['bottom'] ?? 0) . $u . ' '
				. (float) ($bw['left'] ?? 0) . $u;
			if (!empty($s['border_color'])) {
				$css[] = 'border-color:' . (string) $s['border_color'];
			}
		}
		if (!empty($s['border_radius']) && is_array($s['border_radius'])) {
			$br = $s['border_radius'];
			$u = (string) ($br['unit'] ?? 'px');
			$css[] = 'border-radius:' . (float) ($br['top'] ?? 0) . $u . ' '
				. (float) ($br['right'] ?? 0) . $u . ' '
				. (float) ($br['bottom'] ?? 0) . $u . ' '
				. (float) ($br['left'] ?? 0) . $u;
		}
		if (!empty($s['box_shadow_box_shadow']) && is_array($s['box_shadow_box_shadow'])) {
			$sh = $s['box_shadow_box_shadow'];
			$css[] = 'box-shadow:' . (float) ($sh['horizontal'] ?? 0) . 'px '
				. (float) ($sh['vertical'] ?? 0) . 'px '
				. (float) ($sh['blur'] ?? 0) . 'px '
				. (float) ($sh['spread'] ?? 0) . 'px '
				. (string) ($sh['color'] ?? 'rgba(0,0,0,.2)')
				. (!empty($sh['position']) ? ' inset' : '');
		}
		if (!empty($s['text_color'])) {
			$css[] = 'color:' . (string) $s['text_color'];
		}
		if (!empty($s['align'])) {
			$css[] = 'text-align:' . (string) $s['align'];
		}
		return $css;
	}

	/**
	 * @param array<string,mixed> $s Settings.
	 * @return array<int,string>
	 */
	private function typography_styles(array $s): array
	{
		$css = array();
		if (!empty($s['typography_font_family'])) {
			$css[] = 'font-family:' . (string) $s['typography_font_family'];
		}
		if (!empty($s['typography_font_size']['size'])) {
			$css[] = 'font-size:' . (float) $s['typography_font_size']['size'] . ($s['typography_font_size']['unit'] ?? 'px');
		}
		if (!empty($s['typography_font_weight'])) {
			$css[] = 'font-weight:' . (string) $s['typography_font_weight'];
		}
		if (!empty($s['typography_font_style'])) {
			$css[] = 'font-style:' . (string) $s['typography_font_style'];
		}
		if (!empty($s['typography_line_height']['size'])) {
			$u = (string) ($s['typography_line_height']['unit'] ?? 'em');
			$css[] = 'line-height:' . (float) $s['typography_line_height']['size'] . ('em' === $u || '' === $u ? $u : $u);
		}
		if (!empty($s['typography_letter_spacing']['size'])) {
			$css[] = 'letter-spacing:' . (float) $s['typography_letter_spacing']['size'] . ($s['typography_letter_spacing']['unit'] ?? 'px');
		}
		if (!empty($s['typography_text_transform'])) {
			$css[] = 'text-transform:' . (string) $s['typography_text_transform'];
		}
		if (!empty($s['text_shadow_text_shadow']) && is_array($s['text_shadow_text_shadow'])) {
			$t = $s['text_shadow_text_shadow'];
			$css[] = 'text-shadow:' . (float) ($t['horizontal'] ?? 0) . 'px '
				. (float) ($t['vertical'] ?? 0) . 'px '
				. (float) ($t['blur'] ?? 0) . 'px '
				. (string) ($t['color'] ?? 'rgba(0,0,0,.4)');
		}
		return $css;
	}

	/**
	 * @param array<string,mixed> $s Settings.
	 * @return array<int,string>
	 */
	private function background_styles(array $s): array
	{
		$css = array();
		$type = (string) ($s['background_background'] ?? '');
		$overlay_type = (string) ($s['background_overlay_background'] ?? '');

		if ('gradient' === $type) {
			$a = (string) ($s['background_color'] ?? '#000');
			$b = (string) ($s['background_color_b'] ?? '#fff');
			$angle = (float) ($s['background_gradient_angle']['size'] ?? 180);
			$css[] = 'background-image:linear-gradient(' . $angle . 'deg,' . $a . ',' . $b . ')';
		} else {
			if (!empty($s['background_color'])) {
				$css[] = 'background-color:' . (string) $s['background_color'];
			}
			$layers = array();
			if ('gradient' === $overlay_type) {
				$oa = (string) ($s['background_overlay_color'] ?? 'rgba(0,0,0,.4)');
				$ob = (string) ($s['background_overlay_color_b'] ?? $oa);
				$oangle = (float) ($s['background_overlay_gradient_angle']['size'] ?? 180);
				$layers[] = 'linear-gradient(' . $oangle . 'deg,' . $oa . ',' . $ob . ')';
			}
			if (!empty($s['background_image']['url'])) {
				$url = (string) $s['background_image']['url'];
				// Skip obviously missing local files so gradient/color still shows.
				$skip = false;
				if (0 === stripos($url, 'file://')) {
					$path = substr($url, 7);
					$skip = !is_file($path);
				}
				if (!$skip) {
					$layers[] = 'url(' . $url . ')';
				}
			}
			if (!empty($layers)) {
				$css[] = 'background-image:' . implode(',', $layers);
				$css[] = 'background-size:' . (string) ($s['background_size'] ?? 'cover');
				$css[] = 'background-position:' . (string) ($s['background_position'] ?? 'center center');
				$css[] = 'background-repeat:' . (string) ($s['background_repeat'] ?? 'no-repeat');
			}
		}
		return $css;
	}
}
