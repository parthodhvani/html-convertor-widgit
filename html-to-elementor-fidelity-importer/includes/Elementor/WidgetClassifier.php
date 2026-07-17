<?php
/**
 * Classifies DOM tree nodes into native Elementor widgets / components.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The component recognition engine. Given a node from the Chromium layout tree
 * it decides whether the node maps to a native Elementor widget, must fall back
 * to an HTML widget (last resort), or should be treated as a container.
 */
final class WidgetClassifier
{

    /**
     * Decide how to handle an atomic (non-recursed) node.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array{kind:string,type?:string,settings?:array<string,mixed>}|null
     *               kind: "widget" | "fallback"; null means "treat as container".
     */
    public function classify(array $node): ?array
    {
        $tag = (string) ($node['tag'] ?? '');

        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return $this->heading($node, $tag);
            case 'p':
                return $this->text_editor($node);
            case 'blockquote':
                return $this->text_editor($node);
            case 'img':
                return $this->image($node);
            case 'button':
                return $this->button($node);
            case 'a':
                return $this->anchor($node);
            case 'ul':
            case 'ol':
                return $this->icon_list($node);
            case 'hr':
                return array('kind' => 'widget', 'type' => 'divider', 'settings' => array());
            case 'video':
                return $this->video_tag($node);
            case 'iframe':
                return $this->iframe($node);
            case 'i':
            case 'span':
                return $this->maybe_icon($node);
            case 'svg':
                return $this->svg_image($node);
            case 'canvas':
            case 'form':
            case 'table':
            case 'object':
            case 'embed':
            case 'input':
            case 'select':
            case 'textarea':
                return array('kind' => 'fallback');
        }

