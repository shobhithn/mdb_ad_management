<?php
/**
 * Front end: inserting ads into posts, the [mdb_ad] shortcode, and impression/click tracking.
 *
 * The markup, CSS classes, script handles and file names match version 1, so pages cached
 * before the update keep working and keep counting clicks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDB_Ads_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'insert_into_content' ) );
		add_shortcode( 'mdb_ad', array( __CLASS__, 'shortcode' ) );

		add_action( 'wp_ajax_mdb_ads_track', array( __CLASS__, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_mdb_ads_track', array( __CLASS__, 'ajax_track' ) );

		// Version 1 click endpoint, still called by browsers holding the old frontend.js.
		add_action( 'wp_ajax_track_ad_click', array( __CLASS__, 'ajax_legacy_click' ) );
		add_action( 'wp_ajax_nopriv_track_ad_click', array( __CLASS__, 'ajax_legacy_click' ) );
	}

	/**
	 * Same handles, files and pages as version 1. jQuery and the lightbox stay loaded on every
	 * page as before, because the theme or post content may already rely on them.
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( 'lightbox', MDB_ADS_URL . 'lightbox.css', array(), MDB_ADS_VERSION );
		wp_enqueue_script( 'lightbox', MDB_ADS_URL . 'lightbox.js', array( 'jquery' ), MDB_ADS_VERSION, true );
		wp_enqueue_script( 'mdb-ad-management-frontend-js', MDB_ADS_URL . 'frontend.js', array( 'jquery' ), MDB_ADS_VERSION, true );
		wp_localize_script(
			'mdb-ad-management-frontend-js',
			'mdb_ad_management_ajax',
			array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) )
		);
	}

	/**
	 * Places ads between the paragraphs of single posts.
	 */
	public static function insert_into_content( $content ) {
		if ( ! is_single() || ! MDB_Ads_Settings::get( 'auto_insert' ) ) {
			return $content;
		}

		$ads = MDB_Ads_DB::get_live( (int) MDB_Ads_Settings::get( 'max_per_post' ) );
		if ( ! $ads ) {
			return $content;
		}

		// Split right after each closing </p>, so an ad never ends up inside a paragraph.
		$parts = preg_split( '#(?<=</p>)#i', $content );
		$slots = array();
		foreach ( $parts as $index => $part ) {
			if ( preg_match( '#</p>$#i', $part ) ) {
				$slots[] = $index;
			}
		}

		if ( ! $slots ) {
			return $content . self::render_ads( $ads );
		}

		// Spread the ads over random paragraphs; with more ads than paragraphs, some share one.
		shuffle( $slots );
		$placed = array();
		foreach ( array_values( $ads ) as $n => $ad ) {
			$placed[ $slots[ $n % count( $slots ) ] ][] = $ad;
		}
		foreach ( $placed as $index => $slot_ads ) {
			$parts[ $index ] .= self::render_ads( $slot_ads );
		}

		return implode( '', $parts );
	}

	/**
	 * [mdb_ad]               one random active ad
	 * [mdb_ad count="3"]     three random active ads
	 * [mdb_ad id="5"]        ad 5, if it is active
	 * [mdb_ad id="5,8,9"]    those ads; add count="1" to pick one of them at random
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => '',
				'count' => '',
			),
			$atts,
			'mdb_ad'
		);

		$ids   = wp_parse_id_list( $atts['id'] );
		$limit = '' !== $atts['count'] ? max( 1, absint( $atts['count'] ) ) : ( $ids ? 0 : 1 );

		return self::render_ads( MDB_Ads_DB::get_live( $limit, $ids ) );
	}

	private static function render_ads( array $ads ) {
		return implode( '', array_map( array( __CLASS__, 'render_ad' ), $ads ) );
	}

	/**
	 * Version 1 markup. Inline styles are kept so the ads look exactly as they did.
	 */
	public static function render_ad( $ad ) {
		$label = (string) MDB_Ads_Settings::get( 'label' );
		$id    = esc_attr( $ad->id );
		$link  = (string) $ad->link_url;
		$image = '<img src="' . esc_url( $ad->image_url ) . '" alt="' . esc_attr( $ad->title ) . '" style="width: 100%;">';

		$html = '<div class="mdb-ad adlink ads_image" style="text-align: center;" data-ad-id="' . $id . '">';

		if ( '' === $link ) {
			$html .= $image;
		} elseif ( 0 === stripos( $link, 'tel:' ) ) {
			// No new tab for a phone link: it would leave an empty tab behind.
			$html .= '<a href="' . esc_url( $link ) . '" data-ad-id="' . $id . '">' . $image . '</a>';
		} else {
			$html .= '<a href="' . esc_url( $link ) . '" target="_blank" rel="sponsored noopener" data-ad-id="' . $id . '">' . $image . '</a>';
		}

		if ( '' !== $label ) {
			$html .= '<p class="mdb-ad-label" style="text-align: center; font-size: 12px;">' . esc_html( $label ) . '</p>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Records impressions or clicks sent by frontend.js.
	 *
	 * There is deliberately no nonce: pages are often served from a cache, where a nonce
	 * would expire and silently stop all counting. The only effect is a counter going up.
	 */
	public static function ajax_track() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		$ids   = isset( $_POST['ids'] ) ? wp_parse_id_list( wp_unslash( $_POST['ids'] ) ) : array();
		// phpcs:enable

		$columns = array(
			'view'  => 'impressions',
			'click' => 'clicks',
		);

		if ( ! isset( $columns[ $event ] ) || ! $ids || ! MDB_Ads_DB::is_current() ) {
			wp_send_json_error( null, 400 );
		}

		MDB_Ads_DB::increment( $columns[ $event ], array_slice( $ids, 0, 20 ) );
		wp_send_json_success();
	}

	/**
	 * Version 1 behaviour: count the click and hand back the link for the old script to open.
	 */
	public static function ajax_legacy_click() {
		$ad = MDB_Ads_DB::get( isset( $_GET['ad_id'] ) ? absint( $_GET['ad_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $ad ) {
			wp_send_json_error( 'Ad not found.' );
		}

		MDB_Ads_DB::increment( 'clicks', array( $ad->id ) );
		wp_send_json_success(
			array(
				'success'      => true,
				'redirect_url' => $ad->link_url,
			)
		);
	}
}
