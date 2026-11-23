/* "Copy for email" dialog (wp-admin): a ready-made picture and link for newsletters. */
( function () {
	'use strict';

	var cfg = window.svcEmailShare;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key.slice( 0, 2 ) === 'on' ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else if ( key === 'checked' ) {
				node.checked = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function escapeHtml( text ) {
		return String( text ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	/**
	 * What gets pasted into the newsletter: the picture, linked, with the
	 * title linked underneath for readers whose mail app hides pictures.
	 */
	function snippet( data, link ) {
		var href = escapeHtml( link );
		var title = escapeHtml( data.title );
		return '<p><a href="' + href + '"><img src="' + escapeHtml( data.image ) + '" width="' + data.width + '" height="' + data.height +
			'" alt="' + title + '" style="display:block;width:100%;max-width:' + data.width + 'px;height:auto;border:0;"></a></p>' +
			'<p><a href="' + href + '">' + title + '</a></p>';
	}

	function flash( button, text ) {
		var original = button.textContent;
		button.textContent = text;
		setTimeout( function () {
			button.textContent = original;
		}, 1500 );
	}

	/**
	 * Copy as rich content (so a newsletter editor receives a real picture
	 * and link) with the plain address as the text-only fallback.
	 */
	function copyRich( html, plain ) {
		if ( navigator.clipboard && window.ClipboardItem ) {
			return navigator.clipboard.write( [ new window.ClipboardItem( {
				'text/html': new Blob( [ html ], { type: 'text/html' } ),
				'text/plain': new Blob( [ plain ], { type: 'text/plain' } ),
			} ) ] );
		}

		// Older browsers and plain-http sites: copy a rendered selection.
		return new Promise( function ( resolve, reject ) {
			var holder = el( 'div', { contenteditable: 'true', style: 'position:fixed;left:-9999px;top:0;' } );
			holder.innerHTML = html;
			( document.querySelector( 'dialog[open]' ) || document.body ).appendChild( holder );
			var range = document.createRange();
			range.selectNodeContents( holder );
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			selection.removeAllRanges();
			holder.remove();
			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'copy' ) );
			}
		} );
	}

	function copyPlain( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		return copyRich( escapeHtml( text ), text );
	}

	function request( postId ) {
		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'post', postId );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : t.failed );
				}
				return json.data;
			} );
	}

	function open( postId ) {
		var body = el( 'div', { class: 'svc-es-body' }, [ el( 'p', { text: t.working } ) ] );
		var dialog = el( 'dialog', { class: 'svc-es-dialog' }, [
			el( 'div', { class: 'svc-es-head' }, [
				el( 'h2', { text: t.title } ),
				el( 'button', { type: 'button', class: 'button-link svc-es-close', 'aria-label': t.close, text: '×', onclick: function () {
					dialog.close();
				} } ),
			] ),
			body,
		] );
		dialog.addEventListener( 'close', function () {
			dialog.remove();
		} );
		document.body.appendChild( dialog );
		dialog.showModal();

		request( postId ).then( function ( data ) {
			var autoplay = el( 'input', { type: 'checkbox', checked: true } );
			var address = el( 'code', { class: 'svc-es-link' } );
			var notice = el( 'p', { class: 'svc-es-notice', role: 'status' } );

			function link() {
				return autoplay.checked ? data.playLink : data.link;
			}
			function showLink() {
				address.textContent = link();
			}
			autoplay.addEventListener( 'change', showLink );
			showLink();

			body.textContent = '';
			[
				el( 'p', { text: t.intro } ),
				el( 'div', { class: 'svc-es-preview' }, [
					el( 'img', { src: data.image, width: data.width, height: data.height, alt: data.title } ),
				] ),
				el( 'label', { class: 'svc-es-option' }, [ autoplay, ' ' + t.autoplay ] ),
				el( 'p', { class: 'svc-es-address' }, [ el( 'strong', { text: t.linkLabel + ': ' } ), address ] ),
				el( 'div', { class: 'svc-es-actions' }, [
					el( 'button', { type: 'button', class: 'button button-primary', text: t.copyBoth, onclick: function ( event ) {
						var button = event.currentTarget;
						notice.textContent = '';
						copyRich( snippet( data, link() ), link() ).then( function () {
							flash( button, t.copied );
						} ).catch( function () {
							notice.textContent = t.copyFailed;
						} );
					} } ),
					el( 'a', { class: 'button', href: data.image, download: data.filename, text: t.download } ),
					el( 'button', { type: 'button', class: 'button', text: t.copyLink, onclick: function ( event ) {
						var button = event.currentTarget;
						copyPlain( link() ).then( function () {
							flash( button, t.copied );
						} ).catch( function () {
							window.prompt( t.linkLabel, link() );
						} );
					} } ),
				] ),
				notice,
				el( 'p', { class: 'description', text: t.howTo } ),
			].forEach( function ( node ) {
				body.appendChild( node );
			} );
		} ).catch( function ( error ) {
			body.textContent = '';
			body.appendChild( el( 'p', { class: 'svc-es-notice', text: error.message || t.failed } ) );
		} );
	}

	cfg.open = open;

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest && event.target.closest( '.svc-email-share' );
		if ( button ) {
			event.preventDefault();
			open( button.getAttribute( 'data-post' ) );
		}
	} );
}() );
