# Visual Reconstruction Engine v2

The plugin transforms HTML imports into native Elementor pages using a
multi-stage visual reconstruction pipeline. DOM is supporting metadata only;
the rendered page and visual tree are the source of truth.

## Pipeline

```
Rendered Page (Chromium)
    ↓
VisualExtractionEngine
    ↓
WrapperEliminationEngine
    ↓
LayoutGraphEngine
    ↓
ConstraintLayoutEngine
    ↓
DesignTokenExtractor
    ↓
ResponsiveReconstructionEngine
    ↓
AnimationEngine
    ↓
ComponentRecognitionEngine → NativeWidgetMapper
    ↓
CssMappingEngine → LayoutTreeConverter
    ↓
VisualValidationEngine (iterative repair)
    ↓
ImportQualityReport
```

## Engines

| Engine | Class | Role |
|--------|-------|------|
| Chromium Visual Extraction | `chromium-service/lib/segmenter.js` + `VisualExtractionEngine` | Bounding boxes, computed styles, transforms, accessibility |
| Layout Graph | `LayoutGraphEngine` | Sections, rows, columns, heroes, nav, cards, grids |
| Constraint Layout | `ConstraintLayoutEngine` | Margins → gap, spacing tokens, container padding |
| Design Tokens | `DesignTokenExtractor` | Palette, typography/spacing/radius/shadow scales |
| Component Recognition | `ComponentRecognitionEngine` | Confidence-based classification |
| Native Widget Mapping | `NativeWidgetMapper` | Maps to Elementor widgets (>95% native target) |
| Responsive Reconstruction | `ResponsiveReconstructionEngine` | 7 breakpoints, responsive controls |
| Media | `MediaEngine` | Images, SVG, backgrounds for Media Library import |
| CSS Mapping | `CssMappingEngine` | Computed CSS → Elementor controls |
| Animation | `AnimationEngine` | CSS transitions → Motion Effects |
| Visual Validation | `VisualValidationEngine` + `chromium-service/compare.js` | SSIM, repair loop |
| Wrapper Elimination | `WrapperEliminationEngine` | Removes meaningless wrapper divs |

## Orchestration

`VisualReconstructionOrchestrator` coordinates preprocessing and validation.
`ElementorJsonGenerator` delegates native mode to the orchestrator while
preserving the `preserve` mode API unchanged.

## Configuration

Settings (`h2e_settings`):

- `breakpoints` — wide (1920) through mobile (375)
- `widget_confidence` — minimum confidence for native widgets (default 95)
- `fidelity_threshold` — visual validation target (default 95)

## Tests

```bash
composer test                              # PHPUnit (engines + kontakt regression)
php tests/harness.php layout.json native   # Standalone JSON generator
node chromium-service/compare.js --original a.png --generated b.png --json
```
