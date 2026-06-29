# html-convertor-widgit

This repo contains one product under `html-to-elementor-fidelity-importer/`: a WordPress
plugin ("HTML To Elementor Fidelity Importer") plus a bundled Node/Puppeteer
`chromium-service/` that renders source HTML in headless Chromium and extracts a layout
document. The PHP side converts that layout into native Elementor JSON.

## Cursor Cloud specific instructions

### Services / components
- **PHP plugin** (`html-to-elementor-fidelity-importer/`, PHP 8.2+ via Composer): unit
  tests, the standalone `tests/harness.php`, and the Elementor JSON generator. PHP tests
  use WordPress function shims (`tests/php/bootstrap.php`), so **no WordPress, Elementor, or
  MySQL is needed** for tests or the harness.
- **chromium-service** (`chromium-service/`, Node 18+): headless rendering + extraction. The
  PHP plugin spawns `node chromium-service/cli.js` by default; the HTTP `server.js` transport
  is optional.

### Standard commands (already documented; don't duplicate logic)
- Lint: there is **no lint step** configured in this repo.
- PHP tests: `composer test` (alias for `phpunit`), run from the plugin dir. (39 tests pass.)
- Render HTML -> layout: `node chromium-service/cli.js --input <page.html> --out <layout.json>`
- Layout -> Elementor JSON (no WP needed): `php tests/harness.php <layout.json> [preserve|widgets]`
- Optional asset bundle: `npm run build:assets` (outputs to gitignored `assets/dist/`).
- See `README.md` and `chromium-service/README.md` for the full pipeline and HTTP transport.

### Non-obvious gotchas
- **Puppeteer cache path is hardcoded.** `chromium-service/.puppeteerrc.cjs` pins the browser
  cache to `/home/parth/.cache/puppeteer` (an absolute path, not `$HOME`). That directory is
  created during environment setup and is recreated by the update script; if a future
  `npm install` re-downloads Chromium it must be able to write there. Do not assume the cache
  lives under `$HOME`.
- **Run the Chromium CLI from inside `chromium-service/`.** Puppeteer reads `.puppeteerrc.cjs`
  (which pins the browser cache path) from the *current working directory*, so
  `node cli.js --input ../tests/fixtures/<page>.html --out /tmp/layout.json` works, but invoking
  `node chromium-service/cli.js` from the plugin root fails with "Could not find Chrome".
- **Closed `<details>` answers are not extracted.** The extractor only captures visible nodes, so
  collapsed disclosure/accordion bodies (`display:none`) are dropped; use `<details open>` fixtures
  when verifying FAQ/accordion reconstruction end to end.
- A real, in-CMS end-to-end import (admin UI / REST `/wp-json/h2e/v1` / `wp h2e ...`) requires a
  full WordPress + Elementor + MySQL stack, which is **not** provisioned in this repo. The CLI +
  harness flow above is the supported way to exercise the conversion engine without WordPress.
- This is a headless/CLI product; there is no standalone web GUI to run here (the only GUI is the
  WordPress admin page). Demonstrate the engine via the CLI render + harness, not a dev server.
