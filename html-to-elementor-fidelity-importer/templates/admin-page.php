<?php
/**
 * Admin screen markup — Convert / Advanced settings / Report.
 *
 * @package HtmlToElementor
 * @var array<string,mixed> $settings Current settings (provided by AdminPage::render).
 */

if (!defined('ABSPATH')) {
	exit;
}

$bp = is_array($settings['breakpoints'] ?? null) ? $settings['breakpoints'] : array();
$bp_keys = array('wide', 'desktop', 'laptop', 'tablet_landscape', 'tablet', 'mobile_landscape', 'mobile');
$render_mode = (string) ($settings['render_mode'] ?? 'cli');
$wait_until = (string) ($settings['wait_until'] ?? 'networkidle0');
?>
<div class="wrap h2e-wrap">
	<h1><?php esc_html_e('HTML To Elementor Fidelity Importer', 'html-to-elementor-fidelity-importer'); ?></h1>
	<p class="h2e-tagline">
		<?php esc_html_e('Render any HTML/CSS/JS page in headless Chromium and import it into Elementor as native widgets.', 'html-to-elementor-fidelity-importer'); ?>
	</p>

	<div class="h2e-stack">
		<!-- A. Convert card -->
		<div class="h2e-card h2e-card--primary">
			<h2><?php esc_html_e('Convert', 'html-to-elementor-fidelity-importer'); ?></h2>
			<form id="h2e-form">
				<p>
					<label for="h2e-title"><strong><?php esc_html_e('Page title', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
					<input type="text" id="h2e-title" name="title" class="regular-text"
						placeholder="<?php esc_attr_e('Imported Page', 'html-to-elementor-fidelity-importer'); ?>" />
				</p>

				<p>
					<label for="h2e-file"><strong><?php esc_html_e('HTML file or ZIP / website export', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
					<input type="file" id="h2e-file" name="file" accept=".html,.htm,.zip" />
					<span id="h2e-file-hint" class="description h2e-source-hint" hidden></span>
				</p>

				<p>
					<label for="h2e-html"><strong><?php esc_html_e('…or paste raw HTML', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
					<textarea id="h2e-html" name="html" rows="6" class="large-text code"
						placeholder="<!DOCTYPE html> ..."></textarea>
					<span id="h2e-html-hint" class="description h2e-source-hint" hidden></span>
				</p>

				<p>
					<label><input type="checkbox" id="h2e-import" name="import" checked />
						<?php esc_html_e('Import into Elementor', 'html-to-elementor-fidelity-importer'); ?></label>
				</p>

				<p>
					<button type="submit" class="button button-primary button-hero" id="h2e-submit">
						<?php esc_html_e('Render & Convert', 'html-to-elementor-fidelity-importer'); ?>
					</button>
				</p>
			</form>
			<div id="h2e-status" class="h2e-status" hidden></div>
		</div>

		<!-- B. Advanced settings (collapsed) -->
		<div class="h2e-card h2e-card--advanced">
			<button type="button" class="h2e-toggle-advanced button-link" id="h2e-toggle-advanced" aria-expanded="false">
				<?php esc_html_e('Advanced settings', 'html-to-elementor-fidelity-importer'); ?> ▾
			</button>
			<div id="h2e-advanced-panel" class="h2e-advanced-panel" hidden>
				<form id="h2e-advanced-form">
					<p>
						<label for="h2e-widget-confidence"><strong><?php esc_html_e('Minimum widget confidence (%)', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<input type="number" id="h2e-widget-confidence" name="widget_confidence" min="50" max="100" step="1"
							value="<?php echo esc_attr((string) (int) ($settings['widget_confidence'] ?? 95)); ?>" />
						<span class="description"><?php esc_html_e('Nodes scoring below this are converted to a plain container instead of a specific widget.', 'html-to-elementor-fidelity-importer'); ?></span>
					</p>

					<p>
						<label for="h2e-fidelity-threshold"><strong><?php esc_html_e('Required fidelity score (%)', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<input type="number" id="h2e-fidelity-threshold" name="fidelity_threshold" min="50" max="100"
							value="<?php echo esc_attr((string) (int) ($settings['fidelity_threshold'] ?? 95)); ?>" />
						<span class="description"><?php esc_html_e('Conversion is flagged as needing review below this score.', 'html-to-elementor-fidelity-importer'); ?></span>
					</p>

					<p>
						<label for="h2e-validation-max"><strong><?php esc_html_e('Max re-validation passes', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<input type="number" id="h2e-validation-max" name="validation_max_iterations" min="1" max="10"
							value="<?php echo esc_attr((string) (int) ($settings['validation_max_iterations'] ?? 3)); ?>" />
					</p>

					<fieldset class="h2e-fieldset">
						<legend><?php esc_html_e('Chromium service mode', 'html-to-elementor-fidelity-importer'); ?></legend>
						<label><input type="radio" name="render_mode" value="cli" id="h2e-render-cli" <?php checked($render_mode, 'cli'); ?> /> cli</label>
						<label><input type="radio" name="render_mode" value="http" id="h2e-render-http" <?php checked($render_mode, 'http'); ?> /> http</label>
					</fieldset>

					<p class="h2e-dep-cli">
						<label for="h2e-node-binary"><strong><?php esc_html_e('Node binary path', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<input type="text" id="h2e-node-binary" name="node_binary" class="regular-text"
							value="<?php echo esc_attr((string) ($settings['node_binary'] ?? 'node')); ?>" />
					</p>

					<p class="h2e-dep-http" <?php echo 'http' === $render_mode ? '' : 'hidden'; ?>>
						<label for="h2e-service-url"><strong><?php esc_html_e('Chromium service URL', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<input type="url" id="h2e-service-url" name="service_url" class="regular-text"
							value="<?php echo esc_attr((string) ($settings['service_url'] ?? '')); ?>" />
					</p>

					<p class="h2e-dep-http" <?php echo 'http' === $render_mode ? '' : 'hidden'; ?>>
						<label for="h2e-service-token"><strong><?php esc_html_e('Chromium service token', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<input type="password" id="h2e-service-token" name="service_token" class="regular-text" autocomplete="off"
							value="<?php echo esc_attr((string) ($settings['service_token'] ?? '')); ?>" />
					</p>

					<p>
						<label><input type="checkbox" id="h2e-node-strip-env" name="node_strip_env" <?php checked(!empty($settings['node_strip_env'])); ?> />
							<?php esc_html_e('Strip inherited LD_LIBRARY_PATH', 'html-to-elementor-fidelity-importer'); ?></label><br />
						<span class="description"><?php esc_html_e("Fixes 'libstdc++ version not found' errors when PHP runs under XAMPP/MAMP.", 'html-to-elementor-fidelity-importer'); ?></span>
					</p>

					<p id="h2e-ld-path-wrap" <?php echo empty($settings['node_strip_env']) ? '' : 'hidden'; ?>>
						<label for="h2e-node-ld"><strong><?php esc_html_e('Explicit LD_LIBRARY_PATH', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<input type="text" id="h2e-node-ld" name="node_ld_library_path" class="regular-text"
							value="<?php echo esc_attr((string) ($settings['node_ld_library_path'] ?? '')); ?>" />
					</p>

					<p>
						<label for="h2e-wait-until"><strong><?php esc_html_e('Wait for page to be considered loaded', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<select id="h2e-wait-until" name="wait_until">
							<?php foreach (array('load', 'domcontentloaded', 'networkidle0', 'networkidle2') as $opt) : ?>
								<option value="<?php echo esc_attr($opt); ?>" <?php selected($wait_until, $opt); ?>><?php echo esc_html($opt); ?></option>
							<?php endforeach; ?>
						</select>
					</p>

					<p>
						<label for="h2e-render-timeout"><strong><?php esc_html_e('Render timeout (ms)', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
						<input type="number" id="h2e-render-timeout" name="render_timeout_ms" min="5000" step="1000"
							value="<?php echo esc_attr((string) (int) ($settings['render_timeout_ms'] ?? 60000)); ?>" />
					</p>

					<p>
						<label><input type="checkbox" id="h2e-capture-screenshots" name="capture_screenshots" <?php checked(!empty($settings['capture_screenshots'])); ?> />
							<?php esc_html_e('Capture before/after screenshots', 'html-to-elementor-fidelity-importer'); ?></label>
					</p>
					<p>
						<label><input type="checkbox" id="h2e-import-media" name="import_media" <?php checked(!empty($settings['import_media'])); ?> />
							<?php esc_html_e('Download images into Media Library', 'html-to-elementor-fidelity-importer'); ?></label>
					</p>
					<p>
						<label><input type="checkbox" id="h2e-inject-assets" name="inject_source_assets" <?php checked(!empty($settings['inject_source_assets'])); ?> />
							<?php esc_html_e('Re-apply original CSS as a fidelity safety net', 'html-to-elementor-fidelity-importer'); ?></label>
					</p>
					<p class="h2e-warn">
						<label><input type="checkbox" id="h2e-inject-js" name="inject_source_js" <?php checked(!empty($settings['inject_source_js'])); ?> />
							⚠ <?php esc_html_e('Re-run original inline scripts (opt-in, off by default)', 'html-to-elementor-fidelity-importer'); ?></label>
					</p>
					<p>
						<label><input type="checkbox" id="h2e-global-colors" name="apply_global_colors" <?php checked(!empty($settings['apply_global_colors'])); ?> />
							<?php esc_html_e('Register extracted brand colors as Elementor globals', 'html-to-elementor-fidelity-importer'); ?></label>
					</p>
					<p>
						<label><input type="checkbox" id="h2e-debug" name="debug" <?php checked(!empty($settings['debug'])); ?> />
							<?php esc_html_e('Debug mode (verbose per-request log)', 'html-to-elementor-fidelity-importer'); ?></label>
					</p>

					<h3 class="h2e-subheading"><?php esc_html_e('Responsive breakpoints (px)', 'html-to-elementor-fidelity-importer'); ?></h3>
					<div class="h2e-bp-grid">
						<?php foreach ($bp_keys as $key) : ?>
							<label>
								<span><?php echo esc_html($key); ?></span>
								<input type="number" class="h2e-bp" data-bp="<?php echo esc_attr($key); ?>"
									value="<?php echo esc_attr((string) (int) ($bp[$key] ?? 0)); ?>" min="0" step="1" />
							</label>
						<?php endforeach; ?>
					</div>

					<p class="h2e-advanced-actions">
						<button type="button" class="button" id="h2e-save-defaults">
							<?php esc_html_e('Save as defaults', 'html-to-elementor-fidelity-importer'); ?>
						</button>
						<span id="h2e-save-status" class="description" aria-live="polite"></span>
					</p>
				</form>
			</div>
		</div>

		<!-- C. Report card -->
		<div class="h2e-card h2e-card--report">
			<h2><?php esc_html_e('Report', 'html-to-elementor-fidelity-importer'); ?></h2>
			<div id="h2e-report" class="h2e-report">
				<p class="description">
					<?php esc_html_e('Run a conversion to see the fidelity report here.', 'html-to-elementor-fidelity-importer'); ?>
				</p>
			</div>
			<p class="h2e-actions">
				<button type="button" class="button" id="h2e-view-last-log">
					<?php esc_html_e('View last conversion log', 'html-to-elementor-fidelity-importer'); ?>
				</button>
			</p>
			<div id="h2e-last-log" class="h2e-last-log" hidden></div>
		</div>
	</div>
</div>
