/**
 * Webpack entry point for the admin UI.
 *
 * The canonical, zero-build runtime implementation lives in
 * ../js/admin.js (shipped with the plugin). This entry simply wraps it so the
 * optional webpack build can produce a bundled/minified artifact in
 * assets/dist for teams that extend the admin experience.
 */
import '../js/admin.js';
