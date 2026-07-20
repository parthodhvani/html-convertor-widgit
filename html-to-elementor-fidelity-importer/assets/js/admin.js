/* global H2E_DATA */
( function () {
	'use strict';

	const form = document.getElementById( 'h2e-form' );
	const statusEl = document.getElementById( 'h2e-status' );
	const reportEl = document.getElementById( 'h2e-report' );
	const fileInput = document.getElementById( 'h2e-file' );
	const htmlInput = document.getElementById( 'h2e-html' );
	const fileHint = document.getElementById( 'h2e-file-hint' );
	const htmlHint = document.getElementById( 'h2e-html-hint' );

	if ( ! form ) {
		return;
	}

	function setStatus( message, state ) {
		statusEl.hidden = false;
		statusEl.textContent = message;
		statusEl.className = 'h2e-status is-' + state;
	}

	function metric( value, label ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'h2e-metric';
		const strong = document.createElement( 'strong' );
		strong.textContent = String( value );
		const span = document.createElement( 'span' );
		span.textContent = label;
		wrap.appendChild( strong );
		wrap.appendChild( span );
		return wrap;
	}

	function appendTextParagraph( parent, label, text ) {
		const p = document.createElement( 'p' );
		const strong = document.createElement( 'strong' );
		strong.textContent = label;
		p.appendChild( strong );
		p.appendChild( document.createTextNode( ' ' + text ) );
		parent.appendChild( p );
	}

	function appendDebugLog( parent, debugLog ) {
		if ( ! debugLog ) {
			return;
		}
		const details = document.createElement( 'details' );
		details.className = 'h2e-debug-log';
		const summary = document.createElement( 'summary' );
		summary.textContent = 'Debug log';
		details.appendChild( summary );
		const pre = document.createElement( 'pre' );
		const text =
			Array.isArray( debugLog ) ? debugLog.join( '\n' ) : String( debugLog );
		pre.textContent = text;
		details.appendChild( pre );
		parent.appendChild( details );
	}

	function appendStages( parent, stages ) {
		if ( ! stages || ! stages.length ) {
			return;
		}
		const details = document.createElement( 'details' );
		details.className = 'h2e-stages';
		const summary = document.createElement( 'summary' );
		summary.textContent = 'Pipeline stages';
		details.appendChild( summary );
		const ul = document.createElement( 'ul' );
		stages.forEach( function ( s ) {
			const li = document.createElement( 'li' );
			li.textContent =
				( s.label || s.id ) +
				' — ' +
				( s.status || '?' ) +
				' (' +
				( s.duration_ms != null ? s.duration_ms + 'ms' : '?' ) +
				')' +
				( s.error ? ' — ' + s.error : '' );
			ul.appendChild( li );
		} );
		details.appendChild( ul );
		parent.appendChild( details );
	}

	function renderReport( report, postId, editUrl, errorPayload ) {
		reportEl.textContent = '';

		if ( errorPayload ) {
			const banner = document.createElement( 'div' );
			banner.className = 'h2e-report-banner is-error';
			banner.textContent =
				errorPayload.message ||
				'Conversion failed.';
			reportEl.appendChild( banner );
			appendStages( reportEl, errorPayload.stages );
			appendDebugLog( reportEl, errorPayload.debug_log );
			return;
		}

		const banner = document.createElement( 'div' );
		banner.className = 'h2e-report-banner is-success';
		banner.textContent = 'Conversion succeeded.';
		reportEl.appendChild( banner );

		const scores = ( report && report.scores ) || {};
		const metrics = document.createElement( 'div' );
		metrics.className = 'h2e-metrics';
		metrics.appendChild(
			metric(
				( scores.widget_fidelity != null
					? scores.widget_fidelity
					: report.fidelity_score ) + '%',
				'Native widgets'
			)
		);
		metrics.appendChild(
			metric(
				( scores.html_widget_percentage != null
					? scores.html_widget_percentage
					: 0 ) + '%',
				'HTML widgets'
			)
		);
		metrics.appendChild( metric( report.sections || 0, 'Sections' ) );
		metrics.appendChild( metric( report.containers || 0, 'Containers' ) );
		metrics.appendChild(
			metric(
				report.native_widgets != null ? report.native_widgets : report.widgets,
				'Native'
			)
		);
		metrics.appendChild(
			metric(
				report.html_widgets != null ? report.html_widgets : report.html_blocks,
				'HTML'
			)
		);
		reportEl.appendChild( metrics );

		appendPackageReport( reportEl, report.package );
		appendMediaReport( reportEl, report.media, report.package );

		if ( report.widget_breakdown && Object.keys( report.widget_breakdown ).length ) {
			appendTextParagraph(
				reportEl,
				'Widget breakdown:',
				Object.entries( report.widget_breakdown )
					.map( function ( e ) {
						return e[ 0 ] + ' × ' + e[ 1 ];
					} )
					.join( ', ' )
			);
		}

		if ( report.components && Object.keys( report.components ).length ) {
			appendTextParagraph(
				reportEl,
				'Components detected:',
				Object.entries( report.components )
					.map( function ( e ) {
						return e[ 0 ] + ' × ' + e[ 1 ];
					} )
					.join( ', ' )
			);
		}

		appendStages( reportEl, report.stages );
		appendDebugLog( reportEl, report.debug_log );

		if ( postId && editUrl ) {
			const p = document.createElement( 'p' );
			p.className = 'h2e-actions';
			const a = document.createElement( 'a' );
			a.className = 'button button-primary';
			a.href = editUrl;
			a.textContent = 'Edit in Elementor (#' + postId + ')';
			p.appendChild( a );
			reportEl.appendChild( p );
		}
	}

	function appendPackageReport( parent, pkg ) {
		if ( ! pkg || typeof pkg !== 'object' ) {
			return;
		}
		const box = document.createElement( 'div' );
		const warn = pkg.warning || ( ! pkg.has_local_assets && pkg.source !== 'zip' );
		box.className = 'h2e-package-report' + ( warn ? ' is-warn' : '' );
		const parts = [];
		parts.push( 'Source: ' + ( pkg.source || 'upload' ) );
		if ( pkg.entry_name ) {
			parts.push( 'entry ' + pkg.entry_name );
		}
		parts.push( ( pkg.images || 0 ) + ' image(s)' );
		parts.push( ( pkg.stylesheets || 0 ) + ' CSS' );
		parts.push( ( pkg.scripts || 0 ) + ' JS' );
		box.textContent = parts.join( ' · ' );
		if ( pkg.warning ) {
			const w = document.createElement( 'p' );
			w.style.margin = '6px 0 0';
			w.textContent = pkg.warning;
			box.appendChild( w );
		}
		parent.appendChild( box );
	}

	function appendMediaReport( parent, media, pkg ) {
		if ( ! media || typeof media !== 'object' ) {
			return;
		}
		const box = document.createElement( 'div' );
		const failed = ( media.failed || 0 ) > 0;
		box.className = 'h2e-media-report' + ( failed ? ' is-warn' : '' );
		box.textContent =
			'Media library: ' +
			( media.imported || 0 ) +
			' imported / ' +
			( media.attempted || 0 ) +
			' attempted' +
			( media.failed ? ' · ' + media.failed + ' failed' : '' ) +
			( media.skipped ? ' · ' + media.skipped + ' skipped (data URIs)' : '' );
		if ( failed && pkg && ! pkg.has_local_assets ) {
			const tip = document.createElement( 'p' );
			tip.style.margin = '6px 0 0';
			tip.textContent =
				'Tip: re-upload as a ZIP that includes the assets/ folder so images can be sideloaded.';
			box.appendChild( tip );
		}
		parent.appendChild( box );
	}

	function syncSourceExclusive() {
		const hasFile = fileInput.files && fileInput.files.length > 0;
		const hasHtml = htmlInput.value.trim().length > 0;
		const fileNameEl = document.getElementById( 'h2e-file-name' );
		const packagePreview = document.getElementById( 'h2e-package-preview' );

		if ( hasFile && hasHtml ) {
			htmlInput.value = '';
			htmlInput.disabled = true;
			htmlHint.hidden = false;
			htmlHint.textContent = 'Cleared because a file is selected.';
			fileHint.hidden = true;
			fileHint.textContent = '';
		} else if ( hasFile ) {
			htmlInput.disabled = true;
			htmlHint.hidden = false;
			htmlHint.textContent =
				'Paste disabled while a file is selected. Clear the file to paste HTML.';
			fileHint.hidden = true;
		} else {
			htmlInput.disabled = false;
			htmlHint.hidden = true;
			htmlHint.textContent = '';
			if ( hasHtml ) {
				fileHint.hidden = false;
				fileHint.textContent =
					'Paste mode: local images will not import. Prefer a ZIP with assets/.';
			} else {
				fileHint.hidden = true;
				fileHint.textContent = '';
			}
		}

		if ( fileNameEl ) {
			if ( hasFile ) {
				const f = fileInput.files[ 0 ];
				fileNameEl.hidden = false;
				fileNameEl.textContent = f.name + ' (' + Math.round( f.size / 1024 ) + ' KB)';
			} else {
				fileNameEl.hidden = true;
				fileNameEl.textContent = '';
			}
		}

		if ( packagePreview ) {
			if ( hasFile ) {
				const name = ( fileInput.files[ 0 ].name || '' ).toLowerCase();
				const isZip = name.endsWith( '.zip' );
				packagePreview.hidden = false;
				if ( isZip ) {
					packagePreview.className = 'h2e-package-preview is-ok';
					packagePreview.textContent =
						'ZIP selected — images/CSS/JS inside the archive will be used for conversion.';
				} else {
					packagePreview.className = 'h2e-package-preview is-warn';
					packagePreview.textContent =
						'HTML-only file — relative assets/img paths will not show. Upload a ZIP for images.';
				}
			} else if ( hasHtml ) {
				packagePreview.hidden = false;
				packagePreview.className = 'h2e-package-preview is-warn';
				packagePreview.textContent =
					'Paste mode has no asset files. Use a ZIP package to import images.';
			} else {
				packagePreview.hidden = true;
				packagePreview.textContent = '';
			}
		}
	}

	function wireDropzone() {
		const zone = document.getElementById( 'h2e-dropzone' );
		if ( ! zone || ! fileInput ) {
			return;
		}
		[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
			zone.addEventListener( ev, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				zone.classList.add( 'is-dragover' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( ev ) {
			zone.addEventListener( ev, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				zone.classList.remove( 'is-dragover' );
			} );
		} );
		zone.addEventListener( 'drop', function ( e ) {
			const files = e.dataTransfer && e.dataTransfer.files;
			if ( files && files.length ) {
				fileInput.files = files;
				syncSourceExclusive();
			}
		} );
	}

	function syncImportMediaCheckboxes( fromMain ) {
		const main = document.getElementById( 'h2e-import-media-main' );
		const adv = document.getElementById( 'h2e-import-media' );
		if ( ! main || ! adv ) {
			return;
		}
		if ( fromMain ) {
			adv.checked = main.checked;
		} else {
			main.checked = adv.checked;
		}
	}

	fileInput.addEventListener( 'change', syncSourceExclusive );
	htmlInput.addEventListener( 'input', syncSourceExclusive );
	wireDropzone();
	syncSourceExclusive();

	const mediaMain = document.getElementById( 'h2e-import-media-main' );
	const mediaAdv = document.getElementById( 'h2e-import-media' );
	if ( mediaMain && mediaAdv ) {
		mediaMain.checked = mediaAdv.checked;
		mediaMain.addEventListener( 'change', function () {
			syncImportMediaCheckboxes( true );
		} );
		mediaAdv.addEventListener( 'change', function () {
			syncImportMediaCheckboxes( false );
		} );
	}

	// Advanced panel toggle (client-side only).
	const toggleBtn = document.getElementById( 'h2e-toggle-advanced' );
	const advancedPanel = document.getElementById( 'h2e-advanced-panel' );
	if ( toggleBtn && advancedPanel ) {
		toggleBtn.addEventListener( 'click', function () {
			const open = advancedPanel.hasAttribute( 'hidden' );
			if ( open ) {
				advancedPanel.removeAttribute( 'hidden' );
				toggleBtn.setAttribute( 'aria-expanded', 'true' );
				toggleBtn.textContent = 'Advanced settings ▴';
			} else {
				advancedPanel.setAttribute( 'hidden', '' );
				toggleBtn.setAttribute( 'aria-expanded', 'false' );
				toggleBtn.textContent = 'Advanced settings ▾';
			}
		} );
	}

	function syncRenderModeDeps() {
		const mode =
			( document.querySelector( 'input[name="render_mode"]:checked' ) || {} )
				.value || 'cli';
		document.querySelectorAll( '.h2e-dep-cli' ).forEach( function ( el ) {
			el.hidden = mode !== 'cli';
		} );
		document.querySelectorAll( '.h2e-dep-http' ).forEach( function ( el ) {
			el.hidden = mode !== 'http';
		} );
	}

	document.querySelectorAll( 'input[name="render_mode"]' ).forEach( function ( el ) {
		el.addEventListener( 'change', syncRenderModeDeps );
	} );
	syncRenderModeDeps();

	const stripEnv = document.getElementById( 'h2e-node-strip-env' );
	const ldWrap = document.getElementById( 'h2e-ld-path-wrap' );
	if ( stripEnv && ldWrap ) {
		stripEnv.addEventListener( 'change', function () {
			ldWrap.hidden = stripEnv.checked;
		} );
	}

	function collectAdvancedFields( fd ) {
		const num = function ( id ) {
			const el = document.getElementById( id );
			return el ? el.value : '';
		};
		const chk = function ( id ) {
			const el = document.getElementById( id );
			return el && el.checked ? '1' : '0';
		};

		fd.append( 'widget_confidence', num( 'h2e-widget-confidence' ) );
		fd.append( 'confidence', num( 'h2e-widget-confidence' ) );
		fd.append( 'fidelity_threshold', num( 'h2e-fidelity-threshold' ) );
		fd.append( 'validation_max_iterations', num( 'h2e-validation-max' ) );

		const modeEl = document.querySelector( 'input[name="render_mode"]:checked' );
		fd.append( 'render_mode', modeEl ? modeEl.value : 'cli' );
		fd.append( 'node_binary', num( 'h2e-node-binary' ) );
		fd.append( 'service_url', num( 'h2e-service-url' ) );
		fd.append( 'service_token', num( 'h2e-service-token' ) );
		fd.append( 'node_strip_env', chk( 'h2e-node-strip-env' ) );
		fd.append( 'node_ld_library_path', num( 'h2e-node-ld' ) );
		fd.append( 'wait_until', num( 'h2e-wait-until' ) );
		fd.append( 'render_timeout_ms', num( 'h2e-render-timeout' ) );
		fd.append( 'capture_screenshots', chk( 'h2e-capture-screenshots' ) );
		// Prefer Convert-card media toggle; fall back to Advanced.
		const mediaMainEl = document.getElementById( 'h2e-import-media-main' );
		const mediaAdvEl = document.getElementById( 'h2e-import-media' );
		const mediaOn = mediaMainEl
			? mediaMainEl.checked
			: !!( mediaAdvEl && mediaAdvEl.checked );
		fd.append( 'import_media', mediaOn ? '1' : '0' );
		fd.append( 'inject_source_assets', chk( 'h2e-inject-assets' ) );
		fd.append( 'inject_source_js', chk( 'h2e-inject-js' ) );
		fd.append( 'apply_global_colors', chk( 'h2e-global-colors' ) );
		fd.append( 'debug', chk( 'h2e-debug' ) );

		const breakpoints = {};
		document.querySelectorAll( '.h2e-bp' ).forEach( function ( el ) {
			breakpoints[ el.getAttribute( 'data-bp' ) ] = parseInt( el.value, 10 ) || 0;
		} );
		fd.append( 'breakpoints', JSON.stringify( breakpoints ) );
	}

	function collectSettingsPayload() {
		const payload = {};
		payload.widget_confidence = parseInt(
			document.getElementById( 'h2e-widget-confidence' ).value,
			10
		);
		payload.fidelity_threshold = parseInt(
			document.getElementById( 'h2e-fidelity-threshold' ).value,
			10
		);
		payload.validation_max_iterations = parseInt(
			document.getElementById( 'h2e-validation-max' ).value,
			10
		);
		const modeEl = document.querySelector( 'input[name="render_mode"]:checked' );
		payload.render_mode = modeEl ? modeEl.value : 'cli';
		payload.node_binary = document.getElementById( 'h2e-node-binary' ).value;
		payload.service_url = document.getElementById( 'h2e-service-url' ).value;
		payload.service_token = document.getElementById( 'h2e-service-token' ).value;
		payload.node_strip_env = document.getElementById( 'h2e-node-strip-env' ).checked;
		payload.node_ld_library_path = document.getElementById( 'h2e-node-ld' ).value;
		payload.wait_until = document.getElementById( 'h2e-wait-until' ).value;
		payload.render_timeout_ms = parseInt(
			document.getElementById( 'h2e-render-timeout' ).value,
			10
		);
		payload.capture_screenshots = document.getElementById(
			'h2e-capture-screenshots'
		).checked;
		const mediaMainEl = document.getElementById( 'h2e-import-media-main' );
		const mediaAdvEl = document.getElementById( 'h2e-import-media' );
		payload.import_media = mediaMainEl
			? mediaMainEl.checked
			: !!( mediaAdvEl && mediaAdvEl.checked );
		payload.inject_source_assets = document.getElementById(
			'h2e-inject-assets'
		).checked;
		payload.inject_source_js = document.getElementById( 'h2e-inject-js' ).checked;
		payload.apply_global_colors = document.getElementById(
			'h2e-global-colors'
		).checked;
		payload.debug = document.getElementById( 'h2e-debug' ).checked;
		payload.breakpoints = {};
		document.querySelectorAll( '.h2e-bp' ).forEach( function ( el ) {
			payload.breakpoints[ el.getAttribute( 'data-bp' ) ] =
				parseInt( el.value, 10 ) || 0;
		} );
		return payload;
	}

	const saveBtn = document.getElementById( 'h2e-save-defaults' );
	const saveStatus = document.getElementById( 'h2e-save-status' );
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', function () {
			saveStatus.textContent = 'Saving…';
			window.wp
				.apiFetch( {
					url: H2E_DATA.restUrl + '/settings',
					method: 'POST',
					headers: {
						'X-WP-Nonce': H2E_DATA.nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( collectSettingsPayload() ),
				} )
				.then( function () {
					saveStatus.textContent = 'Defaults saved.';
				} )
				.catch( function ( err ) {
					saveStatus.textContent =
						'Save failed: ' + ( err.message || 'unknown error' );
				} );
		} );
	}

	const lastLogBtn = document.getElementById( 'h2e-view-last-log' );
	const lastLogEl = document.getElementById( 'h2e-last-log' );
	if ( lastLogBtn && lastLogEl ) {
		lastLogBtn.addEventListener( 'click', function () {
			lastLogEl.hidden = false;
			lastLogEl.textContent = 'Loading…';
			window.wp
				.apiFetch( {
					url: H2E_DATA.restUrl + '/last-log',
					method: 'GET',
					headers: { 'X-WP-Nonce': H2E_DATA.nonce },
				} )
				.then( function ( log ) {
					lastLogEl.textContent = '';
					if ( ! log || ! log.stages || ! log.stages.length ) {
						lastLogEl.textContent = 'No conversion has been run yet.';
						return;
					}
					const head = document.createElement( 'p' );
					head.textContent =
						( log.ok ? 'PASS' : 'FAIL' ) +
						( log.failed_stage ? ' at ' + log.failed_stage : '' ) +
						( log.at ? ' — ' + log.at : '' );
					lastLogEl.appendChild( head );
					appendStages( lastLogEl, log.stages );
					if ( log.error ) {
						const errP = document.createElement( 'p' );
						errP.textContent = log.error;
						lastLogEl.appendChild( errP );
					}
				} )
				.catch( function ( err ) {
					lastLogEl.textContent =
						'Could not load last log: ' + ( err.message || '' );
				} );
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		syncSourceExclusive();

		const fd = new FormData();
		const html = htmlInput.value;
		const title = document.getElementById( 'h2e-title' ).value;

		if ( fileInput.files.length ) {
			fd.append( 'file', fileInput.files[ 0 ] );
		} else if ( html.trim() ) {
			fd.append( 'html', html );
		} else {
			setStatus( 'Please choose a file or paste some HTML.', 'error' );
			return;
		}

		fd.append( 'title', title || 'Imported Page' );
		// No 'mode' param — conversion is always widgets-only.
		fd.append(
			'import',
			document.getElementById( 'h2e-import' ).checked ? '1' : '0'
		);
		collectAdvancedFields( fd );

		setStatus( H2E_DATA.i18n.converting, 'loading' );
		document.getElementById( 'h2e-submit' ).disabled = true;

		window.wp
			.apiFetch( {
				url: H2E_DATA.restUrl + '/convert',
				method: 'POST',
				headers: { 'X-WP-Nonce': H2E_DATA.nonce },
				body: fd,
			} )
			.then( function ( res ) {
				setStatus( H2E_DATA.i18n.done, 'success' );
				if ( res.package && res.report && ! res.report.package ) {
					res.report.package = res.package;
				}
				renderReport(
					res.report,
					res.post_id,
					res.report && res.report.edit_url
				);
			} )
			.catch( function ( err ) {
				const data = ( err && err.data ) || {};
				const msg =
					err.message ||
					( data.stage_label
						? 'Failed at: ' + data.stage_label
						: H2E_DATA.i18n.failed );
				setStatus( msg, 'error' );
				renderReport( null, null, null, {
					message: msg,
					stages: data.stages,
					debug_log: data.debug_log,
				} );
			} )
			.finally( function () {
				document.getElementById( 'h2e-submit' ).disabled = false;
			} );
	} );
} )();
