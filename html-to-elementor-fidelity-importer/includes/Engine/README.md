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
| `PixelRepairEngine` | Iterative layout/typography/gap repair |
| `VisualValidationEngine` | Geometry-first fidelity scoring |

## Tests

```bash
composer test
```

Regression fixtures: Bootstrap, Tailwind, HTML5 UP, nested flex, Kontakt.
