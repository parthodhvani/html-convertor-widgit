# Fidelity Implementation Plan

## 1. Current understanding

This is a **Browser Rendering → Elementor Compiler**. Chromium already computed correct layout/paint. Failures are **information loss** between IR stages and emission — not missing widgets.

## 2. Architecture findings

See `docs/rendering-pipeline-audit.md`. Critical path:

`extractor/segmenter → VisualTree → ConstraintSolver → WhitespaceAnalyzer → Emitter/CssMapper → Preview/Import`

## 3. Main information-loss points (updated)

1. External CSS blocked (bad SRI) → unstyled flex/grid (earliest geometry loss)
2. Residual bbox padding invented over computed `padding:0`
3. Fixed/`visually-hidden` chrome marking whole trees as `layered_block` → legacy flatten
4. Broken `children_are_columns()` forcing page roots to `layoutType=row`
5. Flex/grid tracks mislabeled `hero` → layered emission
6. (Prior) Margin→gap wipe, missing max-width/flex-item, preview CSS omission

## 4. Top blockers ranked

| Rank | Blocker | Est. gain |
|------|---------|-----------|
| 1 | Stylesheet load / SRI recovery + local vendor | +2–4% on framework pages |
| 2 | Anti-flatten (layered chrome + column inference) | +1–3% geo |
| 3 | Residual padding invention | +0.5–1.5% |
| 4 | Icon-box/form preview placeholders | +0.5–1% pixel |
| 5 | Segmenter atomic recursion | +1–2% long-term |

## 5. Files affected

`extractor.js`, `VisualSignals`, `LayoutGraphEngine`, `ConstraintLayoutSolver`, `SemanticComponentGraph`, `LayoutGraphEmitter`, `WhitespaceAnalyzer`, `CssMappingEngine`, `PixelRepairEngine`, `build-corpus.js`, corpus HTML + `vendor/bootstrap.min.css`, docs/*

## 6. Expected accuracy improvement

- forensic-v1: **89.1%** on 100 pages (from ~84.1% on 42)
- Anti-flatten iteration: target **≥89.5%** with Bootstrap geo recovery; path to 95% needs segmenter + paint/pseudo + real Elementor oracle

## 7. Implementation order

1. Audit docs  
2. Stylesheet fidelity (vendor + SRI recovery)  
3. Stop invented padding  
4. Stop layered/row misclassification  
5. Benchmark loop → keep >0.5% gains only
