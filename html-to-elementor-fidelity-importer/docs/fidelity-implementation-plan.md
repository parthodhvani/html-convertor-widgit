# Fidelity Implementation Plan (pre-code summary)

## 1. Current understanding

This is a **Browser Rendering → Elementor Compiler**. Chromium already computed correct layout/paint. Failures are **information loss** between IR stages and emission — not missing widgets.

Baseline suite (PR #12): **~84.1%** composite on 42 pages.

## 2. Architecture findings

See `docs/rendering-pipeline-audit.md`. Critical path:

`extractor/segmenter → VisualTree → ConstraintSolver → WhitespaceAnalyzer → Emitter/CssMapper → Preview/Import`

## 3. Main information-loss points

1. WhitespaceAnalyzer inventing flex-gap from margins and wiping child margins  
2. CssMapper omitting max-width / flex-item / container margins  
3. Preview oracle omitting source + `_h2e_custom_css` (false pixel scores)  
4. Layered absolute content losing insets  
5. Emitter hoisting boxes that still carry spacing/width  

## 4. Top blockers ranked

| Rank | Blocker | Est. gain |
|------|---------|-----------|
| 1 | Margin→gap wipe | +1.5–3% |
| 2 | Preview CSS injection | +1–2% |
| 3 | Width/max-width/flex-item mapping | +1–2% |
| 4 | Absolute insets | +0.5–1.5% |
| 5 | Preserve spaced containers | +0.5–1% |

## 5. Files affected

WhitespaceAnalyzer, CssMappingEngine, CssMapper, LayeredLayoutSolver, LayoutGraphEmitter, ElementorPreviewRenderer, compile-and-preview.php, run-suite.js, build-corpus.js, docs/*

## 6. Expected accuracy improvement

Near-term target from this iteration: **84% → 87–90%** on comparable pages; path to 95% requires segmenter atomic recursion + full responsive paint + pseudo-elements.

## 7. Implementation order

Earliest loss first: margins → sizing/CSS → preview/debug → absolute → emitter preserve → corpus expansion → benchmark loop.
