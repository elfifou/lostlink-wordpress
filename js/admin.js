/**
 * Lostlink admin page.
 *
 * Opens the fix and edit pages in a dialog, and makes "Use" fill the target
 * field. Progressive enhancement: without this script, the links open the
 * same forms as plain pages.
 */
( function () {
	'use strict';

	const strings = window.lostlink || { close: 'Close', loading: 'Loading…' };
	let dialog = null;
	// Number of the last panel requested: a slower earlier response, or one
	// arriving after the dialog was closed, is dropped.
	let request = 0;

	/**
	 * Returns the dialog, created on first use.
	 */
	function getDialog() {
		if ( dialog ) {
			return dialog;
		}

		dialog = document.createElement( 'dialog' );
		dialog.className = 'lostlink-dialog';

		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'lostlink-dialog-close';
		close.setAttribute( 'aria-label', strings.close );
		close.textContent = '×';
		close.addEventListener( 'click', () => dialog.close() );
		dialog.addEventListener( 'close', () => {
			request++;
		} );

		const content = document.createElement( 'div' );
		content.className = 'lostlink-dialog-content';

		dialog.append( close, content );
		// A click on the backdrop (the dialog itself, outside its content) closes it.
		dialog.addEventListener( 'click', ( event ) => {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
		document.querySelector( '.lostlink' ).append( dialog );
		return dialog;
	}

	/**
	 * Opens the page behind a fix or edit link in the dialog.
	 *
	 * @param {string} url The page URL.
	 */
	async function openPanel( url ) {
		const modal = getDialog();
		const content = modal.querySelector( '.lostlink-dialog-content' );
		const loading = document.createElement( 'p' );
		loading.textContent = strings.loading;
		content.replaceChildren( loading );
		modal.removeAttribute( 'aria-labelledby' );
		if ( ! modal.open ) {
			modal.showModal();
		}
		const current = ++request;

		let panel = null;
		try {
			const response = await fetch( url, { credentials: 'same-origin' } );
			if ( response.ok ) {
				const page = new DOMParser().parseFromString( await response.text(), 'text/html' );
				panel = page.querySelector( '.lostlink-panel' );
			}
		} catch ( error ) {
			panel = null;
		}
		if ( current !== request ) {
			return;
		}

		// An error page (URL already fixed, redirect deleted...): show it in full.
		if ( ! panel ) {
			window.location.href = url;
			return;
		}

		panel = document.importNode( panel, true );
		const title = panel.querySelector( '.lostlink-panel-title' );
		if ( title ) {
			title.id = 'lostlink-dialog-title';
			modal.setAttribute( 'aria-labelledby', title.id );
		}
		content.replaceChildren( panel );

		const target = panel.querySelector( '#lostlink-target' );
		if ( target ) {
			target.focus();
		}
	}

	document.addEventListener( 'click', ( event ) => {
		// "Use" fills the target field instead of reloading the page.
		const use = event.target.closest( '.lostlink-use' );
		if ( use ) {
			const panel = use.closest( '.lostlink-panel' );
			const target = panel && panel.querySelector( '#lostlink-target' );
			if ( target ) {
				event.preventDefault();
				target.value = use.dataset.target;
				target.focus();
			}
			return;
		}

		const link = event.target.closest( 'a.lostlink-modal' );
		// Keep the browser behavior for a new tab or window.
		if ( ! link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || typeof HTMLDialogElement === 'undefined' ) {
			return;
		}
		event.preventDefault();
		openPanel( link.href );
	} );
}() );
