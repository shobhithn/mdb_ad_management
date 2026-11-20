<?php
/**
 * Database layer: the ads table schema, upgrades, and every query against it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDB_Ads_DB {

	/** Filters shown above the ads list. */
	const VIEWS = array( 'all', 'active', 'scheduled', 'expired', 'inactive', 'broken' );

	/**
	 * A link that works: tel:, a site-relative path, or http(s) with a real host name.
	 * Written to mean the same in PHP (PCRE) and in MySQL/MariaDB REGEXP.
	 * Version 1 accepted anything, so phone numbers were saved as "http://LOTUS%20TRAVEL%209538538313".
	 */
	const LINK_PATTERN = '^(tel:|/|https?://[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+(:[0-9]+)?([/?#]|$))';

	/** Sort keys the list may request, mapped to SQL. */
	const SORTABLE = array(
		'id'          => 'id',
		'title'       => 'title',
		'status'      => 'status',
		'start_date'  => 'start_date',
		'impressions' => 'impressions',
		'clicks'      => 'clicks',
		'ctr'         => 'ctr',
	);

	/** Columns added in version 2. */
	const NEW_COLUMNS = array( 'impressions', 'start_date', 'end_date' );

	const CTR_SQL = '(CASE WHEN impressions > 0 THEN clicks / impressions ELSE 0 END)';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mdb_ads';
	}

	/**
	 * Creates the table, or adds the version 2 columns to an existing one.
	 *
	 * The version 1 columns are declared exactly as before so dbDelta leaves them and their
	 * data untouched; it only adds what is missing and never drops anything.
	 *
	 * @return bool Whether the table now has every column.
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is strict: one column per line and two spaces after PRIMARY KEY.
		$sql = "CREATE TABLE {$table} (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			title text NOT NULL,
			image_url text NOT NULL,
			link_url text NOT NULL,
			status tinyint(1) NOT NULL DEFAULT 1,
			clicks mediumint(9) NOT NULL DEFAULT 0,
			impressions bigint(20) unsigned NOT NULL DEFAULT 0,
			start_date datetime DEFAULT NULL,
			end_date datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( array_diff( self::NEW_COLUMNS, $columns ) ) {
			return false;
		}

		update_option( 'mdb_ads_db_version', MDB_ADS_DB_VERSION );
		delete_transient( 'mdb_ads_upgrade_failed' );
		return true;
	}

	/**
	 * Runs the upgrade once after the plugin files are replaced. A failed attempt is retried
	 * at most once an hour; until it succeeds, ads keep showing with the version 1 query.
	 */
	public static function maybe_upgrade() {
		if ( self::is_current() || get_transient( 'mdb_ads_upgrade_failed' ) ) {
			return;
		}

		if ( ! self::install() ) {
			global $wpdb;
			set_transient( 'mdb_ads_upgrade_failed', $wpdb->last_error ? $wpdb->last_error : 'unknown error', HOUR_IN_SECONDS );
		}
	}

	public static function is_current() {
		return get_option( 'mdb_ads_db_version' ) === MDB_ADS_DB_VERSION;
	}

	/**
	 * Where an ad stands right now: active, inactive, scheduled (not started yet) or expired.
	 *
	 * @param object $ad Row from the ads table.
	 * @return string
	 */
	public static function state( $ad ) {
		if ( ! (int) $ad->status ) {
			return 'inactive';
		}

		$now = current_time( 'mysql' );

		if ( ! empty( $ad->start_date ) && $ad->start_date > $now ) {
			return 'scheduled';
		}
		if ( ! empty( $ad->end_date ) && $ad->end_date <= $now ) {
			return 'expired';
		}
		return 'active';
	}

	/**
	 * SQL condition matching the ads in one view. Dates are stored in the site's timezone.
	 *
	 * @param string $view One of self::VIEWS.
	 * @return string
	 */
	private static function view_sql( $view ) {
		global $wpdb;

		if ( 'broken' === $view ) {
			return $wpdb->prepare( "link_url <> '' AND link_url NOT REGEXP %s", self::LINK_PATTERN );
		}

		if ( ! self::is_current() ) {
			// The date columns do not exist yet: behave exactly like version 1.
			return 'active' === $view ? 'status = 1' : ( 'inactive' === $view ? 'status = 0' : ( 'all' === $view ? '1=1' : '1=0' ) );
		}

		$now = current_time( 'mysql' );

		switch ( $view ) {
			case 'active':
				return $wpdb->prepare(
					'status = 1 AND (start_date IS NULL OR start_date <= %s) AND (end_date IS NULL OR end_date > %s)',
					$now,
					$now
				);
			case 'scheduled':
				return $wpdb->prepare( 'status = 1 AND start_date > %s', $now );
			case 'expired':
				return $wpdb->prepare( 'status = 1 AND end_date <= %s', $now );
			case 'inactive':
				return 'status = 0';
		}
		return '1=1';
	}

	/**
	 * Turns a list of IDs into a safe comma-separated string for IN (...).
	 *
	 * @param array $ids Raw IDs.
	 * @return string Empty when no valid ID is left.
	 */
	private static function id_list( array $ids ) {
		return implode( ',', array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * One page of ads for the admin list.
	 *
	 * @param array $args search, view, orderby, order, per_page, page.
	 * @return array [ rows, total matching rows ]
	 */
	public static function query( array $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'view'     => 'all',
				'orderby'  => 'id',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$table = self::table();
		$where = self::view_sql( $args['view'] );

		if ( '' !== $args['search'] ) {
			$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND (title LIKE %s OR link_url LIKE %s)', $like, $like );
		}

		$orderby  = isset( self::SORTABLE[ $args['orderby'] ] ) ? self::SORTABLE[ $args['orderby'] ] : 'id';
		$order    = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$ctr      = self::CTR_SQL;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results(
			"SELECT *, {$ctr} AS ctr FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT {$per_page} OFFSET {$offset}"
		);

		return array( (array) $rows, $total );
	}

	/**
	 * Number of ads in each view.
	 *
	 * @return int[] Keyed by view name.
	 */
	public static function counts() {
		global $wpdb;

		static $counts = null;
		if ( null !== $counts ) {
			return $counts;
		}

		$table   = self::table();
		$columns = array();
		foreach ( self::VIEWS as $view ) {
			$columns[] = 'SUM(CASE WHEN ' . self::view_sql( $view ) . " THEN 1 ELSE 0 END) AS `{$view}`";
		}

		$row = $wpdb->get_row( 'SELECT ' . implode( ', ', $columns ) . " FROM {$table}", ARRAY_A );

		$counts = array_map( 'intval', wp_parse_args( (array) $row, array_fill_keys( self::VIEWS, 0 ) ) );
		return $counts;
	}

	/**
	 * Whether a saved link leads nowhere. An empty link is fine: the ad is shown without one.
	 */
	public static function link_is_broken( $url ) {
		return '' !== (string) $url && ! preg_match( '~' . self::LINK_PATTERN . '~i', $url );
	}

	/**
	 * Ads that should be showing right now, in random order.
	 *
	 * @param int   $limit Maximum number of ads; 0 for no limit.
	 * @param int[] $ids   Only consider these ads.
	 * @return object[]
	 */
	public static function get_live( $limit = 0, array $ids = array() ) {
		global $wpdb;

		$table = self::table();
		$sql   = "SELECT * FROM {$table} WHERE " . self::view_sql( 'active' );

		if ( $ids ) {
			$list = self::id_list( $ids );
			if ( '' === $list ) {
				return array();
			}
			$sql .= " AND id IN ({$list})";
		}

		$sql .= ' ORDER BY RAND()';

		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}

		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * @param array $data Column => value.
	 * @return int|false New ad ID.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		return $wpdb->insert( self::table(), $data ) ? (int) $wpdb->insert_id : false;
	}

	/**
	 * @return int|false Rows changed, or false on a database error.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		return $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function set_status( array $ids, $status ) {
		self::update_many( $ids, 'status = ' . ( $status ? 1 : 0 ) );
	}

	public static function reset_stats( array $ids ) {
		self::update_many( $ids, 'clicks = 0, impressions = 0' );
	}

	/**
	 * Adds one to the impressions or clicks of each ad.
	 *
	 * @param string $column 'impressions' or 'clicks'.
	 * @param int[]  $ids    Ad IDs.
	 */
	public static function increment( $column, array $ids ) {
		if ( in_array( $column, array( 'impressions', 'clicks' ), true ) ) {
			self::update_many( $ids, "{$column} = {$column} + 1" );
		}
	}

	/**
	 * Runs an UPDATE with a fixed, trusted SET clause against a list of IDs.
	 */
	private static function update_many( array $ids, $set ) {
		global $wpdb;

		$list = self::id_list( $ids );
		if ( '' === $list ) {
			return;
		}

		$table = self::table();
		$wpdb->query( "UPDATE {$table} SET {$set} WHERE id IN ({$list})" );
	}

	/**
	 * Whether any ad still uses this image.
	 */
	public static function image_in_use( $url ) {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE image_url = %s", $url ) );
	}
}
