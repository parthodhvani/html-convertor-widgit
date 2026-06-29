<?php
/**
 * Recursively reconstructs a Chromium DOM tree as nested Elementor containers
 * and native widgets, mapping computed CSS to Elementor controls.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

use HtmlToElementor\Engine\CssMappingEngine;
use HtmlToElementor\Engine\SemanticComponentRecognizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The visual reconstruction engine. Walks the layout tree and emits a native
 * Elementor element graph (containers + widgets). HTML widgets are used only as
 * a last resort (layered/absolute designs, third-party embeds, SVG, forms,
 * canvas, tables).
 */
final class LayoutTreeConverter
{

    private CssMapper $css;
    private WidgetClassifier $classifier;
    private ?SemanticComponentRecognizer $recognition = null;
    private ?CssMappingEngine $css_engine = null;

    /**
     * Running statistics for the conversion report.
     *
     * @var array<string,mixed>
     */
    private array $stats;

    public function __construct(?CssMapper $css = null, ?WidgetClassifier $classifier = null)
    {
        $this->css = $css ?? new CssMapper();
        $this->classifier = $classifier ?? new WidgetClassifier();
        $this->reset_stats();
    }

    /**
     * Wire v2 engine subsystems from the orchestrator.
     */
    public function use_engines(SemanticComponentRecognizer $recognition, CssMappingEngine $css_engine): void
    {
        $this->recognition = $recognition;
        $this->css_engine = $css_engine;
    }

    /**
     * Reset internal statistics.
     */
    public function reset_stats(): void
    {
        $this->stats = array(
            'containers' => 0,
            'widgets' => 0,
            'html_widgets' => 0,
            'native_widgets' => 0,
            'widget_breakdown' => array(),
            'roles' => array(),
            'html_fallback_reasons' => array(),
            'max_depth' => 0,
        );
    }

    /**
     * Statistics gathered during the last conversion.
     *
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Convert a section's tree into a top-level Elementor container.
     *
     * @param array<string,mixed> $tree Section root tree node.
     * @return array<string,mixed>|null
     */
    public function convert_section(array $tree): ?array
    {
        $role = (string) ($tree['layoutRole'] ?? '');

        if ('hero' === $role) {
            $hero = $this->reconstruct_hero($tree);
            if (null !== $hero) {
                return $hero;
            }
        }
        if ('navigation' === $role) {
            $nav = $this->reconstruct_navigation($tree);
            if (null !== $nav) {
                return $nav;
            }
        }

        if ($this->needs_fallback($tree)) {
            return $this->wrap_section($tree, array($this->html_widget($tree)));
        }
        $children = $this->convert_node($tree, true);
        if (empty($children)) {
            return null;
        }
        // convert_node already wrapped the section into a container.
        return $children[0];
    }

    /**
     * Convert a single node into a list of Elementor elements.
     *
     * @param array<string,mixed> $node       Tree node.
     * @param bool                $is_section Whether this is a top-level section.
     * @param bool                $parent_row Whether the parent lays children in a row.
     * @return array<int,array<string,mixed>>
     */
    private function convert_node(array $node, bool $is_section = false, bool $parent_row = false, float $parent_width = 0.0, int $depth = 0): array
    {
        $this->stats['max_depth'] = max((int) ($this->stats['max_depth'] ?? 0), $depth);

        $is_container = isset($node['children']) && empty($node['atomic']);

        if (!$is_container) {
            return $this->convert_leaf($node);
        }

        if ($this->needs_fallback($node)) {
            return array($this->html_widget($node));
        }

        $children = array();
        $child_row = 'row' === $this->row_direction($node);
        $self_width = (float) ($node['s']['w'] ?? 0);

        // Stray direct text inside a container that also has element children.
        $text = trim((string) ($node['text'] ?? ''));
        if ('' !== $text) {
            $children[] = $this->text_widget($text, $node);
        }

        foreach ((array) ($node['children'] ?? array()) as $child) {
            foreach ($this->convert_node($child, false, $child_row, $self_width, $depth + 1) as $el) {
                $children[] = $el;
            }
        }

        if (empty($children)) {
            if ($this->looks_like_spacer($node)) {
                return array($this->spacer($node));
            }
            if (!empty($node['html'])) {
                return array($this->html_widget($node));
            }
            return array();
        }

        return array($this->container($node, $children, $is_section, $parent_row, $parent_width));
    }

