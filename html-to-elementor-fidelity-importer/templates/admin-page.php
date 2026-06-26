<?php
/**
 * Admin screen markup.
 *
 * @package HtmlToElementor
 * @var array<string,mixed> $settings Current settings (provided by AdminPage::render).
 */

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap h2e-wrap">
	<h1><?php esc_html_e('HTML To Elementor Fidelity Importer', 'html-to-elementor-fidelity-importer'); ?></h1>
	<p class="h2e-tagline">
		<?php esc_html_e('Render any HTML/CSS/JS page in headless Chromium, segment it into sections and import it into Elementor with maximum visual fidelity.', 'html-to-elementor-fidelity-importer'); ?>
	</p>

	<div class="h2e-grid">
		<div class="h2e-card">
			<h2><?php esc_html_e('Convert & Import', 'html-to-elementor-fidelity-importer'); ?></h2>
			<form id="h2e-form">
				<p>
					<label
						for="h2e-title"><strong><?php esc_html_e('Page title', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
					<input type="text" id="h2e-title" name="title" class="regular-text"
						placeholder="<?php esc_attr_e('Imported Page', 'html-to-elementor-fidelity-importer'); ?>" />
				</p>

				<p>
					<label
						for="h2e-file"><strong><?php esc_html_e('HTML file or ZIP / website export', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
					<input type="file" id="h2e-file" name="file" accept=".html,.htm,.zip" />
				</p>

				<p>
					<label
						for="h2e-html"><strong><?php esc_html_e('…or paste raw HTML', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
					<textarea id="h2e-html" name="html" rows="6" class="large-text code"
						placeholder="<!DOCTYPE html> ..."></textarea>
				</p>

				<p>
					<label
						for="h2e-mode"><strong><?php esc_html_e('Conversion mode', 'html-to-elementor-fidelity-importer'); ?></strong></label><br />
					<select id="h2e-mode" name="mode">
						<option value="preserve" <?php selected($settings['conversion_mode'], 'preserve'); ?>>
							<?php esc_html_e('Preserve HTML (max fidelity)', 'html-to-elementor-fidelity-importer'); ?>
						</option>
						<option value="widgets" <?php selected($settings['conversion_mode'], 'widgets'); ?>>
							<?php esc_html_e('Convert obvious widgets (>95% confidence)', 'html-to-elementor-fidelity-importer'); ?>
						</option>
					</select>
				</p>

				<p>
					<label><input type="checkbox" id="h2e-import" name="import" checked />
						<?php esc_html_e('Import into a new Elementor page', 'html-to-elementor-fidelity-importer'); ?></label><br />
					<label><input type="checkbox" id="h2e-debug" name="debug" />
						<?php esc_html_e('Debug mode', 'html-to-elementor-fidelity-importer'); ?></label>
				</p>

				<p>
					<button type="submit" class="button button-primary button-hero" id="h2e-submit">
						<?php esc_html_e('Render & Convert', 'html-to-elementor-fidelity-importer'); ?>
					</button>
				</p>
			</form>
			<div id="h2e-status" class="h2e-status" hidden></div>
		</div>

		<div class="h2e-card">
			<h2><?php esc_html_e('Conversion Report', 'html-to-elementor-fidelity-importer'); ?></h2>
			<div id="h2e-report" class="h2e-report">
				<p class="description">
					<?php esc_html_e('Run a conversion to see the fidelity report here.', 'html-to-elementor-fidelity-importer'); ?>
				</p>
			</div>
		</div>
	</div>

	<div class="h2e-card">
		<h2><?php esc_html_e('Chromium Service', 'html-to-elementor-fidelity-importer'); ?></h2>
		<p class="description">
			<?php esc_html_e('Rendering is performed by the bundled Node.js / Puppeteer service. Mode:', 'html-to-elementor-fidelity-importer'); ?>
			<code><?php echo esc_html((string) $settings['render_mode']); ?></code>
			<?php if ('cli' === $settings['render_mode']): ?>
				— <?php esc_html_e('spawning', 'html-to-elementor-fidelity-importer'); ?>
				<code><?php echo esc_html((string) $settings['node_binary']); ?> chromium-service/cli.js</code>
			<?php else: ?>
				— <code><?php echo esc_html((string) $settings['service_url']); ?></code>
			<?php endif; ?>
		</p>
	</div>
</div>