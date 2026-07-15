# Visual Reconstruction Engine v4

Geometry-first pipeline. The rendered page is the source of truth; DOM is metadata only.

## Pipeline

```
Chromium Render
    ↓
VisualExtractionEngine (section-local bbox normalization)
    ↓
VisualTreeBuilder
    ↓
LayoutGraphEngine
    ↓
ConstraintLayoutSolver
    ↓
SemanticComponentGraph
    ↓
WhitespaceAnalyzer
    ↓
AlignmentEngine
    ↓
WrapperEliminationEngine
    ↓
ResponsiveLayoutEngine
    ↓
LayoutGraphEmitter
    ↓
ContainerTreeOptimizer (compress redundant containers)
    ↓
GeometryComparator
    ↓
PixelRepairEngine (closed-loop)
    ↓
ImportQualityReport
```

## Priority metrics (v4)

1. Geometry similarity (`geometry_similarity`, `bbox_delta`, `position_rmse`)
2. Visual layout accuracy (`layout_similarity`)
3. Spacing accuracy (`spacing_similarity`)
4. Typography / responsive / pixel (secondary)
5. Native widget ratio (tertiary)

## Key engines

| Engine | Responsibility |
|--------|----------------|
| `VisualTreeBuilder` | Rebuild tree from bounding boxes, not DOM |
| `ConstraintLayoutSolver` | Figma-style stacks, gap, equal sizing |
| `WhitespaceAnalyzer` | Measure whitespace → Elementor gap/padding |
| `AlignmentEngine` | Shared edges, baselines, centers → flex alignment |
| `SemanticComponentRecognizer` | Geometry + context classification |
| `AccordionRecognizer` | Detects `<details>`/FAQ/accordion groups → single native `accordion` widget |
| `PixelRepairEngine` | Iterative layout/typography/gap repair |
| `VisualValidationEngine` | Geometry-first fidelity scoring |

## Component reconstruction

Beyond per-node classification, some multi-node structures are recognised as a
whole and reconstructed as one native Elementor widget (eliminating the wrapper
and item markup instead of mirroring the DOM):

| Structure | Detected by | Native output |
|-----------|-------------|---------------|
| Hero (background image + overlay + content) | `LayoutTreeConverter` (role `hero`) | container with `background_image` |
| Navigation | `LayoutTreeConverter` (role `navigation`) | row container of native widgets |
| Accordion / FAQ / `<details>` disclosure | `AccordionRecognizer` (role `faq`, `.accordion`/`.faq` hints, or ≥2 `<details>`) | one `accordion` widget with `tabs` |

> Note: closed `<details>` content is `display:none`, so the Chromium extractor
> only captures answer text for **open** disclosures; the recognizer still maps
> the titles for collapsed ones.

## Tests

```bash
composer test
```

Regression fixtures: Bootstrap, Tailwind, HTML5 UP, nested flex, Kontakt.
