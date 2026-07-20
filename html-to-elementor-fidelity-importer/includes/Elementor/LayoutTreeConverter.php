<?php
/**
 * Recursively reconstructs a Chromium DOM tree as nested Elementor containers
 * and native widgets, mapping computed CSS to Elementor controls.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

use HtmlToElementor\Engine\CompositePatternBuilder;
use HtmlToElementor\Engine\CssMappingEngine;
use HtmlToElementor\Engine\LayeredLayoutSolver;
use HtmlToElementor\Engine\SemanticComponentRecognizer;
use HtmlToElementor\Engine\VisualSignals;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Facade for the v4 Layout Graph Emitter. Retains public API for backward
 * compatibility while emission is driven by solved layout constraints.
 */
final class LayoutTreeConverter
{

    private CssMapper $css;
    private WidgetClassifier $classifier;
    private CompositePatternBuilder $patterns;
    private ?SemanticComponentRecognizer $recognition = null;
    private ?CssMappingEngine $css_engine = null;
    private ?LayeredLayoutSolver $layered_solver = null;
    private ?LayoutGraphEmitter $emitter = null;

    /**
     * Running statistics for the conversion report.
     *
     * @var array<string,mixed>
     */
    private array $stats;

    public function __construct(?CssMapper $css = null, ?WidgetClassifier $classifier = null, ?CompositePatternBuilder $patterns = null)
    {
        $this->css = $css ?? new CssMapper();
        $this->classifier = $classifier ?? new WidgetClassifier();
        $this->patterns = $patterns ?? new CompositePatternBuilder();
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
        return $this->graph_emitter()->emit_section($tree);
    }

    /* --------------------------------------------------------------------- */
    /* Public API for LayoutGraphEmitter                                     */
    /* --------------------------------------------------------------------- */

    public function emit_layered_block(array $node): ?array
    {
        $layered = $this->layered_solver()->to_container(
            $node,
            fn(array $n): array => $this->emit_children_legacy($n, false),
            function (string $r): void {
                $this->stats['roles'][$r] = ($this->stats['roles'][$r] ?? 0) + 1;
            }
        );
        return $layered;
    }

    public function emit_horizontal_bar(array $node): ?array
    {
        return $this->reconstruct_horizontal_bar($node);
    }

    /**
     * Emit a composite native widget (accordion, form, testimonial, …) when
     * the node matches a recognised marketing-page pattern.
     *
     * @param array<string,mixed> $node Source node.
     * @return array<string,mixed>|null
     */
    public function emit_composite_widget(array $node): ?array
    {
        $built = $this->patterns->build($node);
        if (null === $built) {
            return null;
        }

        $type = (string) ($built['type'] ?? '');
        $settings = (array) ($built['settings'] ?? array());
        $role = (string) ($built['role'] ?? '');

        // Accordion tabs need stable Elementor repeater IDs.
        if ('accordion' === $type && !empty($settings['tabs']) && is_array($settings['tabs'])) {
            foreach ($settings['tabs'] as $i => $tab) {
                if (empty($tab['_id'])) {
                    $settings['tabs'][$i]['_id'] = ElementId::generate();
                }
            }
        }
        if ('form' === $type && !empty($settings['form_fields']) && is_array($settings['form_fields'])) {
            foreach ($settings['form_fields'] as $i => $field) {
                if (empty($field['_id'])) {
                    $settings['form_fields'][$i]['_id'] = ElementId::generate();
                }
            }
        }
        if ('social-icons' === $type && !empty($settings['social_icon_list']) && is_array($settings['social_icon_list'])) {
            foreach ($settings['social_icon_list'] as $i => $item) {
                if (empty($item['_id'])) {
                    $settings['social_icon_list'][$i]['_id'] = ElementId::generate();
                }
            }
        }

        if ('' !== $role) {
            $this->stats['roles'][$role] = ($this->stats['roles'][$role] ?? 0) + 1;
        }

        $widget = $this->widget($type, $settings, $node);

        // Painted chrome descendants (card-icon / avatar / logo-mark) are absorbed
        // into composites and would otherwise lose their browser gradients. Emit
        // them as nested containers so paint survives Elementor reconstruction.
        $chrome = $this->emit_paint_chrome_elements($node);
        if (empty($chrome)) {
            return $widget;
        }

        $this->strip_background_settings($widget['settings']);
        return $this->container($node, array_merge($chrome, array($widget)), false, false, 0.0);
    }

