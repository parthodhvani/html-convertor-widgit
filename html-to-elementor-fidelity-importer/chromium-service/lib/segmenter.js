'use strict';

/**
 * Section segmentation + visual structure + full computed-style extraction.
 *
 * `browserPageSegmenter` is serialised and executed inside Chromium via
 * page.evaluate(), so it must be fully self-contained (no outer references).
 *
 * For each top-level section it produces:
 *   - semantic/visual metadata (tag, classes, bbox, key computed styles)
 *   - the original outerHTML (kept for last-resort HTML fallback)
 *   - a recursive `tree` of the section's DOM annotated with the FULL computed
 *     style set (typography, spacing, background, border, shadow, sizing,
 *     flex/grid layout) plus the data needed to emit native Elementor widgets.
 *
 * Every captured element is tagged with `data-h2e-uid` so the extractor can
 * re-measure it at tablet/mobile viewports for responsive fidelity.
 */

/**
 * The in-page segmenter. Returns an array of section descriptors.
 *
 * @returns {Array<object>}
 */
function browserPageSegmenter() {
  const SEMANTIC = ['HEADER', 'NAV', 'MAIN', 'SECTION', 'ARTICLE', 'ASIDE', 'FOOTER'];
  const CAPTURED_PROPS = [
    'display', 'position', 'backgroundColor', 'backgroundImage', 'color',
    'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight',
    'marginTop', 'marginBottom', 'marginLeft', 'marginRight',
    'flexDirection', 'justifyContent', 'alignItems', 'textAlign',
    'fontSize', 'fontFamily', 'lineHeight',
    'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
    'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius',
    'minHeight', 'gap', 'columnGap', 'rowGap', 'gridTemplateColumns', 'overflow',
  ];

  const ATOMIC = new Set([
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'img', 'hr', 'br',
    'video', 'audio', 'picture', 'source', 'blockquote', 'pre', 'code',
    'table', 'form', 'svg', 'canvas', 'iframe', 'object', 'embed',
    'input', 'select', 'textarea', 'label', 'button', 'figcaption',
  ]);
  // Note: ul/ol are NOT atomic — nav lists need li>a children for Elementor.
  const SKIP = new Set(['script', 'style', 'noscript', 'template', 'link', 'meta']);
  const INLINE = new Set(['b', 'strong', 'i', 'em', 'span', 'small', 'u', 'mark', 'code', 'br', 'sub', 'sup', 'abbr', 'time', 'a', 'svg']);

  const MAX_DEPTH = 18;
  const MAX_NODES = 8000;
  const MAX_HTML = 120000;
  const counter = { n: 0 };
  const uidSeq = { v: 0 };

  function isVisible(el) {
    if (!(el instanceof Element)) return false;
    const cs = window.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) {
      return false;
    }
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function visibleChildren(el) {
    return Array.from(el.children).filter(isVisible);
  }

  function isSemantic(el) {
    return SEMANTIC.indexOf(el.tagName) !== -1;
  }

  function directText(el) {
    let t = '';
    el.childNodes.forEach((n) => {
      if (n.nodeType === 3) t += n.nodeValue;
    });
    return t.replace(/\s+/g, ' ').trim();
  }

  function num(v) {
    const n = parseFloat(v);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
  }

  function transparent(c) {
    if (!c) return true;
    if (c === 'transparent') return true;
    const m = c.match(/rgba?\(([^)]+)\)/);
    if (m) {
      const p = m[1].split(',').map((s) => s.trim());
      if (p.length === 4 && parseFloat(p[3]) === 0) return true;
    }
    return false;
  }

  // Interactive elements worth checking for a `:hover` style change (kept
  // narrow — parsing every stylesheet rule against every node would be slow
  // and CTAs/links/buttons are the only nodes Elementor has hover controls
  // for today).
  const HOVER_CANDIDATE_TAGS = new Set(['a', 'button']);

  /**
   * One-time scan of every same-origin stylesheet for rules containing a
   * `:hover` selector, expanded into {selector (hover stripped), decl} pairs.
   * `rule.style` always exposes expanded longhand properties even when the
   * author wrote shorthand (e.g. `background: red`), so reading the
   * longhands directly is reliable without a shorthand parser.
   */
  function collectHoverRules() {
    const props = [
      'background-color', 'color',
      // Longhand border-*-color first (what Chromium's CSSOM normally
      // exposes even when the author wrote the `border`/`border-color`
      // shorthand); the bare shorthand is also read as a fallback.
      'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
      'border-color',
      'box-shadow',
    ];
    const rules = [];
    Array.from(document.styleSheets).forEach((sheet) => {
      let cssRules;
      try {
        cssRules = sheet.cssRules;
      } catch (e) {
        return; // Cross-origin stylesheet — cssRules access throws.
      }
      if (!cssRules) return;
      Array.from(cssRules).forEach((rule) => {
        if (!rule.selectorText || !rule.style || rule.selectorText.indexOf(':hover') === -1) return;
        rule.selectorText.split(',').forEach((rawSelector) => {
          const selector = rawSelector.replace(/:hover\b/g, '').replace(/::?(before|after)\b.*$/, '').trim();
          if (!selector) return;
          const decl = {};
          props.forEach((p) => {
            const v = rule.style.getPropertyValue(p);
            if (v) decl[p] = v;
          });
          if (Object.keys(decl).length) rules.push({ selector, decl });
        });
      });
    });
    return rules;
  }

  let hoverRulesCache = null;
  function getHoverRules() {
    if (hoverRulesCache === null) hoverRulesCache = collectHoverRules();
    return hoverRulesCache;
  }

  /**
   * Resolve `var(--name)` tokens in a raw declaration value against an
   * element's own resolved custom-property environment (same approach as
   * `extractCssVars` below) — `rule.style` returns the literal authored CSS
   * text, so a hover rule written as `color: var(--secondary)` would
   * otherwise hand PHP the unusable string "var(--secondary)" instead of
   * the actual colour.
   */
  function resolveVarTokens(value, cs) {
    if (!value || typeof value !== 'string' || value.indexOf('var(') === -1) return value;
    return value.replace(/var\(\s*(--[a-zA-Z0-9-_]+)\s*(?:,\s*([^)]+))?\)/g, (match, name, fallback) => {
      const resolved = cs.getPropertyValue(name);
      if (resolved && resolved.trim()) return resolved.trim();
      return fallback ? fallback.trim() : match;
    });
  }

  /**
   * Merge every matching `:hover` rule's declarations for one element
   * (later/more-specific source-order rules simply overwrite earlier ones —
   * an approximation of the cascade, good enough for the handful of
   * properties captured here). Returns null when nothing matches.
   */
  function hoverStyleFor(el) {
    const merged = {};
    getHoverRules().forEach(({ selector, decl }) => {
      let matches = false;
      try {
        matches = el.matches(selector);
      } catch (e) {
        matches = false;
      }
      if (matches) Object.assign(merged, decl);
    });
    if (!Object.keys(merged).length) return null;

    const cs = window.getComputedStyle(el);
    Object.keys(merged).forEach((k) => {
      merged[k] = resolveVarTokens(merged[k], cs);
    });

    const out = {};
    if (merged['background-color'] && !transparent(merged['background-color'])) out.bg = merged['background-color'];
    if (merged.color) out.color = merged.color;
    const bdc = merged['border-top-color'] || merged['border-right-color']
      || merged['border-bottom-color'] || merged['border-left-color'] || merged['border-color'];
    if (bdc && !transparent(bdc)) out.bdc = bdc;
    if (merged['box-shadow'] && merged['box-shadow'] !== 'none') out.sh = merged['box-shadow'];
    return Object.keys(out).length ? out : null;
  }

  function anchorIsContainer(el) {
    const kids = Array.from(el.children);
    if (kids.length === 0) return false;
    // Logo / brand anchors with multiple styled spans should stay containers.
    if (kids.length >= 2) return true;
    if (/logo|brand/.test(typeof el.className === 'string' ? el.className : '')) return true;
    return kids.some((c) => !INLINE.has(c.tagName.toLowerCase()));
  }

  function listItems(el) {
    const items = [];
    Array.from(el.children).forEach((li) => {
      if (li.tagName.toLowerCase() === 'li' && items.length < 60) {
        const txt = li.textContent.replace(/\s+/g, ' ').trim();
        if (txt) items.push(txt);
      }
    });
    return items;
  }

  // Capture the full computed style set for an element.
  // Keys are abbreviated for IR size; keep legacy scalars (bdw/br) for PHP CssMapper.
  function styleSet(cs) {
    const s = {
      // Typography.
      ff: cs.fontFamily,
      fs: cs.fontSize,
      fw: cs.fontWeight,
      lh: cs.lineHeight,
      ls: cs.letterSpacing,
      tt: cs.textTransform,
      ta: cs.textAlign,
      color: cs.color,
      // Spacing.
      mt: num(cs.marginTop), mr: num(cs.marginRight), mb: num(cs.marginBottom), ml: num(cs.marginLeft),
      pt: num(cs.paddingTop), pr: num(cs.paddingRight), pb: num(cs.paddingBottom), pl: num(cs.paddingLeft),
      // Sizing.
      w: num(cs.width), h: num(cs.height),
      minW: cs.minWidth, maxW: cs.maxWidth, minH: cs.minHeight, maxH: cs.maxHeight,
      // Layout.
      disp: cs.display,
      pos: cs.position,
      td: cs.textDecorationLine || cs.textDecoration,
      fst: cs.fontStyle,
      vis: cs.visibility,
      pe: cs.pointerEvents,
      wm: cs.writingMode,
      of: cs.objectFit,
      ar: cs.aspectRatio,
    };
    if (cs.zIndex && cs.zIndex !== 'auto') s.z = cs.zIndex;
    if (cs.overflow && cs.overflow !== 'visible') s.ov = cs.overflow;
    if (cs.overflowX && cs.overflowX !== 'visible') s.ovX = cs.overflowX;
    if (cs.overflowY && cs.overflowY !== 'visible') s.ovY = cs.overflowY;
    if (cs.transform && cs.transform !== 'none') s.tf = cs.transform;
    if (cs.transformOrigin && cs.transformOrigin !== '50% 50% 0px') s.tfo = cs.transformOrigin;
    if (cs.filter && cs.filter !== 'none') s.filter = cs.filter;
    if (cs.clipPath && cs.clipPath !== 'none') s.clip = cs.clipPath;
    if (cs.maskImage && cs.maskImage !== 'none') s.mask = cs.maskImage;
    if (cs.mixBlendMode && cs.mixBlendMode !== 'normal') s.blend = cs.mixBlendMode;
    if (cs.isolation && cs.isolation !== 'auto') s.isolation = cs.isolation;
    if (cs.backdropFilter && cs.backdropFilter !== 'none') s.bdFilter = cs.backdropFilter;
    else if (cs.webkitBackdropFilter && cs.webkitBackdropFilter !== 'none') s.bdFilter = cs.webkitBackdropFilter;
    if (cs.contain && cs.contain !== 'none') s.contain = cs.contain;
    if (cs.willChange && cs.willChange !== 'auto') s.willChange = cs.willChange;
    if (cs.paintOrder && cs.paintOrder !== 'normal') s.paintOrder = cs.paintOrder;
    if (cs.perspective && cs.perspective !== 'none') s.perspective = cs.perspective;
    if (cs.transition && cs.transition !== 'all 0s ease 0s') s.transition = cs.transition;
    if (cs.animationName && cs.animationName !== 'none') s.animation = cs.animationName;
    if (cs.textShadow && cs.textShadow !== 'none') s.tsh = cs.textShadow;
    if (cs.whiteSpace && cs.whiteSpace !== 'normal') s.ws = cs.whiteSpace;
    if (cs.wordSpacing && cs.wordSpacing !== '0px') s.wsp = cs.wordSpacing;
    if (cs.direction && cs.direction !== 'ltr') s.dir = cs.direction;

    // Flex container + item props (item props matter for absolute fidelity).
    if (cs.display.indexOf('flex') !== -1) {
      s.fd = cs.flexDirection;
      s.fw_wrap = cs.flexWrap;
      if (cs.alignContent && cs.alignContent !== 'normal') s.ac = cs.alignContent;
    }
    const flexGrow = cs.flexGrow;
    const flexShrink = cs.flexShrink;
    const flexBasis = cs.flexBasis;
    if (flexGrow && flexGrow !== '0') s.fg = flexGrow;
    if (flexShrink && flexShrink !== '1') s.fsh = flexShrink;
    if (flexBasis && flexBasis !== 'auto') s.fb = flexBasis;
    if (cs.order && cs.order !== '0') s.ord = cs.order;
    if (cs.alignSelf && cs.alignSelf !== 'auto') s.aself = cs.alignSelf;

    // Grid container tracks / areas.
    if (cs.display.indexOf('grid') !== -1) {
      s.gtc = cs.gridTemplateColumns;
      s.gtr = cs.gridTemplateRows;
      if (cs.gridTemplateAreas && cs.gridTemplateAreas !== 'none') s.gta = cs.gridTemplateAreas;
      if (cs.gridAutoFlow && cs.gridAutoFlow !== 'row') s.gaf = cs.gridAutoFlow;
      if (cs.justifyItems && cs.justifyItems !== 'normal') s.ji = cs.justifyItems;
    }
    if (cs.gridColumn && cs.gridColumn !== 'auto') s.gc = cs.gridColumn;
    if (cs.gridRow && cs.gridRow !== 'auto') s.gr = cs.gridRow;

    if (cs.justifyContent && cs.justifyContent !== 'normal') s.jc = cs.justifyContent;
    if (cs.alignItems && cs.alignItems !== 'normal') s.ai = cs.alignItems;
    const gap = cs.columnGap !== 'normal' ? cs.columnGap : (cs.gap !== 'normal' ? cs.gap : '');
    if (gap) s.gap = gap;
    if (cs.rowGap && cs.rowGap !== 'normal' && cs.rowGap !== gap) s.rgap = cs.rowGap;
    if (cs.columnGap && cs.columnGap !== 'normal' && cs.columnGap !== gap) s.cgap = cs.columnGap;

    // Background (preserve gradients as structured flag + raw image).
    if (!transparent(cs.backgroundColor)) s.bg = cs.backgroundColor;
    if (cs.backgroundImage && cs.backgroundImage !== 'none') {
      s.bgImg = cs.backgroundImage;
      s.bgSize = cs.backgroundSize;
      s.bgPos = cs.backgroundPosition;
      s.bgRepeat = cs.backgroundRepeat;
      if (/gradient\(/i.test(cs.backgroundImage)) s.bgGrad = true;
    }

    // Per-side borders (legacy bdw/bds/bdc = max/first non-zero for backcompat).
    const bdwT = num(cs.borderTopWidth);
    const bdwR = num(cs.borderRightWidth);
    const bdwB = num(cs.borderBottomWidth);
    const bdwL = num(cs.borderLeftWidth);
    const bdsT = cs.borderTopStyle;
    const bdsR = cs.borderRightStyle;
    const bdsB = cs.borderBottomStyle;
    const bdsL = cs.borderLeftStyle;
    const sides = [
      { w: bdwT, st: bdsT, c: cs.borderTopColor, k: 'T' },
      { w: bdwR, st: bdsR, c: cs.borderRightColor, k: 'R' },
      { w: bdwB, st: bdsB, c: cs.borderBottomColor, k: 'B' },
      { w: bdwL, st: bdsL, c: cs.borderLeftColor, k: 'L' },
    ];
    let maxW = 0;
    let repStyle = '';
    let repColor = '';
    sides.forEach((side) => {
      if (side.w > 0 && side.st && side.st !== 'none') {
        s['bdw' + side.k] = side.w;
        s['bds' + side.k] = side.st;
        if (!transparent(side.c)) s['bdc' + side.k] = side.c;
        if (side.w > maxW) {
          maxW = side.w;
          repStyle = side.st;
          repColor = side.c;
        }
      }
    });
    if (maxW > 0) {
      s.bdw = maxW;
      s.bds = repStyle;
      if (!transparent(repColor)) s.bdc = repColor;
      // Structured per-side widths for CssMapper dimensions controls.
      s.bd = {
        t: bdsT !== 'none' ? bdwT : 0,
        r: bdsR !== 'none' ? bdwR : 0,
        b: bdsB !== 'none' ? bdwB : 0,
        l: bdsL !== 'none' ? bdwL : 0,
      };
    }

    // Per-corner radii. Preserve units + elliptical "a / b" form — getComputedStyle
    // often returns "40% 60% 42% 58% / 55% 45%" for organic frames. Parsing with
    // num() alone strips % and the slash radii, turning blob masks into boxes.
    const brRaw = (cs.borderRadius || '').trim();
    const brTLRaw = (cs.borderTopLeftRadius || '').trim();
    const brTRRaw = (cs.borderTopRightRadius || '').trim();
    const brBRRaw = (cs.borderBottomRightRadius || '').trim();
    const brBLRaw = (cs.borderBottomLeftRadius || '').trim();
    const brTL = num(brTLRaw);
    const brTR = num(brTRRaw);
    const brBR = num(brBRRaw);
    const brBL = num(brBLRaw);
    const hasElliptical = brRaw.indexOf('/') !== -1
      || brTLRaw.indexOf('/') !== -1
      || brTRRaw.indexOf('%') !== -1
      || brTLRaw.indexOf('%') !== -1
      || brBRRaw.indexOf('%') !== -1
      || brBLRaw.indexOf('%') !== -1;
    if (brTL > 0 || brTR > 0 || brBR > 0 || brBL > 0 || (brRaw && brRaw !== '0px')) {
      s.brTL = brTL;
      s.brTR = brTR;
      s.brBR = brBR;
      s.brBL = brBL;
      s.br = Math.max(brTL, brTR, brBR, brBL);
      s.brad = { tl: brTL, tr: brTR, br: brBR, bl: brBL };
      if (hasElliptical && brRaw && brRaw !== '0px') {
        s.brRaw = brRaw;
      }
    }

    // Effects.
    if (cs.boxShadow && cs.boxShadow !== 'none') s.sh = cs.boxShadow;
    if (cs.opacity && parseFloat(cs.opacity) < 1) s.op = parseFloat(cs.opacity);
    if (cs.position === 'sticky' || cs.position === 'fixed' || cs.position === 'absolute') {
      const inset = {
        top: cs.top, right: cs.right, bottom: cs.bottom, left: cs.left,
      };
      if (Object.values(inset).some((v) => v && v !== 'auto')) s.inset = inset;
    }
    return s;
  }

  function domPath(el) {
    const parts = [];
    let cur = el;
    while (cur && cur.nodeType === 1 && cur !== document.body) {
      let part = cur.tagName.toLowerCase();
      if (cur.id) part += '#' + cur.id;
      else if (cur.className && typeof cur.className === 'string' && cur.className.trim()) {
        part += '.' + cur.className.trim().split(/\s+/).slice(0, 2).join('.');
      }
      parts.unshift(part);
      cur = cur.parentElement;
    }
    return parts.join(' > ');
  }

  function xpath(el) {
    const parts = [];
    let cur = el;
    while (cur && cur.nodeType === 1) {
      let index = 1;
      let sib = cur.previousElementSibling;
      while (sib) {
        if (sib.tagName === cur.tagName) index += 1;
        sib = sib.previousElementSibling;
      }
      parts.unshift(`${cur.tagName.toLowerCase()}[${index}]`);
      cur = cur.parentElement;
      if (cur === document.documentElement) {
        parts.unshift('html[1]');
        break;
      }
    }
    return '/' + parts.join('/');
  }

  function pseudoStyle(el, pseudo) {
    try {
      const ps = window.getComputedStyle(el, pseudo);
      const content = ps.content || '';
      const hasContent = content && content !== 'none' && content !== 'normal' && content !== '""';
      if (!hasContent && ps.display === 'none') {
        return null;
      }
      return {
        content,
        display: ps.display,
        color: ps.color,
        background: ps.backgroundColor,
        position: ps.position,
        inset: {
          top: ps.top,
          right: ps.right,
          bottom: ps.bottom,
          left: ps.left,
        },
      };
    } catch (e) {
      return null;
    }
  }

  function extractCssVars(styleValues, cs) {
    const found = new Set();
    const probe = (v) => {
      if (!v || typeof v !== 'string') return;
      const m = v.match(/var\(\s*(--[a-zA-Z0-9\-_]+)/g);
      if (!m) return;
      m.forEach((token) => {
        const name = token.replace(/var\(\s*/, '').trim();
        if (name.startsWith('--')) found.add(name);
      });
    };
    Object.keys(styleValues).forEach((k) => probe(styleValues[k]));
    const vars = {};
    found.forEach((name) => {
      const resolved = cs.getPropertyValue(name);
      if (resolved && resolved.trim()) {
        vars[name] = resolved.trim();
      }
    });
    return vars;
  }

  function buildTree(el, depth) {
    if (depth > MAX_DEPTH || counter.n > MAX_NODES) return null;
    if (!isVisible(el)) return null;
    const tag = el.tagName.toLowerCase();
    if (SKIP.has(tag)) return null;

    counter.n += 1;
    const uid = String(uidSeq.v++);
    el.setAttribute('data-h2e-uid', uid);

    const cs = window.getComputedStyle(el);
    const rect = el.getBoundingClientRect();
    const styles = styleSet(cs);
    const parent = el.parentElement;
    const role = el.getAttribute('role') || '';
    const before = pseudoStyle(el, '::before');
    const after = pseudoStyle(el, '::after');
    const vars = extractCssVars(styles, cs);

    const node = {
      tag,
      uid,
      id: el.id || '',
      cls: typeof el.className === 'string' ? el.className.trim() : '',
      text: directText(el),
      s: styles,
      bbox: { x: num(rect.x), y: num(rect.y), width: num(rect.width), height: num(rect.height) },
      domPath: domPath(el),
      xpath: xpath(el),
      ariaRole: role,
      ariaLabel: el.getAttribute('aria-label') || '',
      ariaLabelledby: el.getAttribute('aria-labelledby') || '',
      ariaDescribedby: el.getAttribute('aria-describedby') || '',
      a11yName: el.getAttribute('aria-label') || directText(el) || el.getAttribute('title') || '',
      uniqueKey: `${tag}:${uid}:${el.id || ''}`,
      parentUid: parent && parent.hasAttribute('data-h2e-uid') ? parent.getAttribute('data-h2e-uid') : '',
      siblingIndex: parent ? Array.prototype.indexOf.call(parent.children, el) : 0,
      siblingCount: parent ? parent.children.length : 0,
      childCount: el.children.length,
      visibleText: directText(el),
      pseudo: { before, after },
      states: {
        hover: el.matches(':hover'),
        focus: el.matches(':focus'),
        active: el.matches(':active'),
      },
      stacking: {
        zIndex: cs.zIndex,
        position: cs.position,
        opacity: cs.opacity,
        transform: cs.transform,
        filter: cs.filter,
        mixBlendMode: cs.mixBlendMode,
        isolation: cs.isolation,
        backdropFilter: cs.backdropFilter || cs.webkitBackdropFilter || 'none',
        overflow: cs.overflow,
        clipPath: cs.clipPath,
        contain: cs.contain,
        willChange: cs.willChange,
        pointerEvents: cs.pointerEvents,
        visibility: cs.visibility,
      },
      cssVars: vars,
    };

    if (HOVER_CANDIDATE_TAGS.has(tag) || role === 'button') {
      const hover = hoverStyleFor(el);
      if (hover) node.hover = hover;
    }

    // Phase 12 — capture measured text metrics for atomic/text leaves.
    const direct = node.text;
    if (direct && (ATOMIC.has(tag) || tag === 'span' || tag === 'a' || tag === 'li')) {
      try {
        const range = document.createRange();
        range.selectNodeContents(el);
        const tr = range.getBoundingClientRect();
        const fontPx = num(cs.fontSize) || 16;
        let lineHeightPx = num(cs.lineHeight);
        // CSS "normal" parses to 0 — recover from measured box / font size.
        if (lineHeightPx <= 0) {
          lineHeightPx = tr.height > 0 ? num(tr.height) : Math.round(fontPx * 1.2 * 100) / 100;
        }
        const lineCount = Math.max(1, Math.round(tr.height / Math.max(1, lineHeightPx || fontPx)));
        node.typography = {
          textWidth: num(tr.width),
          textHeight: num(tr.height),
          lineCount,
          fontSizePx: fontPx,
          lineHeightPx,
          letterSpacingPx: num(cs.letterSpacing),
          wordSpacingPx: num(cs.wordSpacing),
          fontAscentApprox: Math.round(fontPx * 0.8 * 100) / 100,
          fontDescentApprox: Math.round(fontPx * 0.2 * 100) / 100,
          whiteSpace: cs.whiteSpace,
          wordBreak: cs.wordBreak,
          overflowWrap: cs.overflowWrap,
        };
      } catch (e) {
        // ignore measurement failures
      }
    }

    if (tag === 'img') {
      node.src = el.currentSrc || el.getAttribute('src') || '';
      node.alt = el.getAttribute('alt') || '';
      node.loading = el.getAttribute('loading') || '';
      node.lazy = el.loading === 'lazy' || el.getAttribute('data-src') ? true : undefined;
      if (el.getAttribute('data-src')) node.dataSrc = el.getAttribute('data-src');
    }
    if (tag === 'a') {
      node.href = el.getAttribute('href') || '';
    }
    if (tag === 'iframe' || tag === 'video' || tag === 'audio' || tag === 'source') {
      node.src = el.getAttribute('src') || '';
    }
    if (tag === 'ul' || tag === 'ol') {
      node.items = listItems(el);
    }

    const treatAsContainer = (tag === 'a') ? anchorIsContainer(el) : !ATOMIC.has(tag);

    if (treatAsContainer) {
      const kids = [];
      Array.from(el.children).forEach((child) => {
        const c = buildTree(child, depth + 1);
        if (c) kids.push(c);
      });
      node.children = kids;
      if (kids.length === 0 && node.text) {
        node.atomicText = true;
        node.html = el.outerHTML.slice(0, MAX_HTML);
      }
      // Carry outerHTML for containers that will need an HTML fallback
      // (layered/absolute designs or third-party slider widgets), so the PHP
      // converter can preserve them faithfully.
      const layered = Array.from(el.children).some((c) => {
        const p = window.getComputedStyle(c).position;
        return p === 'absolute' || p === 'fixed' || p === 'sticky';
      });
      const slider = /swiper|slick|owl-carousel|splide|flickity/i.test(node.cls);
      const composite = /faq|accordion|testimonial|form|newsletter|cta-banner|service-card|socials/i.test(node.cls)
        || tag === 'form' || tag === 'details';
      if ((layered || slider || composite) && !node.html) {
        node.html = el.outerHTML.slice(0, MAX_HTML);
      }

      // Include collapsed FAQ/accordion panels that fail the height>0 visibility
      // check so accordion answers still reach the PHP reconstructor.
      if (/faq|accordion|disclosure/i.test(node.cls) || tag === 'details') {
        Array.from(el.children).forEach((child) => {
          if (kids.some((k) => k.uid && child.getAttribute('data-h2e-uid') === k.uid)) {
            return;
          }
          const forced = buildTreeForce(child, depth + 1);
          if (forced) kids.push(forced);
        });
        node.children = kids;
      }
    } else {
      node.atomic = true;
      node.html = el.outerHTML.slice(0, MAX_HTML);
    }

    return node;
  }

  /**
   * Build a tree node even when height is 0 (collapsed disclosure panels).
   */
  function buildTreeForce(el, depth) {
    if (depth > MAX_DEPTH || counter.n > MAX_NODES) return null;
    if (!(el instanceof Element)) return null;
    const cs = window.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return null;
    const tag = el.tagName.toLowerCase();
    if (SKIP.has(tag)) return null;

    counter.n += 1;
    const uid = String(uidSeq.v++);
    el.setAttribute('data-h2e-uid', uid);
    const rect = el.getBoundingClientRect();
    const styles = styleSet(cs);
    const node = {
      tag,
      uid,
      id: el.id || '',
      cls: typeof el.className === 'string' ? el.className.trim() : '',
      text: directText(el),
      s: styles,
      bbox: {
        x: num(rect.x),
        y: num(rect.y),
        width: num(Math.max(rect.width, 1)),
        height: num(Math.max(rect.height, 1)),
      },
      collapsed: true,
      ariaRole: el.getAttribute('role') || '',
    };
    if (tag === 'a') node.href = el.getAttribute('href') || '';

    const treatAsContainer = !ATOMIC.has(tag);
    if (treatAsContainer) {
      const kids = [];
      Array.from(el.children).forEach((child) => {
        const c = buildTreeForce(child, depth + 1);
        if (c) kids.push(c);
      });
      node.children = kids;
      node.html = el.outerHTML.slice(0, MAX_HTML);
    } else {
      node.atomic = true;
      node.html = el.outerHTML.slice(0, MAX_HTML);
    }
    return node;
  }

  function bgKey(cs) {
    const img = cs.backgroundImage && cs.backgroundImage !== 'none' ? cs.backgroundImage : '';
    const color = transparent(cs.backgroundColor) ? 'transparent' : cs.backgroundColor;
    return color + '|' + img;
  }

  /**
   * Visual segmentation: prefer whitespace gaps + background continuity over
   * raw DOM siblings. Groups consecutive siblings that share background and
   * have small vertical gaps; splits on large whitespace or bg changes.
   *
   * @param {Element[]} els Visible sibling candidates.
   * @returns {Element[]} Section roots (may be original els or wrappers).
   */
  function visualSegment(els) {
    if (els.length <= 1) return els;

    const meta = els.map((el) => {
      const cs = window.getComputedStyle(el);
      const r = el.getBoundingClientRect();
      return {
        el,
        y: r.y,
        bottom: r.y + r.height,
        h: r.height,
        bg: bgKey(cs),
        semantic: isSemantic(el),
      };
    });

    // Sort by visual top (DOM order usually matches, but absolute siblings may not).
    meta.sort((a, b) => {
      if (Math.abs(a.y - b.y) > 1) return a.y - b.y;
      return (a.el.compareDocumentPosition(b.el) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
    });

    const gaps = [];
    for (let i = 0; i < meta.length - 1; i += 1) {
      gaps.push(Math.max(0, meta[i + 1].y - meta[i].bottom));
    }
    const medianGap = gaps.length
      ? gaps.slice().sort((a, b) => a - b)[Math.floor(gaps.length / 2)]
      : 0;
    // Large whitespace separator: max(48px, 2.5× median inter-sibling gap).
    const splitGap = Math.max(48, medianGap * 2.5);

    const groups = [];
    let current = [meta[0]];
    for (let i = 1; i < meta.length; i += 1) {
      const prev = meta[i - 1];
      const cur = meta[i];
      const gap = Math.max(0, cur.y - prev.bottom);
      const bgBreak = prev.bg !== cur.bg && prev.bg !== 'transparent|' && cur.bg !== 'transparent|';
      const semanticBreak = cur.semantic && prev.semantic && gap >= 16;
      if (gap >= splitGap || bgBreak || semanticBreak) {
        groups.push(current);
        current = [cur];
      } else {
        current.push(cur);
      }
    }
    groups.push(current);

    // Flatten groups: single-element groups stay as-is; multi-element groups
    // that already share a common parent keep the first as section root only
    // when they were already separate — prefer emitting each semantic block.
    const out = [];
    groups.forEach((group) => {
      if (group.length === 1) {
        out.push(group[0].el);
        return;
      }
      // Landmark / semantic blocks must stay independent sections — merging
      // nav+hero+services destroys geometry matching and layered heroes.
      const landmark = (g) => {
        const t = (g.el.tagName || '').toUpperCase();
        return g.semantic || ['SECTION', 'HEADER', 'FOOTER', 'NAV', 'MAIN', 'ASIDE', 'ARTICLE'].includes(t)
          || /\b(nav|navbar|hero|banner|footer|header)\b/i.test(g.el.className || '');
      };
      if (group.some(landmark)) {
        group.forEach((g) => out.push(g.el));
        return;
      }
      // Non-semantic cluster → tag every member with the same visual group id
      // so PHP VisualTreeBuilder can merge them into one visual section.
      const gid = 'vg-' + String(out.length);
      const primary = group.reduce((best, g) => (g.h > best.h ? g : best), group[0]);
      group.forEach((g) => {
        g.el.setAttribute('data-h2e-visual-group', gid);
      });
      primary.el.setAttribute('data-h2e-visual-section', '1');
      group.forEach((g) => out.push(g.el));
    });

    return out.length ? out : els;
  }

  // 1. Find the content container by descending single non-semantic wrappers.
  let container = document.body;
  let guard = 0;
  while (guard < 10) {
    guard += 1;
    const kids = visibleChildren(container);
    if (kids.length === 1 && !isSemantic(kids[0]) && kids[0].children.length > 0) {
      container = kids[0];
    } else {
      break;
    }
  }

  let candidates = visibleChildren(container);
  if (candidates.length === 0) {
    candidates = [container];
  }

  // Phase 8: visual segmentation overrides pure DOM sibling order.
  candidates = visualSegment(candidates);

  const sections = [];
  candidates.forEach((el, index) => {
    el.setAttribute('data-h2e-section', String(index));
    const rect = el.getBoundingClientRect();
    const cs = window.getComputedStyle(el);
    const styles = {};
    CAPTURED_PROPS.forEach((p) => {
      styles[p] = cs[p];
    });

    counter.n = 0;
    const tree = buildTree(el, 0);

    sections.push({
      index,
      tag: el.tagName.toLowerCase(),
      id: el.id || '',
      classes: el.className && typeof el.className === 'string' ? el.className : '',
      semantic: isSemantic(el),
      html: el.outerHTML,
      bbox: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
      styles,
      background: cs.backgroundColor,
      visualGroup: el.getAttribute('data-h2e-visual-group') || '',
      visualSection: el.hasAttribute('data-h2e-visual-section'),
      tree,
    });
  });

  return sections;
}

module.exports = { browserPageSegmenter };