    /**
     * Effective flex direction of a node ("row" or "column").
     *
     * @param array<string,mixed> $node Node.
     */
    private function row_direction(array $node): string
    {
        $constraint = $node['layoutConstraint'] ?? array();
        if (!empty($constraint['direction'])) {
            return (string) $constraint['direction'];
        }
        if ('row' === ($node['visualGroup'] ?? '')) {
            return 'row';
        }

        $s = $node['s'] ?? array();
        $disp = (string) ($s['disp'] ?? '');
        if (false !== strpos($disp, 'grid')) {
            return 'row';
        }
        if (false !== strpos($disp, 'flex')) {
            return (false !== strpos(strtolower((string) ($s['fd'] ?? 'row')), 'column')) ? 'column' : 'row';
        }
        return 'column';
    }

    /**
     * Convert a leaf (atomic / text) node.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<int,array<string,mixed>>
     */
    private function convert_leaf(array $node): array
    {
        if (null !== $this->recognition) {
            $classified = $this->recognition->classify($node);
            if ('container' === $classified['kind']) {
                $text = trim((string) ($node['text'] ?? ''));
                return '' !== $text ? array($this->text_widget($text, $node)) : array();
            }
            if ('fallback' === $classified['kind']) {
                $this->stats['html_fallback_reasons'][] = array(
                    'role' => (string) ($classified['role'] ?? ''),
                    'confidence' => (int) ($classified['confidence'] ?? 0),
                    'tag' => (string) ($node['tag'] ?? ''),
                    'cls' => (string) ($node['cls'] ?? ''),
                );
                return array($this->html_widget($node));
            }
            if ('pattern' === $classified['kind']) {
                return array();
            }
            return array($this->widget(
                (string) ($classified['type'] ?? 'text-editor'),
                $classified['settings'] ?? array(),
                $node
            ));
        }

        $classified = $this->classifier->classify($node);

        if (null === $classified) {
            $text = trim((string) ($node['text'] ?? ''));
            return '' !== $text ? array($this->text_widget($text, $node)) : array();
        }
        if ('fallback' === $classified['kind']) {
            return array($this->html_widget($node));
        }
        return array($this->widget($classified['type'], $classified['settings'], $node));
    }

    /* --------------------------------------------------------------------- */
    /* Element builders                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * Build a native widget element with mapped CSS controls.
     *
     * @param string              $type     Elementor widget type.
     * @param array<string,mixed> $settings Base settings.
     * @param array<string,mixed> $node     Source node.
     * @return array<string,mixed>
     */
    private function widget(string $type, array $settings, array $node): array
    {
        $settings = array_merge($settings, $this->style_for_widget($type, $node), $this->identity($node));

        $this->stats['widgets']++;
        $this->stats['native_widgets']++;
        $this->stats['widget_breakdown'][$type] = ($this->stats['widget_breakdown'][$type] ?? 0) + 1;

        return array(
            'id' => ElementId::generate(),
            'elType' => 'widget',
            'widgetType' => $type,
            'settings' => $settings,
            'elements' => array(),
        );
    }