    public function emit_fallback_wrap(array $node): array
    {
        return $this->wrap_section($node, array($this->html_widget($node)));
    }

    public function needs_html_fallback(array $node): bool
    {
        return $this->needs_fallback($node);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function emit_leaves(array $node): array
    {
        return $this->convert_leaf($node);
    }

    /**
     * @param array<int,array<string,mixed>> $children Children.
     */
    public function emit_container(array $node, array $children, bool $is_section, bool $parent_row, float $parent_width): array
    {
        return $this->container($node, $children, $is_section, $parent_row, $parent_width);
    }

    public function emit_html_widget(array $node): array
    {
        return $this->html_widget($node);
    }

    public function emit_text_widget(string $text, array $node): array
    {
        return $this->text_widget($text, $node);
    }

    public function emit_spacer(array $node): array
    {
        return $this->spacer($node);
    }

    public function looks_like_spacer(array $node): bool
    {
        return $this->looks_like_spacer_internal($node);
    }

    public function flex_direction(array $node): string
    {
        return $this->row_direction($node);
    }

    private function graph_emitter(): LayoutGraphEmitter
    {
        return $this->emitter ??= new LayoutGraphEmitter($this);
    }

    /**
     * Legacy child emission for layered content blocks.
     *
     * @return array<int,array<string,mixed>>
     */
    private function emit_children_legacy(array $node, bool $is_section): array
    {
        if (!empty($node['atomic'])) {
            return $this->convert_leaf($node);
        }
        $children = array();
        $text = trim((string) ($node['text'] ?? ''));
        if ('' === $text && !empty($node['html'])) {
            $text = trim(wp_strip_all_tags((string) $node['html']));
        }
        // Absolute badges / overlays often carry label text on the wrapper.
        if ('' !== $text && empty($node['children'])) {
            return $this->convert_leaf(array_merge($node, array('atomic' => true, 'text' => $text)));
        }
        if ('' !== $text) {
            $cls = strtolower((string) ($node['cls'] ?? ''));
            if (preg_match('/\b(badge|chip|pill|label|hero-badge)\b/', $cls) || VisualSignals::looks_button(array_merge($node, array('text' => $text)))) {
                $children[] = $this->widget(
                    'button',
                    array(
                        'text' => $text,
                        'link' => array('url' => (string) ($node['href'] ?? ''), 'is_external' => '', 'nofollow' => ''),
                    ),
                    $node
                );
                return $children;
            }
            $children[] = $this->text_widget($text, $node);
        }
        foreach ((array) ($node['children'] ?? array()) as $child) {
            if (!is_array($child)) {
                continue;
            }
            foreach ($this->emit_children_legacy($child, false) as $el) {
                $children[] = $el;
            }
        }
        return $children;
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
                if ('' !== $text) {
                    return array($this->text_widget($text, $node));
                }
                // Painted leaf with no text (e.g. gradient blog thumbs) must still emit.
                $signals = \HtmlToElementor\Engine\VisualSignals::analyze($node);
                if ($signals['has_background'] || $signals['has_border'] || $signals['has_shadow']) {
                    return array($this->container($node, array(), false, false, 0.0));
                }
                return array();
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
        $settings = array_merge($settings, $this->style_for_widget($type, $node), $this->identity($node, $type));
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
                $this->css->effects($node),
                $this->css->spacing($node, !$is_section)
            );
        }

