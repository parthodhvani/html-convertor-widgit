<?php
/**
 * Maps computed CSS (captured by the Chromium service) to Elementor controls.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts a node's computed style set into Elementor widget/container settings
 * (typography, colours, spacing, background, border, shadow, flex layout) with
 * responsive (tablet/mobile) variants. Styling lives in native Elementor
 * controls rather than inline HTML.
 */
final class CssMapper
{

    /**
     * Build typography settings for a content widget (heading / text / button).
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function typography(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();

        $family = $this->font_family((string) ($s['ff'] ?? ''));
        $size = $this->size($s['fs'] ?? null);
        $weight = $this->font_weight((string) ($s['fw'] ?? ''));

        if ($family || $size || $weight) {
            $out['typography_typography'] = 'custom';
        }
        if ($family) {
            $out['typography_font_family'] = $family;
        }
        if ($size) {
            $out['typography_font_size'] = $size;
            $this->add_responsive_size($out, 'typography_font_size', $node, 'fs');
        }
        if ('' !== $weight) {
            $out['typography_font_weight'] = $weight;
        }
        $transform = strtolower((string) ($s['tt'] ?? ''));
        if ($transform && 'none' !== $transform) {
            $out['typography_text_transform'] = $transform;
        }
        $lh = $this->line_height($s['lh'] ?? null, $s['fs'] ?? null);
        if ($lh) {
            $out['typography_line_height'] = $lh;
        }
        $ls = $this->size($s['ls'] ?? null);
        if ($ls && 'normal' !== ($s['ls'] ?? '')) {
            $out['typography_letter_spacing'] = $ls;
        }
        $decoration = strtolower((string) ($s['td'] ?? ''));
        if ($decoration && 'none' !== $decoration && false !== strpos($decoration, 'underline')) {
            $out['typography_text_decoration'] = 'underline';
        }

        return $out;
    }

    /**
     * Text colour mapped to the widget-specific colour control key.
     *
     * @param array<string,mixed> $node Tree node.
     * @param string              $key  Colour control key (e.g. title_color).
     * @return array<string,mixed>
     */
    public function text_color(array $node, string $key): array
    {
        $color = (string) ($node['s']['color'] ?? '');
        if ('' === $color || $this->is_transparent($color)) {
            return array();
        }
        return array($key => $color);
    }

    /**
     * Text alignment mapped to an Elementor align control (with responsive).
     *
     * @param array<string,mixed> $node Tree node.
     * @param string              $key  Align control key (default "align").
     * @return array<string,mixed>
     */
    public function alignment(array $node, string $key = 'align'): array
    {
        $map = array(
            'left' => 'left',
            'right' => 'right',
            'center' => 'center',
            'justify' => 'justify',
            'start' => 'left',
            'end' => 'right',
        );
        $out = array();
        $ta = strtolower((string) ($node['s']['ta'] ?? ''));
        if (isset($map[$ta])) {
            $out[$key] = $map[$ta];
        }
        foreach (array('tablet', 'mobile') as $device) {
            $rta = strtolower((string) ($node['r'][$device]['ta'] ?? ''));
            if (isset($map[$rta]) && (!isset($out[$key]) || $map[$rta] !== $out[$key])) {
                $out[$key . '_' . $device] = $map[$rta];
            }
        }
        return $out;
    }

    /**
     * Padding + margin dimension controls (with responsive variants).
     *
     * @param array<string,mixed> $node          Tree node.
     * @param bool                $include_margin Whether to emit margins too.
     * @return array<string,mixed>
     */
    public function spacing(array $node, bool $include_margin = true): array
    {
        $s = $node['s'] ?? array();
        $out = array();

        if ($this->has_any($s, array('pt', 'pr', 'pb', 'pl'))) {
            $out['padding'] = $this->dimensions(
                $s['pt'] ?? 0,
                $s['pr'] ?? 0,
                $s['pb'] ?? 0,
                $s['pl'] ?? 0
            );
            $this->add_responsive_dimensions($out, 'padding', $node, array('pt', 'pr', 'pb', 'pl'));
        }

        if ($include_margin && $this->has_any($s, array('mt', 'mr', 'mb', 'ml'))) {
            $out['margin'] = $this->dimensions(
                $s['mt'] ?? 0,
                $s['mr'] ?? 0,
                $s['mb'] ?? 0,
                $s['ml'] ?? 0
            );
            $this->add_responsive_dimensions($out, 'margin', $node, array('mt', 'mr', 'mb', 'ml'));
        }

        return $out;
    }