    /**
     * Build a container element with mapped CSS controls.
     *
     * @param array<string,mixed>            $node       Source node.
     * @param array<int,array<string,mixed>> $children   Child elements.
     * @param bool                           $is_section Top-level section flag.
     * @return array<string,mixed>
     */
    private function container(array $node, array $children, bool $is_section, bool $parent_row = false, float $parent_width = 0.0): array
    {
        $settings = array(
            'content_width' => 'full',
        );

        if (null !== $this->css_engine) {
            $settings = array_merge($settings, $this->css_engine->map_container($node, $is_section));
        } else {
            $flex = $this->css->flex($node);
            $settings = array_merge($settings, $flex);
            if (empty($settings['flex_direction'])) {
                $settings['flex_direction'] = 'column';
            }
            $settings = array_merge(
                $settings,
                $this->css->background($node),
                $this->css->border($node),
                $this->css->box_shadow($node),
                $this->css->sizing($node),
                $this->css->spacing($node, !$is_section)
            );
        }

        // v3 geometry-derived layout overrides CSS literals.
        $settings = array_merge($settings, $this->geometry_settings($node));
        $settings = array_merge($settings, $this->identity($node));

        // When this container is a column/cell inside a flex-row parent, give it
        // a percentage width derived from its measured share of the parent so
        // the row lays out side-by-side across viewports (Elementor would
        // otherwise stretch every nested container to 100%).
        if ($parent_row) {
            $width = (float) ($node['s']['w'] ?? 0);
            if ($width > 0 && $parent_width > 0) {
                $pct = (int) round(min(100, ($width / $parent_width) * 100));
                $pct = max(5, $pct);
                $settings['width'] = array('unit' => '%', 'size' => $pct);
                $settings['flex_grow'] = 0;
                $settings['flex_shrink'] = 1;
                // Stack to full width on the smallest breakpoint.
                $settings['width_mobile'] = array('unit' => '%', 'size' => 100);
            } elseif ($width > 0) {
                $settings['width'] = array('unit' => 'px', 'size' => round($width, 0));
            }
        }

        $role = $this->classifier->role($node);
        if ('' !== $role) {
            $this->stats['roles'][$role] = ($this->stats['roles'][$role] ?? 0) + 1;
        }

        $this->stats['containers']++;

        return array(
            'id' => ElementId::generate(),
            'elType' => 'container',
            'settings' => $settings,
            'elements' => array_values($children),
            'isInner' => !$is_section,
        );
    }

    /**
     * Wrap arbitrary children in a plain top-level section container.
     *
     * @param array<string,mixed>            $node     Source node.
     * @param array<int,array<string,mixed>> $children Children.
     * @return array<string,mixed>
     */
    private function wrap_section(array $node, array $children): array
    {
        $this->stats['containers']++;
        return array(
            'id' => ElementId::generate(),
            'elType' => 'container',
            'settings' => array_merge(
                array('content_width' => 'full', 'flex_direction' => 'column'),
                $this->css->background($node),
                $this->css->sizing($node),
                $this->identity($node)
            ),
            'elements' => array_values($children),
            'isInner' => false,
        );
    }

    /**
     * Build a text-editor widget from plain text.
     *
     * @param string              $text Text content.
     * @param array<string,mixed> $node Source node.
     * @return array<string,mixed>
     */
    private function text_widget(string $text, array $node): array
    {
        return $this->widget(
            'text-editor',
            array('editor' => '<p>' . esc_html($text) . '</p>'),
            $node
        );
    }

    /**
     * Build a spacer widget sized to the node height.
     *
     * @param array<string,mixed> $node Source node.
     * @return array<string,mixed>
     */
    private function spacer(array $node): array
    {
        $height = (float) ($node['s']['h'] ?? 0);
        return $this->widget(
            'spacer',
            array('space' => array('unit' => 'px', 'size' => round($height, 0))),
            $node
        );
    }

    /**
     * Build a last-resort HTML widget that preserves the original markup.
     *
     * @param array<string,mixed> $node Source node.
     * @return array<string,mixed>
     */
    private function html_widget(array $node): array
    {
        $html = (string) ($node['html'] ?? '');
        $this->stats['widgets']++;
        $this->stats['html_widgets']++;
        $this->stats['widget_breakdown']['html'] = ($this->stats['widget_breakdown']['html'] ?? 0) + 1;

        return array(
            'id' => ElementId::generate(),
            'elType' => 'widget',
            'widgetType' => 'html',
            'settings' => array_merge(array('html' => $html), $this->identity($node)),
            'elements' => array(),
        );
    }