        // Unknown atomic-ish leaf with only text -> text widget.
        if ('' !== trim((string) ($node['text'] ?? ''))) {
            return $this->text_editor($node);
        }
        return null;
    }

    /**
     * Whether a (container) node should be rendered as a single HTML widget.
     * Used for layered/absolute designs and third-party widgets we cannot
     * faithfully rebuild natively.
     *
     * @param array<string,mixed> $node Tree node.
     */
    public function container_needs_fallback(array $node): bool
    {
        // Third-party slider/carousel libraries.
        $cls = strtolower((string) ($node['cls'] ?? ''));
        foreach (array('swiper', 'slick', 'owl-carousel', 'splide', 'flickity') as $lib) {
            if (false !== strpos($cls, $lib)) {
                return true;
            }
        }
		// Absolutely-positioned children are reconstructed via Elementor
		// position controls — do not force the whole parent into HTML.
		return false;
	}

    /**
     * Detect a semantic component role from tag + classes (for the report and
     * for light structural hints). Returns '' when no specific role matches.
     *
     * @param array<string,mixed> $node Tree node.
     */
    public function role(array $node): string
    {
        $tag = strtolower((string) ($node['tag'] ?? ''));
        $cls = strtolower((string) ($node['cls'] ?? '') . ' ' . (string) ($node['id'] ?? ''));

        $map = array(
            'header' => array('header'),
            'footer' => array('footer'),
            'nav' => array('nav', 'navbar', 'menu'),
            'hero' => array('hero', 'banner', 'masthead', 'jumbotron'),
            'cta' => array('cta', 'call-to-action', 'get-started'),
            'testimonial' => array('testimonial', 'review', 'quote'),
            'faq' => array('faq', 'accordion', 'collapse'),
            'pricing' => array('pricing', 'price', 'plan', 'tier'),
            'feature' => array('feature', 'icon-box', 'service'),
            'card' => array('card', 'box'),
            'team' => array('team', 'member', 'staff'),
            'counter' => array('counter', 'stat', 'count'),
            'gallery' => array('gallery'),
        );

        if ('header' === $tag) {
            return 'header';
        }
        if ('footer' === $tag) {
            return 'footer';
        }
        if ('nav' === $tag) {
            return 'nav';
        }

        foreach ($map as $role => $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '/', $cls)) {
                    return $role;
                }
            }
        }
        return '';
    }

    /* --------------------------------------------------------------------- */

    /**
     * @param array<string,mixed> $node Node.
     * @param string              $tag  Heading tag.
     * @return array{kind:string,type:string,settings:array<string,mixed>}|null
     */
    private function heading(array $node, string $tag): ?array
    {
        $text = $this->inner_text($node);
        if ('' === $text) {
            return null;
        }
        return array(
            'kind' => 'widget',
            'type' => 'heading',
            'settings' => array(
                'title' => $text,
                'header_size' => $tag,
            ),
        );
    }

    /**
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type:string,settings:array<string,mixed>}|null
     */
    private function text_editor(array $node): ?array
    {
        $inner = $this->inner_html($node);
        if ('' === trim(wp_strip_all_tags($inner))) {
            return null;
        }
        $tag = (string) ($node['tag'] ?? 'p');
        if (in_array($tag, array('p', 'blockquote'), true)) {
            $inner = '<' . $tag . '>' . $inner . '</' . $tag . '>';
        }
        return array(
            'kind' => 'widget',
            'type' => 'text-editor',
            'settings' => array('editor' => $inner),
        );
    }

    /**
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type:string,settings:array<string,mixed>}|null
     */
    private function image(array $node): ?array
    {
        $src = (string) ($node['src'] ?? '');
        if ('' === $src) {
            return null;
        }
        return array(
            'kind' => 'widget',
            'type' => 'image',
            'settings' => array(
                'image' => array('url' => $src, 'id' => ''),
                'image_size' => 'full',
                'alt' => (string) ($node['alt'] ?? ''),
            ),
        );
    }

    /**
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type:string,settings:array<string,mixed>}
     */
    private function button(array $node): array
    {
        return array(
            'kind' => 'widget',
            'type' => 'button',
            'settings' => array('text' => $this->inner_text($node) ?: 'Button'),
        );
    }

    /**
     * Anchor handling: button-like links become Button widgets; image/icon-only
     * links become Image widgets or HTML fallback (logo SVGs).
     *
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type?:string,settings?:array<string,mixed>}|null
     */
    private function anchor(array $node): ?array
    {
        $text = $this->inner_text($node);
        $html = (string) ($node['html'] ?? '');
        $href = (string) ($node['href'] ?? '');

        if ('' === $text) {
            // Image-only link.
            if (false !== stripos($html, '<img')) {
                $src = $this->first_attr($html, 'img', 'src');
                if ('' !== $src) {
                    return array(
                        'kind' => 'widget',
                        'type' => 'image',
                        'settings' => array(
                            'image' => array('url' => $src, 'id' => ''),
                            'image_size' => 'full',
                            'link_to' => $href ? 'custom' : 'none',
                            'link' => array('url' => $href),
                        ),
                    );
                }
            }
            // Inline-SVG-only link (e.g. a logo) -> native Image widget (data URI).
            if (false !== stripos($html, '<svg')) {
                $svg = $this->extract_svg($html);
                if ('' !== $svg) {
                    return array(
                        'kind' => 'widget',
                        'type' => 'image',
                        'settings' => array(
                            'image' => array('url' => $this->svg_data_uri($svg), 'id' => ''),
                            'image_size' => 'full',
                            'link_to' => $href ? 'custom' : 'none',
                            'link' => array('url' => $href),
                        ),
                    );
                }
            }
            return array('kind' => 'fallback');
        }

        // Button chrome (class or filled+padded) → Button; else linked text.
        $cls = strtolower((string) ($node['cls'] ?? ''));
        $s = $node['s'] ?? array();
        $has_chrome = preg_match('/\b(btn|button|cta)\b/', $cls)
            || (!empty($s['bg']) && ((float) ($s['pt'] ?? 0) + (float) ($s['pb'] ?? 0) + (float) ($s['pl'] ?? 0) + (float) ($s['pr'] ?? 0)) >= 6);
        if ($has_chrome) {
            return array(
                'kind' => 'widget',
                'type' => 'button',
                'settings' => array(
                    'text' => $text,
                    'link' => array('url' => $href, 'is_external' => '', 'nofollow' => ''),
                ),
            );
        }

        return array(
            'kind' => 'widget',
            'type' => 'text-editor',
            'settings' => array(
                'editor' => '<p><a href="' . esc_url($href) . '">' . esc_html($text) . '</a></p>',
            ),
        );
    }

    /**
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type:string,settings:array<string,mixed>}|null
     */
    private function icon_list(array $node): ?array
    {
        $items = array();
        foreach ((array) ($node['items'] ?? array()) as $text) {
            $text = trim((string) $text);
            if ('' !== $text) {
                $items[] = array(
                    'text' => $text,
                    // Do not invent icons the source never had.
                    'selected_icon' => array('value' => '', 'library' => ''),
                );
            }
        }
        if (empty($items)) {
            return null;
        }
        return array(
            'kind' => 'widget',
            'type' => 'icon-list',
            'settings' => array(
                'icon_list' => $items,
                'space_between' => array('unit' => 'px', 'size' => 8),
            ),
        );
    }

    /**
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type:string,settings:array<string,mixed>}|null
     */
    private function video_tag(array $node): ?array
    {
        $src = (string) ($node['src'] ?? '');
        if ('' === $src && preg_match('/<source[^>]+src=["\']([^"\']+)/i', (string) ($node['html'] ?? ''), $m)) {
            $src = $m[1];
        }
        if ('' === $src) {
            return array('kind' => 'fallback');
        }
        return array(
            'kind' => 'widget',
            'type' => 'video',
            'settings' => array(
                'video_type' => 'hosted',
                'hosted_url' => array('url' => $src),
            ),
        );
    }

    /**
     * iframe -> Video (YouTube/Vimeo) or Google Maps widget, else HTML fallback.
     *
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type?:string,settings?:array<string,mixed>}
     */
    private function iframe(array $node): array
    {
        $src = (string) ($node['src'] ?? '');
        if ('' === $src && preg_match('/src=["\']([^"\']+)/i', (string) ($node['html'] ?? ''), $m)) {
            $src = $m[1];
        }
        if (preg_match('#(youtube\.com|youtu\.be)#i', $src)) {
            return array(
                'kind' => 'widget',
                'type' => 'video',
                'settings' => array('video_type' => 'youtube', 'youtube_url' => $src),
            );
        }
        if (preg_match('#vimeo\.com#i', $src)) {
            return array(
                'kind' => 'widget',
                'type' => 'video',
                'settings' => array('video_type' => 'vimeo', 'vimeo_url' => $src),
            );
        }
        if (preg_match('#(google\.[a-z.]+/maps|maps\.google)#i', $src)) {
            $address = '';
            if (preg_match('/[?&]q=([^&]+)/i', $src, $m)) {
                $address = rawurldecode(str_replace('+', ' ', $m[1]));
            } elseif (preg_match('/[?&]query=([^&]+)/i', $src, $m)) {
                $address = rawurldecode(str_replace('+', ' ', $m[1]));
            } elseif (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $src, $m)) {
                $address = $m[1] . ', ' . $m[2];
            }
            return array(
                'kind' => 'widget',
                'type' => 'google_maps',
                'settings' => array(
                    'address' => $address,
                    'custom_height' => array('unit' => 'px', 'size' => 360),
                ),
            );
        }
        return array('kind' => 'fallback');
    }

    /**
     * Convert a standalone inline <svg> into a native Image widget (data URI),
     * preserving the vector visually without an HTML widget.
     *
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type?:string,settings?:array<string,mixed>}
     */
    private function svg_image(array $node): array
    {
        $svg = $this->extract_svg((string) ($node['html'] ?? ''));
        if ('' === $svg) {
            return array('kind' => 'fallback');
        }
        return array(
            'kind' => 'widget',
            'type' => 'image',
            'settings' => array(
                'image' => array('url' => $this->svg_data_uri($svg), 'id' => ''),
                'image_size' => 'full',
            ),
        );
    }

    /**
     * Extract the first <svg>...</svg> block from an HTML string.
     *
     * @param string $html HTML.
     */
    private function extract_svg(string $html): string
    {
        if (preg_match('/<svg\b.*?<\/svg>/is', $html, $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * Build a base64 data URI for an SVG string.
     *
     * @param string $svg SVG markup.
     */
    private function svg_data_uri(string $svg): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Map a font-icon element (<i class="fa ...">) to an Icon widget.
     *
     * @param array<string,mixed> $node Node.
     * @return array{kind:string,type?:string,settings?:array<string,mixed>}|null
     */
    private function maybe_icon(array $node): ?array
    {
        $cls = (string) ($node['cls'] ?? '');
        if (preg_match('/\bfa[bsrl]?\b|\bfa-[\w-]+/', $cls)) {
            $value = trim(preg_replace('/\s+/', ' ', $cls));
            return array(
                'kind' => 'widget',
                'type' => 'icon',
                'settings' => array(
                    'selected_icon' => array('value' => $value, 'library' => 'fa-solid'),
                ),
            );
        }
        // Plain span/i with text -> let it be handled as text by the caller.
        return null;
    }

    /* --------------------------------------------------------------------- */

    /**
     * Inner HTML of the node's root element (from captured outerHTML).
     *
     * @param array<string,mixed> $node Node.
     */
    private function inner_html(array $node): string
    {
        $html = (string) ($node['html'] ?? '');
        if ('' === $html) {
            return trim((string) ($node['text'] ?? ''));
        }
        if (preg_match('/^<[^>]+>(.*)<\/[^>]+>\s*$/s', trim($html), $m)) {
            return trim($m[1]);
        }
        return trim((string) ($node['text'] ?? ''));
    }

    /**
     * Plain-text content of the node.
     *
     * @param array<string,mixed> $node Node.
     */
    private function inner_text(array $node): string
    {
        $text = trim((string) ($node['text'] ?? ''));
        if ('' !== $text) {
            return $text;
        }
        return trim(wp_strip_all_tags((string) ($node['html'] ?? '')));
    }

    /**
     * Read the first attribute of the first matching tag in an HTML string.
     *
     * @param string $html HTML.
     * @param string $tag  Tag name.
     * @param string $attr Attribute name.
     */
    private function first_attr(string $html, string $tag, string $attr): string
    {
        if (preg_match('/<' . preg_quote($tag, '/') . '[^>]*\s' . preg_quote($attr, '/') . '=["\']([^"\']+)/i', $html, $m)) {
            return $m[1];
        }
        return '';
    }
}
