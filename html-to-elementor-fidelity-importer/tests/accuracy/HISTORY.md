# Accuracy optimization history

Composite score = weighted pixel/geometry/typography/spacing/responsive/colour.

| Run | avg_composite | avg_pixel | avg_geo | avg_spacing | Delta | Top fix |
|-----|---------------|-----------|---------|-------------|-------|---------|
| baseline | 81.7% | 83.2% | 69.5% | 75.4% | — | — |
| iter1 | 82.0% | 82.8% | 69.9% | 78.6% | +0.3% | flex_gap no-op thrash + CSS direction priority + gap collapse rules |
| iter2 | 82.2% | 82.8% | 70.5% | 78.6% | +0.2% | composite absorption credit + price-table features |
| iter3 | 83.8% | 82.9% | 74.5% | 81.8% | **+1.6%** | landmark visual-section merge guard + layered gradient paint |
| iter4 | 84.1% | 82.8% | 75.5% | 82.3% | +0.3% | expand feature-rich pricing to native widget stacks |
| forensic-v1 | **89.1%** | **92.6%** | **79.3%** | **87.5%** | **+5.0pp*** | margin/CSS/preview/debug + 100-page corpus (*vs iter4; corpus expanded) |
| forensic-v2 | 87.6% | 93.3% | 74.1% | 84.7% | **−1.5pp** | SRI/vendor + anti-flatten over-filtered absolute heroes (reverted via v3) |
| forensic-v3 | **89.0%** | **92.7%** | **78.8%** | **87.3%** | **−0.1pp** | restore layered emission for absolute overlays; Bootstrap rows preserved |

\* forensic-v1 also expanded corpus 42 → 100 pages; compare multi-metric fields, not only composite.

**Stop rule:** keep only changes that improve avg composite **> 0.5%**. forensic-v3 vs forensic-v1 is ≤0.5% — **stop accuracy loop**.

Architectural wins kept despite flat composite: Chromium stylesheet recovery, no invented residual padding, Bootstrap flex rows emit with % columns, absolute heroes still layered.

Net vs early baseline: **+7.3pp** composite (81.7 → 89.0), geometry **+9.3pp**, pixel **+9.5pp**.
