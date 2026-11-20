<?php
/**
 * Page-cache clearing. Cached pages keep showing old ads, so the full-page cache is cleared
 * whenever ads or settings change, and again when a scheduled ad starts or ends.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDB_Ads_Cache {

	const CRON_HOOK = 'mdb_ads_purge_cache';

	public static function init() {
		// Not admin-only: scheduled clears run from WP-Cron.
		add_action( self::CRON_HOOK, array( __CLASS__, 'purge' ) );
		add_action( 'add_option_' . MDB_Ads_Settings::OPTION, array( __CLASS__, 'purge' ) );
		add_action( 'update_option_' . MDB_Ads_Settings::OPTION, array( __CLASS__, 'purge' ) );
	}

	/**
	 * Clears every known full-page cache. Each call is skipped when that cache isn't installed.
	 *
	 * @return string[] Names of the caches that were cleared.
	 */
	public static function purge() {
		$cleared = array();

		$functions = array(
			'WP Rocket'          => 'rocket_clean_domain',
			'W3 Total Cache'     => 'w3tc_flush_all',
			'WP Super Cache'     => 'wp_cache_clear_cache',
			'SiteGround'         => 'sg_cachepress_purge_cache',
		);
		foreach ( $functions as $name => $function ) {
			if ( function_exists( $function ) ) {
				call_user_func( $function );
				$cleared[] = $name;
			}
		}

		// Caches that listen for an action; has_action() tells whether one is installed.
		$actions = array(
			'LiteSpeed Cache'    => 'litespeed_purge_all',
			'WP Fastest Cache'   => 'wpfc_clear_all_cache',
			'Breeze'             => 'breeze_clear_all_cache',
			'Hummingbird'        => 'wphb_clear_page_cache',
			'Cache Enabler'      => 'cache_enabler_clear_complete_cache',
			'Nginx Helper'       => 'rt_nginx_helper_purge_all',
		);
		foreach ( $actions as $name => $action ) {
			if ( has_action( $action ) ) {
				do_action( $action );
				$cleared[] = $name;
			}
		}

		if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ) ) {
			comet_cache::clear();
			$cleared[] = 'Comet Cache';
		}

		if ( class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'instance' ) ) {
			$wpo = WPO_Page_Cache::instance();
			if ( is_object( $wpo ) && method_exists( $wpo, 'purge' ) ) {
				$wpo->purge();
				$cleared[] = 'WP-Optimize';
			}
		}

		if ( class_exists( 'WpeCommon' ) ) {
			foreach ( array( 'purge_memcached', 'purge_varnish_cache' ) as $method ) {
				if ( method_exists( 'WpeCommon', $method ) ) {
					call_user_func( array( 'WpeCommon', $method ) );
				}
			}
			$cleared[] = 'WP Engine';
		}

		/**
		 * Fires after ads changed and known page caches were cleared. Hook in to clear any other cache.
		 */
		do_action( 'mdb_ads_cache_purged', $cleared );

		return $cleared;
	}

	/**
	 * Schedules cache clears for the moments an ad starts and ends, replacing earlier ones.
	 *
	 * @param int         $id    Ad ID.
	 * @param string|null $start Start date (site timezone), or null.
	 * @param string|null $end   End date (site timezone), or null.
	 */
	public static function schedule( $id, $start, $end ) {
		foreach ( array( 'start' => $start, 'end' => $end ) as $which => $date ) {
			$args = array( (int) $id, $which );
			wp_clear_scheduled_hook( self::CRON_HOOK, $args );

			if ( ! $date ) {
				continue;
			}

			$time = ( new DateTime( $date, wp_timezone() ) )->getTimestamp();
			if ( $time > time() ) {
				wp_schedule_single_event( $time, self::CRON_HOOK, $args );
			}
		}
	}

	public static function unschedule( $id ) {
		self::schedule( $id, null, null );
	}

	/**
	 * Sentence for the admin notice after a change.
	 *
	 * @param string[] $cleared Result of purge().
	 */
	public static function notice( array $cleared ) {
		if ( $cleared ) {
			/* translators: %s: cache plugin names, e.g. WP Rocket */
			return sprintf( __( 'The page cache (%s) was cleared, so visitors see the change now.', 'mdb-ad-management' ), implode( ', ', $cleared ) );
		}
		return __( 'If your site uses a page cache, clear it so visitors see the change.', 'mdb-ad-management' );
	}
}
