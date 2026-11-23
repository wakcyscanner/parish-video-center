/* Vimeo → video library migrator (wp-admin). */
( function () {
	'use strict';

	var cfg = window.svcMigrator;
	var root = document.getElementById( 'svc-mig-app' );
	if ( ! cfg || ! root ) {
		return;
	}

	var t = cfg.i18n;
	var items = [];
	var collections = {};
	var selected = {};
	var filter = '';
	var search = '';
	var busy = false;
	var pollTimer = null;

	function h( tag, attrs, children ) {
		var el = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( key === 'text' ) {
				el.textContent = value;
			} else if ( key.slice( 0, 2 ) === 'on' ) {
				el.addEventListener( key.slice( 2 ), value );
			} else if ( key === 'value' || key === 'checked' || key === 'disabled' || key === 'selected' ) {
				el[ key ] = value;
			} else {
				el.setAttribute( key, value === true ? '' : value );
			}
		} );
		[].concat( children || [] ).forEach( function ( child ) {
			if ( child !== null && child !== undefined && child !== false ) {
				el.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return el;
	}

	function fmt( template ) {
		var args = [].slice.call( arguments, 1 );
		var next = 0;
		return template.replace( /%(?:(\d+)\$)?[sd]/g, function ( match, index ) {
			return String( args[ index ? index - 1 : next++ ] );
		} );
	}

	function api( op, data ) {
		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'op', op );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( t.error );
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || t.error );
				}
				return json.data;
			} );
	}

	var STATES = {
		vimeo: t.stateVimeo,
		copying: t.stateCopying,
		ready: t.stateReady,
		switched: t.stateSwitched,
		failed: t.stateFailed,
	};

	function canCopy( item ) {
		return item.state === 'vimeo' || item.state === 'failed';
	}

	function copyingCount() {
		return items.filter( function ( item ) {
			return item.state === 'copying';
		} ).length;
	}

	function selectedIds() {
		return items.filter( function ( item ) {
			return selected[ item.id ] && canCopy( item );
		} ).map( function ( item ) {
			return item.id;
		} );
	}

	// ------------------------------------------------------------------
	// Layout
	// ------------------------------------------------------------------

	var notices = h( 'div', { class: 'svc-bl-notices' } );
	var controls = h( 'div', { class: 'svc-bl-upload svc-mig-controls' } );
	var toolbar = h( 'div', { class: 'svc-bl-toolbar' } );
	var tableWrap = h( 'div', { class: 'svc-bl-table' } );
	root.textContent = '';
	[ notices, controls, toolbar, tableWrap ].forEach( function ( el ) {
		root.appendChild( el );
	} );

	function notify( message, type ) {
		var box = h( 'div', { class: 'notice notice-' + ( type || 'success' ) }, [ h( 'p', { text: message } ) ] );
		notices.textContent = '';
		notices.appendChild( box );
	}

	var quality = h( 'select', {}, [
		h( 'option', { value: '1080', text: t.q1080 } ),
		h( 'option', { value: '720', text: t.q720 } ),
		h( 'option', { value: 'original', text: t.qOriginal } ),
	] );
	var collectionSlot = h( 'span' );
	var collection = null;
	var auto = h( 'input', { type: 'checkbox' } );
	var copyButton = h( 'button', { type: 'button', class: 'button button-primary', text: t.copy, onclick: confirmCopy } );
	var selectionLabel = h( 'span', { class: 'svc-bl-count' } );
	var progressLabel = h( 'span', { class: 'svc-bl-count' } );

	controls.appendChild( h( 'p', { class: 'svc-mig-row' }, [
		h( 'label', {}, [ t.quality + ' ', quality ] ),
		' ',
		h( 'label', {}, [ t.collection + ' ', collectionSlot ] ),
	] ) );
	controls.appendChild( h( 'p', { class: 'svc-mig-row' }, [ h( 'label', {}, [ auto, ' ' + t.auto ] ) ] ) );
	controls.appendChild( h( 'p', { class: 'svc-mig-row' }, [ copyButton, ' ', selectionLabel, ' ', progressLabel ] ) );

	var filterSelect = h( 'select', {
		'aria-label': t.colStatus,
		onchange: function () {
			filter = filterSelect.value;
			renderTable();
		},
	}, [ h( 'option', { value: '', text: t.filterAll } ) ].concat( Object.keys( STATES ).map( function ( key ) {
		return h( 'option', { value: key, text: STATES[ key ] } );
	} ) ) );
	var searchInput = h( 'input', {
		type: 'search',
		placeholder: t.search,
		'aria-label': t.search,
		oninput: function () {
			search = searchInput.value.trim().toLowerCase();
			renderTable();
		},
	} );
	toolbar.appendChild( filterSelect );
	toolbar.appendChild( searchInput );

	function renderCollections() {
		var current = collection ? collection.value : '';
		collection = h( 'select', {}, [ h( 'option', { value: '', text: t.noCollection } ) ] );
		Object.keys( collections ).forEach( function ( guid ) {
			collection.appendChild( h( 'option', { value: guid, text: collections[ guid ], selected: guid === current } ) );
		} );
		collectionSlot.textContent = '';
		collectionSlot.appendChild( collection );
	}

	function renderSummary() {
		var count = selectedIds().length;
		selectionLabel.textContent = fmt( t.selected, count );
		progressLabel.textContent = '· ' + fmt( t.inProgress, copyingCount(), cfg.limit );
		copyButton.disabled = busy || ! count;
	}

	function visibleItems() {
		return items.filter( function ( item ) {
			return ( ! filter || item.state === filter ) && ( ! search || item.title.toLowerCase().indexOf( search ) !== -1 );
		} );
	}

	function renderTable() {
		var shown = visibleItems();
		var copyable = shown.filter( canCopy );
		var allChecked = copyable.length > 0 && copyable.every( function ( item ) {
			return selected[ item.id ];
		} );

		var head = h( 'tr', {}, [
			h( 'td', { class: 'check-column' }, [ h( 'input', {
				type: 'checkbox',
				'aria-label': t.selectAll,
				checked: allChecked,
				disabled: ! copyable.length,
				onchange: function ( event ) {
					copyable.forEach( function ( item ) {
						selected[ item.id ] = event.currentTarget.checked;
					} );
					renderTable();
				},
			} ) ] ),
			h( 'th', { scope: 'col', text: t.colVideo } ),
			h( 'th', { scope: 'col', text: t.colTopic } ),
			h( 'th', { scope: 'col', text: t.colLength } ),
			h( 'th', { scope: 'col', text: t.colDate } ),
			h( 'th', { scope: 'col', text: t.colStatus } ),
		] );

		var body = h( 'tbody' );
		shown.forEach( function ( item ) {
			body.appendChild( row( item ) );
		} );
		if ( ! shown.length ) {
			body.appendChild( h( 'tr', {}, [ h( 'td', { colspan: 6, text: t.empty } ) ] ) );
		}

		tableWrap.textContent = '';
		tableWrap.appendChild( h( 'table', { class: 'wp-list-table widefat fixed striped' }, [ h( 'thead', {}, [ head ] ), body ] ) );
		renderSummary();
	}

	function row( item ) {
		var actions = [
			h( 'a', { href: item.edit, text: t.editPost } ),
			item.status === 'publish' ? ' | ' : ' (' + t.draft + ')',
			item.status === 'publish' ? h( 'a', { href: item.view, target: '_blank', rel: 'noopener', text: t.view } ) : null,
		];
		if ( item.state === 'ready' ) {
			actions.push( ' | ', h( 'button', { type: 'button', class: 'button-link', text: t.switchTo, onclick: function () {
				switchPlayback( item, 'bunny' );
			} } ) );
		}
		if ( item.state === 'switched' ) {
			actions.push( ' | ', h( 'button', { type: 'button', class: 'button-link', text: t.switchBack, onclick: function () {
				switchPlayback( item, 'vimeo' );
			} } ) );
		}

		var status = STATES[ item.state ] || item.state;
		var note = '';
		if ( item.state === 'copying' && item.auto ) {
			note = t.autoNote;
		} else if ( item.state === 'failed' ) {
			note = item.error;
		}

		return h( 'tr', {}, [
			h( 'th', { class: 'check-column', scope: 'row' }, [ canCopy( item ) ? h( 'input', {
				type: 'checkbox',
				'aria-label': item.title,
				checked: !! selected[ item.id ],
				onchange: function ( event ) {
					selected[ item.id ] = event.currentTarget.checked;
					renderTable();
				},
			} ) : null ] ),
			h( 'td', {}, [ h( 'strong', { text: item.title } ), h( 'div', { class: 'row-actions visible' }, actions ) ] ),
			h( 'td', { text: item.topics || '—' } ),
			h( 'td', { text: item.duration || '—' } ),
			h( 'td', { text: item.date } ),
			h( 'td', {}, [
				h( 'span', {
					class: 'svc-bl-status' + ( item.state === 'switched' ? ' is-ready' : item.state === 'failed' ? ' is-failed' : item.state === 'vimeo' ? '' : ' is-busy' ),
					text: status,
				} ),
				note ? h( 'p', { class: 'svc-bl-desc', text: note } ) : null,
			] ),
		] );
	}

	// ------------------------------------------------------------------
	// Actions
	// ------------------------------------------------------------------

	function load() {
		return api( 'list' )
			.then( function ( data ) {
				items = data.items;
				collections = data.collections || {};
				renderCollections();
				renderTable();

				// Keep checking while copies are running.
				clearTimeout( pollTimer );
				if ( copyingCount() ) {
					pollTimer = setTimeout( function () {
						if ( ! busy && ! document.querySelector( 'dialog.svc-bl-dialog[open]' ) ) {
							load();
						}
					}, 20000 );
				}
			} )
			.catch( function ( error ) {
				notify( error.message, 'error' );
			} );
	}

	function switchPlayback( item, to ) {
		api( 'switch', { ids: item.id, to: to } )
			.then( function ( data ) {
				notify( fmt( t.switched, data.switched ) );
				return load();
			} )
			.catch( function ( error ) {
				notify( error.message, 'error' );
			} );
	}

	function setBusy( state ) {
		busy = state;
		renderSummary();
	}

	// Step 1: look up sizes, so the transfer is known before anything starts.
	function confirmCopy() {
		var ids = selectedIds();
		var room = cfg.limit - copyingCount();
		if ( ids.length > room ) {
			notify( fmt( t.tooMany, Math.max( 0, room ) ), 'warning' );
			return;
		}

		setBusy( true );
		notify( t.checking, 'info' );

		api( 'estimate', { ids: ids.join( ',' ), quality: quality.value } )
			.then( function ( data ) {
				notices.textContent = '';
				showConfirm( data );
			} )
			.catch( function ( error ) {
				notify( error.message, 'error' );
			} )
			.then( function () {
				setBusy( false );
			} );
	}

	function showConfirm( data ) {
		var good = data.videos.filter( function ( video ) {
			return ! video.error;
		} );
		var list = h( 'ul', { class: 'svc-mig-list' } );
		data.videos.forEach( function ( video ) {
			var item = items.filter( function ( candidate ) {
				return candidate.id === video.id;
			} )[ 0 ];
			var detail = video.error ? video.error : [ video.height ? video.height + 'p' : '', video.size ].filter( Boolean ).join( ', ' );
			list.appendChild( h( 'li', {}, [
				h( 'strong', { text: item ? item.title : String( video.id ) } ),
				h( 'span', { class: video.error ? 'svc-bl-form-message is-error' : 'description', text: ' — ' + detail } ),
			] ) );
		} );

		var go = h( 'button', { type: 'button', class: 'button button-primary', text: t.confirmGo, disabled: ! good.length } );
		var dialog = h( 'dialog', { class: 'svc-bl-dialog svc-bl-dialog--wide' }, [
			h( 'div', { class: 'svc-bl-dialog__head' }, [
				h( 'h2', { text: t.confirmTitle } ),
				h( 'button', { type: 'button', class: 'button-link svc-bl-dialog__close', 'aria-label': t.close, text: '×', onclick: function () {
					dialog.close();
				} } ),
			] ),
			h( 'div', { class: 'svc-bl-dialog__body' }, [
				list,
				good.length && data.total ? h( 'p', {}, [ h( 'strong', { text: fmt( t.confirmTotal, data.total ) } ) ] ) : null,
				good.length && data.unknown ? h( 'p', { class: 'description', text: t.confirmUnknown } ) : null,
				! good.length ? h( 'p', { text: t.confirmNone } ) : null,
				h( 'div', { class: 'svc-bl-dialog__foot' }, [
					go,
					h( 'button', { type: 'button', class: 'button', text: t.cancel, onclick: function () {
						dialog.close();
					} } ),
				] ),
			] ),
		] );
		dialog.addEventListener( 'close', function () {
			dialog.remove();
		} );
		go.addEventListener( 'click', function () {
			dialog.close();
			startCopies( good.map( function ( video ) {
				return video.id;
			} ) );
		} );
		document.body.appendChild( dialog );
		dialog.showModal();
	}

	// Step 2: start the copies one after another.
	function startCopies( ids ) {
		var started = 0;
		var errors = [];
		var options = { quality: quality.value, collection: collection ? collection.value : '', auto: auto.checked ? '1' : '' };

		setBusy( true );
		notify( t.starting, 'info' );

		ids.reduce( function ( chain, id ) {
			return chain.then( function () {
				return api( 'start', { ids: id, quality: options.quality, collection: options.collection, auto: options.auto } ).then(
					function () {
						started++;
						delete selected[ id ];
					},
					function ( error ) {
						errors.push( error.message );
					}
				);
			} );
		}, Promise.resolve() ).then( function () {
			setBusy( false );
			if ( errors.length ) {
				notify( ( started ? fmt( t.started, started ) + ' ' : '' ) + errors.join( ' ' ), 'error' );
			} else {
				notify( fmt( t.started, started ) );
			}
			return load();
		} );
	}

	load();
}() );
