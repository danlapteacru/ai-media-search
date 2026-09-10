( function () {
	'use strict';

	var data = window.aimsData || {};
	var i18n = data.i18n || {};

	function api( path, body ) {
		return fetch( data.restUrl + path, {
			method: body === undefined ? 'GET' : 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
			credentials: 'same-origin',
			body: body === undefined ? undefined : JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					throw new Error( ( json && json.message ) || i18n.failed );
				}
				return json;
			} );
		} );
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text == null ? '' : String( text );
		return div.innerHTML;
	}

	function statusHtml( item ) {
		var status = item.status || 'none';
		var labels = i18n.statusLabels || {};
		var html = '<span class="aims-status aims-status-' + escapeHtml( status ) + '">' + escapeHtml( labels[ status ] || status ) + '</span>';
		if ( item.error && status !== 'indexed' ) {
			html += ' <span class="aims-error">' + escapeHtml( item.error ) + '</span>';
		}
		return html;
	}

	// Update every place on the page that shows this attachment.
	function applyResult( item ) {
		var id = String( item.id );
		document.querySelectorAll( '.aims-status-wrap[data-id="' + id + '"]' ).forEach( function ( el ) {
			el.innerHTML = statusHtml( item );
		} );
		if ( item.tags !== undefined ) {
			document.querySelectorAll( '.aims-tags[data-id="' + id + '"]' ).forEach( function ( el ) {
				el.textContent = item.tags.join( ', ' );
			} );
		}
		var row = document.querySelector( '.aims-row[data-id="' + id + '"]' );
		if ( row ) {
			row.querySelector( '.aims-description' ).textContent = item.description || '';
			if ( item.tags !== undefined ) {
				row.querySelector( '.aims-tags' ).textContent = item.tags.join( ', ' );
			}
			var btn = row.querySelector( '.aims-index-one' );
			if ( btn ) {
				btn.textContent = item.status === 'indexed' ? i18n.regenerate : i18n.index;
			}
		}
		// Media modal / attachment edit screen textarea.
		var textarea = document.querySelector( 'textarea[name="attachments[' + id + '][aims_description]"]' );
		if ( textarea ) {
			textarea.value = item.description || '';
		}
	}

	// ---- Tabs -------------------------------------------------------------
	function activateTab( name ) {
		document.querySelectorAll( '.aims-tab' ).forEach( function ( tab ) {
			tab.classList.toggle( 'nav-tab-active', tab.dataset.tab === name );
		} );
		document.querySelectorAll( '.aims-tab-panel' ).forEach( function ( panel ) {
			panel.hidden = panel.id !== 'aims-tab-' + name;
		} );
	}
	document.querySelectorAll( '.aims-tab' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activateTab( tab.dataset.tab );
			history.replaceState( null, '', '#' + tab.dataset.tab );
		} );
	} );
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '.aims-tab-link' );
		if ( ! link ) {
			return;
		}
		e.preventDefault();
		activateTab( 'settings' );
		history.replaceState( null, '', '#settings' );
	} );
	if ( location.hash === '#settings' || location.search.indexOf( 'settings-updated=true' ) !== -1 ) {
		activateTab( 'settings' );
	}

	// ---- Settings helpers -------------------------------------------------
	document.querySelectorAll( '.aims-model-select' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			var custom = select.parentNode.querySelector( '.aims-custom-model' );
			if ( custom ) {
				custom.hidden = select.value !== 'custom';
			}
		} );
	} );

	var testBtn = document.getElementById( 'aims-test' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			var out = document.getElementById( 'aims-test-result' );
			out.textContent = i18n.working;
			testBtn.disabled = true;
			api( 'test', {} ).then( function ( json ) {
				out.textContent = json.message;
				out.className = json.ok ? 'aims-ok' : 'aims-error';
			} ).catch( function ( err ) {
				out.textContent = err.message;
				out.className = 'aims-error';
			} ).then( function () {
				testBtn.disabled = false;
			} );
		} );
	}

	// ---- Single index / regenerate (dashboard rows and media modal) -------
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.aims-index-one, .aims-regenerate' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var id = btn.dataset.id;
		var label = btn.textContent;
		btn.disabled = true;
		btn.textContent = i18n.working;
		api( 'index/' + id, {} ).then( applyResult ).catch( function ( err ) {
			applyResult( { id: id, status: 'failed', error: err.message } );
		} ).then( function () {
			btn.disabled = false;
			if ( btn.textContent === i18n.working ) {
				btn.textContent = label;
			}
		} );
	} );

	// ---- Dashboard batch loop --------------------------------------------
	var selectAll = document.getElementById( 'aims-select-all' );
	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			document.querySelectorAll( '.aims-select' ).forEach( function ( box ) {
				box.checked = selectAll.checked;
			} );
		} );
	}

	var state = { running: false, stop: false };

	function setProgress( done, total ) {
		var bar = document.getElementById( 'aims-progress-bar' );
		var text = document.getElementById( 'aims-progress-text' );
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		bar.style.width = pct + '%';
		text.textContent = ( i18n.progress || '%1$s of %2$s' ).replace( '%1$s', done ).replace( '%2$s', total );
	}

	function log( item ) {
		var ul = document.getElementById( 'aims-log' );
		var li = document.createElement( 'li' );
		li.className = item.ok ? 'aims-log-ok' : 'aims-log-fail';
		var statusLabels = i18n.statusLabels || {};
		li.textContent = '#' + item.id + ' ' + ( item.title || '' ) + ' — ' + ( item.ok ? ( statusLabels[ item.status ] || item.status || '' ) : ( item.error || '' ) );
		ul.insertBefore( li, ul.firstChild );
	}

	function setRunning( running ) {
		state.running = running;
		[ 'aims-index-selected', 'aims-index-all' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.disabled = running;
			}
		} );
		document.getElementById( 'aims-stop' ).disabled = ! running;
	}

	function runBatch( ids, total, done, afterId ) {
		if ( state.stop ) {
			finish( i18n.stopped );
			return;
		}
		var body = {
			batch_size: data.batchSize,
			retry_failed: document.getElementById( 'aims-retry-failed' ).checked
		};
		if ( ids ) {
			body.ids = ids;
		} else {
			body.after_id = afterId;
		}
		api( 'bulk', body ).then( function ( json ) {
			json.results.forEach( function ( item ) {
				applyResult( item );
				log( item );
			} );
			done += json.results.length;
			if ( ids ) {
				total = done + json.remaining_ids.length;
				setProgress( done, total );
				if ( json.remaining_ids.length === 0 || json.results.length === 0 ) {
					finish( i18n.done );
					return;
				}
				runBatch( json.remaining_ids, total, done, afterId );
			} else {
				if ( json.last_id > afterId ) {
					afterId = json.last_id;
				}
				total = done + json.remaining_count;
				setProgress( done, total );
				if ( json.remaining_count === 0 || json.results.length === 0 ) {
					finish( i18n.done );
					return;
				}
				runBatch( null, total, done, afterId );
			}
		} ).catch( function ( err ) {
			log( { id: '-', ok: false, error: err.message } );
			finish( i18n.failed );
		} );
	}

	function finish( message ) {
		document.getElementById( 'aims-progress-text' ).textContent += ' ' + message;
		setRunning( false );
		state.stop = false;
	}

	function start( ids ) {
		if ( state.running ) {
			return;
		}
		document.getElementById( 'aims-log' ).innerHTML = '';
		setProgress( 0, ids ? ids.length : 0 );
		state.stop = false;
		setRunning( true );
		runBatch( ids, ids ? ids.length : 0, 0, 0 );
	}

	var indexSelected = document.getElementById( 'aims-index-selected' );
	if ( indexSelected ) {
		indexSelected.addEventListener( 'click', function () {
			var ids = Array.prototype.map.call( document.querySelectorAll( '.aims-select:checked' ), function ( box ) {
				return parseInt( box.value, 10 );
			} );
			if ( ids.length === 0 ) {
				window.alert( i18n.noSelection );
				return;
			}
			start( ids );
		} );
		document.getElementById( 'aims-index-all' ).addEventListener( 'click', function () {
			start( null );
		} );
		document.getElementById( 'aims-stop' ).addEventListener( 'click', function () {
			state.stop = true;
		} );
	}
}() );
