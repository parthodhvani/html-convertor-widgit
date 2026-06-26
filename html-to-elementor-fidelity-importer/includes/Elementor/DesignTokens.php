<?php
/**
 * Extracts global design tokens (palette + fonts) from the layout tree.
 *
 * @package HtmlToElementor
 */

declare(strict_types=1);

namespace HtmlToElementor\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scans every node's computed styles to derive a small global palette and a
 * heading/body font pairing, mirroring what an Elementor designer would set up
 * as global styles.
 */
final class DesignTokens
{

    /**
     * Extract tokens from a list of sections (each with a `tree`).
     *
     * @param array<int,array<string,mixed>> $sections Sections.
     * @return array<string,mixed>
     */
    public function extract(array $sections): array
    {
        $colors = array();
        $bgs = array();
        $fonts = array();
        $head_fonts = array();
        $radii = array();

        foreach ($sections as $section) {
            $this->walk(
                $section['tree'] ?? null,
                $colors,
                $bgs,
                $fonts,
                $head_fonts,
                $radii
            );
        }

        arsort($colors);
        arsort($bgs);
        arsort($fonts);
        arsort($head_fonts);

        $palette = $this->build_palette($colors, $bgs);

        return array(
            'primary' => $palette[0] ?? '',
            'secondary' => $palette[1] ?? '',
            'accent' => $palette[2] ?? '',
            'palette' => $palette,
            'heading_font' => $this->first_key($head_fonts),
            'body_font' => $this->first_key($fonts),
            'fonts' => array_slice(array_keys($fonts), 0, 5),
        );
    }

    /**
     * Recursively tally colours / fonts / radii.
     *
     * @param array<string,mixed>|null $node       Tree node.
     * @param array<string,int>        $colors     Text colour tally (by ref).
     * @param array<string,int>        $bgs        Background tally (by ref).
     * @param array<string,int>        $fonts      Font tally (by ref).
     * @param array<string,int>        $head_fonts Heading font tally (by ref).
     * @param array<int,float>         $radii      Border radii (by ref).
     */
    private function walk($node, array &$colors, array &$bgs, array &$fonts, array &$head_fonts, array &$radii): void
    {
        if (!is_array($node)) {
            return;
        }
        $s = $node['s'] ?? array();

        $color = (string) ($s['color'] ?? '');
        if ($color && !$this->is_neutral($color)) {
            $colors[$color] = ($colors[$color] ?? 0) + 1;
        }
        $bg = (string) ($s['bg'] ?? '');
        if ($bg && !$this->is_neutral($bg)) {
            $bgs[$bg] = ($bgs[$bg] ?? 0) + 1;
        }
        $ff = $this->first_family((string) ($s['ff'] ?? ''));
        if ('' !== $ff) {
            $fonts[$ff] = ($fonts[$ff] ?? 0) + 1;
            if (preg_match('/^h[1-6]$/', (string) ($node['tag'] ?? ''))) {
                $head_fonts[$ff] = ($head_fonts[$ff] ?? 0) + 1;
            }
        }
        if (!empty($s['br'])) {
            $radii[] = (float) $s['br'];
        }

        foreach ((array) ($node['children'] ?? array()) as $child) {
            $this->walk($child, $colors, $bgs, $fonts, $head_fonts, $radii);
        }
    }

    /**
     * Build a small ordered palette from the most common brand colours.
     *
     * @param array<string,int> $colors Text colours.
     * @param array<string,int> $bgs    Backgrounds.
     * @return array<int,string>
     */
    private function build_palette(array $colors, array $bgs): array
    {
        $merged = array();
        foreach (array($bgs, $colors) as $set) {
            foreach ($set as $color => $count) {
                $merged[$color] = ($merged[$color] ?? 0) + $count;
            }
        }
        arsort($merged);
        return array_slice(array_keys($merged), 0, 4);
    }

    /**
     * First key of a sorted tally array.
     *
     * @param array<string,int> $arr Tally.
     */
    private function first_key(array $arr): string
    {
        foreach ($arr as $k => $_) {
            return $k;
        }
        return '';
    }

    /**
     * First font family with quotes stripped.
     *
     * @param string $family Font-family list.
     */
    private function first_family(string $family): string
    {
        if ('' === $family) {
            return '';
        }
        $first = explode(',', $family)[0];
        return trim(str_replace(array('"', "'"), '', $first));
    }

    /**
     * Whether a colour is near-black/white/grey (not a brand colour).
     *
     * @param string $color CSS colour.
     */
    private function is_neutral(string $color): bool
    {
        if (!preg_match('/rgba?\(([^)]+)\)/', $color, $m)) {
            return false;
        }
        $p = array_map('trim', explode(',', $m[1]));
        if (count($p) < 3) {
            return false;
        }
        $r = (int) $p[0];
        $g = (int) $p[1];
        $b = (int) $p[2];
        $a = isset($p[3]) ? (float) $p[3] : 1.0;
        if ($a < 0.1) {
            return true;
        }
        // Greyscale (r≈g≈b) is treated as neutral.
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        return ($max - $min) <= 12;
    }
}
