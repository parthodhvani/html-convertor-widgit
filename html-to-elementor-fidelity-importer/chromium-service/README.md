# Chromium Service

Headless Chromium rendering + extraction engine for the HTML To Elementor Fidelity Importer.

Built on [Puppeteer](https://pptr.dev/). Loads a source HTML page, executes its CSS/JS, waits
for fonts / images / network idle, then extracts the segmented sections (outerHTML + computed
styles + box model), per-breakpoint measurements and full-page screenshots. The Chromium output
is the source of truth.

## Install

```bash
npm install      # downloads a matching Chromium build
```

## CLI

```bash
node cli.js --input page.html --out layout.json [--config config.json]
```

`config.json` (all optional):

```json
{
  "breakpoints": { "desktop": 1280, "tablet": 768, "mobile": 375 },
  "waitUntil": "networkidle0",
  "timeout": 60000,
  "captureScreenshots": true,
  "debug": false
}
```

## HTTP service

```bash
H2E_PORT=8745 H2E_SERVICE_TOKEN=secret node server.js
```

* `GET /health` → `{ ok: true }`
* `POST /convert` → body `{ inputPath, outDir, config }` → layout document

## Output (layout document)

```json
{
  "meta": { "title": "...", "url": "...", "width": 1280, "height": 2400 },
  "breakpoints": { "desktop": 1280, "tablet": 768, "mobile": 375 },
  "screenshots": { "desktop": "/abs/shot-desktop.png", "tablet": "...", "mobile": "..." },
  "assets": { "stylesheets": [], "combinedCss": "...", "scripts": [], "combinedJs": "..." },
  "sections": [
    {
      "index": 0,
      "tag": "header",
      "id": "",
      "classes": "hero",
      "semantic": true,
      "html": "<header class=\"hero\">…</header>",
      "bbox": { "x": 0, "y": 0, "width": 1280, "height": 400 },
      "styles": { "backgroundColor": "rgb(13, 71, 161)", "paddingTop": "80px", … },
      "responsive": { "desktop": {…}, "tablet": {…}, "mobile": {…} }
    }
  ]
}
```