    /**
     * Background controls for a container (colour, image, or CSS gradient).
     *
     * Gradients from Chromium computed styles are mapped to Elementor's native
     * gradient background controls. Multi-layer backgrounds use the dominant
     * linear (or radial) layer as the primary fill; additional layers cannot
     * be expressed as native Elementor stops and rely on source-CSS reinjection.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function background(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();
        $raw_bg_img = (string) ($s['bgImg'] ?? '');

        // Prefer real image URLs over gradients when both appear.
        $bg_image = $this->css_url($raw_bg_img);
        if ('' !== $bg_image) {
            $out['background_background'] = 'classic';
            $out['background_image'] = array(
                'url' => $bg_image,
                'id' => '',
            );
            if (!empty($s['bgSize'])) {
                $out['background_size'] = $this->bg_keyword((string) $s['bgSize']);
            }
            if (!empty($s['bgPos'])) {
                $out['background_position'] = $this->bg_position((string) $s['bgPos']);
            }
            if (!empty($s['bgRepeat'])) {
                $out['background_repeat'] = (string) $s['bgRepeat'];
            }
        } else {
            $gradient = $this->parse_gradient($raw_bg_img);
            if (null === $gradient) {
                $gradient = $this->parse_gradient((string) ($s['bg'] ?? ''));
            }
            if (null !== $gradient) {
                $out = array_merge($out, $this->elementor_gradient_settings($gradient));
            }
        }

        $bg_color = (string) ($s['bg'] ?? '');
        if ('' !== $bg_color && !$this->is_transparent($bg_color) && false === stripos($bg_color, 'gradient')) {
            if (empty($out['background_background'])) {
                $out['background_background'] = 'classic';
            }
            // Do not overwrite gradient color A with a solid fill when gradient won.
            if ('gradient' !== ($out['background_background'] ?? '')) {
                $out['background_color'] = $bg_color;
            }
        }

        return $out;
    }

    /**
     * Convert a parsed gradient into Elementor background controls.
     *
     * @param array{type:string,angle:float,color_a:string,color_b:string,position:string} $gradient Parsed gradient.
     * @return array<string,mixed>
     */
    public function elementor_gradient_settings(array $gradient): array
    {
        $out = array(
            'background_background' => 'gradient',
            'background_color' => $gradient['color_a'],
            'background_color_b' => $gradient['color_b'],
            'background_gradient_type' => $gradient['type'],
        );
        if ('linear' === $gradient['type']) {
            $out['background_gradient_angle'] = array(
                'unit' => 'deg',
                'size' => $gradient['angle'],
            );
        } else {
            $out['background_gradient_position'] = $gradient['position'] ?: 'center center';
        }
        return $out;
    }

    /**
     * Parse a CSS background-image value containing linear/radial gradients.
     *
     * Supports multi-layer lists: prefers the last linear-gradient (base fill),
     * then the first linear, then the first radial. Elementor only exposes two
     * colour stops, so first and last stops of the chosen layer are used.
     *
     * @param string $value CSS background-image (may include url() and gradients).
     * @return array{type:string,angle:float,color_a:string,color_b:string,position:string}|null
     */
    public function parse_gradient(string $value): ?array
    {
        $value = trim($value);
        if ('' === $value || false === stripos($value, 'gradient')) {
            return null;
        }

        $layers = $this->split_background_layers($value);
        $linear = null;
        $radial = null;
        foreach ($layers as $layer) {
            $layer = trim($layer);
            if (preg_match('/^linear-gradient\s*\(/i', $layer)) {
                $linear = $layer; // keep last linear as base.
            } elseif (null === $radial && preg_match('/^radial-gradient\s*\(/i', $layer)) {
                $radial = $layer;
            }
        }

        $chosen = $linear ?? $radial;
        if (null === $chosen) {
            // Fallback: whole value may be a single gradient without clean split.
            if (preg_match('/((?:repeating-)?(?:linear|radial)-gradient\s*\([^;]*)/i', $value, $m)) {
                $chosen = $m[1];
            } else {
                return null;
            }
        }

        $type = (false !== stripos($chosen, 'radial-gradient')) ? 'radial' : 'linear';
        $inner = $this->gradient_inner($chosen);
        if ('' === $inner) {
            return null;
        }

        $angle = 180.0;
        $position = 'center center';
        $stop_source = $inner;

        if ('linear' === $type) {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*deg\s*,/i', $inner, $m)) {
                $angle = (float) $m[1];
                $stop_source = trim(substr($inner, strlen($m[0])));
            } elseif (preg_match('/^to\s+([\w\s]+)\s*,/i', $inner, $m)) {
                $angle = $this->to_direction_angle(trim($m[1]));
                $stop_source = trim(substr($inner, strlen($m[0])));
            }
        } else {
            // Drop size/shape/at-position prefix before colour stops.
            if (preg_match('/^(.*?\bat\s+([^,]+))\s*,/i', $inner, $m)) {
                $position = $this->bg_position(trim($m[2]));
                $stop_source = trim(substr($inner, strlen($m[1]) + 1));
            } elseif (preg_match('/^(circle|ellipse|closest-side|closest-corner|farthest-side|farthest-corner|[\d.]+(?:px|%|em)?(?:\s+[\d.]+(?:px|%|em)?)?)\s*,/i', $inner, $m)) {
                $stop_source = trim(substr($inner, strlen($m[0])));
            }
        }

