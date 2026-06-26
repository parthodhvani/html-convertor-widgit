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
     * Background controls for a container (colour + image).
     *
     * @param array<string,mixed> $node Tree node.
     * @return array<string,mixed>
     */
    public function background(array $node): array
    {
        $s = $node['s'] ?? array();
        $out = array();

        $bg_image = $this->css_url((string) ($s['bgImg'] ?? ''));
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

        $bg_color = (string) ($s['bg'] ?? '');
        if ('' !== $bg_color && !$this->is_transparent($bg_color)) {
            if (empty($out['background_background'])) {
                $out['background_background'] = 'classic';
            }
            $out['background_color'] = $bg_color;
        }

        return $out;
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

        // Map both flex and grid onto Elementor's flex container.
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
