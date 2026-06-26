# HTML To Elementor Fidelity Importer

Import arbitrary HTML/CSS/JS pages into [Elementor](https://elementor.com/) while maximizing
**Chromium-rendered visual fidelity**. The plugin renders the source page in headless Chromium
(via a bundled Node.js / Puppeteer service), extracts the computed layout, segments it into
visual sections and generates valid Elementor container data.

> Primary objective: **Visual fidelity + native, editable Elementor structure.**

## Visual reconstruction engine

By default (`conversion_mode = native`) the importer rebuilds each page as **nested
Elementor containers and native widgets**, not HTML blocks:

* **Full computed-CSS extraction** (Chromium): typography, spacing, background, border,
  shadow, sizing, flex/grid layout and position — per node, with tablet/mobile responsive
  values. See `chromium-service/lib/segmenter.js`.
* **Component recognition** (`includes/Elementor/WidgetClassifier.php`): headings → Heading,
  `<p>` → Text Editor, `<img>` → Image, buttons/`.btn`/`a` → Button, `<ul>`/`<ol>` → Icon List,
  `<hr>` → Divider, font icons → Icon, inline SVG → Image (data URI), YouTube/Vimeo → Video,
  Google Maps → Map. Detects header/nav/hero/cta/card/feature/testimonial/faq/pricing roles.
* **CSS → Elementor controls** (`includes/Elementor/CssMapper.php`): computed styles become
  native typography / spacing / background / border / shadow / flex controls (responsive),
  instead of inline HTML.
* **Layout reconstruction** (`includes/Elementor/LayoutTreeConverter.php`): flex/grid rows
  become row containers with measured percentage-width columns; nested structure is preserved.
* **Design tokens** (`includes/Elementor/DesignTokens.php`): a brand palette + heading/body
  fonts are extracted and registered as Elementor global colours.
* **Media handling**: images and background images are downloaded into the Media Library and
  URLs are rewritten to attachments (`includes/Elementor/ImportEngine.php`).
* **Fidelity safety net**: the original stylesheet is re-applied on imported pages via
  `includes/Frontend/Frontend.php` (no HTML widget), and widgets keep their original CSS
  classes/ids in the Advanced tab.
* **HTML widgets are a last resort** — only for layered/absolute designs, third-party sliders,
  forms, canvas and tables. Typical pages convert to **>95% native widgets**.
* **Visual validation**: `chromium-service/compare.js` scores similarity between the original
  and generated screenshots.

Set `conversion_mode = preserve` to fall back to the original "one HTML widget per section"
behaviour.

## Pipeline

```
HTML Upload
   ↓
Chromium Render Engine            (chromium-service/lib/extractor.js)
   ↓
DOM + Computed Style + Bounding Box Extraction
   ↓
Visual Layout Tree
   ↓
Section Segmentation Engine       (chromium-service/lib/segmenter.js)
   ↓
Elementor Container Generator     (includes/Elementor/ContainerFactory.php)
   ↓
Optional Widget Detection         (includes/Elementor/WidgetDetector.php)
   ↓
Elementor JSON Generator          (includes/Elementor/ElementorJsonGenerator.php)
   ↓
Import Into Elementor             (includes/Elementor/ImportEngine.php)
```

## Directory tree

```
html-to-elementor-fidelity-importer/
├── html-to-elementor-fidelity-importer.php   # Main plugin bootstrap
├── uninstall.php
├── composer.json                             # PSR-4 autoload (HtmlToElementor\)
├── package.json                              # Build tooling (webpack, zip)
├── webpack.config.js
├── readme.txt                                # WordPress.org readme
├── bin/
│   └── build-zip.sh                          # Builds an installable ZIP
├── includes/
│   ├── Plugin.php  Activator.php  Deactivator.php
│   ├── Admin/AdminPage.php                   # Admin panel
│   ├── Rest/RestController.php               # REST API  (/wp-json/h2e/v1)
│   ├── Cli/ConvertCommand.php                # WP-CLI  (wp h2e import|batch)
│   ├── Services/
│   │   ├── UploadHandler.php  PackageExtractor.php
│   │   ├── ChromiumService.php  RenderResult.php
│   │   └── ConversionPipeline.php
│   ├── Elementor/
│   │   ├── SectionSegmenter handled in Node; PHP side:
│   │   ├── ElementorJsonGenerator.php  ContainerFactory.php
│   │   ├── WidgetFactory.php  WidgetDetector.php
│   │   ├── ElementId.php  ImportEngine.php
│   ├── Export/ExportEngine.php               # Export engine
│   ├── Batch/BatchConverter.php              # Batch conversion
│   ├── Report/ConversionReport.php           # Conversion report
│   └── Support/Settings.php  Logger.php  Autoloader.php
├── chromium-service/                         # Node.js / Puppeteer service
│   ├── package.json
│   ├── cli.js                                # Standalone CLI
│   ├── server.js                             # Long-running HTTP service
│   └── lib/extractor.js  segmenter.js
├── assets/ (css/ js/ src/)                   # Admin UI assets
├── templates/admin-page.php                  # Admin panel template
└── tests/ (php/ fixtures/)                   # Tests + sample pages
```

## Installation

1. Install and activate **Elementor**.
2. Place this folder in `wp-content/plugins/` and activate it.
3. Install dependencies:
   ```bash
   composer install
   cd chromium-service && npm install   # downloads a Chromium build via Puppeteer
   ```
4. Make sure `node` (18+) is on the server `PATH`, or set the binary/path under
   **HTML → Elementor** settings.
5. Open **HTML → Elementor** in wp-admin and import a page.

## Build

```bash
npm install            # root dev tooling (webpack)
npm run build          # installs chromium-service deps + builds optional assets bundle
npm run zip            # produces dist/html-to-elementor-fidelity-importer.zip
```

The plugin runs **without** any build step — `assets/js` and `assets/css` ship ready to use.

## Rendering transports

* **CLI (default):** PHP spawns `node chromium-service/cli.js`. Zero configuration.
* **HTTP:** run `node chromium-service/server.js` (env `H2E_PORT`, `H2E_SERVICE_TOKEN`) and set
  render mode to `http` in settings.

## CLI usage (Node)

```bash
node chromium-service/cli.js --input page.html --out layout.json --config config.json
```

## WP-CLI

```bash
wp h2e import /path/to/page.html --title="Landing" --mode=preserve
wp h2e batch  /path/to/site-export --mode=preserve
```

## Troubleshooting

### `node: ... libstdc++.so.6: version 'GLIBCXX_3.4.21' not found` (XAMPP / LAMPP / MAMP)

When WordPress runs under XAMPP/LAMPP/MAMP, PHP inherits an `LD_LIBRARY_PATH`
pointing at the stack's bundled libraries, which are usually older than the system
Node binary requires. PHP then passes that path to the spawned `node`, which fails
to load with errors like `CXXABI_1.3.11`/`GLIBCXX_3.4.21 not found`.

The plugin fixes this automatically by stripping `LD_LIBRARY_PATH` / `LD_PRELOAD`
(and the macOS `DYLD_*` equivalents) from the Node child process so it uses the
system libraries. This is controlled by two settings (defaults shown):

* `node_strip_env` = `true` — strip dynamic-loader variables when spawning Node.
* `node_ld_library_path` = `''` — set this to force a specific `LD_LIBRARY_PATH`
  for Node instead of stripping it.

If you still see the error, ensure a working `node` (18+) is on the server `PATH`
and set the absolute Node binary path in **HTML → Elementor** settings.