    /* --------------------------------------------------------------------- */
    /* Style + identity                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * Map geometry constraints to Elementor container settings.
     *
     * @param array<string,mixed> $node Source node.
     * @return array<string,mixed>
     */
    private function geometry_settings(array $node): array
    {
        $out = array();
        $constraint = $node['layoutConstraint'] ?? array();
        $alignment = $node['alignment'] ?? array();
        $whitespace = $node['whitespace'] ?? array();

        if (!empty($constraint['direction'])) {
            $out['flex_direction'] = (string) $constraint['direction'];
        }
        $gap = (float) ($constraint['gap'] ?? $whitespace['gap'] ?? 0);
        if ($gap > 0) {
            $g = (string) round($gap);
            $out['flex_gap'] = array(
                'column' => $g,
                'row' => $g,
                'isLinked' => true,
                'unit' => 'px',
                'size' => round($gap),
            );
        }
        if (!empty($alignment['justify'])) {
            $out['flex_justify_content'] = (string) $alignment['justify'];
        }
        if (!empty($alignment['align_items'])) {
            $out['flex_align_items'] = (string) $alignment['align_items'];
        }
		if (!empty($constraint['stretch'])) {
			$out['flex_align_items'] = 'stretch';
		}
		if (!empty($constraint['fill'])) {
			$out['flex_grow'] = 1;
		}
		if (!empty($constraint['auto_width'])) {
			$out['width'] = array('unit' => '%', 'size' => 100);
		}

		$responsive = (array) ($node['responsiveLayout'] ?? array());
		if (!empty($responsive['tablet']['flex_direction'])) {
			$out['flex_direction_tablet'] = (string) $responsive['tablet']['flex_direction'];
		}
		if (!empty($responsive['mobile']['flex_direction'])) {
			$out['flex_direction_mobile'] = (string) $responsive['mobile']['flex_direction'];
		}

        return $out;
    }

    /**
     * Whether a container should fall back to an HTML widget.
     *
     * @param array<string,mixed> $node Source node.
     */
    private function needs_fallback(array $node): bool
    {
        if (null !== $this->recognition) {
            return $this->recognition->container_needs_fallback($node);
        }
        return $this->classifier->container_needs_fallback($node);
    }

    /**
     * Reconstruct a hero section natively: background image, overlay, content.
     *
     * @param array<string,mixed> $node Hero section node.
     * @return array<string,mixed>|null
     */
    private function reconstruct_hero(array $node): ?array
    {
        $children = (array) ($node['children'] ?? array());
        $bg_image = null;
        $overlay = null;
        $content_children = array();

        foreach ($children as $child) {
            $tag = strtolower((string) ($child['tag'] ?? ''));
            $pos = strtolower((string) ($child['s']['pos'] ?? ''));
            $cls = strtolower((string) ($child['cls'] ?? ''));

            if ('img' === $tag) {
                $bg_image = $child;
                continue;
            }
            if (false !== strpos($cls, 'overlay') || ('absolute' === $pos && empty($child['text']) && empty($child['children']))) {
                $overlay = $child;
                continue;
            }
            if ('absolute' === $pos || false !== strpos($cls, 'hero-content') || false !== strpos($cls, 'content')) {
                foreach ($this->convert_node($child, false) as $el) {
                    $content_children[] = $el;
                }
                continue;
            }
            foreach ($this->convert_node($child, false) as $el) {
                $content_children[] = $el;
            }
        }

        if (empty($content_children) && empty($bg_image)) {
            return null;
        }

        $settings = array_merge(
            array('content_width' => 'full', 'flex_direction' => 'column', 'min_height' => array('unit' => 'px', 'size' => (float) ($node['s']['h'] ?? 380))),
            $this->css->background($node),
            $this->css->sizing($node),
            $this->identity($node)
        );

        if (null !== $bg_image) {
            $src = (string) ($bg_image['src'] ?? '');
            if ('' !== $src) {
                $settings['background_background'] = 'classic';
                $settings['background_image'] = array('url' => $src, 'id' => '');
                $settings['background_position'] = 'center center';
                $settings['background_size'] = 'cover';
            }
        }
        if (null !== $overlay && !empty($overlay['s']['bgImg'])) {
            $settings['_h2e_hero_overlay'] = (string) $overlay['s']['bgImg'];
        }

        $this->stats['containers']++;
        $this->stats['roles']['hero'] = ($this->stats['roles']['hero'] ?? 0) + 1;

        return array(
            'id' => ElementId::generate(),
            'elType' => 'container',
            'settings' => $settings,
            'elements' => array_values($content_children),
            'isInner' => false,
        );
    }

    /**
     * Reconstruct navigation as nested containers + native widgets.
     *
     * @param array<string,mixed> $node Nav node.
     * @return array<string,mixed>|null
     */
    private function reconstruct_navigation(array $node): ?array
    {
        $widgets = array();
        foreach ($this->flatten_nav_widgets($node) as $w) {
            $widgets[] = $w;
        }
        if (empty($widgets)) {
            return null;
        }

        $settings = array_merge(
            array(
                'content_width' => 'full',
                'flex_direction' => 'row',
                'flex_justify_content' => 'space-between',
                'flex_align_items' => 'center',
            ),
            $this->css->background($node),
            $this->css->spacing($node, true),
            $this->identity($node)
        );

        $this->stats['containers']++;
        $this->stats['roles']['nav'] = ($this->stats['roles']['nav'] ?? 0) + 1;

        return array(
            'id' => ElementId::generate(),
            'elType' => 'container',
            'settings' => $settings,
            'elements' => $widgets,
            'isInner' => false,
        );
    }

