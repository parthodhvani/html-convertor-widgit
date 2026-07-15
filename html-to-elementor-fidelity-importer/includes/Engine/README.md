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
| `AccordionRecognizer` | FAQ / `<details>` → native Elementor Accordion |
| `CompositePatternBuilder` | Forms, testimonials, icon-boxes, CTAs, social icons, price tables |
| `PixelRepairEngine` | Iterative layout/typography/gap repair |
| `VisualValidationEngine` | Geometry-first fidelity scoring |

## Native composite widgets

Marketing-page patterns (Petra Müller–style and similar) map to native Elementor widgets:

| Pattern | Elementor widget |
|---------|------------------|
| FAQ / accordion / `<details>` | `accordion` |
| Contact / booking / newsletter forms | `form` |
| Testimonial / review cards | `testimonial` |
| Service cards with prices | `price-table` |
| Feature / icon cards | `icon-box` |
| CTA banners | `call-to-action` |
| Social icon rows | `social-icons` |
| Star ratings | `star-rating` |
| Font Awesome icons | `icon` |

Collapsed FAQ panels are expanded during Chromium extraction so answers are not dropped.

## Tests

```bash
composer test
```

Regression fixtures: Bootstrap, Tailwind, HTML5 UP, nested flex, Kontakt.
