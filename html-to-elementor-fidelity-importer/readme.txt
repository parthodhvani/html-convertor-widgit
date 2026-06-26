=== HTML To Elementor Fidelity Importer ===
Contributors: htmltoelementor
Tags: elementor, html, import, puppeteer, chromium, migration
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import arbitrary HTML/CSS/JS pages into Elementor while maximizing Chromium-rendered visual fidelity.

== Description ==

This plugin renders any HTML page in headless Chromium (via a bundled Node.js / Puppeteer
service), extracts the computed layout, segments it into visual sections and generates valid
Elementor container data.

Primary objective: **Rendered Browser Fidelity > Elementor Widget Purity**. The imported page
should look as close as possible to the Chromium-rendered source.

Pipeline:

HTML Upload -> Chromium Render -> DOM + Computed Style + Bounding Box Extraction ->
Visual Layout Tree -> Section Segmentation -> Elementor Container Generator ->
Optional Widget Detection -> Elementor JSON -> Import Into Elementor

= Features =

* Upload an HTML file, a ZIP package, or a full website export
* Paste raw HTML
* Headless Chromium rendering (CSS + JS executed; waits for fonts, images, network idle)
* Desktop / tablet / mobile capture
* Section segmentation (semantic tags + visual grouping + bounding boxes)
* HTML preservation mode (default, max fidelity)
* Optional widget conversion (only when confidence >= 95%)
* Batch conversion and re-import
* Conversion report with fidelity score
* Debug mode

== Installation ==

1. Install Elementor (free) and activate it.
2. Upload the plugin folder to `/wp-content/plugins/` and activate it.
3. Ensure Node.js 18+ is available on the server (the bundled Chromium service uses Puppeteer).
4. From the plugin folder run: `composer install` and `cd chromium-service && npm install`.
5. Go to **HTML → Elementor** in wp-admin and convert a page.

== Frequently Asked Questions ==

= Does it require Node.js? =
Yes. Rendering uses headless Chromium through Puppeteer. The PHP plugin spawns the bundled
`chromium-service/cli.js`, or you can run `chromium-service/server.js` and use HTTP mode.

= Will it modify my layout? =
No. By default each detected section becomes an Elementor container holding the original HTML.

= I get "libstdc++.so.6: version GLIBCXX_... not found" under XAMPP/LAMPP/MAMP =
PHP inherits an LD_LIBRARY_PATH pointing at the stack's bundled libraries, which are
too old for the system Node binary. The plugin strips LD_LIBRARY_PATH/LD_PRELOAD from
the spawned Node process automatically (setting "node_strip_env", on by default), so
Node uses the system libraries instead.

== Changelog ==

= 1.0.0 =
* Initial release.
