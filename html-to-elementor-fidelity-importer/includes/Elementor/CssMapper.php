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
        if ($decoration && 'none' !== $decoration) {
            if (false !== strpos($decoration, 'underline')) {
                $out['typography_text_decoration'] = 'underline';
            } elseif (false !== strpos($decoration, 'line-through')) {
                $out['typography_text_decoration'] = 'line-through';
            }
        }

        $style = strtolower((string) ($s['fst'] ?? ''));
        if ('italic' === $style || 'oblique' === $style) {
            $out['typography_font_style'] = 'italic';
        }

        $text_shadow = (string) ($s['tsh'] ?? '');
        if ('' !== $text_shadow && 'none' !== $text_shadow) {
            $parsed = $this->parse_shadow($text_shadow);
            if (null !== $parsed) {
                // Elementor Text Shadow group (no spread).
                $out['text_shadow_text_shadow_type'] = 'yes';
                $out['text_shadow_text_shadow'] = array(
                    'horizontal' => $parsed['horizontal'],
                    'vertical' => $parsed['vertical'],
                    'blur' => $parsed['blur'],
                    'color' => $parsed['color'],
                );
            }
        }

        // Phase 12 — prefer measured line-height / letter-spacing from typography bag.
        $typo = $node['typography'] ?? array();
        if (is_array($typo)) {
            if (empty($out['typography_line_height']) && !empty($typo['lineHeightPx']) && !empty($typo['fontSizePx'])) {
                $ratio = ((float) $typo['lineHeightPx']) / max(1.0, (float) $typo['fontSizePx']);
                if ($ratio > 0.8 && $ratio < 3.5) {
                    $out['typography_typography'] = 'custom';
                    $out['typography_line_height'] = array('unit' => 'em', 'size' => round($ratio, 2));
                }
            }
            if (empty($out['typography_letter_spacing']) && isset($typo['letterSpacingPx'])
                && abs((float) $typo['letterSpacingPx']) > 0.01) {
                $out['typography_typography'] = 'custom';
                $out['typography_letter_spacing'] = array(
                    'unit' => 'px',
                    'size' => round((float) $typo['letterSpacingPx'], 2),
                );
            }
            if (!empty($typo['textWidth'])) {
                $out['_h2e_text_width'] = (float) $typo['textWidth'];
            }
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
     * Background controls for a container (colour + image + gradient).
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function background(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();
        $unsupported = array();

        $bg_img_raw = (string) ($s['bgImg'] ?? '');
        $is_gradient = !empty($s['bgGrad']) || ('' !== $bg_img_raw && false !== stripos($bg_img_raw, 'gradient('));

        if ($is_gradient) {
            $gradient = $this->parse_gradient($bg_img_raw);
            if (null !== $gradient) {
                $out = array_merge($out, $gradient);
            } else {
                $unsupported[] = 'background-image:gradient';
                $out = $this->merge_custom_css($out, 'background-image:' . $bg_img_raw);
            }
        } else {
            $bg_image = $this->css_url($bg_img_raw);
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
            }
        }

        $bg_color = (string) ($s['bg'] ?? '');
        if ('' !== $bg_color && !$this->is_transparent($bg_color)) {
            if (empty($out['background_background'])) {
                $out['background_background'] = 'classic';
            }
            // Gradient parse already sets color A; only fill classic backgrounds here.
            if ('gradient' !== ($out['background_background'] ?? '') || empty($out['background_color'])) {
                if ('gradient' !== ($out['background_background'] ?? '')) {
                    $out['background_color'] = $bg_color;
                } elseif (empty($out['background_color'])) {
                    $out['background_color'] = $bg_color;
                }
            }
        }

        if (!empty($unsupported)) {
            $out['_h2e_unsupported'] = array_values(array_unique(array_merge(
                (array) ($out['_h2e_unsupported'] ?? array()),
                $unsupported
            )));
        }

        return $out;
    }

    /**
     * Border + border-radius controls (per-side / per-corner when IR provides them).
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function border(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();

        $bd = is_array($s['bd'] ?? null) ? $s['bd'] : null;
        $top = $bd ? (float) ($bd['t'] ?? 0) : (float) ($s['bdwT'] ?? $s['bdw'] ?? 0);
        $right = $bd ? (float) ($bd['r'] ?? 0) : (float) ($s['bdwR'] ?? $s['bdw'] ?? 0);
        $bottom = $bd ? (float) ($bd['b'] ?? 0) : (float) ($s['bdwB'] ?? $s['bdw'] ?? 0);
        $left = $bd ? (float) ($bd['l'] ?? 0) : (float) ($s['bdwL'] ?? $s['bdw'] ?? 0);

        if ($top > 0 || $right > 0 || $bottom > 0 || $left > 0) {
            $out['border_border'] = (string) ($s['bds'] ?? $s['bdsT'] ?? $s['bdsR'] ?? 'solid');
            $out['border_width'] = $this->dimensions($top, $right, $bottom, $left);
            $color = (string) ($s['bdc'] ?? $s['bdcT'] ?? $s['bdcR'] ?? $s['bdcB'] ?? $s['bdcL'] ?? '');
            if ('' !== $color && !$this->is_transparent($color)) {
                $out['border_color'] = $color;
            }
        }

        $brad = is_array($s['brad'] ?? null) ? $s['brad'] : null;
        if ($brad) {
            $tl = (float) ($brad['tl'] ?? 0);
            $tr = (float) ($brad['tr'] ?? 0);
            $br = (float) ($brad['br'] ?? 0);
            $bl = (float) ($brad['bl'] ?? 0);
            if ($tl > 0 || $tr > 0 || $br > 0 || $bl > 0) {
                // Elementor border_radius dimensions: top=TL, right=TR, bottom=BR, left=BL.
                $out['border_radius'] = $this->dimensions($tl, $tr, $br, $bl);
            }
        } else {
            $tl = (float) ($s['brTL'] ?? $s['br'] ?? 0);
            $tr = (float) ($s['brTR'] ?? $s['br'] ?? 0);
            $br = (float) ($s['brBR'] ?? $s['br'] ?? 0);
            $bl = (float) ($s['brBL'] ?? $s['br'] ?? 0);
            if ($tl > 0 || $tr > 0 || $br > 0 || $bl > 0) {
                $out['border_radius'] = $this->dimensions($tl, $tr, $br, $bl);
            }
        }

        return $out;
    }

    /**
     * Image-specific media controls (object-fit / aspect-ratio) + custom CSS fallback.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function image_media(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();
        $css = array();

        $object_fit = strtolower((string) ($s['of'] ?? ''));
        if ('' !== $object_fit && 'fill' !== $object_fit) {
            // Elementor Image has no universal object-fit control across versions;
            // emit wrapper CSS so raster fidelity is preserved.
            $css[] = 'object-fit:' . $object_fit;
            $out['_h2e_object_fit'] = $object_fit;
        }

        $ar = trim((string) ($s['ar'] ?? ''));
        if ('' !== $ar && 'auto' !== $ar) {
            $css[] = 'aspect-ratio:' . $ar;
            $out['_h2e_aspect_ratio'] = $ar;
        }

        if (!empty($css)) {
            $out = $this->merge_custom_css($out, implode(';', $css));
            $out['_h2e_unsupported'] = array_values(array_unique(array_merge(
                (array) ($out['_h2e_unsupported'] ?? array()),
                array_filter(array(
                    '' !== $object_fit && 'fill' !== $object_fit ? 'object-fit' : '',
                    ('' !== $ar && 'auto' !== $ar) ? 'aspect-ratio' : '',
                ))
            )));
        }

        return $out;
    }

    /**
     * Paint/layout effects Elementor cannot express as native controls.
     * Emits `_h2e_custom_css` (property list) instead of silently dropping.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function effects(array $node): array
    {
        $s = $node['s'] ?? array();
        $css = array();
        $unsupported = array();

        if (!empty($s['tf']) && 'none' !== $s['tf']) {
            $css[] = 'transform:' . $s['tf'];
            $unsupported[] = 'transform';
            if (!empty($s['tfo'])) {
                $css[] = 'transform-origin:' . $s['tfo'];
            }
        }
        if (!empty($s['filter']) && 'none' !== $s['filter']) {
            $css[] = 'filter:' . $s['filter'];
            $unsupported[] = 'filter';
        }
        if (!empty($s['clip']) && 'none' !== $s['clip']) {
            $css[] = 'clip-path:' . $s['clip'];
            $unsupported[] = 'clip-path';
        }
        if (!empty($s['mask']) && 'none' !== $s['mask']) {
            $css[] = 'mask-image:' . $s['mask'];
            $unsupported[] = 'mask-image';
        }
        if (!empty($s['blend']) && 'normal' !== $s['blend']) {
            $css[] = 'mix-blend-mode:' . $s['blend'];
            $unsupported[] = 'mix-blend-mode';
        }
        if (!empty($s['isolation']) && 'auto' !== $s['isolation']) {
            $css[] = 'isolation:' . $s['isolation'];
            $unsupported[] = 'isolation';
        }
        if (!empty($s['bdFilter']) && 'none' !== $s['bdFilter']) {
            $css[] = 'backdrop-filter:' . $s['bdFilter'];
            $unsupported[] = 'backdrop-filter';
        }
        if (!empty($s['ov']) && 'visible' !== $s['ov']) {
            $css[] = 'overflow:' . $s['ov'];
            $unsupported[] = 'overflow';
        }
        if (!empty($s['contain']) && 'none' !== $s['contain']) {
            $css[] = 'contain:' . $s['contain'];
            $unsupported[] = 'contain';
        }
        if (!empty($s['z'])) {
            // Elementor containers support z_index in advanced; map when numeric.
            if (is_numeric($s['z'])) {
                $out = array('z_index' => (string) $s['z']);
            } else {
                $css[] = 'z-index:' . $s['z'];
                $unsupported[] = 'z-index';
                $out = array();
            }
        } else {
            $out = array();
        }

        $pos = strtolower((string) ($s['pos'] ?? ''));
        if (in_array($pos, array('sticky', 'fixed', 'absolute'), true)) {
            // Prefer Elementor position control when sticky/absolute.
            if ('sticky' === $pos) {
                $out['position'] = 'sticky';
            } elseif ('absolute' === $pos) {
                $out['position'] = 'absolute';
            } else {
                $css[] = 'position:fixed';
                $unsupported[] = 'position:fixed';
            }
            $inset = is_array($s['inset'] ?? null) ? $s['inset'] : array();
            foreach (array('top', 'right', 'bottom', 'left') as $side) {
                $val = (string) ($inset[$side] ?? '');
                if ('' !== $val && 'auto' !== $val) {
                    $size = $this->size($val);
                    if ($size) {
                        $out[$side] = $size;
                    } else {
                        $css[] = $side . ':' . $val;
                    }
                }
            }
        }

        if (!empty($s['dir']) && 'rtl' === strtolower((string) $s['dir'])) {
            $out['direction'] = 'rtl';
        }

        if (!empty($css)) {
            $out = $this->merge_custom_css($out, implode(';', $css));
        }
        if (!empty($unsupported)) {
            $out['_h2e_unsupported'] = array_values(array_unique($unsupported));
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

        // Map both flex and grid onto Elementor's flex container.
        $direction = strtolower((string) ($s['fd'] ?? 'row'));
        $direction = (false !== strpos($direction, 'column')) ? 'column' : 'row';
        if ($is_grid) {
            $direction = 'row';
            // Preserve grid track definition via custom CSS — Elementor has no CSS Grid.
            $grid_css = array();
            if (!empty($s['gtc'])) {
                $grid_css[] = 'display:grid';
                $grid_css[] = 'grid-template-columns:' . $s['gtc'];
            }
            if (!empty($s['gtr'])) {
                $grid_css[] = 'grid-template-rows:' . $s['gtr'];
            }
            if (!empty($s['gta'])) {
                $grid_css[] = 'grid-template-areas:' . $s['gta'];
            }
            if (!empty($grid_css)) {
                $out = $this->merge_custom_css($out, implode(';', $grid_css));
                $out['_h2e_unsupported'] = array_values(array_unique(array_merge(
                    (array) ($out['_h2e_unsupported'] ?? array()),
                    array('display:grid')
                )));
            }
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
     * Minimum-height / opacity sizing controls for a container.
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function sizing(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();
        $min_h = $this->size($s['minH'] ?? null);
        if ($min_h && $min_h['size'] > 0) {
            $out['min_height'] = $min_h;
        }
        if (isset($s['op']) && (float) $s['op'] < 1) {
            $out['_opacity'] = array(
                'unit' => 'px',
                'size' => round((float) $s['op'], 2),
            );
        }
        return $out;
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
     * Extract a URL from a CSS url(...) value (ignores gradients).
     *
     * @param string $value background-image value.
     */
    private function css_url(string $value): string
    {
        if ('' === $value || false !== strpos($value, 'gradient')) {
            return '';
        }
        if (preg_match('/url\((["\']?)(.*?)\1\)/', $value, $m)) {
            return $m[2];
        }
        return '';
    }

    /**
     * Parse a CSS gradient into Elementor background gradient controls.
     *
     * Verified control names against Elementor Group_Control_Background:
     * background_background=gradient, background_color, background_color_b,
     * background_gradient_type, background_gradient_angle, stop controls.
     *
     * @param string $value background-image value.
     * @return array<string,mixed>|null
     */
    private function parse_gradient(string $value): ?array
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $type = 'linear';
        if (preg_match('/radial-gradient\s*\(/i', $value)) {
            $type = 'radial';
        } elseif (!preg_match('/linear-gradient\s*\(/i', $value)) {
            return null;
        }

        $angle = 180.0;
        if (preg_match('/linear-gradient\s*\(\s*([+-]?\d+(?:\.\d+)?)deg/i', $value, $m)) {
            $angle = (float) $m[1];
        } elseif (preg_match('/linear-gradient\s*\(\s*to\s+([a-z\s]+),/i', $value, $m)) {
            $to = strtolower(trim(preg_replace('/\s+/', ' ', $m[1])));
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
            $angle = $map[$to] ?? 180.0;
        }

        // Collect color stops (rgb()/rgba()/hsl()/hex/named roughly via regex).
        $colors = array();
        if (preg_match_all('/(rgba?\([^)]+\)|hsla?\([^)]+\)|#[0-9a-fA-F]{3,8})/', $value, $matches)) {
            $colors = $matches[1];
        }
        if (count($colors) < 2) {
            return null;
        }

        $out = array(
            'background_background' => 'gradient',
            'background_color' => $colors[0],
            'background_color_b' => $colors[count($colors) - 1],
            'background_gradient_type' => $type,
            'background_color_stop' => array('unit' => '%', 'size' => 0),
            'background_color_b_stop' => array('unit' => '%', 'size' => 100),
        );
        if ('linear' === $type) {
            $out['background_gradient_angle'] = array(
                'unit' => 'deg',
                'size' => $angle,
            );
        }

        // Preserve multi-stop / complex gradients via custom CSS as well.
        if (count($colors) > 2) {
            $out = $this->merge_custom_css($out, 'background-image:' . $value);
            $out['_h2e_unsupported'] = array('background-image:multi-stop-gradient');
        }

        return $out;
    }

    /**
     * Append CSS declarations onto `_h2e_custom_css` (semicolon-separated props).
     *
     * @param array<string,mixed> $settings Settings.
     * @param string              $css      Declarations without selector.
     * @return array<string,mixed>
     */
    private function merge_custom_css(array $settings, string $css): array
    {
        $css = trim($css, " \t\n\r\0\x0B;");
        if ('' === $css) {
            return $settings;
        }
        $existing = trim((string) ($settings['_h2e_custom_css'] ?? ''), " \t\n\r\0\x0B;");
        $settings['_h2e_custom_css'] = '' === $existing ? $css : ($existing . ';' . $css);
        return $settings;
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
