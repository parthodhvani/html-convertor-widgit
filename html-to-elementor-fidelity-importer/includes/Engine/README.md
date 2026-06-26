# Visual Reconstruction Engine v3

Geometry-first pipeline. The rendered page is the source of truth; DOM is metadata only.

## Pipeline

```
Chromium Render
    ↓
VisualExtractionEngine
    ↓
VisualTreeBuilder
    ↓
LayoutGraphEngine
    ↓
ConstraintLayoutSolver
    ↓
WhitespaceAnalyzer
    ↓
AlignmentEngine
    ↓
WrapperEliminationEngine
    ↓
SemanticComponentRecognizer
    ↓
DesignTokenExtractor
    ↓
ResponsiveLayoutEngine
    ↓
ElementorJsonGenerator (LayoutTreeConverter)
    ↓
VisualValidationEngine
    ↓
PixelRepairEngine
    ↓
ImportQualityReport
```

## Priority metrics (v3)

1. Visual layout accuracy (`layout_similarity`)
2. Spacing accuracy (`spacing_similarity`)
3. Typography similarity
4. Responsive similarity
5. Native widget ratio (secondary)

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