    /**
     * Flatten nav tree into a row of native widgets.
     *
     * @param array<string,mixed> $node Nav node.
     * @return array<int,array<string,mixed>>
     */
    private function flatten_nav_widgets(array $node): array
    {
        $out = array();
        if (!empty($node['atomic'])) {
            $leaf = $this->convert_leaf($node);
            return $leaf;
        }
        foreach ((array) ($node['children'] ?? array()) as $child) {
            if (!is_array($child)) {
                continue;
            }
            $tag = strtolower((string) ($child['tag'] ?? ''));
            if (in_array($tag, array('ul', 'ol'), true)) {
                foreach ((array) ($child['children'] ?? array()) as $li) {
                    if (!is_array($li)) {
                        continue;
                    }
                    foreach ($this->convert_leaf($li) as $w) {
                        $out[] = $w;
                    }
                }
                continue;
            }
            if (!empty($child['children'])) {
                $out = array_merge($out, $this->flatten_nav_widgets($child));
                continue;
            }
            foreach ($this->convert_leaf($child) as $w) {
                $out[] = $w;
            }
        }
        return $out;
    }

    private function style_for_widget(string $type, array $node): array
    {
        if (null !== $this->css_engine) {
            return $this->css_engine->map_widget($node, $type);
        }
        switch ($type) {
            case 'heading':
                return array_merge(
                    $this->css->typography($node),
                    $this->css->text_color($node, 'title_color'),
                    $this->css->alignment($node, 'align'),
                    $this->css->spacing($node, true)
                );
            case 'text-editor':
                return array_merge(
                    $this->css->typography($node),
                    $this->css->text_color($node, 'text_color'),
                    $this->css->alignment($node, 'align'),
                    $this->css->spacing($node, true)
                );
            case 'button':
                $style = array_merge(
                    $this->css->typography($node),
                    $this->css->text_color($node, 'button_text_color'),
                    $this->css->alignment($node, 'align'),
                    $this->css->border($node),
                    $this->css->box_shadow($node)
                );
                $bg = (string) ($node['s']['bg'] ?? '');
                if ('' !== $bg) {
                    $style['background_color'] = $bg;
                }
                return $style;
            case 'image':
                return array_merge(
                    $this->css->alignment($node, 'align'),
                    $this->css->spacing($node, true),
                    $this->css->border($node),
                    $this->css->box_shadow($node)
                );
            case 'icon':
                $out = array();
                if (!empty($node['s']['color'])) {
                    $out['primary_color'] = (string) $node['s']['color'];
                }
                return $out;
            case 'icon-list':
            case 'divider':
            case 'spacer':
            case 'video':
            case 'google_maps':
            default:
                return $this->css->spacing($node, true);
        }
    }

    /**
     * Retain original id/classes so the imported page benefits from the source
     * stylesheet (kept editable in Elementor's Advanced tab).
     *
     * @param array<string,mixed> $node Source node.
     * @return array<string,mixed>
     */
    private function identity(array $node): array
    {
        $out = array();
        $classes = trim((string) ($node['cls'] ?? ''));
        if ('' !== $classes) {
            $out['_css_classes'] = $classes;
        }
        $id = trim((string) ($node['id'] ?? ''));
        if ('' !== $id) {
            $out['_element_id'] = sanitize_html_class($id);
        }
        return $out;
    }

    /**
     * Heuristic: an empty block with height that acts as vertical spacing.
     *
     * @param array<string,mixed> $node Source node.
     */
    private function looks_like_spacer(array $node): bool
    {
        $s = $node['s'] ?? array();
        $h = (float) ($s['h'] ?? 0);
        $has_visual = !empty($s['bg']) || !empty($s['bgImg']) || !empty($s['bdw']);
        return $h >= 6 && $h <= 400 && !$has_visual;
    }
}
