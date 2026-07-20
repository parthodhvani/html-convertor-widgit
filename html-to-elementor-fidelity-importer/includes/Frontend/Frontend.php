<?php
/**
 * Front-end output of preserved source styling for imported pages.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Re-applies the original page's stylesheet (and optional scripts) to imported
 * pages. This preserves visual fidelity for CSS that native Elementor controls
 * do not cover (pseudo-elements, descendant rules, hover states, ...) without
 * resorting to HTML widgets, while widgets keep their original CSS classes.
 *
 * Cascade order (intentional — design CSS must win over Elementor kit defaults):
 *   1. Uploaded stylesheet <link>s (fonts/icons)     — early enqueue
 *   2. Elementor kit / widget / document CSS         — default priorities
 *   3. Uploaded combinedCss (design system)          — late, after Elementor
 *   4. H2E per-element custom CSS                    — last (grids, transforms,
 *      elliptical radii Elementor cannot express)
 *
 * Conversion still *reads* uploaded CSS first (Chromium computed styles →
 * Elementor controls). Front-end print order keeps the HTML look matching.
 */
final class Frontend
{

    /**
     * Register hooks.
     */
    public function register(): void
    {
        // Early: font/icon stylesheet links so they are available before paint.
        add_action('wp_enqueue_scripts', array($this, 'enqueue_source_styles'), 5);
        // Late: uploaded design CSS after Elementor so class rules win.
        add_action('wp_head', array($this, 'output_source_css'), 99);
        // Last: generated per-element custom CSS.
        add_action('wp_head', array($this, 'output_element_css'), 100);
        add_action('wp_footer', array($this, 'output_scripts'), 99);
    }

    /**
     * Enqueue remote stylesheet links from the uploaded package (fonts/icons).
     */
    public function enqueue_source_styles(): void
    {
        $post_id = $this->current_post_id();
        if (!$post_id) {
            return;
        }

        $links = get_post_meta($post_id, '_h2e_source_links', true);
        if (!is_array($links)) {
            return;
        }

        $i = 0;
        foreach ($links as $href) {
            $href = esc_url((string) $href);
            if ('' === $href) {
                continue;
            }
            ++$i;
            wp_enqueue_style(
                'h2e-source-' . $i,
                $href,
                array(),
                null
            );
        }
    }

    /**
     * Print the uploaded package's combined CSS after Elementor defaults.
     */
    public function output_source_css(): void
    {
        $post_id = $this->current_post_id();
        if (!$post_id) {
            return;
        }

        // Prefer the split meta (uploaded only). Fall back to legacy combined blob
        // when element custom CSS has not been separated yet.
        $css = (string) get_post_meta($post_id, '_h2e_uploaded_css', true);
        if ('' === trim($css)) {
            $legacy = (string) get_post_meta($post_id, '_h2e_source_css', true);
            $element = (string) get_post_meta($post_id, '_h2e_element_css', true);
            if ('' !== trim($element) && '' !== trim($legacy)
                && false !== strpos($legacy, '/* h2e element custom css */')
            ) {
                $parts = explode('/* h2e element custom css */', $legacy, 2);
                $css = $parts[0];
            } else {
                $css = $legacy;
            }
        }

        if ('' === trim($css)) {
            return;
        }

        echo '<style id="h2e-source-css">' . "\n" . $this->sanitize_css($css) . "\n" . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Print H2E-generated per-element CSS after uploaded design CSS.
     */
    public function output_element_css(): void
    {
        $post_id = $this->current_post_id();
        if (!$post_id) {
            return;
        }

        $css = (string) get_post_meta($post_id, '_h2e_element_css', true);
        if ('' === trim($css)) {
            // Legacy: element rules appended after the marker inside _h2e_source_css.
            $legacy = (string) get_post_meta($post_id, '_h2e_source_css', true);
            if ('' !== trim($legacy) && false !== strpos($legacy, '/* h2e element custom css */')) {
                $parts = explode('/* h2e element custom css */', $legacy, 2);
                $css = $parts[1] ?? '';
            }
        }

        if ('' === trim($css)) {
            return;
        }

        echo '<style id="h2e-element-css">' . "\n" . $this->sanitize_css($css) . "\n" . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Output preserved inline scripts in the footer (opt-in).
     */
    public function output_scripts(): void
    {
        $post_id = $this->current_post_id();
        if (!$post_id) {
            return;
        }
        $js = (string) get_post_meta($post_id, '_h2e_source_js', true);
        if ('' !== trim($js)) {
            echo '<script id="h2e-source-js">' . "\n" . $js . "\n" . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    /**
     * Resolve the current singular post id, if any.
     */
    private function current_post_id(): int
    {
        if (!is_singular()) {
            return 0;
        }
        $post_id = get_queried_object_id();
        if (!$post_id || !get_post_meta($post_id, '_h2e_imported', true)) {
            return 0;
        }
        return (int) $post_id;
    }

    /**
     * Minimal guard against closing the style tag from within stored CSS.
     *
     * @param string $css CSS.
     */
    private function sanitize_css(string $css): string
    {
        return str_ireplace('</style', '<\/style', $css);
    }
}