        $colors = $this->extract_gradient_colors($stop_source);
        if (count($colors) < 1) {
            return null;
        }
        $color_a = $colors[0];
        $color_b = $colors[count($colors) - 1];
        if ($color_a === $color_b && count($colors) > 2) {
            $color_b = $colors[(int) floor(count($colors) / 2)];
        }

        return array(
            'type' => $type,
            'angle' => $angle,
            'color_a' => $color_a,
            'color_b' => $color_b,
            'position' => $position,
        );
    }

    /**
     * Border + border-radius controls.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function border(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();

        $width = (float) ($s['bdw'] ?? 0);
        if ($width > 0) {
            $out['border_border'] = (string) ($s['bds'] ?? 'solid');
            $out['border_width'] = $this->dimensions($width, $width, $width, $width);
            if (!empty($s['bdc']) && !$this->is_transparent((string) $s['bdc'])) {
                $out['border_color'] = (string) $s['bdc'];
            }
        }

        $radius = (float) ($s['br'] ?? 0);
        if ($radius > 0) {
            $out['border_radius'] = $this->dimensions($radius, $radius, $radius, $radius);
        }

        return $out;
    }

    /**
     * Box-shadow control.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function box_shadow(array $node): array
    {
        $shadow = (string) ($node['s']['sh'] ?? '');
        if ('' === $shadow || 'none' === $shadow) {
            return array();
        }
        $parsed = $this->parse_shadow($shadow);
        if (null === $parsed) {
            return array();
        }
        return array(
            'box_shadow_box_shadow_type' => 'yes',
            'box_shadow_box_shadow' => $parsed,
        );
    }

    /**
     * Flex container layout controls.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function flex(array $node): array
    {
        $s = $node['s'] ?? array();
        $disp = (string) ($s['disp'] ?? '');
        $out = array();

        $is_flex = false !== strpos($disp, 'flex');
        $is_grid = false !== strpos($disp, 'grid');
        if (!$is_flex && !$is_grid) {
            return array();
        }

        // Multi-track CSS Grid → Elementor container + custom CSS (do not fake as flex).
        if ($is_grid) {
            $grid = $this->grid_settings($node);
            if (!empty($grid)) {
                return $grid;
            }
            // Single-track grid used for centering — fall through to flex-like mapping.
        }

        $direction = strtolower((string) ($s['fd'] ?? 'row'));
        $direction = (false !== strpos($direction, 'column')) ? 'column' : 'row';
        if ($is_grid) {
            $direction = 'row';
        }
        $out['flex_direction'] = $direction;

        if (!empty($s['jc'])) {
            $out['flex_justify_content'] = $this->flex_align((string) $s['jc']);
        }
        if (!empty($s['ai'])) {
            $out['flex_align_items'] = $this->flex_align((string) $s['ai']);
        }
        if ('row' === $direction || $is_grid) {
            $out['flex_wrap'] = 'wrap';
        }
        // Always set the gap explicitly (0 when the source has none) so it
        // overrides Elementor's default container gap, which would otherwise
        // push percentage-width columns onto a new line.
        $gap = $this->size($s['gap'] ?? null);
        $size = $gap ? $gap['size'] : 0;
        $out['flex_gap'] = array(
            'unit' => 'px',
            'size' => $size,
            'column' => (string) $size,
            'row' => (string) $size,
            'isLinked' => true,
        );

        // Responsive direction (e.g. row on desktop, column on mobile).
        foreach (array('tablet', 'mobile') as $device) {
            $rdisp = (string) ($node['r'][$device]['disp'] ?? '');
            $rfd = (string) ($node['r'][$device]['fd'] ?? '');
            if (false !== strpos($rdisp, 'flex')) {
                $rdir = (false !== strpos(strtolower($rfd), 'column')) ? 'column' : 'row';
                if ($rdir !== $direction) {
                    $out['flex_direction_' . $device] = $rdir;
                }
            }
        }

        return $out;
    }

    /**
     * Map multi-column CSS Grid onto Elementor container + custom CSS.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function grid_settings(array $node): array
    {
        $s = $node['s'] ?? array();
        $gtc = trim((string) ($s['gtc'] ?? ''));
        if ('' === $gtc || 'none' === strtolower($gtc)) {
            return array();
        }

        $tracks = preg_split('/\s+/', $gtc) ?: array();
        $tracks = array_values(array_filter($tracks, static fn($t) => '' !== $t && 'none' !== strtolower($t)));
        if (count($tracks) < 2) {
            return array();
        }

        $columns = $this->normalize_grid_columns($tracks);
        $gap = $this->size($s['gap'] ?? null);
        $gap_css = $gap ? (rtrim(rtrim((string) $gap['size'], '0'), '.') . $gap['unit']) : '0';
        if ($gap && abs($gap['size'] - round($gap['size'])) < 0.001) {
            $gap_css = ((string) (int) round($gap['size'])) . $gap['unit'];
        }

        $out = array(
            'flex_direction' => 'row',
            'flex_wrap' => 'wrap',
            '_h2e_display' => 'grid',
            'custom_css' => sprintf(
                'selector { display: grid !important; grid-template-columns: %s; gap: %s; align-items: %s; }',
                $columns,
                $gap_css,
                $this->css_align_keyword((string) ($s['ai'] ?? 'stretch'))
            ),
        );

        if ($gap) {
            $size = $gap['size'];
            $out['flex_gap'] = array(
                'unit' => 'px',
                'size' => $size,
                'column' => (string) $size,
                'row' => (string) $size,
                'isLinked' => true,
            );
        }

        if (!empty($s['jc'])) {
            $out['flex_justify_content'] = $this->flex_align((string) $s['jc']);
        }
        if (!empty($s['ai'])) {
            $out['flex_align_items'] = $this->flex_align((string) $s['ai']);
        }

        return $out;
    }

    /**
     * @param array<int,string> $tracks Computed grid-template-columns tracks.
     */
    private function normalize_grid_columns(array $tracks): string
    {
        $px = array();
        foreach ($tracks as $track) {
            if (!preg_match('/^(\d+(?:\.\d+)?)px$/i', $track, $m)) {
                return implode(' ', $tracks);
            }
            $px[] = (float) $m[1];
        }

        $count = count($px);
        $avg = array_sum($px) / max(1, $count);
        $equal = true;
        foreach ($px as $value) {
            if (abs($value - $avg) > max(8.0, $avg * 0.08)) {
                $equal = false;
                break;
            }
        }

        if ($equal && $count >= 2) {
            return 'repeat(' . $count . ', minmax(0, 1fr))';
        }

        return implode(' ', $tracks);
    }

    private function css_align_keyword(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'start', 'flex-start', 'left' => 'start',
            'end', 'flex-end', 'right' => 'end',
            'center' => 'center',
            'baseline' => 'baseline',
            default => 'stretch',
        };
    }

    /**
     * Width / height / max-width / min-* sizing controls for a container.
     *
     * Browser computed max-width (and related constraints) are mapped to
     * Elementor size controls so centered content columns keep their measure.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function sizing(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();

		$max_w = $this->constrained_size($s['maxW'] ?? null);
        if (null !== $max_w) {
            $out['max_width'] = $max_w;
            // Fill available width up to the browser max-width constraint.
            if (empty($out['width'])) {
                $out['width'] = array('unit' => '%', 'size' => 100);
            }
            // Center constrained measure boxes (margin:auto equivalent in flex).
            $out['align_self'] = 'center';
        }

        $min_w = $this->constrained_size($s['minW'] ?? null);
        if (null !== $min_w && $min_w['size'] > 0) {
            $out['min_width'] = $min_w;
        }

        $min_h = $this->constrained_size($s['minH'] ?? null);
        if (null !== $min_h && $min_h['size'] > 0) {
            $out['min_height'] = $min_h;
        }

        $max_h = $this->constrained_size($s['maxH'] ?? null);
        if (null !== $max_h) {
            $out['max_height'] = $max_h;
        }

        // Explicit non-auto aspect-ratio when Chromium reports one.
        $ar = trim((string) ($s['ar'] ?? ''));
        if ('' !== $ar && 'auto' !== strtolower($ar)) {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $ar, $m)) {
                $out['aspect_ratio'] = round(((float) $m[1]) / max(0.0001, (float) $m[2]), 4);
            } elseif (is_numeric($ar)) {
                $out['aspect_ratio'] = (float) $ar;
            }
        }

        if (isset($s['op']) && (float) $s['op'] < 1) {
            $out['_opacity'] = array(
                'unit' => 'px',
                'size' => round((float) $s['op'], 2),
            );
        }

        return $out;
    }

    /**
     * Parse a constraining CSS length, ignoring none/auto/0.
     *
     * @param mixed $value Raw CSS size.
     * @return array{unit:string,size:float}|null
     */
    private function constrained_size($value): ?array
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, array('none', 'auto', 'initial', 'unset', '0', '0px'), true)) {
                return null;
            }
        }
        $parsed = $this->size($value);
        if (null === $parsed || $parsed['size'] <= 0) {
            return null;
        }
        return $parsed;
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Parse a CSS length into an Elementor size control value.
     *
     * @param mixed $value Raw value (e.g. "24px", "1.5em", 24).
     * @return array{unit:string,size:float}|null
     */
    private function size($value): ?array
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_numeric($value)) {
            return array('unit' => 'px', 'size' => (float) $value);
        }
        $value = (string) $value;
        if (preg_match('/^(-?\d+(?:\.\d+)?)\s*(px|em|rem|%|vh|vw)?$/', trim($value), $m)) {
            $unit = $m[2] ?: 'px';
            return array('unit' => $unit, 'size' => (float) $m[1]);
        }
        return null;
    }

    /**
     * Line-height handling (unit-less ratios are converted to em).
     *
     * @param mixed $value Line-height value.
     * @param mixed $font  Font-size value (for unit-less ratios).
     * @return array{unit:string,size:float}|null
     */
    private function line_height($value, $font): ?array
    {
        if (null === $value || 'normal' === $value) {
            return null;
        }
        if (is_numeric($value)) {
            return array('unit' => 'em', 'size' => (float) $value);
        }
        return $this->size($value);
    }

    /**
     * Build an Elementor dimensions control value.
     *
     * @param mixed $top    Top.
     * @param mixed $right  Right.
     * @param mixed $bottom Bottom.
     * @param mixed $left   Left.
     * @return array<string,mixed>
     */
    private function dimensions($top, $right, $bottom, $left): array
    {
        $t = (float) $top;
        $r = (float) $right;
        $b = (float) $bottom;
        $l = (float) $left;
        return array(
            'unit' => 'px',
            'top' => (string) $t,
            'right' => (string) $r,
            'bottom' => (string) $b,
            'left' => (string) $l,
            'isLinked' => ($t === $r && $r === $b && $b === $l),
        );
    }

    /**
     * Append a responsive size override when tablet/mobile differ.
     *
     * @param array<string,mixed> $out  Output settings (by ref).
     * @param string              $key  Base control key.
     * @param array<string,mixed> $node Tree node.
     * @param string              $prop Responsive property key (e.g. "fs").
     */
    private function add_responsive_size(array &$out, string $key, array $node, string $prop): void
    {
        $base = $out[$key]['size'] ?? null;
        foreach (array('tablet', 'mobile') as $device) {
            $val = $this->size($node['r'][$device][$prop] ?? null);
            if ($val && (null === $base || abs($val['size'] - (float) $base) > 0.5)) {
                $out[$key . '_' . $device] = $val;
            }
        }
    }

    /**
     * Append responsive dimensions when tablet/mobile differ.
     *
     * @param array<string,mixed> $out   Output settings (by ref).
     * @param string              $key   Base control key (padding/margin).
     * @param array<string,mixed> $node  Tree node.
     * @param array<int,string>   $props [top,right,bottom,left] responsive keys.
     */
    private function add_responsive_dimensions(array &$out, string $key, array $node, array $props): void
    {
        foreach (array('tablet', 'mobile') as $device) {
            $r = $node['r'][$device] ?? null;
            if (!is_array($r)) {
                continue;
            }
            $vals = array();
            foreach ($props as $p) {
                $vals[] = $this->px_number($r[$p] ?? null);
            }
            if (null === $vals[0] && null === $vals[1] && null === $vals[2] && null === $vals[3]) {
                continue;
            }
            $dim = $this->dimensions($vals[0] ?? 0, $vals[1] ?? 0, $vals[2] ?? 0, $vals[3] ?? 0);
            if (($out[$key] ?? null) !== $dim) {
                $out[$key . '_' . $device] = $dim;
            }
        }
    }

    /**
     * Parse a "24px" string into a number.
     *
     * @param mixed $value Raw value.
     */
    private function px_number($value): ?float
    {
        if (null === $value) {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/(-?\d+(?:\.\d+)?)/', (string) $value, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * Whether any of the given keys hold a non-zero numeric value.
     *
     * @param array<string,mixed> $s    Style set.
     * @param array<int,string>   $keys Keys to check.
     */
    private function has_any(array $s, array $keys): bool
    {
        foreach ($keys as $k) {
            if ((float) ($s[$k] ?? 0) !== 0.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract the first family from a CSS font-family list (quotes stripped).
     *
     * @param string $family Font-family value.
     */
    private function font_family(string $family): string
    {
        if ('' === $family) {
            return '';
        }
        $first = explode(',', $family)[0];
        return trim(str_replace(array('"', "'"), '', $first));
    }

    /**
     * Normalise a font-weight value.
     *
     * @param string $weight Weight value.
     */
    private function font_weight(string $weight): string
    {
        $weight = trim(strtolower($weight));
        $names = array(
            'normal' => '400',
            'bold' => '700',
        );
        if (isset($names[$weight])) {
            return $names[$weight];
        }
        return is_numeric($weight) ? $weight : '';
    }

    /**
     * Map CSS justify/align values to Elementor flex alignment values.
     *
     * @param string $value CSS value.
     */
    private function flex_align(string $value): string
    {
        $value = strtolower(trim($value));
        $map = array(
            'flex-start' => 'flex-start',
            'start' => 'flex-start',
            'flex-end' => 'flex-end',
            'end' => 'flex-end',
            'center' => 'center',
            'space-between' => 'space-between',
            'space-around' => 'space-around',
            'space-evenly' => 'space-evenly',
            'stretch' => 'stretch',
        );
        return $map[$value] ?? '';
    }

    /**
     * Extract a URL from a CSS url(...) value (ignores gradients — use parse_gradient).
     *
     * @param string $value background-image value.
     */
    private function css_url(string $value): string
    {
        if ('' === $value || false !== stripos($value, 'gradient')) {
            // url(...) may coexist with gradients; still try to pull a URL first.
            if (preg_match('/url\((["\']?)(.*?)\1\)/', $value, $m) && '' !== trim($m[2])) {
                return $m[2];
            }
            return '';
        }
        if (preg_match('/url\((["\']?)(.*?)\1\)/', $value, $m)) {
            return $m[2];
        }
        return '';
    }

    /**
     * Split a CSS background-image list into layers (comma-aware of nested parens).
     *
     * @param string $value Raw background-image.
     * @return array<int,string>
     */
    private function split_background_layers(string $value): array
    {
        $layers = array();
        $depth = 0;
        $current = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $value[$i];
            if ('(' === $ch) {
                ++$depth;
                $current .= $ch;
            } elseif (')' === $ch) {
                $depth = max(0, $depth - 1);
                $current .= $ch;
            } elseif (',' === $ch && 0 === $depth) {
                if ('' !== trim($current)) {
                    $layers[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if ('' !== trim($current)) {
            $layers[] = trim($current);
        }
        return $layers;
    }

    /**
     * Inner contents of a gradient(...) function.
     *
     * @param string $gradient Gradient CSS function call.
     */
    private function gradient_inner(string $gradient): string
    {
        $start = strpos($gradient, '(');
        if (false === $start) {
            return '';
        }
        $depth = 0;
        $len = strlen($gradient);
        for ($i = $start; $i < $len; ++$i) {
            if ('(' === $gradient[$i]) {
                ++$depth;
            } elseif (')' === $gradient[$i]) {
                --$depth;
                if (0 === $depth) {
                    return trim(substr($gradient, $start + 1, $i - $start - 1));
                }
            }
        }
        return trim(substr($gradient, $start + 1));
    }

    /**
     * Extract colour tokens from a gradient colour-stop list.
     *
     * @param string $stops Colour-stop source.
     * @return array<int,string>
     */
    private function extract_gradient_colors(string $stops): array
    {
        $colors = array();
        if (preg_match_all('/(rgba?\([^)]+\)|hsla?\([^)]+\)|#[0-9a-fA-F]{3,8}|\b(?:transparent|currentColor)\b)/i', $stops, $m)) {
            foreach ($m[1] as $c) {
                $c = trim($c);
                if ('' === $c || $this->is_transparent($c)) {
                    continue;
                }
                $colors[] = $c;
            }
        }
        // If every stop was transparent, keep the first transparent as a soft fade.
        if (!$colors && preg_match('/(rgba?\([^)]+\))/i', $stops, $m)) {
            $colors[] = $m[1];
            $colors[] = 'rgba(0, 0, 0, 0)';
        }
        return $colors;
    }

    /**
     * Map CSS "to …" direction keywords to degrees.
     *
     * @param string $dir Direction keywords.
     */
    private function to_direction_angle(string $dir): float
    {
        $dir = strtolower(preg_replace('/\s+/', ' ', trim($dir)) ?? '');
        $map = array(
            'top' => 0.0,
            'right' => 90.0,
            'bottom' => 180.0,
            'left' => 270.0,
            'top right' => 45.0,
            'right top' => 45.0,
            'bottom right' => 135.0,
            'right bottom' => 135.0,
            'bottom left' => 225.0,
            'left bottom' => 225.0,
            'top left' => 315.0,
            'left top' => 315.0,
        );
        return $map[$dir] ?? 180.0;
    }

    /**
     * Map background-size to an Elementor keyword.
     *
     * @param string $value background-size value.
     */
    private function bg_keyword(string $value): string
    {
        $value = strtolower(trim($value));
        if ('cover' === $value || 'contain' === $value || 'auto' === $value) {
            return $value;
        }
        return 'cover';
    }

    /**
     * Map background-position to an Elementor keyword.
     *
     * @param string $value background-position value.
     */
    private function bg_position(string $value): string
    {
        $value = strtolower(trim($value));
        $allowed = array('center center', 'center left', 'center right', 'top center', 'top left', 'top right', 'bottom center', 'bottom left', 'bottom right');
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        return 'center center';
    }

    /**
     * Parse a CSS box-shadow into an Elementor shadow control value.
     *
     * @param string $shadow box-shadow value.
     * @return array<string,mixed>|null
     */
    private function parse_shadow(string $shadow): ?array
    {
        // Only handle the first shadow layer.
        $shadow = trim(explode('),', $shadow)[0]);
        if (false !== strpos($shadow, 'rgb') && substr_count($shadow, ')') === 0) {
            $shadow .= ')';
        }
        $inset = false;
        if (false !== strpos($shadow, 'inset')) {
            $inset = true;
            $shadow = trim(str_replace('inset', '', $shadow));
        }

        $color = '';
        if (preg_match('/(rgba?\([^)]+\)|#[0-9a-fA-F]{3,8})/', $shadow, $m)) {
            $color = $m[1];
            $shadow = trim(str_replace($color, '', $shadow));
        }

        preg_match_all('/-?\d+(?:\.\d+)?px/', $shadow, $nums);
        $offsets = array_map(static fn($v) => (float) $v, $nums[0] ?? array());
        if (count($offsets) < 2) {
            return null;
        }

        return array(
            'horizontal' => $offsets[0],
            'vertical' => $offsets[1],
            'blur' => $offsets[2] ?? 0,
            'spread' => $offsets[3] ?? 0,
            'color' => $color ?: 'rgba(0,0,0,0.5)',
            'position' => $inset ? 'inset' : '',
        );
    }

    /**
     * Whether a CSS colour is fully transparent.
     *
     * @param string $color CSS colour.
     */
    private function is_transparent(string $color): bool
    {
        $color = strtolower(trim($color));
        if ('' === $color || 'transparent' === $color) {
            return true;
        }
        if (preg_match('/rgba?\(([^)]+)\)/', $color, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            if (4 === count($parts) && (float) $parts[3] === 0.0) {
                return true;
            }
        }
        return false;
    }
}
