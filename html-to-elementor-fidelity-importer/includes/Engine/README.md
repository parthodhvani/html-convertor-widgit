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
