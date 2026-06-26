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
 */
final class Frontend
{

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('wp_head', array($this, 'output_styles'), 99);
        add_action('wp_footer', array($this, 'output_scripts'), 99);
    }

    /**
     * Output preserved stylesheet links + inline CSS in the document head.
     */
    public function output_styles(): void
    {
        $post_id = $this->current_post_id();
        if (!$post_id) {
            return;
        }

        $links = get_post_meta($post_id, '_h2e_source_links', true);
        if (is_array($links)) {
            foreach ($links as $href) {
                $href = esc_url((string) $href);
                if ('' !== $href) {
                    echo '<link rel="stylesheet" href="' . $href . '" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
                }
            }
        }

        $css = (string) get_post_meta($post_id, '_h2e_source_css', true);
        if ('' !== trim($css)) {
            echo '<style id="h2e-source-css">' . "\n" . $this->sanitize_css($css) . "\n" . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
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
