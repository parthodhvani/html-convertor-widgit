/* global H2E_DATA */
( function () {
	'use strict';

	const form = document.getElementById( 'h2e-form' );
	const statusEl = document.getElementById( 'h2e-status' );
	const reportEl = document.getElementById( 'h2e-report' );

	if ( ! form ) {
		return;
	}

	function setStatus( message, state ) {
		statusEl.hidden = false;
		statusEl.textContent = message;
		statusEl.className = 'h2e-status is-' + state;
	}

	function metric( value, label ) {
		return (
			'<div class="h2e-metric"><strong>' +
			value +
			'</strong><span>' +
			label +
			'</span></div>'
		);
	}

	function renderReport( report, postId, editUrl ) {
		const scores = report.scores || {};
		let html = '';
		html += metric( ( scores.widget_fidelity != null ? scores.widget_fidelity : report.fidelity_score ) + '%', 'Native widgets' );
		html += metric( ( scores.html_widget_percentage != null ? scores.html_widget_percentage : 0 ) + '%', 'HTML widgets' );
		html += metric( report.sections, 'Sections' );
		html += metric( report.containers, 'Containers' );
		html += metric( report.native_widgets != null ? report.native_widgets : report.widgets, 'Native' );
		html += metric( report.html_widgets != null ? report.html_widgets : report.html_blocks, 'HTML' );

		if ( report.widget_breakdown && Object.keys( report.widget_breakdown ).length ) {
			html += '<p><strong>Widget breakdown:</strong> ';
			html += Object.entries( report.widget_breakdown )
				.map( function ( e ) {
					return e[ 0 ] + ' × ' + e[ 1 ];
				} )
				.join( ', ' );
			html += '</p>';
		}

		if ( report.components && Object.keys( report.components ).length ) {
			html += '<p><strong>Components detected:</strong> ';
			html += Object.entries( report.components )
				.map( function ( e ) {
					return e[ 0 ] + ' × ' + e[ 1 ];
				} )
				.join( ', ' );
			html += '</p>';
		}

		if ( postId && editUrl ) {
			html +=
				'<p class="h2e-actions"><a class="button button-primary" href="' +
				editUrl +
				'">Edit in Elementor (#' +
				postId +
				')</a></p>';
		}

		reportEl.innerHTML = html;
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		const fd = new FormData();
		const fileInput = document.getElementById( 'h2e-file' );
		const html = document.getElementById( 'h2e-html' ).value;
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
		fd.append( 'mode', document.getElementById( 'h2e-mode' ).value );
		fd.append( 'import', document.getElementById( 'h2e-import' ).checked ? '1' : '0' );
		fd.append( 'debug', document.getElementById( 'h2e-debug' ).checked ? '1' : '0' );

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
				renderReport(
					res.report,
					res.post_id,
					res.report && res.report.edit_url
				);
			} )
			.catch( function ( err ) {
				setStatus(
					H2E_DATA.i18n.failed + ' ' + ( err.message || '' ),
					'error'
				);
			} )
			.finally( function () {
				document.getElementById( 'h2e-submit' ).disabled = false;
			} );
	} );
} )();
