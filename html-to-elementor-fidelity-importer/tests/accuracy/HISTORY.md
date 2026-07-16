# Accuracy optimization history

Composite score = weighted pixel/geometry/typography/spacing/responsive/colour.

| Run | avg_composite | avg_pixel | avg_geo | avg_spacing | Delta | Top fix |
|-----|---------------|-----------|---------|-------------|-------|---------|
| baseline | 81.7% | 83.2% | 69.5% | 75.4% | — | — |
| iter1 | 82.0% | 82.8% | 69.9% | 78.6% | +0.3% | flex_gap no-op thrash + CSS direction priority + gap collapse rules |
| iter2 | 82.2% | 82.8% | 70.5% | 78.6% | +0.2% | composite absorption credit + price-table features |
| iter3 | 83.8% | 82.9% | 74.5% | 81.8% | **+1.6%** | landmark visual-section merge guard + layered gradient paint |
| iter4 | 84.1% | 82.8% | 75.5% | 82.3% | +0.3% | expand feature-rich pricing to native widget stacks |

**Stop:** iter4 gain ≤ 0.5% threshold.

Net improvement vs baseline: **+2.4%** average composite (81.7 → 84.1), geometry **+6.0pp**.
