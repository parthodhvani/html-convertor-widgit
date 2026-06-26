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
    'marginTop', 'marginBottom', 'flexDirection', 'justifyContent',
    'alignItems', 'textAlign', 'fontSize', 'fontFamily', 'lineHeight',
    'borderTopWidth', 'borderBottomWidth', 'minHeight',
  ];

  const ATOMIC = new Set([
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'img', 'ul', 'ol', 'hr', 'br',
    'video', 'audio', 'picture', 'source', 'blockquote', 'pre', 'code',
    'table', 'form', 'svg', 'canvas', 'iframe', 'object', 'embed',
    'input', 'select', 'textarea', 'label', 'button', 'figcaption',
  ]);
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

  function anchorIsContainer(el) {
    const kids = Array.from(el.children);
    if (kids.length === 0) return false;
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
    };
    if (cs.zIndex && cs.zIndex !== 'auto') s.z = cs.zIndex;
    if (cs.overflow && cs.overflow !== 'visible') s.ov = cs.overflow;
    if (cs.display.indexOf('flex') !== -1) {
      s.fd = cs.flexDirection;
      s.fw_wrap = cs.flexWrap;
    }
    if (cs.display.indexOf('grid') !== -1) {
      s.gtc = cs.gridTemplateColumns;
    }
    if (cs.justifyContent && cs.justifyContent !== 'normal') s.jc = cs.justifyContent;
    if (cs.alignItems && cs.alignItems !== 'normal') s.ai = cs.alignItems;
    const gap = cs.columnGap !== 'normal' ? cs.columnGap : (cs.gap !== 'normal' ? cs.gap : '');
    if (gap) s.gap = gap;
    // Background.
    if (!transparent(cs.backgroundColor)) s.bg = cs.backgroundColor;
    if (cs.backgroundImage && cs.backgroundImage !== 'none') {
      s.bgImg = cs.backgroundImage;
      s.bgSize = cs.backgroundSize;
      s.bgPos = cs.backgroundPosition;
      s.bgRepeat = cs.backgroundRepeat;
    }
    // Border (top edge as representative + radius).
    if (num(cs.borderTopWidth) > 0 && cs.borderTopStyle !== 'none') {
      s.bdw = num(cs.borderTopWidth);
      s.bds = cs.borderTopStyle;
      s.bdc = cs.borderTopColor;
    }
    const radius = num(cs.borderTopLeftRadius);
    if (radius > 0) s.br = radius;
    // Effects.
    if (cs.boxShadow && cs.boxShadow !== 'none') s.sh = cs.boxShadow;
    if (cs.opacity && parseFloat(cs.opacity) < 1) s.op = parseFloat(cs.opacity);
    return s;
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
    const node = {
      tag,
      uid,
      id: el.id || '',
      cls: typeof el.className === 'string' ? el.className.trim() : '',
      text: directText(el),
      s: styleSet(cs),
    };

    if (tag === 'img') {
      node.src = el.currentSrc || el.getAttribute('src') || '';
      node.alt = el.getAttribute('alt') || '';
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
      if ((layered || slider) && !node.html) {
        node.html = el.outerHTML.slice(0, MAX_HTML);
      }
    } else {
      node.atomic = true;
      node.html = el.outerHTML.slice(0, MAX_HTML);
    }

    return node;
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
      tree,
    });
  });

  return sections;
}

module.exports = { browserPageSegmenter };
