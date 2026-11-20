/**
 * MDB Ad Management: counts ad impressions and clicks.
 *
 * Links open on their own; counting runs alongside and never delays or blocks them.
 * Works with the ad markup of version 1 too, so pages cached before the update keep counting.
 */
( function () {
	'use strict';

	var config = window.mdb_ad_management_ajax;
	if ( ! config || ! config.ajaxurl ) {
		return;
	}

	function send( event, ids ) {
		var data = new FormData();
		data.append( 'action', 'mdb_ads_track' );
		data.append( 'event', event );
		data.append( 'ids', ids.join( ',' ) );

		// sendBeacon is still delivered after the visitor leaves the page, which matters for clicks.
		if ( navigator.sendBeacon && navigator.sendBeacon( config.ajaxurl, data ) ) {
			return;
		}
		if ( window.fetch ) {
			window.fetch( config.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true } )
				.catch( function () {} );
		}
	}

	function adId( element ) {
		if ( element.hasAttribute( 'data-ad-id' ) ) {
			return element.getAttribute( 'data-ad-id' );
		}
		var inner = element.querySelector( '[data-ad-id]' );
		return inner ? inner.getAttribute( 'data-ad-id' ) : '';
	}

	/* Impressions: each ad counts once per page view, when at least half of it is on screen. */

	var queue = [];
	var timer = null;

	function flush() {
		clearTimeout( timer );
		timer = null;
		if ( queue.length ) {
			send( 'view', queue.splice( 0, queue.length ) );
		}
	}

	function seen( ad ) {
		var id = adId( ad );
		if ( id ) {
			queue.push( id );
			timer = timer || setTimeout( flush, 1000 );
		}
	}

	function watchAds() {
		var ads = document.querySelectorAll( '.mdb-ad' );
		if ( ! ads.length ) {
			return;
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			Array.prototype.forEach.call( ads, seen );
			return;
		}

		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				// An ad taller than the screen counts once it fills half the screen.
				var visible = entry.intersectionRatio >= 0.5 ||
					entry.intersectionRect.height >= window.innerHeight * 0.5;

				if ( entry.isIntersecting && visible ) {
					observer.unobserve( entry.target );
					seen( entry.target );
				}
			} );
		}, { threshold: [ 0, 0.25, 0.5 ] } );

		Array.prototype.forEach.call( ads, function ( ad ) {
			observer.observe( ad );
		} );
	}

	/* Clicks: left click and middle click (open in new tab). */

	function onClick( event ) {
		if ( 'auxclick' === event.type && 1 !== event.button ) {
			return;
		}

		var link = event.target.closest ? event.target.closest( '.mdb-ad a' ) : null;
		if ( ! link ) {
			return;
		}

		var id = adId( link ) || adId( link.closest( '.mdb-ad' ) );
		if ( id ) {
			send( 'click', [ id ] );
		}
	}

	document.addEventListener( 'click', onClick );
	document.addEventListener( 'auxclick', onClick );
	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			flush();
		}
	} );
	window.addEventListener( 'pagehide', flush );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', watchAds );
	} else {
		watchAds();
	}
}() );
