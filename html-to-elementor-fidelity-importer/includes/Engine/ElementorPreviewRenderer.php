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
				if ('' !== $candidate && false === stripos($candidate, 'rgba(0, 0, 0, 0)')) {
					$body_bg = $candidate;
					break;
				}
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
		$style = implode(';', array_merge($this->box_styles($s), $this->typography_styles($s), $this->background_styles($s), $this->position_styles($s)));
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
				if (!empty($s['padding']) && is_array($s['padding'])) {
					$p = $s['padding'];
					$u = (string) ($p['unit'] ?? 'px');
					$st[] = 'padding:' . (float) ($p['top'] ?? 0) . $u . ' '
						. (float) ($p['right'] ?? 0) . $u . ' '
						. (float) ($p['bottom'] ?? 0) . $u . ' '
						. (float) ($p['left'] ?? 0) . $u;
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
				$icon_html = '';
				$icon_val = (string) ($s['selected_icon']['value'] ?? '');
				if ('' !== $icon_val) {
					$icon_html = ' <i class="' . htmlspecialchars($icon_val, ENT_QUOTES) . '" aria-hidden="true"></i>';
				}
				$align = (string) ($s['icon_align'] ?? 'left');
				$label = ('right' === $align || 'row-reverse' === $align)
					? ($text . $icon_html)
					: ($icon_html . $text);
				$attr = empty($st) ? '' : ' style="' . htmlspecialchars(implode(';', $st), ENT_QUOTES) . '"';
				return '<div class="e-widget-button"><a href="' . $url . '"' . $attr . '>' . $label . '</a></div>';
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
					$label = htmlspecialchars(preg_replace('/^fa-\w+\s+/', '', $icon) ?? $icon, ENT_QUOTES);
					$items .= '<span style="display:inline-flex;align-items:center;justify-content:center;'
						. 'width:40px;height:40px;border-radius:999px;background:rgba(13,59,102,.08);font-size:12px">'
						. $label . '</span>';
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
		if ('gradient' === $type) {
			$a = (string) ($s['background_color'] ?? '#000');
			$b = (string) ($s['background_color_b'] ?? '#fff');
			$angle = (float) ($s['background_gradient_angle']['size'] ?? 180);
			$css[] = 'background-image:linear-gradient(' . $angle . 'deg,' . $a . ',' . $b . ')';
		} else {
			if (!empty($s['background_color'])) {
				$css[] = 'background-color:' . (string) $s['background_color'];
			}
			if (!empty($s['background_image']['url'])) {
				$css[] = 'background-image:url(' . (string) $s['background_image']['url'] . ')';
				$css[] = 'background-size:' . (string) ($s['background_size'] ?? 'cover');
				$css[] = 'background-position:' . (string) ($s['background_position'] ?? 'center center');
				$css[] = 'background-repeat:' . (string) ($s['background_repeat'] ?? 'no-repeat');
			}
		}
		return $css;
	}
}
