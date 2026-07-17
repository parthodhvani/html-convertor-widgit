'use strict';

/**
 * Chromium rendering + extraction engine.
 *
 * Loads a source HTML page in headless Chromium, waits for fonts / images /
 * network idle, then extracts:
 *   - the segmented top-level sections, each with a recursive DOM `tree`
 *     annotated with the full computed style set (typography, spacing,
 *     background, border, shadow, sizing, flex/grid layout)
 *   - per-node responsive values (tablet / mobile) for responsive fidelity
 *   - inlined CSS and JS assets
 *   - full-page screenshots per breakpoint
 *
 * The Chromium-rendered output is treated as the source of truth.
 */

const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
const puppeteer = require('puppeteer');

const { browserPageSegmenter } = require('./segmenter');

const DEFAULT_CONFIG = {
  breakpoints: {
    wide: 1920,
    desktop: 1440,
    laptop: 1280,
    tablet_landscape: 1024,
    tablet: 768,
    mobile_landscape: 480,
    mobile: 375,
  },
  waitUntil: 'networkidle0',
  timeout: 60000,
  captureScreenshots: true,
  conversionMode: 'native',
  widgetConfidence: 95,
  debug: false,
};

/**
 * Render and extract a layout document from an HTML entry file.
 *
 * @param {string} inputPath  Absolute path to the entry .html file.
 * @param {string} outDir     Directory for screenshots and artifacts.
 * @param {object} userConfig Partial configuration overrides.
 * @returns {Promise<object>} The layout document.
 */
async function renderToLayout(inputPath, outDir, userConfig = {}) {
  const config = { ...DEFAULT_CONFIG, ...userConfig };
  // Caller-provided breakpoints replace defaults entirely (do not merge),
  // so desktop-only / fast configs stay fast.
  if (userConfig.breakpoints && typeof userConfig.breakpoints === 'object') {
    config.breakpoints = { ...userConfig.breakpoints };
  } else {
    config.breakpoints = { ...DEFAULT_CONFIG.breakpoints };
  }

  if (!fs.existsSync(inputPath)) {
    throw new Error(`Input file not found: ${inputPath}`);
  }
  fs.mkdirSync(outDir, { recursive: true });

  if (config.debug) {
    // eslint-disable-next-line no-console
    console.log('Launching Chrome from:', '/usr/bin/google-chrome');
    // eslint-disable-next-line no-console
    console.log('Puppeteer version:', require('puppeteer/package.json').version);
  }

  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: 'new',
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--font-render-hinting=none',
    ],
  });

  try {
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(config.timeout);
    page.setDefaultTimeout(config.timeout);

    const fileUrl = pathToFileURL(inputPath).href;

    // Desktop pass — full extraction + segmentation.
    await page.setViewport({ width: config.breakpoints.desktop, height: 900, deviceScaleFactor: 1 });
    await page.goto(fileUrl, { waitUntil: config.waitUntil, timeout: config.timeout });
    await waitForStable(page, config);

    // Expand collapsed FAQ/accordion panels so answer content is extractable.
    await page.evaluate(() => {
      document.querySelectorAll('details:not([open])').forEach((el) => {
        el.open = true;
      });
      document.querySelectorAll('.faq-item, .accordion-item, [data-accordion-item]').forEach((item) => {
        item.classList.add('open', 'active', 'show');
        item.querySelectorAll('.faq-a, .accordion-collapse, .accordion-body, .accordion-content').forEach((panel) => {
          panel.style.maxHeight = 'none';
          panel.style.height = 'auto';
          panel.style.overflow = 'visible';
          panel.style.display = 'block';
          panel.style.opacity = '1';
          panel.classList.add('show', 'open', 'in');
        });
      });
    });

    const meta = await page.evaluate(() => {
      const body = getComputedStyle(document.body);
      const html = getComputedStyle(document.documentElement);
      return {
        title: document.title || '',
        url: location.href,
        width: document.documentElement.scrollWidth,
        height: document.documentElement.scrollHeight,
        page: {
          backgroundColor: body.backgroundColor || html.backgroundColor || '',
          color: body.color || '',
          fontFamily: body.fontFamily || '',
          fontSize: body.fontSize || '',
        },
      };
    });

    const assets = await page.evaluate(extractAssetsInPage);
    let sections = await page.evaluate(browserPageSegmenter);

    const sectionResponsive = { desktop: indexBySection(sections) };
    const uidMaps = {};
    const screenshots = {};

    // Primary desktop screenshot.
    const desktopWidth = config.breakpoints.desktop || config.breakpoints.wide || 1440;
    await page.setViewport({ width: desktopWidth, height: 900, deviceScaleFactor: 1 });

    if (config.captureScreenshots) {
      screenshots.desktop = await screenshot(page, outDir, 'desktop');
    }

    // Responsive passes — re-measure tagged sections and nodes at each breakpoint.
    const responsiveDevices = Object.keys(config.breakpoints).filter((k) => k !== 'desktop');
    for (const device of responsiveDevices) {
      const width = config.breakpoints[device];
      if (!width) continue;
      await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
      await sleep(350);
      await waitForStable(page, config);
      if ('tablet' === device || 'mobile' === device) {
        sectionResponsive[device] = await page.evaluate(measureTaggedSections);
      }
      uidMaps[device] = await page.evaluate(measureUids);
      if (config.captureScreenshots) {
        screenshots[device] = await screenshot(page, outDir, device);
      }
    }

    // Backwards-compatible tablet/mobile section maps.
    if (!sectionResponsive.tablet && uidMaps.tablet_landscape) {
      sectionResponsive.tablet = sectionResponsive.tablet_landscape || {};
    }
    if (!sectionResponsive.mobile && uidMaps.mobile) {
      sectionResponsive.mobile = sectionResponsive.mobile || {};
    }

    // Merge responsive measurements back into sections and their trees.
    sections = sections.map((section) => {
      attachNodeResponsive(section.tree, uidMaps);
      stripTreeMarkers(section.tree);
      return {
        ...section,
        responsive: {
          desktop: sectionResponsive.desktop[section.index] || null,
          tablet: (sectionResponsive.tablet || {})[section.index] || null,
          mobile: (sectionResponsive.mobile || {})[section.index] || null,
        },
        html: (section.html || '').replace(/\sdata-h2e-(section|uid)="\d+"/g, ''),
      };
    });

    return {
      meta: { ...meta, generatedAt: new Date().toISOString() },
      breakpoints: config.breakpoints,
      screenshots,
      assets,
      sections,
    };
  } finally {
    await browser.close();
  }
}

