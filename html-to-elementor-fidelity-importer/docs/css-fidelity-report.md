# CSS Fidelity Report

**Companion to:** `docs/rendering-pipeline-audit.md`  
**Legend:** IR = captured in Chromium tree · Map = Elementor control or `_h2e_custom_css` · Import = survives WP import

---

## Layout

| Property | IR | Map | Import | Why lost / where | How to recover | Est. gain |
|----------|----|-----|--------|------------------|----------------|-----------|
| display flex/grid/block | Yes `disp` | Flex yes; grid → custom CSS | Partial | Elementor has no CSS Grid | Keep `display:grid` + tracks on same node; % children from tracks | +0.5–1.5% |
| width / height (px) | Yes `w/h` | % width in rows; height via min-height | Partial | Fixed px width often dropped | Map `content_width` + `width` from bbox vs parent | +1–2% |
| min-width / max-width | Yes `minW/maxW` | **No** | No | `CssMapper::sizing` ignores | Map to Elementor min/max width controls + custom CSS | +0.5–1% |
| min-height | Yes `minH` | Yes | Yes | — | — | — |
| max-height | Yes `maxH` | **No** | No | sizing() incomplete | custom CSS `max-height` | +0.2–0.5% |
| aspect-ratio | Yes `ar` | Partial `_h2e_custom_css` | Partial | Intent only when constrained | Always map `ar` | +0.2% |

## Box model

| Property | IR | Map | Import | Why lost / where | How to recover | Est. gain |
|----------|----|-----|--------|------------------|----------------|-----------|
| padding | Yes | Yes | Yes | Whitespace may inflate | Prefer CSS padding over inferred | +0.3% |
| margin | Yes | **Containers: no**; children stripped | Partial | `map_container` + `strip_child_margins` / `clear_child_margins` | Preserve margins; map container margin; only collapse when CSS gap exists | **+1.5–3%** |
| border / radius | Yes | Yes | Yes | — | — | — |
| box-sizing | Implicit | Assumed border-box | — | Not explicit | Rarely needed if sizes from bbox | — |
| box-shadow | Yes | Parsed | Yes | Complex shadows may fail parse | Fallback full string → custom CSS | +0.2% |

## Flexbox

| Property | IR | Map | Import | Why lost / where | How to recover | Est. gain |
|----------|----|-----|--------|------------------|----------------|-----------|
| flex-direction | Yes | Yes (+ constraints) | Yes | VisualSiblingLayout used to override CSS (mitigated) | Keep CSS authority | +0.5% |
| flex-wrap | Yes | Forced wrap on row | Yes | Over-wrap | Map CSS wrap literally | +0.3% |
| justify/align | Yes | Yes | Yes | Geometry override possible | Prefer CSS `jc`/`ai` | +0.3% |
| gap / rowGap / columnGap | Yes | Linked single gap | Yes | row≠column lost | Separate row/column gap controls | +0.3–0.6% |
| flex-grow/shrink/basis | Yes `fg/fsh/fb` | Almost unused | No | Emitter ignores item props | Map to custom CSS / advanced | +0.5–1% |
| align-self / order | Yes | No | No | Unused | custom CSS | +0.3% |
| align-content | Partial `ac` | No | No | Unused | custom CSS | +0.2% |

## Grid

| Property | IR | Map | Import | Why lost / where | How to recover | Est. gain |
|----------|----|-----|--------|------------------|----------------|-----------|
| grid-template-columns/rows | Yes | custom CSS | Partial | Preview may force flex | Ensure preview applies custom CSS; emit % widths from tracks | +0.5–1.5% |
| grid gaps | Yes | Merged flex_gap | Partial | — | Preserve as grid-gap in custom CSS | +0.3% |
| grid placement | Yes `gc/gr` | No | No | Dropped | custom CSS grid-column/row | +0.3% |

## Positioning

| Property | IR | Map | Import | Why lost / where | How to recover | Est. gain |
|----------|----|-----|--------|------------------|----------------|-----------|
| absolute/fixed + insets | Yes | Partial sticky/absolute | Partial | LayeredSolver flattens content | Emit positioned Elementor elements with top/left/right/bottom | **+0.5–1.5%** |
| sticky | Yes | Yes | Yes | — | — | — |
| z-index | Yes | Yes when numeric | Yes | — | — | — |
| containing block | Implicit bbox | Lost for abs children | No | Flattened layers | Preserve parent `position:relative` + child offsets | +0.5% |

## Paint

| Property | IR | Map | Import | Why lost / where | How to recover | Est. gain |
|----------|----|-----|--------|------------------|----------------|-----------|
| background color/image | Yes | Yes | Yes | — | — | — |
| gradients | Yes | Parsed / custom CSS | Partial | Preview omits custom CSS in suite | Inject CSS in preview; improve parse | **+1%** metric |
| overlays / layered bg | Partial | LayeredSolver | Partial | Absolute paint → bg only | Full paint stack | +0.5–1% |
| opacity | Yes | Yes | Yes | — | — | — |
| filters / blend / clip / mask / backdrop | Yes | custom CSS | Partial | Suite preview drops CSS | Wire preview + Frontend | +0.5–1% |
| ::before/::after | `pseudo` | **Never emitted** | Only via source CSS | No emitter | Emit decorative HTML/CSS or source CSS | +0.3–0.8% |
| multi-background | No | No | No | Not captured | Extend styleSet | +0.2% |

## Typography

| Property | IR | Map | Import | Why lost / where | How to recover | Est. gain |
|----------|----|-----|--------|------------------|----------------|-----------|
| family/size/weight/lh/ls/color/align | Yes | Yes | Yes | Font loading CDN may fail offline | Bundle/font-face capture | +0.3% |
| measured textWidth/Height | Yes `typography` | Diagnostic `_h2e_text_width` | No | Not used to constrain width | Optional max-width from measure | +0.3% |
| white-space / word-break / overflow-wrap | In bag | **No** | No | CssMapper::typography gap | Map to custom CSS | +0.3–0.8% |
| Responsive font/color | Thin `r` | Partial font-size | Partial | measureUids incomplete | Widen measure + map | +0.5% |

---

## Highest-ROI recovery list

1. **Stop margin destruction** unless author CSS `gap` exists (WhitespaceAnalyzer + ConstraintSolver).  
2. **Map container width / max-width / margin** from IR.  
3. **Preview fidelity** — inject `combinedCss` + element `_h2e_custom_css` so paint/typography CSS is visible to the oracle.  
4. **Flex item + grid placement** via custom CSS on the emitted element.  
5. **Absolute content insets** in LayeredLayoutSolver.  
6. **Typography white-space/word-break**.  
7. **Pseudo-elements** via scoped custom CSS from `pseudo` bag.

Never silently drop: if Elementor has no control, write `_h2e_custom_css` and record `_h2e_unsupported`.
