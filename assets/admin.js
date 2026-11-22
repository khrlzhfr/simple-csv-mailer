/* Simple CSV Mailer: compose screen. Data comes from window.scmData. */
( function () {
	const data = window.scmData || { lists: {}, from: '', alert: '', i18n: {} };
	const t = data.i18n;

	if ( data.alert ) {
		window.alert( data.alert );
	}

	const wrap = document.querySelector( '.wrap.scm' );
	const toggle = document.getElementById( 'scm-upload-toggle' );
	const upload = document.getElementById( 'scm-csv' );
	if ( wrap && toggle && upload ) {
		const submit = upload.form.querySelector( '[type=submit]' );
		toggle.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			const on = wrap.classList.toggle( 'show-upload-view' );
			toggle.setAttribute( 'aria-expanded', on );
			if ( on ) {
				upload.focus();
			}
		} );
		upload.addEventListener( 'change', () => ( submit.disabled = ! upload.files.length ) );
	}

	const form = document.getElementById( 'scm-compose' );
	if ( ! form ) {
		return;
	}
	const lists = data.lists || {};
	const file = document.getElementById( 'scm-file' );
	const subject = document.getElementById( 'scm-subject' );
	const attachments = document.getElementById( 'scm-attachments' );
	const tagsBox = document.getElementById( 'scm-tags' );
	const bodyField = document.getElementById( 'scm_body' );
	let lastFocus = 'body';

	subject.addEventListener( 'focus', () => ( lastFocus = 'subject' ) );
	attachments.addEventListener( 'focus', () => ( lastFocus = 'attachments' ) );
	if ( bodyField ) {
		bodyField.addEventListener( 'focus', () => ( lastFocus = 'body' ) );
	}
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'tinymce-editor-init', ( e, ed ) => ed.on( 'focus', () => ( lastFocus = 'body' ) ) );
	}

	function insertAt( el, text ) {
		const s = el.selectionStart ?? el.value.length;
		const en = el.selectionEnd ?? el.value.length;
		el.value = el.value.slice( 0, s ) + text + el.value.slice( en );
		el.focus();
		el.selectionStart = el.selectionEnd = s + text.length;
	}

	function insertTag( tag ) {
		if ( lastFocus === 'subject' ) {
			return insertAt( subject, tag );
		}
		if ( lastFocus === 'attachments' ) {
			return insertAt( attachments, tag );
		}
		const ed = window.tinymce && window.tinymce.get( 'scm_body' );
		if ( ed && ! ed.isHidden() ) {
			ed.focus();
			ed.execCommand( 'mceInsertContent', false, tag );
		} else if ( bodyField ) {
			insertAt( bodyField, tag );
		}
	}

	function current() {
		return file ? lists[ file.value ] : null;
	}

	function renderTags() {
		tagsBox.innerHTML = '';
		const l = current();
		if ( ! l ) {
			tagsBox.textContent = t.noList;
			return;
		}
		l.tags.forEach( ( tag ) => {
			const b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'button button-small';
			b.textContent = '%' + tag + '%';
			b.addEventListener( 'mousedown', ( ev ) => ev.preventDefault() ); // Keep the cursor where it is.
			b.addEventListener( 'click', () => insertTag( b.textContent ) );
			tagsBox.appendChild( b );
		} );
	}

	function fmt( s ) {
		const h = Math.floor( s / 3600 );
		const m = Math.floor( ( s % 3600 ) / 60 );
		const x = s % 60;
		const p = [];
		if ( h ) {
			p.push( h + ' ' + ( h > 1 ? t.hours : t.hour ) );
		}
		if ( m ) {
			p.push( m + ' ' + ( m > 1 ? t.minutes : t.minute ) );
		}
		if ( x || ! p.length ) {
			p.push( x + ' ' + ( x !== 1 ? t.seconds : t.second ) );
		}
		return p.join( ' ' );
	}

	function eta() {
		const l = current();
		const box = document.getElementById( 'scm-eta' );
		if ( ! l ) {
			box.textContent = '';
			return;
		}
		const left = Math.max( 0, l.count - l.done );
		const n = Math.max( 1, +document.getElementById( 'scm-count' ).value || 1 );
		const s = Math.max( 1, +document.getElementById( 'scm-seconds' ).value || 1 );
		box.textContent = t.eta.replace( '%1$s', left ).replace( '%2$s', fmt( Math.ceil( left / n ) * s ) );
	}

	if ( file ) {
		file.addEventListener( 'change', () => {
			renderTags();
			eta();
		} );
	}
	[ 'scm-count', 'scm-seconds' ].forEach( ( id ) => document.getElementById( id ).addEventListener( 'input', eta ) );
	renderTags();
	eta();

	form.querySelectorAll( 'button[data-action]' ).forEach( ( b ) =>
		b.addEventListener( 'click', ( ev ) => {
			const action = b.dataset.action;
			document.getElementById( 'scm-action' ).value = action;
			if ( window.tinymce ) {
				window.tinymce.triggerSave();
			}
			form.querySelector( '[name=test_to]' ).required = action === 'test';
			if ( action === 'send' ) {
				const l = current();
				const addr = ( form.querySelector( '[name=alias]' ).value || '?' ) + data.from;
				if ( ! l || ! window.confirm( t.confirm.replace( '%1$s', Math.max( 0, l.count - l.done ) ).replace( '%2$s', addr ) ) ) {
					ev.preventDefault();
				}
			}
		} )
	);
} )();