/**
 * Wait for fonts and images to settle in addition to the navigation wait.
 *
 * @param {import('puppeteer').Page} page   Page.
 * @param {object}                   config Config.
 */
async function waitForStable(page, config) {
  try {
    await page.evaluate(async () => {
      if (document.fonts && document.fonts.ready) {
        await document.fonts.ready;
      }
      const imgs = Array.from(document.images || []);
      await Promise.all(
        imgs.map((img) =>
          img.complete
            ? Promise.resolve()
            : new Promise((res) => {
              img.addEventListener('load', res, { once: true });
              img.addEventListener('error', res, { once: true });
            })
        )
      );
    });
  } catch (e) {
    if (config.debug) {
      // eslint-disable-next-line no-console
      console.error('waitForStable warning:', e.message);
    }
  }
}

/**
 * Capture a full-page screenshot.
 *
 * @param {import('puppeteer').Page} page   Page.
 * @param {string}                   outDir Output dir.
 * @param {string}                   device Device label.
 * @returns {Promise<string>} Absolute path to the PNG.
 */
async function screenshot(page, outDir, device) {
  const file = path.join(outDir, `shot-${device}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

/**
 * Build an index map { sectionIndex: measurement } from segmented sections.
 *
 * @param {Array<object>} sections Sections.
 * @returns {Object<number,object>}
 */
function indexBySection(sections) {
  const out = {};
  for (const s of sections) {
    out[s.index] = { bbox: s.bbox, styles: s.styles };
  }
  return out;
}

/**
 * Attach tablet/mobile measurements to each tree node by its uid.
 *
 * @param {object|null}            node    Tree node.
 * @param {Object<string,object>}  uidMaps Per-device uid measurement maps.
 */
function attachNodeResponsive(node, uidMaps) {
  if (!node) return;
  if (node.uid !== undefined) {
    const r = {};
    // Map all captured breakpoints; keep tablet/mobile aliases for PHP CssMapper.
    const alias = { tablet_landscape: 'tablet', mobile: 'mobile', laptop: 'laptop' };
    Object.keys(uidMaps).forEach((device) => {
      if (uidMaps[device] && uidMaps[device][node.uid]) {
        r[device] = uidMaps[device][node.uid];
        if (alias[device]) r[alias[device]] = uidMaps[device][node.uid];
      }
    });
    if (Object.keys(r).length) node.r = r;
  }
  (node.children || []).forEach((c) => attachNodeResponsive(c, uidMaps));
}

/**
 * Remove internal marker attributes from captured HTML.
 * Keeps `uid` on the IR so responsive maps and repair can re-join nodes.
 *
 * @param {object|null} node Tree node.
 */
function stripTreeMarkers(node) {
  if (!node) return;
  if (typeof node.html === 'string') {
    node.html = node.html.replace(/\sdata-h2e-(section|uid)="\d+"/g, '');
  }
  (node.children || []).forEach(stripTreeMarkers);
}

/* ----------------------------------------------------------------------------
 * In-page (browser context) helpers.
 * ------------------------------------------------------------------------- */

/**
 * Collect inlined CSS/JS assets from the rendered document.
 *
 * @returns {object}
 */
function extractAssetsInPage() {
  const stylesheets = [];
  let combinedCss = '';

  for (const sheet of Array.from(document.styleSheets)) {
    try {
      const rules = sheet.cssRules;
      if (rules) {
        for (const rule of Array.from(rules)) {
          combinedCss += rule.cssText + '\n';
        }
      } else if (sheet.href) {
        stylesheets.push(sheet.href);
      }
    } catch (e) {
      if (sheet.href) stylesheets.push(sheet.href);
    }
  }

  let combinedJs = '';
  const scripts = [];
  for (const script of Array.from(document.querySelectorAll('script'))) {
    if (script.src) {
      scripts.push(script.src);
    } else if (script.textContent && script.textContent.trim()) {
      combinedJs += script.textContent + '\n';
    }
  }

  return { stylesheets, combinedCss, scripts, combinedJs };
}

/**
 * Re-measure previously tagged sections at the current viewport.
 *
 * @returns {Object<number,object>}
 */
function measureTaggedSections() {
  const props = [
    'display', 'backgroundColor', 'color', 'paddingTop', 'paddingBottom',
    'paddingLeft', 'paddingRight', 'marginTop', 'marginBottom', 'marginLeft', 'marginRight',
    'flexDirection', 'justifyContent', 'alignItems', 'textAlign', 'fontSize',
    'gap', 'columnGap', 'rowGap', 'gridTemplateColumns', 'overflow',
  ];
  const out = {};
  document.querySelectorAll('[data-h2e-section]').forEach((el) => {
    const idx = parseInt(el.getAttribute('data-h2e-section'), 10);
    const rect = el.getBoundingClientRect();
    const cs = window.getComputedStyle(el);
    const styles = {};
    props.forEach((p) => {
      styles[p] = cs[p];
    });
    out[idx] = {
      bbox: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
      styles,
    };
  });
  return out;
}

/**
 * Re-measure every uid-tagged node at the current viewport (responsive pass).
 *
 * @returns {Object<string,object>}
 */
function measureUids() {
  const out = {};
  document.querySelectorAll('[data-h2e-uid]').forEach((el) => {
    const cs = window.getComputedStyle(el);
    const r = el.getBoundingClientRect();
    const entry = {
      fs: cs.fontSize,
      mt: cs.marginTop, mr: cs.marginRight, mb: cs.marginBottom, ml: cs.marginLeft,
      pt: cs.paddingTop, pr: cs.paddingRight, pb: cs.paddingBottom, pl: cs.paddingLeft,
      ta: cs.textAlign,
      disp: cs.display,
      fd: cs.flexDirection,
      jc: cs.justifyContent,
      ai: cs.alignItems,
      gap: cs.columnGap !== 'normal' ? cs.columnGap : cs.gap,
      fw_wrap: cs.flexWrap,
      pos: cs.position,
      w: Math.round(r.width * 100) / 100,
      h: Math.round(r.height * 100) / 100,
    };
    if (cs.display.indexOf('grid') !== -1) {
      entry.gtc = cs.gridTemplateColumns;
      entry.gtr = cs.gridTemplateRows;
    }
    out[el.getAttribute('data-h2e-uid')] = entry;
  });
  return out;
}

/**
 * Sleep helper (Node context).
 *
 * @param {number} ms Milliseconds.
 * @returns {Promise<void>}
 */
function sleep(ms) {
  return new Promise((res) => setTimeout(res, ms));
}

module.exports = { renderToLayout, DEFAULT_CONFIG };
