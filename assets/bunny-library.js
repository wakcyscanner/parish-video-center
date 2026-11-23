/* Bunny Stream library manager (wp-admin). */
( function () {
	'use strict';

	var cfg = window.svcBunnyLibrary;
	var root = document.getElementById( 'svc-bl-app' );
	if ( ! cfg || ! root ) {
		return;
	}

	var t = cfg.i18n;
	var state = {
		page: 1,
		search: '',
		collection: '',
		total: 0,
		items: [],
		collections: [],
		loading: false,
	};
	var uploads = [];
	var uploading = false;
	var pollTimer = null;

	// ------------------------------------------------------------------
	// Small helpers
	// ------------------------------------------------------------------

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

	function api( op, data, file ) {
		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'op', op );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		if ( file ) {
			body.append( 'file', file );
		}
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

	function notify( message, type ) {
		var box = h( 'div', { class: 'notice is-dismissible notice-' + ( type || 'success' ) }, [
			h( 'p', { text: message } ),
			h( 'button', {
				type: 'button',
				class: 'notice-dismiss',
				'aria-label': t.close,
				onclick: function () {
					box.remove();
				},
			} ),
		] );
		notices.textContent = '';
		notices.appendChild( box );
		if ( type !== 'error' ) {
			setTimeout( function () {
				box.remove();
			}, 8000 );
		}
	}

	function collectionName( guid ) {
		for ( var i = 0; i < state.collections.length; i++ ) {
			if ( state.collections[ i ].guid === guid ) {
				return state.collections[ i ].name;
			}
		}
		return '';
	}

	function collectionSelect( current, emptyLabel, attrs ) {
		var select = h( 'select', attrs || {}, [ h( 'option', { value: '', text: emptyLabel } ) ] );
		state.collections.forEach( function ( collection ) {
			select.appendChild( h( 'option', {
				value: collection.guid,
				text: collection.name + ' (' + collection.count + ')',
				selected: collection.guid === current,
			} ) );
		} );
		return select;
	}

	function openDialog( title, content, wide ) {
		var dialog = h( 'dialog', { class: 'svc-bl-dialog' + ( wide ? ' svc-bl-dialog--wide' : '' ) }, [
			h( 'div', { class: 'svc-bl-dialog__head' }, [
				h( 'h2', { text: title } ),
				h( 'button', {
					type: 'button',
					class: 'button-link svc-bl-dialog__close',
					'aria-label': t.close,
					text: '×',
					onclick: function () {
						dialog.close();
					},
				} ),
			] ),
			h( 'div', { class: 'svc-bl-dialog__body' }, content ),
		] );
		dialog.addEventListener( 'close', function () {
			dialog.remove();
		} );
		document.body.appendChild( dialog );
		dialog.showModal();
		return dialog;
	}

	// ------------------------------------------------------------------
	// Layout
	// ------------------------------------------------------------------

	var notices = h( 'div', { class: 'svc-bl-notices' } );
	var uploadPanel = h( 'div', { class: 'svc-bl-upload', hidden: true } );
	var toolbar = h( 'div', { class: 'svc-bl-toolbar' } );
	var tableWrap = h( 'div', { class: 'svc-bl-table' } );
	var pager = h( 'div', { class: 'svc-bl-pager tablenav' } );

	root.textContent = '';
	[ notices, uploadPanel, toolbar, tableWrap, pager ].forEach( function ( el ) {
		root.appendChild( el );
	} );

	var searchTimer = null;
	var searchInput = h( 'input', {
		type: 'search',
		placeholder: t.search,
		'aria-label': t.search,
		oninput: function () {
			clearTimeout( searchTimer );
			searchTimer = setTimeout( function () {
				state.search = searchInput.value.trim();
				state.page = 1;
				load();
			}, 350 );
		},
	} );
	var countLabel = h( 'span', { class: 'svc-bl-count' } );
	var syncLabel = h( 'span', { class: 'svc-bl-sync description' } );
	var filterSlot = h( 'span' );
	toolbar.appendChild( filterSlot );
	toolbar.appendChild( searchInput );
	toolbar.appendChild( countLabel );
	toolbar.appendChild( syncLabel );

	function renderFilter() {
		var select = collectionSelect( state.collection, t.allVideos, {
			'aria-label': t.colCollection,
			onchange: function () {
				state.collection = select.value;
				state.page = 1;
				load();
			},
		} );
		filterSlot.textContent = '';
		filterSlot.appendChild( select );
	}

	// ------------------------------------------------------------------
	// Video list
	// ------------------------------------------------------------------

	function load( quiet ) {
		if ( state.loading ) {
			return Promise.resolve();
		}
		state.loading = true;
		if ( ! quiet ) {
			tableWrap.classList.add( 'is-loading' );
		}
		return api( 'list', {
			page: state.page,
			per_page: cfg.perPage,
			search: state.search,
			collection: state.collection,
		} )
			.then( function ( data ) {
				state.items = data.items;
				state.total = data.total;
				state.collections = data.collections;
				syncLabel.textContent = data.lastSync || '';
				render();
			} )
			.catch( function ( error ) {
				if ( ! quiet ) {
					notify( error.message, 'error' );
					tableWrap.textContent = '';
				}
			} )
			.then( function () {
				state.loading = false;
				tableWrap.classList.remove( 'is-loading' );
			} );
	}

	function render() {
		renderFilter();
		renderUploadTarget();
		countLabel.textContent = fmt( t.count, state.total );

		var head = h( 'tr', {}, [
			h( 'th', { class: 'svc-bl-col-thumb', scope: 'col' } ),
			h( 'th', { scope: 'col', text: t.colVideo } ),
			h( 'th', { scope: 'col', text: t.colCollection } ),
			h( 'th', { scope: 'col', text: t.colStatus } ),
			h( 'th', { scope: 'col', text: t.colLength } ),
			h( 'th', { scope: 'col', text: t.colSize } ),
			h( 'th', { scope: 'col', text: t.colViews } ),
			h( 'th', { scope: 'col', text: t.colUploaded } ),
			h( 'th', { scope: 'col', text: t.colPost } ),
		] );
		var body = h( 'tbody' );
		state.items.forEach( function ( item ) {
			body.appendChild( row( item ) );
		} );
		if ( ! state.items.length ) {
			body.appendChild( h( 'tr', {}, [ h( 'td', { colspan: 9, text: t.empty } ) ] ) );
		}

		tableWrap.textContent = '';
		tableWrap.appendChild( h( 'table', { class: 'wp-list-table widefat fixed striped' }, [ h( 'thead', {}, [ head ] ), body ] ) );

		var pages = Math.max( 1, Math.ceil( state.total / cfg.perPage ) );
		pager.textContent = '';
		if ( pages > 1 ) {
			pager.appendChild( h( 'div', { class: 'tablenav-pages' }, [
				h( 'button', {
					type: 'button',
					class: 'button',
					text: t.prev,
					disabled: state.page <= 1,
					onclick: function () {
						state.page--;
						load();
					},
				} ),
				h( 'span', { class: 'svc-bl-pageof', text: fmt( t.pageOf, state.page, pages ) } ),
				h( 'button', {
					type: 'button',
					class: 'button',
					text: t.next,
					disabled: state.page >= pages,
					onclick: function () {
						state.page++;
						load();
					},
				} ),
			] ) );
		}

		// Keep refreshing while Bunny is still processing something on this page.
		clearTimeout( pollTimer );
		var busy = state.items.some( function ( item ) {
			return ! item.ready && ! item.failed;
		} );
		if ( busy ) {
			pollTimer = setTimeout( function () {
				if ( ! document.querySelector( 'dialog.svc-bl-dialog[open]' ) ) {
					load( true );
				} else {
					render();
				}
			}, 15000 );
		}
	}

	function row( item ) {
		var actions = h( 'div', { class: 'row-actions visible' }, [
			h( 'button', { type: 'button', class: 'button-link', text: t.edit, onclick: function () {
				editVideo( item );
			} } ),
			' | ',
			item.ready ? h( 'button', { type: 'button', class: 'button-link', text: t.preview, onclick: function () {
				previewVideo( item );
			} } ) : null,
			item.ready ? ' | ' : null,
			h( 'button', { type: 'button', class: 'button-link', text: t.copyId, onclick: function ( event ) {
				copyText( '[parish_video id="' + item.guid + '"]', event.currentTarget );
			} } ),
			' | ',
			// Only a video with a published page has somewhere for an email to link to.
			emailReady( item ) ? h( 'button', { type: 'button', class: 'button-link', text: t.copyEmail, onclick: function () {
				window.svcEmailShare.open( item.post.id );
			} } ) : null,
			emailReady( item ) ? ' | ' : null,
			h( 'button', { type: 'button', class: 'button-link svc-bl-danger', text: t.delete, onclick: function () {
				deleteVideo( item );
			} } ),
		] );

		var status = item.statusLabel;
		if ( ! item.ready && ! item.failed && item.status > 1 && item.progress ) {
			status += ' ' + item.progress + '%';
		}

		var post = t.noPost;
		if ( item.post ) {
			post = h( 'span', {}, [
				h( 'a', { href: item.post.edit, text: t.editPost } ),
				item.post.status === 'publish' ? ' | ' : ' (' + t.draft + ')',
				item.post.status === 'publish' ? h( 'a', { href: item.post.view, target: '_blank', rel: 'noopener', text: t.viewPost } ) : null,
			] );
		}

		return h( 'tr', {}, [
			h( 'td', { class: 'svc-bl-col-thumb' }, [ thumb( item ) ] ),
			h( 'td', {}, [
				h( 'strong', { text: item.title } ),
				item.description ? h( 'p', { class: 'svc-bl-desc', text: item.description } ) : null,
				actions,
			] ),
			h( 'td', { text: collectionName( item.collection ) || '—' } ),
			h( 'td', {}, [ h( 'span', {
				class: 'svc-bl-status' + ( item.ready ? ' is-ready' : item.failed ? ' is-failed' : ' is-busy' ),
				text: status,
			} ) ] ),
			h( 'td', { text: item.duration || '—' } ),
			h( 'td', { text: item.size || '—' } ),
			h( 'td', { text: String( item.views ) } ),
			h( 'td', { text: item.uploaded } ),
			h( 'td', {}, [ post ] ),
		] );
	}

	function emailReady( item ) {
		return !! ( item.post && item.post.status === 'publish' && window.svcEmailShare && window.svcEmailShare.open );
	}

	function thumb( item ) {
		var box = h( 'div', { class: 'svc-bl-thumb' } );
		if ( item.thumbnail && item.ready ) {
			box.appendChild( h( 'img', {
				src: item.thumbnail,
				alt: '',
				loading: 'lazy',
				onerror: function ( event ) {
					event.currentTarget.remove();
				},
			} ) );
		}
		return box;
	}

	function copyText( text, button ) {
		if ( ! navigator.clipboard ) {
			window.prompt( t.copyId, text );
			return;
		}
		navigator.clipboard.writeText( text ).then( function () {
			var original = button.textContent;
			button.textContent = t.copied;
			setTimeout( function () {
				button.textContent = original;
			}, 1500 );
		} );
	}

	// ------------------------------------------------------------------
	// Edit, preview, delete
	// ------------------------------------------------------------------

	function editVideo( item ) {
		var title = h( 'input', { type: 'text', class: 'widefat', value: item.title, required: true } );
		var description = h( 'textarea', { class: 'widefat', rows: 8 } );
		description.value = item.description;
		var collection = collectionSelect( item.collection, t.noCollection, { class: 'widefat' } );
		var file = h( 'input', { type: 'file', accept: 'image/jpeg,image/png,image/webp' } );
		var message = h( 'p', { class: 'svc-bl-form-message', role: 'status' } );
		var save = h( 'button', { type: 'submit', class: 'button button-primary', text: t.save } );

		var form = h( 'form', {}, [
			h( 'label', {}, [ h( 'span', { text: t.title } ), title ] ),
			h( 'label', {}, [ h( 'span', { text: t.description } ), description ] ),
			h( 'label', {}, [ h( 'span', { text: t.collection } ), collection ] ),
			h( 'div', { class: 'svc-bl-thumb-field' }, [
				thumb( item ),
				h( 'label', {}, [ h( 'span', { text: t.thumbnail } ), file, h( 'span', { class: 'description', text: t.thumbnailHelp } ) ] ),
			] ),
			message,
			h( 'div', { class: 'svc-bl-dialog__foot' }, [
				save,
				h( 'button', { type: 'button', class: 'button', text: t.cancel, onclick: function () {
					dialog.close();
				} } ),
			] ),
		] );

		var dialog = openDialog( t.editTitle, [ form ] );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			save.disabled = true;
			message.className = 'svc-bl-form-message';
			message.textContent = t.saving;

			api( 'save', {
				guid: item.guid,
				title: title.value,
				description: description.value,
				collection: collection.value,
			} )
				.then( function () {
					return file.files.length ? api( 'thumbnail', { guid: item.guid }, file.files[ 0 ] ) : null;
				} )
				.then( function () {
					dialog.close();
					notify( t.saved );
					return load( true );
				} )
				.catch( function ( error ) {
					save.disabled = false;
					message.className = 'svc-bl-form-message is-error';
					message.textContent = error.message;
				} );
		} );
	}

	function previewVideo( item ) {
		openDialog( item.title, [
			h( 'div', { class: 'svc-bl-player' }, [
				h( 'iframe', {
					src: item.embed,
					allow: 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen',
					allowfullscreen: true,
					title: item.title,
				} ),
			] ),
		], true );
	}

	function deleteVideo( item ) {
		var question = fmt( t.confirmDelete, item.title );
		if ( item.post && item.post.status === 'publish' ) {
			question += '\n\n' + t.confirmDeletePost;
		}
		if ( ! window.confirm( question ) ) {
			return;
		}
		api( 'delete', { guid: item.guid } )
			.then( function ( data ) {
				notify( data.drafted ? t.deletedDrafted : t.deleted );
				return load( true );
			} )
			.catch( function ( error ) {
				notify( error.message, 'error' );
			} );
	}

	// ------------------------------------------------------------------
	// Collections
	// ------------------------------------------------------------------

	function manageCollections() {
		var list = h( 'div' );
		var name = h( 'input', { type: 'text', class: 'regular-text', placeholder: t.newCollection, 'aria-label': t.newCollection, required: true } );
		var message = h( 'p', { class: 'svc-bl-form-message', role: 'status' } );

		function fail( error ) {
			message.className = 'svc-bl-form-message is-error';
			message.textContent = error.message;
		}

		function done( data ) {
			message.textContent = '';
			state.collections = data.collections;
			draw();
			renderFilter();
			renderUploadTarget();
		}

		function draw() {
			list.textContent = '';
			if ( ! state.collections.length ) {
				list.appendChild( h( 'p', { text: t.noCollections } ) );
				return;
			}
			var body = h( 'tbody' );
			state.collections.forEach( function ( collection ) {
				body.appendChild( h( 'tr', {}, [
					h( 'td', {}, [ h( 'strong', { text: collection.name } ) ] ),
					h( 'td', { text: fmt( t.count, collection.count ) } ),
					h( 'td', { text: collection.topic ? fmt( t.linkedTo, collection.topic ) : t.notLinked } ),
					h( 'td', { class: 'svc-bl-actions' }, [
						h( 'button', { type: 'button', class: 'button-link', text: t.rename, onclick: function () {
							var renamed = window.prompt( t.renamePrompt, collection.name );
							if ( renamed && renamed.trim() && renamed.trim() !== collection.name ) {
								api( 'collection_rename', { guid: collection.guid, name: renamed.trim() } ).then( done, fail );
							}
						} } ),
						! collection.count && ! collection.topic ? ' | ' : null,
						! collection.count && ! collection.topic ? h( 'button', { type: 'button', class: 'button-link svc-bl-danger', text: t.delete, onclick: function () {
							if ( window.confirm( fmt( t.confirmDeleteCollection, collection.name ) ) ) {
								api( 'collection_delete', { guid: collection.guid } ).then( function ( data ) {
									if ( state.collection === collection.guid ) {
										state.collection = '';
										load( true );
									}
									done( data );
								}, fail );
							}
						} } ) : null,
					] ),
				] ) );
			} );
			list.appendChild( h( 'table', { class: 'widefat striped' }, [ body ] ) );
		}

		var form = h( 'form', { class: 'svc-bl-inline-form' }, [ name, h( 'button', { type: 'submit', class: 'button', text: t.add } ) ] );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			api( 'collection_create', { name: name.value.trim() } ).then( function ( data ) {
				name.value = '';
				done( data );
			}, fail );
		} );

		draw();
		openDialog( t.collectionsTitle, [
			h( 'p', { class: 'description' }, [ t.collectionsHelp + ' ', h( 'a', { href: cfg.topicsUrl, text: t.topics } ) ] ),
			list,
			form,
			message,
		], true );
	}

	// ------------------------------------------------------------------
	// Uploads (resumable, straight to Bunny over the TUS protocol)
	// ------------------------------------------------------------------

	var CHUNK = 20 * 1024 * 1024;
	var MAX_RETRIES = 8;

	var uploadTargetSlot = h( 'span' );
	var uploadTarget = null;
	var uploadList = h( 'ul', { class: 'svc-bl-queue' } );
	var fileInput = h( 'input', {
		type: 'file',
		accept: 'video/*,.mkv,.mov,.mp4,.m4v,.avi,.webm,.mts,.mxf',
		multiple: true,
		hidden: true,
		onchange: function () {
			addFiles( fileInput.files );
			fileInput.value = '';
		},
	} );
	var dropZone = h( 'div', { class: 'svc-bl-drop' }, [
		h( 'p', { text: t.uploadHelp } ),
		h( 'p', {}, [
			h( 'button', { type: 'button', class: 'button button-primary', text: t.chooseFiles, onclick: function () {
				fileInput.click();
			} } ),
			' ',
			h( 'label', {}, [ t.uploadTo + ' ', uploadTargetSlot ] ),
		] ),
		fileInput,
	] );
	uploadPanel.appendChild( h( 'h2', { text: t.uploadTitle } ) );
	uploadPanel.appendChild( dropZone );
	uploadPanel.appendChild( uploadList );

	[ 'dragenter', 'dragover' ].forEach( function ( type ) {
		dropZone.addEventListener( type, function ( event ) {
			event.preventDefault();
			dropZone.classList.add( 'is-over' );
		} );
	} );
	[ 'dragleave', 'drop' ].forEach( function ( type ) {
		dropZone.addEventListener( type, function ( event ) {
			event.preventDefault();
			dropZone.classList.remove( 'is-over' );
		} );
	} );
	dropZone.addEventListener( 'drop', function ( event ) {
		addFiles( event.dataTransfer.files );
	} );

	function renderUploadTarget() {
		var current = uploadTarget ? uploadTarget.value : state.collection;
		uploadTarget = collectionSelect( current, t.noCollection );
		uploadTargetSlot.textContent = '';
		uploadTargetSlot.appendChild( uploadTarget );
	}

	function titleFromFile( name ) {
		return name.replace( /\.[^.]+$/, '' ).replace( /[_]+/g, ' ' ).replace( /\s+/g, ' ' ).trim() || name;
	}

	function isVideo( file ) {
		return /^video\//.test( file.type ) || /\.(mp4|m4v|mov|mkv|avi|webm|mts|m2ts|mxf|wmv|flv|mpg|mpeg|ts|3gp)$/i.test( file.name );
	}

	function addFiles( files ) {
		[].forEach.call( files, function ( file ) {
			var upload = {
				file: file,
				title: titleFromFile( file.name ),
				collection: uploadTarget ? uploadTarget.value : '',
				key: 'svcBunnyUpload:' + [ file.name, file.size, file.lastModified ].join( ':' ),
				state: 'queued',
				sent: 0,
				xhr: null,
				cancelled: false,
			};
			upload.el = queueItem( upload );
			uploadList.appendChild( upload.el.node );
			if ( ! isVideo( file ) ) {
				upload.state = 'failed';
				upload.el.update( t.notVideo, true );
				upload.el.finish();
				return;
			}
			uploads.push( upload );
		} );
		pump();
	}

	function queueItem( upload ) {
		var status = h( 'span', { class: 'svc-bl-queue__status', text: t.queued } );
		var bar = h( 'progress', { max: 100, value: 0 } );
		var title = h( 'input', { type: 'text', class: 'regular-text', value: upload.title, 'aria-label': t.title, oninput: function () {
			upload.title = title.value;
		} } );
		var cancel = h( 'button', { type: 'button', class: 'button-link svc-bl-danger', text: t.cancel, onclick: function () {
			cancelUpload( upload );
		} } );
		var retry = h( 'button', { type: 'button', class: 'button-link', text: t.retry, hidden: true, onclick: function () {
			retry.hidden = true;
			upload.state = 'queued';
			upload.cancelled = false;
			status.classList.remove( 'is-error' );
			status.textContent = t.queued;
			pump();
		} } );
		var node = h( 'li', {}, [
			h( 'div', { class: 'svc-bl-queue__row' }, [ title, h( 'span', { class: 'svc-bl-queue__size', text: size( upload.file.size ) } ), retry, cancel ] ),
			bar,
			status,
		] );

		return {
			node: node,
			update: function ( text, isError ) {
				status.textContent = text;
				status.classList.toggle( 'is-error', !! isError );
			},
			progress: function ( sent ) {
				var percent = upload.file.size ? Math.floor( ( sent / upload.file.size ) * 100 ) : 0;
				bar.value = percent;
				status.classList.remove( 'is-error' );
				status.textContent = percent + '% — ' + size( sent ) + ' / ' + size( upload.file.size );
			},
			lockTitle: function () {
				title.disabled = true;
			},
			failed: function () {
				retry.hidden = false;
			},
			finish: function () {
				cancel.hidden = true;
				title.disabled = true;
			},
		};
	}

	function size( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return ( i ? bytes.toFixed( 1 ) : bytes ) + ' ' + units[ i ];
	}

	function remember( upload, record ) {
		try {
			if ( record ) {
				localStorage.setItem( upload.key, JSON.stringify( record ) );
			} else {
				localStorage.removeItem( upload.key );
			}
		} catch ( e ) {}
	}

	function recall( upload ) {
		try {
			return JSON.parse( localStorage.getItem( upload.key ) || 'null' );
		} catch ( e ) {
			return null;
		}
	}

	function tusHeaders( upload, extra ) {
		var headers = { 'Tus-Resumable': '1.0.0' };
		Object.keys( upload.headers ).forEach( function ( key ) {
			headers[ key ] = upload.headers[ key ];
		} );
		Object.keys( extra || {} ).forEach( function ( key ) {
			headers[ key ] = extra[ key ];
		} );
		return headers;
	}

	function b64( text ) {
		return btoa( unescape( encodeURIComponent( text ) ) );
	}

	// Ask Bunny how much of the file it already has.
	function tusOffset( upload ) {
		return fetch( upload.url, { method: 'HEAD', headers: tusHeaders( upload ), cache: 'no-store' } ).then( function ( response ) {
			var offset = parseInt( response.headers.get( 'Upload-Offset' ), 10 );
			if ( ! response.ok || isNaN( offset ) ) {
				throw new Error( 'offset' );
			}
			return offset;
		} );
	}

	function tusCreate( upload ) {
		var meta = [
			'filetype ' + b64( upload.file.type || 'video/mp4' ),
			'title ' + b64( upload.title ),
		];
		if ( upload.collection ) {
			meta.push( 'collection ' + b64( upload.collection ) );
		}
		return fetch( cfg.tusEndpoint, {
			method: 'POST',
			headers: tusHeaders( upload, {
				'Upload-Length': String( upload.file.size ),
				'Upload-Metadata': meta.join( ',' ),
			} ),
		} ).then( function ( response ) {
			var location = response.headers.get( 'Location' );
			if ( response.status !== 201 || ! location ) {
				throw new Error( t.uploadFailed + ' (HTTP ' + response.status + ')' );
			}
			upload.url = new URL( location, cfg.tusEndpoint ).href;
		} );
	}

	function tusChunk( upload, offset ) {
		return new Promise( function ( resolve, reject ) {
			var xhr = new XMLHttpRequest();
			var headers = tusHeaders( upload, {
				'Upload-Offset': String( offset ),
				'Content-Type': 'application/offset+octet-stream',
			} );
			upload.xhr = xhr;
			xhr.open( 'PATCH', upload.url );
			Object.keys( headers ).forEach( function ( key ) {
				xhr.setRequestHeader( key, headers[ key ] );
			} );
			xhr.upload.onprogress = function ( event ) {
				upload.el.progress( offset + event.loaded );
			};
			xhr.onload = function () {
				var next = parseInt( xhr.getResponseHeader( 'Upload-Offset' ), 10 );
				if ( xhr.status >= 200 && xhr.status < 300 && ! isNaN( next ) ) {
					resolve( next );
				} else {
					reject( new Error( 'HTTP ' + xhr.status ) );
				}
			};
			xhr.onerror = xhr.onabort = xhr.ontimeout = function () {
				reject( new Error( 'network' ) );
			};
			xhr.send( upload.file.slice( offset, Math.min( offset + CHUNK, upload.file.size ) ) );
		} );
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	function tusSend( upload, offset ) {
		var failures = 0;

		function step( at ) {
			if ( upload.cancelled ) {
				return Promise.reject( new Error( 'cancelled' ) );
			}
			if ( at >= upload.file.size ) {
				return Promise.resolve();
			}
			return tusChunk( upload, at ).then(
				function ( next ) {
					failures = 0;
					return step( next );
				},
				function ( error ) {
					if ( upload.cancelled ) {
						throw new Error( 'cancelled' );
					}
					failures++;
					if ( failures > MAX_RETRIES ) {
						throw new Error( t.uploadFailed + ' (' + error.message + ')' );
					}
					upload.el.update( t.retrying, true );
					return wait( Math.min( 30000, 1500 * Math.pow( 2, failures - 1 ) ) )
						.then( function () {
							return tusOffset( upload ).catch( function () {
								return at;
							} );
						} )
						.then( step );
				}
			);
		}

		return step( offset );
	}

	// Start (or pick back up) one upload; resolves when the whole file is sent.
	function runUpload( upload ) {
		var saved = recall( upload );
		var begin;

		upload.el.lockTitle();

		if ( upload.url && upload.headers ) {
			// Retry within this page load.
			upload.el.update( t.resuming );
			begin = tusOffset( upload );
		} else if ( saved && saved.guid && saved.url ) {
			// The same file was interrupted in an earlier visit.
			upload.el.update( t.resuming );
			begin = api( 'upload_resume', { guid: saved.guid } )
				.then( function ( data ) {
					upload.guid = data.guid;
					upload.headers = data.headers;
					upload.url = saved.url;
					return tusOffset( upload );
				} )
				.catch( function () {
					remember( upload, null );
					upload.url = null;
					return startFresh( upload );
				} );
		} else {
			begin = startFresh( upload );
		}

		return begin.then( function ( offset ) {
			upload.el.progress( offset );
			return tusSend( upload, offset );
		} );
	}

	function startFresh( upload ) {
		upload.el.update( t.starting );
		return api( 'upload_start', { title: upload.title.trim() || titleFromFile( upload.file.name ), collection: upload.collection } )
			.then( function ( data ) {
				upload.guid = data.guid;
				upload.headers = data.headers;
				return tusCreate( upload );
			} )
			.then( function () {
				remember( upload, { guid: upload.guid, url: upload.url } );
				return 0;
			} );
	}

	function pump() {
		if ( uploading ) {
			return;
		}
		var upload = uploads.filter( function ( candidate ) {
			return candidate.state === 'queued';
		} )[ 0 ];
		if ( ! upload ) {
			return;
		}

		uploading = true;
		upload.state = 'active';

		runUpload( upload )
			.then( function () {
				upload.state = 'done';
				remember( upload, null );
				upload.el.progress( upload.file.size );
				upload.el.update( t.uploadDone );
				upload.el.finish();
			} )
			.catch( function ( error ) {
				if ( upload.cancelled ) {
					return;
				}
				upload.state = 'failed';
				upload.el.update( error.message || t.uploadFailed, true );
				upload.el.failed();
			} )
			.then( function () {
				uploading = false;
				load( true );
				pump();
			} );
	}

	function cancelUpload( upload ) {
		var wasActive = upload.state === 'active';
		upload.cancelled = true;
		upload.state = 'cancelled';
		if ( upload.xhr ) {
			upload.xhr.abort();
		}
		remember( upload, null );
		upload.el.update( t.uploadCancelled );
		upload.el.finish();
		// Remove the half-uploaded entry so it doesn't linger in the library.
		if ( upload.guid ) {
			api( 'delete', { guid: upload.guid } ).catch( function () {} ).then( function () {
				load( true );
			} );
		}
		if ( ! wasActive ) {
			pump();
		}
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( uploading ) {
			event.preventDefault();
			event.returnValue = t.leaveWarning;
		}
	} );

	// ------------------------------------------------------------------
	// Header buttons
	// ------------------------------------------------------------------

	document.querySelector( '[data-svc="upload-toggle"]' ).addEventListener( 'click', function () {
		uploadPanel.hidden = ! uploadPanel.hidden;
	} );
	document.querySelector( '[data-svc="collections"]' ).addEventListener( 'click', manageCollections );
	document.querySelector( '[data-svc="sync"]' ).addEventListener( 'click', function ( event ) {
		var button = event.currentTarget;
		button.disabled = true;
		button.textContent = t.syncing;
		api( 'sync' )
			.then( function ( data ) {
				notify( data.message || t.syncNow );
			}, function ( error ) {
				notify( error.message, 'error' );
			} )
			.then( function () {
				button.disabled = false;
				button.textContent = t.syncNow;
				load( true );
			} );
	} );

	load();
}() );
