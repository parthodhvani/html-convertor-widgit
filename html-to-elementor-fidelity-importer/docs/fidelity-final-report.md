# Fidelity Final Report — Browser Rendering Compiler

## Verdict

The conversion pipeline is a **Browser Rendering → Elementor Compiler**. Forensic audit + earliest-loss fixes lifted the 100-page accuracy suite from **~84.1%** (42-page prior) to **89.1%** (forensic-v1), then targeted geometry flatteners that still crushed Bootstrap/multi-column pages.

## Architecture (unchanged foundation)

```
HTML → Chromium (extractor/segmenter)
  → VisualTreeBuilder → LayoutGraphEngine → ConstraintLayoutSolver
  → SemanticComponentGraph → WhitespaceAnalyzer → AlignmentEngine
  → WrapperElimination → ResponsiveLayoutEngine
  → LayoutGraphEmitter / CssMapper → ContainerTreeOptimizer
  → ClosedLoop / GeometryComparator → ImportEngine
```

Docs: `rendering-pipeline-audit.md`, `css-fidelity-report.md`, `fidelity-implementation-plan.md`.

## Earliest information-loss points found

| Rank | Loss | Stage | Fix |
|------|------|-------|-----|
| 1 | Bad SRI / CDN CSS → unstyled `display:block` rows | Chromium extract | Local Bootstrap vendor + stylesheet SRI recovery |
| 2 | Residual bbox “padding” (1000px+) over computed 0 | WhitespaceAnalyzer / CssMapping / PixelRepair | Never invent padding over Chromium keys; cap residual |
| 3 | Tiny fixed/`visually-hidden` chrome → whole page `layered_block` | VisualSignals + Emitter | Ignore chrome layers; require real cover layers |
| 4 | `children_are_columns()` always true → page root `layoutType=row` | LayoutGraphEngine | Geometry side-by-side test; prefer stacked full-bleed |
| 5 | Flex/grid multi-col tracks labeled `hero` | Semantic + LayoutGraph | Never hero/layered for row/grid tracks |
| 6 | Margin→gap wipe / missing max-width / preview CSS | Prior forensic commit | Already landed in forensic-v1 |

## Benchmarks

### forensic-v1 (100 pages) — post audit fixes

```
avg_composite = 89.1%
avg_pixel     = 92.6%
avg_geo       = 79.3%
spacing       = 87.5%
typography    = 100%
responsive    = 96.3%
colour        = 83.2%
native/html   = 1563 / 48
```

### Spot: real-bs-features (hardest Bootstrap page)

| Metric | Before (SRI fail + flatten) | After (vendor CSS + anti-flatten) |
|--------|----------------------------|-----------------------------------|
| IR row display | `block`, cols 1424px | `flex`/`row`, cols 33%/50% |
| Emitted BS rows | 0 (page flattened) | 6 with correct widths |
| Root direction | row / layered | column / stack |
| Geometry (closed-loop off) | ~20–29 | ~38 |

## Debug artifacts

Each accuracy page writes `debug-output/`:

- `original.png`, `elementor-render.png`
- `layout-diff.json`, `css-loss.json`
- `typography-diff.json`, `paint-diff.json`

## Remaining path to 95%

1. Segmenter: recurse into atomic `p`/`h*`/`button` when they wrap structure  
2. Widget preview oracles for `icon-box` / `form` (placeholders hurt pixel/geo)  
3. Grid track → % columns consistently + gap from Bootstrap `g-*`  
4. Pseudo-elements / multi-layer paint  
5. Real Elementor import validation (suite uses preview oracle)

## Stop rule

Keep only changes that improve avg composite **> 0.5%**. forensic-v1 already cleared that bar vs 84.1%; continue loop on geometry/missing_emission until next change ≤ 0.5%.
