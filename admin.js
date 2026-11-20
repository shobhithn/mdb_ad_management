/**
 * MDB Ad Management: admin screens.
 */
( function () {
	'use strict';

	var text = window.mdbAdsAdmin || {};

	function closest( element, selector ) {
		return element && element.closest ? element.closest( selector ) : null;
	}

	function each( list, callback ) {
		Array.prototype.forEach.call( list, callback );
	}

	/* Ask before deleting, from a row link, the edit screen or the bulk menu. */

	document.addEventListener( 'click', function ( event ) {
		if ( closest( event.target, 'a.mdb-delete' ) && ! window.confirm( text.confirmDelete ) ) {
			event.preventDefault();
		}
	} );

	var list = document.getElementById( 'mdb-ads-list' );
	if ( list ) {
		list.addEventListener( 'submit', function ( event ) {
			var button = event.submitter;
			var menu   = null;

			if ( button && 'doaction' === button.id ) {
				menu = list.querySelector( 'select[name="action"]' );
			} else if ( button && 'doaction2' === button.id ) {
				menu = list.querySelector( 'select[name="action2"]' );
			}
			if ( ! menu || 'delete' !== menu.value ) {
				return;
			}

			var selected = list.querySelectorAll( 'input[name="ad[]"]:checked' ).length;
			if ( selected && ! window.confirm( text.confirmBulkDelete.replace( '%d', selected ) ) ) {
				event.preventDefault();
			}
		} );
	}

	/* Link type: adjust the box to Website, Phone call or No link. */

	function updateLinkField( wrapper ) {
		var type  = wrapper.querySelector( '.mdb-link-type' ).value;
		var input = wrapper.querySelector( '.mdb-link-value' );

		input.hidden      = 'none' === type;
		input.required    = 'none' !== type;
		input.inputMode   = 'tel' === type ? 'tel' : 'url';
		input.placeholder = 'tel' === type ? text.placeholderTel : text.placeholderUrl;
	}

	document.addEventListener( 'change', function ( event ) {
		var wrapper = closest( event.target, '.mdb-link' );
		if ( wrapper && event.target.classList.contains( 'mdb-link-type' ) ) {
			updateLinkField( wrapper );
			wrapper.querySelector( '.mdb-link-value' ).focus();
		}
	} );

	each( document.querySelectorAll( '.mdb-link' ), updateLinkField );

	/* Add New: add and remove ad rows. */

	var rows      = document.getElementById( 'mdb-add-rows' );
	var template  = document.getElementById( 'mdb-row-template' );
	var addButton = document.getElementById( 'mdb-add-row' );

	if ( rows && template && addButton ) {
		var next = rows.querySelectorAll( '.mdb-row' ).length;

		var renumber = function () {
			var all = rows.querySelectorAll( '.mdb-row' );
			each( all, function ( row, i ) {
				row.querySelector( '.mdb-row-number' ).textContent = i + 1;
			} );
			rows.classList.toggle( 'mdb-single', all.length < 2 );
		};

		addButton.addEventListener( 'click', function () {
			rows.insertAdjacentHTML( 'beforeend', template.innerHTML.replace( /__i__/g, String( next++ ) ) );

			var row = rows.lastElementChild;
			each( row.querySelectorAll( '.mdb-link' ), updateLinkField );
			renumber();
			row.querySelector( 'input[type="text"]' ).focus();
		} );

		rows.addEventListener( 'click', function ( event ) {
			var button = closest( event.target, '.mdb-row-remove' );
			if ( button ) {
				closest( button, '.mdb-row' ).remove();
				renumber();
			}
		} );

		renumber();
	}

	/* Preview a newly chosen image; clearing the choice brings back what was there. */

	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( ! input.classList || ! input.classList.contains( 'mdb-image-input' ) ) {
			return;
		}

		var field   = closest( input, '.mdb-image-field' );
		var preview = field ? field.querySelector( '.mdb-image-preview' ) : null;
		if ( ! preview ) {
			return;
		}

		if ( undefined === preview.dataset.initial ) {
			preview.dataset.initial = preview.innerHTML;
		}

		var file = input.files && input.files[ 0 ];
		if ( ! file ) {
			preview.innerHTML = preview.dataset.initial;
			return;
		}

		var image = document.createElement( 'img' );
		image.alt = '';
		image.src = URL.createObjectURL( file );
		image.onload = function () {
			URL.revokeObjectURL( image.src );
		};
		preview.innerHTML = '';
		preview.appendChild( image );
	} );

	/* Schedule: the end has to come after the start. */

	document.addEventListener( 'change', function ( event ) {
		var group = closest( event.target, '.mdb-dates' );
		if ( ! group ) {
			return;
		}

		var start = group.querySelector( '.mdb-start' );
		var end   = group.querySelector( '.mdb-end' );

		end.min = start.value;
		end.setCustomValidity( start.value && end.value && end.value <= start.value ? text.endBeforeStart : '' );
	} );

	/* Shortcode boxes: select the whole shortcode so it can be copied in one go. */

	function selectShortcode( event ) {
		if ( event.target.classList && event.target.classList.contains( 'mdb-shortcode' ) ) {
			event.target.select();
		}
	}

	document.addEventListener( 'focusin', selectShortcode );
	document.addEventListener( 'click', selectShortcode );
}() );