        // v4 geometry-derived layout overrides CSS literals.
        $settings = array_merge($settings, $this->geometry_settings($node));
        $settings = array_merge($settings, $this->responsive_settings($node));
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
                // Shrinking %-columns below measure wraps phones/nav labels in
                // real Elementor even when the preview oracle still looked fine.
                $settings['flex_shrink'] = 0;
                // Full-width on mobile only when the parent row actually stacks.
                if (!empty($node['responsiveConstraints']['full_width_mobile'])) {
                    $settings['width_mobile'] = array('unit' => '%', 'size' => 100);
                }
            } elseif ($width > 0) {
                $settings['width'] = array('unit' => 'px', 'size' => round($width, 0));
                $settings['flex_shrink'] = 0;
            }
        } elseif ($this->should_lock_intrinsic_box($node)) {
            // Column parents default nested containers to width:100% in Elementor.
            // Painted chrome (icons, avatars, marks) must keep measured px size.
            $width = (float) ($node['s']['w'] ?? 0);
            $height = (float) ($node['s']['h'] ?? 0);
            if ($width > 0) {
                $settings['width'] = array('unit' => 'px', 'size' => round($width, 0));
            }
            if ($height > 0 && empty($settings['min_height']['size'])) {
                $settings['min_height'] = array('unit' => 'px', 'size' => round($height, 0));
            }
            $settings['flex_grow'] = 0;
            $settings['flex_shrink'] = 0;
            if (empty($settings['align_self'])) {
                $settings['align_self'] = 'flex-start';
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
        // Chromium CSS justify/align wins over inferred constraints. Constraint
        // inference often mis-labels space-between headers (padding insets) as
        // flex-start, which collapses logo/nav/CTA into a left cluster.
        $css_jc = strtolower(trim((string) ($node['s']['jc'] ?? '')));
        $css_ai = strtolower(trim((string) ($node['s']['ai'] ?? '')));
        if ('' !== $css_jc) {
            $mapped = $this->css->flex_align($css_jc);
            if ('' !== $mapped) {
                $out['flex_justify_content'] = $mapped;
            }
        } elseif (!empty($constraint['justify'])) {
            $out['flex_justify_content'] = (string) $constraint['justify'];
        } elseif (!empty($alignment['justify'])) {
            $out['flex_justify_content'] = (string) $alignment['justify'];
        }
        if ('' !== $css_ai) {
            $mapped = $this->css->flex_align($css_ai);
            if ('' !== $mapped) {
                $out['flex_align_items'] = $mapped;
            }
        } elseif (!empty($constraint['align_items'])) {
            $out['flex_align_items'] = (string) $constraint['align_items'];
        } elseif (!empty($alignment['align_items'])) {
            $out['flex_align_items'] = (string) $alignment['align_items'];
        }
		if (!empty($constraint['stretch']) && empty($constraint['align_items'])) {
			$out['flex_align_items'] = 'stretch';
		}
		if (!empty($constraint['fill'])) {
			$out['flex_grow'] = 1;
		}
		if (!empty($constraint['auto_width'])) {
			$out['width'] = array('unit' => '%', 'size' => 100);
		}
		if (!empty($constraint['intents']['sticky']) || 'sticky' === ($node['s']['pos'] ?? '')) {
			$out['position'] = 'sticky';
		}
		if (!empty($constraint['intents']['aspect_locked']) && !empty($node['s']['ar']) && 'auto' !== $node['s']['ar']) {
			$out['_h2e_custom_css'] = trim(
				((string) ($out['_h2e_custom_css'] ?? '')) . ';aspect-ratio:' . $node['s']['ar'],
				" \t\n\r\0\x0B;"
			);
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
     * Map responsive layout constraints to Elementor breakpoint controls.
     *
     * @param array<string,mixed> $node Source node.
     * @return array<string,mixed>
     */
    private function responsive_settings(array $node): array
    {
        $out = array();
        $responsive = $node['responsiveLayout'] ?? array();
        if (!is_array($responsive)) {
            return $out;
        }

        if (!empty($responsive['mobile']['flex_direction'])) {
            $out['flex_direction_mobile'] = (string) $responsive['mobile']['flex_direction'];
        }
        if (!empty($responsive['tablet']['flex_direction'])) {
            $out['flex_direction_tablet'] = (string) $responsive['tablet']['flex_direction'];
        }
        if (!empty($responsive['mobile']['width']) && is_array($responsive['mobile']['width'])) {
            $out['width_mobile'] = $responsive['mobile']['width'];
        }
        if (!empty($responsive['tablet']['width']) && is_array($responsive['tablet']['width'])) {
            $out['width_tablet'] = $responsive['tablet']['width'];
        }

        $rc = $node['responsiveConstraints'] ?? array();
        if (!empty($rc['mobile_stack']) && empty($out['flex_direction_mobile'])) {
            $out['flex_direction_mobile'] = 'column';
        }
        if (!empty($rc['tablet_stack']) && empty($out['flex_direction_tablet'])) {
            $out['flex_direction_tablet'] = 'column';
        }
        if (!empty($rc['full_width_mobile']) && empty($out['width_mobile'])) {
            $out['width_mobile'] = array('unit' => '%', 'size' => 100);
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
     * Reconstruct a wide shallow row (navigation, toolbar) from geometry.
     *
     * @param array<string,mixed> $node Bar node.
     * @return array<string,mixed>|null
     */
    private function reconstruct_horizontal_bar(array $node): ?array
    {
        // Nested flex groups (logo + nav, nav-list + CTA) must keep their own
        // structure and gaps. Flattening every atom into one row is what made
        // Petra headers render as "HomeAngebotPetra…" with zero spacing.
        if ($this->has_nested_layout_groups($node)) {
            return null;
        }

        $widgets = $this->flatten_atomic_widgets($node);
        if (empty($widgets)) {
            return null;
        }

        // Atomic-only rows with no own gap/paint should hoist into the parent
        // row (Bootstrap-style nav-links). Rows with a real gap (Petra nav-list
        // at 28px) keep their container so spacing survives.
        if (!$this->bar_needs_own_box($node)) {
            return null;
        }

        $alignment = $node['alignment'] ?? array();

        $settings = array_merge(
            array(
                'content_width' => 'full',
                'flex_direction' => 'row',
                'flex_justify_content' => (string) ($alignment['justify'] ?? $node['s']['jc'] ?? 'space-between'),
                'flex_align_items' => (string) ($alignment['align_items'] ?? $node['s']['ai'] ?? 'center'),
            ),
            null !== $this->css_engine
                ? $this->css_engine->map_container($node, true)
                : array_merge($this->css->background($node), $this->css->sizing($node)),
            $this->geometry_settings($node),
            $this->responsive_settings($node),
            $this->identity($node)
        );

        $this->stats['containers']++;
        $this->stats['roles']['horizontal_bar'] = ($this->stats['roles']['horizontal_bar'] ?? 0) + 1;

        return array(
            'id' => ElementId::generate(),
            'elType' => 'container',
            'settings' => $settings,
            'elements' => $widgets,
            'isInner' => false,
        );
    }

    /**
     * True when a bar contains nested non-atomic groups that own their layout.
     *
     * @param array<string,mixed> $node Bar node.
     */
    private function has_nested_layout_groups(array $node): bool
    {
        foreach ((array) ($node['children'] ?? array()) as $child) {
            if (!is_array($child) || !empty($child['atomic']) || !empty($child['atomicText'])) {
                continue;
            }
            $grand = array_values(array_filter((array) ($child['children'] ?? array()), 'is_array'));
            if (count($grand) >= 1) {
                return true;
            }
            $disp = strtolower((string) ($child['s']['disp'] ?? ''));
            if (false !== strpos($disp, 'flex') || false !== strpos($disp, 'grid')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an atomic-only bar must keep its own Elementor container.
     *
     * @param array<string,mixed> $node Bar node.
     */
    private function bar_needs_own_box(array $node): bool
    {
        $gap = $node['s']['gap'] ?? 0;
        if (is_string($gap)) {
            $gap = (float) $gap;
        }
        if ((float) $gap > 0) {
            return true;
        }

        // Ignore s.pt/pb — WhitespaceAnalyzer may stamp invented insets there.
        // Background/image still require an own box.
        $s = $node['s'] ?? array();
        $bg = strtolower((string) ($s['bg'] ?? ''));
        if ('' !== $bg && 'transparent' !== $bg && false === strpos($bg, 'rgba(0, 0, 0, 0)')) {
            return true;
        }

        return !empty($s['bgImg']);
    }

    /**
     * Collect leaf widgets in document order without HTML tag assumptions.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<int,array<string,mixed>>
     */
    private function flatten_atomic_widgets(array $node): array
    {
        $out = array();
        // atomicText spans (breadcrumb separators, logo marks) are leaves too.
        if (!empty($node['atomic']) || !empty($node['atomicText'])) {
            foreach ($this->convert_leaf($node) as $w) {
                $out[] = $w;
            }
            return $out;
        }

        $children = array_values(array_filter((array) ($node['children'] ?? array()), 'is_array'));
        if (empty($children)) {
            $text = trim((string) ($node['text'] ?? ''));
            if ('' !== $text) {
                foreach ($this->convert_leaf($node) as $w) {
                    $out[] = $w;
                }
            }
            return $out;
        }

        foreach ($children as $child) {
            $out = array_merge($out, $this->flatten_atomic_widgets($child));
        }
        return $out;
    }

    private function layered_solver(): LayeredLayoutSolver
    {
        return $this->layered_solver ??= new LayeredLayoutSolver($this->css);
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
                    $this->css->spacing($node, true),
                    $this->css->background($node),
                    $this->css->border($node)
                );
            case 'text-editor':
                return array_merge(
                    $this->css->typography($node),
                    $this->css->text_color($node, 'text_color'),
                    $this->css->alignment($node, 'align'),
                    $this->css->spacing($node, true),
                    $this->css->background($node),
                    $this->css->border($node)
                );
            case 'button':
                $style = array_merge(
                    $this->css->typography($node),
                    $this->css->text_color($node, 'button_text_color'),
                    $this->css->alignment($node, 'align'),
                    $this->css->border($node),
                    $this->css->background($node)
                );
                // Elementor Button control ids (button-trait.php).
                $spacing = $this->css->spacing($node, false);
                if (!empty($spacing['padding']) && is_array($spacing['padding'])) {
                    $style['text_padding'] = $spacing['padding'];
                }
                $shadow = $this->css->box_shadow($node);
                if (!empty($shadow['box_shadow_box_shadow_type'])) {
                    $style['button_box_shadow_box_shadow_type'] = $shadow['box_shadow_box_shadow_type'];
                }
                if (!empty($shadow['box_shadow_box_shadow'])) {
                    $style['button_box_shadow_box_shadow'] = $shadow['box_shadow_box_shadow'];
                }
                // Legacy solid fill when background() found nothing useful.
                if (empty($style['background_background']) && empty($style['background_color'])) {
                    $bg = (string) ($node['s']['bg'] ?? '');
                    if ('' !== $bg && false === stripos($bg, 'gradient')) {
                        $style['background_background'] = 'classic';
                        $style['background_color'] = $bg;
                    } elseif (!empty($style['border_border'])) {
                        $style['background_background'] = 'classic';
                        $style['background_color'] = 'rgba(0,0,0,0)';
                    }
                }
                $gap = $node['s']['gap'] ?? null;
                if (is_numeric($gap) && (float) $gap > 0) {
                    $style['icon_indent'] = array('unit' => 'px', 'size' => (float) $gap);
                } elseif (is_string($gap) && preg_match('/^(-?\d+(?:\.\d+)?)\s*px/i', trim($gap), $m)) {
                    $style['icon_indent'] = array('unit' => 'px', 'size' => (float) $m[1]);
                }
                return $style;
            case 'image':
                return array_merge(
                    $this->css->alignment($node, 'align'),
                    $this->css->spacing($node, true),
                    $this->css->border($node),
                    $this->css->box_shadow($node),
                    $this->css->image_media($node),
                    $this->css->effects($node)
                );
            case 'icon':
                $out = array();
                if (!empty($node['s']['color'])) {
                    $out['primary_color'] = (string) $node['s']['color'];
                }
                $grad = $this->css->parse_gradient((string) ($node['s']['bgImg'] ?? ''));
                if (null !== $grad) {
                    $out['view'] = 'stacked';
                    $out['primary_color'] = $grad['color_a'];
                    $out['secondary_color'] = $grad['color_b'];
                }
                return $out;
            case 'icon-box':
            case 'price-table':
            case 'testimonial':
            case 'call-to-action':
                return array_merge(
                    $this->css->spacing($node, true),
                    $this->css->background($node),
                    $this->css->border($node),
                    $this->css->box_shadow($node)
                );
            case 'accordion':
                return array_merge(
                    $this->css->spacing($node, true),
                    $this->css->background($node),
                    $this->css->border($node),
                    $this->css->box_shadow($node)
                );
            case 'form':
            case 'social-icons':
            case 'star-rating':
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
     * Emit small painted chrome nodes that composites would otherwise discard.
     *
     * @param array<string,mixed> $node Composite root.
     * @return array<int,array<string,mixed>>
     */
    private function emit_paint_chrome_elements(array $node): array
    {
        $out = array();
        $this->collect_paint_chrome($node, $node, $out);
        return $out;
    }

    /**
     * @param array<string,mixed>            $root Root composite node.
     * @param array<string,mixed>            $node Current node.
     * @param array<int,array<string,mixed>> $out  Accumulators.
     */
    private function collect_paint_chrome(array $root, array $node, array &$out): void
    {
        if ($node !== $root && $this->is_paint_chrome($node)) {
            $children = array();
            $text = trim((string) ($node['text'] ?? ''));
            // Prefer a nested icon glyph when present; otherwise keep empty chrome box.
            foreach ((array) ($node['children'] ?? array()) as $child) {
                if (!is_array($child)) {
                    continue;
                }
                if (!empty($child['atomic']) || empty($child['children'])) {
                    foreach ($this->convert_leaf($child) as $leaf) {
                        $children[] = $leaf;
                    }
                }
            }
            if (empty($children) && '' !== $text && mb_strlen($text) <= 3) {
                $children[] = $this->widget(
                    'heading',
                    array(
                        'title' => $text,
                        'header_size' => 'span',
                    ),
                    $node
                );
            }
            $out[] = $this->container($node, $children, false, false, 0.0);
            return;
        }

        foreach ((array) ($node['children'] ?? array()) as $child) {
            if (is_array($child)) {
                $this->collect_paint_chrome($root, $child, $out);
            }
        }
    }

    /**
     * Small gradient/solid badges (icons, avatars, logo marks) used as chrome.
     *
     * @param array<string,mixed> $node Node.
     */
    private function is_paint_chrome(array $node): bool
    {
        $s = $node['s'] ?? array();
        $bg_img = (string) ($s['bgImg'] ?? '');
        $bg = (string) ($s['bg'] ?? '');
        $has_grad = false !== stripos($bg_img, 'gradient') || false !== stripos($bg, 'gradient');
        $has_solid = '' !== $bg && 'transparent' !== strtolower($bg) && false === stripos($bg, 'gradient');
        $has_img = (bool) preg_match('/url\(/i', $bg_img);
        if (!$has_grad && !$has_solid && !$has_img) {
            return false;
        }

        $cls = strtolower((string) ($node['cls'] ?? ''));
        if (preg_match('/\b(card-icon|avatar|logo-mark|icon-badge|icon-wrap|media-icon)\b/', $cls)) {
            return true;
        }

        $w = (float) ($s['w'] ?? 0);
        $h = (float) ($s['h'] ?? 0);
        return $has_grad && $w > 0 && $h > 0 && $w <= 96 && $h <= 96;
    }

    /**
     * @param array<string,mixed> $settings Widget settings (by ref).
     */
    private function strip_background_settings(array &$settings): void
    {
        foreach (array(
            'background_background',
            'background_color',
            'background_color_b',
            'background_gradient_type',
            'background_gradient_angle',
            'background_gradient_position',
            'background_image',
            'background_size',
            'background_position',
            'background_repeat',
            'background_overlay_background',
            'background_overlay_color',
            'background_overlay_color_b',
            'background_overlay_gradient_type',
            'background_overlay_gradient_angle',
            'background_overlay_gradient_position',
        ) as $key) {
            unset($settings[$key]);
        }
    }

    /**
     * Retain original id/classes so the imported page benefits from the source
     * stylesheet (kept editable in Elementor's Advanced tab).
     *
     * Font Awesome / btn utility classes are stripped for native widgets — see
     * sanitize_css_classes() — otherwise source CSS + Elementor controls double
     * paint (2× FA ::before icons, messy button shadows).
     *
     * @param array<string,mixed> $node         Source node.
     * @param string              $widget_type  Elementor widget type when emitting a widget.
     * @return array<string,mixed>
     */
    private function identity(array $node, string $widget_type = ''): array
    {
        $out = array();
        $classes = $this->sanitize_css_classes(trim((string) ($node['cls'] ?? '')), $widget_type);
        if ('' !== $classes) {
            $out['_css_classes'] = $classes;
        }
        $id = trim((string) ($node['id'] ?? ''));
        if ('' !== $id) {
            $out['_element_id'] = sanitize_html_class($id);
        }
        // Carry source geometry so GeometryComparator can score fidelity without
        // relying solely on Elementor flex simulation estimates.
        $box = \HtmlToElementor\Engine\Geometry::bbox($node);
        if (($box['width'] ?? 0) > 0 || ($box['height'] ?? 0) > 0) {
            $out['_h2e_bbox'] = array(
                'x' => (float) ($box['x'] ?? 0),
                'y' => (float) ($box['y'] ?? 0),
                'width' => (float) ($box['width'] ?? 0),
                'height' => (float) ($box['height'] ?? 0),
            );
        }
        return $out;
    }

    /**
     * Strip classes that must not land on the Elementor wrapper.
     *
     * @param string $classes     Raw class attribute.
     * @param string $widget_type Elementor widget type when known.
     */
    private function sanitize_css_classes(string $classes, string $widget_type = ''): string
    {
        if ('' === $classes) {
            return '';
        }
        $parts = preg_split('/\s+/', $classes) ?: array();
        $kept = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ('' === $part) {
                continue;
            }
            // Font Awesome 4/5/6 tokens — FA CSS injects ::before on the wrapper.
            if (preg_match('/^fa(?:s|r|b|l|d)?$/', $part)
                || preg_match('/^fa-(?:solid|regular|brands|light|duotone|thin)$/', $part)
                || preg_match('/^fa-[\w-]+$/', $part)
            ) {
                continue;
            }
            // Button chrome — Elementor Button already carries mapped paint; keeping
            // btn/btn-gold re-applies injected source CSS on the wrapper (double glow).
            if (in_array($widget_type, array('button', 'call-to-action'), true)
                && (preg_match('/^btn(?:-[\w-]+)?$/', $part) || 'button' === $part)
            ) {
                continue;
            }
            if (in_array($widget_type, array('icon', 'icon-box', 'social-icons'), true)
                && preg_match('/^(?:icon|glyphicon)(?:-[\w-]+)?$/', $part)
            ) {
                continue;
            }
            $kept[] = $part;
        }
        return trim(implode(' ', array_unique($kept)));
    }

    /**
     * Heuristic: an empty block with height that acts as vertical spacing.
     *
     * @param array<string,mixed> $node Source node.
     */
    private function looks_like_spacer_internal(array $node): bool
    {
        $s = $node['s'] ?? array();
        $h = (float) ($s['h'] ?? 0);
        $has_visual = !empty($s['bg']) || !empty($s['bgImg']) || !empty($s['bdw']);
        return $h >= 6 && $h <= 400 && !$has_visual;
    }

    /**
     * Lock measured px size for painted chrome that would otherwise stretch to 100%.
     *
     * @param array<string,mixed> $node Source node.
     */
    private function should_lock_intrinsic_box(array $node): bool
    {
        $s = $node['s'] ?? array();
        $w = (float) ($s['w'] ?? 0);
        $h = (float) ($s['h'] ?? 0);
        if ($w <= 0 || $h <= 0 || $w > 160 || $h > 160) {
            return false;
        }

        $cls = strtolower((string) ($node['cls'] ?? ''));
        if (preg_match('/\b(card-icon|avatar|logo-mark|icon-badge|icon-wrap|media-icon|google-logo)\b/', $cls)) {
            return true;
        }

        $disp = strtolower((string) ($s['disp'] ?? ''));
        $gtc = trim((string) ($s['gtc'] ?? ''));
        $gtr = trim((string) ($s['gtr'] ?? ''));
        if (false !== strpos($disp, 'grid') && preg_match('/^\d+(\.\d+)?px$/', $gtc) && preg_match('/^\d+(\.\d+)?px$/', $gtr)) {
            return true;
        }

        $bg_img = (string) ($s['bgImg'] ?? '');
        $bg = (string) ($s['bg'] ?? '');
        $has_paint = (false !== stripos($bg_img, 'gradient'))
            || ('' !== $bg && 'transparent' !== strtolower($bg) && false === stripos($bg, 'rgba(0, 0, 0, 0)'));
        return $has_paint && $w <= 96 && $h <= 96;
    }
}
