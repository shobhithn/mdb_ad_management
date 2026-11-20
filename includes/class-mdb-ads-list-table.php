<?php
/**
 * The ads list: search, status filters, sortable columns, pagination and bulk actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MDB_Ads_List_Table extends WP_List_Table {

	public function __construct() {
		// Prefixed names on purpose: ad blockers hide elements with a plain "ads" class, even in wp-admin.
		parent::__construct(
			array(
				'singular' => 'mdb_ad',
				'plural'   => 'mdb_ads',
				'ajax'     => false,
			)
		);
	}

	public function current_view() {
		$view = MDB_Ads_Admin::request_key( 'ad_status' );
		return in_array( $view, MDB_Ads_DB::VIEWS, true ) ? $view : 'all';
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'mdb_ads_per_page', 20 );
		$orderby  = MDB_Ads_Admin::request_key( 'orderby' );

		list( $this->items, $total ) = MDB_Ads_DB::query(
			array(
				'search'   => MDB_Ads_Admin::request_text( 's' ),
				'view'     => $this->current_view(),
				'orderby'  => '' !== $orderby ? $orderby : 'id',
				'order'    => MDB_Ads_Admin::request_key( 'order' ),
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'image'       => __( 'Image', 'mdb-ad-management' ),
			'title'       => __( 'Title', 'mdb-ad-management' ),
			'link'        => __( 'Link', 'mdb-ad-management' ),
			'status'      => __( 'Status', 'mdb-ad-management' ),
			'schedule'    => __( 'Schedule', 'mdb-ad-management' ),
			'impressions' => __( 'Impressions', 'mdb-ad-management' ),
			'clicks'      => __( 'Clicks', 'mdb-ad-management' ),
			'ctr'         => __( 'CTR', 'mdb-ad-management' ),
			'shortcode'   => __( 'Shortcode', 'mdb-ad-management' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'       => array( 'title', false ),
			'status'      => array( 'status', false ),
			'schedule'    => array( 'start_date', false ),
			'impressions' => array( 'impressions', true ),
			'clicks'      => array( 'clicks', true ),
			'ctr'         => array( 'ctr', true ),
		);
	}

	protected function get_primary_column_name() {
		return 'title';
	}

	protected function get_bulk_actions() {
		return array(
			'activate'   => __( 'Activate', 'mdb-ad-management' ),
			'deactivate' => __( 'Deactivate', 'mdb-ad-management' ),
			'reset'      => __( 'Reset statistics', 'mdb-ad-management' ),
			'delete'     => __( 'Delete', 'mdb-ad-management' ),
		);
	}

	protected function get_views() {
		$labels = array(
			'all'       => __( 'All', 'mdb-ad-management' ),
			'active'    => __( 'Active', 'mdb-ad-management' ),
			'scheduled' => __( 'Scheduled', 'mdb-ad-management' ),
			'expired'   => __( 'Expired', 'mdb-ad-management' ),
			'inactive'  => __( 'Inactive', 'mdb-ad-management' ),
			'broken'    => __( 'Broken links', 'mdb-ad-management' ),
		);

		$counts  = MDB_Ads_DB::counts();
		$current = $this->current_view();
		$base    = admin_url( 'admin.php?page=' . MDB_Ads_Admin::SLUG );
		$views   = array();

		foreach ( $labels as $view => $label ) {
			if ( 'all' !== $view && ! $counts[ $view ] && $current !== $view ) {
				continue;
			}

			$classes = array();
			if ( $current === $view ) {
				$classes[] = 'current';
			}
			if ( 'broken' === $view ) {
				$classes[] = 'mdb-broken';
			}

			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( 'all' === $view ? $base : add_query_arg( 'ad_status', $view, $base ) ),
				( $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '' ) . ( $current === $view ? ' aria-current="page"' : '' ),
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $view ] ) )
			);
		}

		return $views;
	}

	public function no_items() {
		if ( '' !== MDB_Ads_Admin::request_text( 's' ) || 'all' !== $this->current_view() ) {
			esc_html_e( 'No ads match.', 'mdb-ad-management' );
			return;
		}

		printf(
			/* translators: %s: link to the Add New screen */
			esc_html__( 'No ads yet. %s', 'mdb-ad-management' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . MDB_Ads_Admin::SLUG_NEW ) ) . '">' . esc_html__( 'Add your first ad', 'mdb-ad-management' ) . '</a>'
		);
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="mdb-ad-cb-%1$d">%2$s</label><input type="checkbox" id="mdb-ad-cb-%1$d" name="ad[]" value="%1$d" />',
			(int) $item->id,
			/* translators: %s: ad title */
			esc_html( sprintf( __( 'Select %s', 'mdb-ad-management' ), $item->title ) )
		);
	}

	protected function column_image( $item ) {
		return sprintf(
			'<a href="%1$s" class="mdb-thumb" data-lightbox="mdb-ad-%3$d"><img src="%1$s" alt="%2$s" loading="lazy" /></a>',
			esc_url( $item->image_url ),
			esc_attr( $item->title ),
			(int) $item->id
		);
	}

	protected function column_title( $item ) {
		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>',
			esc_url( MDB_Ads_Admin::edit_url( $item->id ) ),
			esc_html( '' !== $item->title ? $item->title : __( '(no title)', 'mdb-ad-management' ) )
		);
	}

	protected function column_link( $item ) {
		$url = (string) $item->link_url;

		if ( '' === $url ) {
			return '<span class="mdb-muted">' . esc_html__( 'No link', 'mdb-ad-management' ) . '</span>';
		}

		if ( MDB_Ads_DB::link_is_broken( $url ) ) {
			// Show what was actually typed ("LOTUS TRAVEL 9538538313"), not the mangled URL.
			$typed = rawurldecode( preg_replace( '#^https?://#i', '', $url ) );

			return sprintf(
				'<a href="%1$s" class="mdb-broken"><span class="dashicons dashicons-warning" aria-hidden="true"></span>%2$s</a><br /><span class="mdb-muted">%3$s</span>',
				esc_url( MDB_Ads_Admin::edit_url( $item->id ) ),
				esc_html__( 'Broken link, fix it', 'mdb-ad-management' ),
				esc_html( wp_html_excerpt( $typed, 45, '…' ) )
			);
		}

		if ( 'tel' === MDB_Ads_Admin::link_type( $url ) ) {
			return sprintf(
				'<span class="dashicons dashicons-phone" aria-hidden="true"></span> <a href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html( substr( $url, 4 ) )
			);
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr( $url ),
			esc_html( wp_html_excerpt( preg_replace( '#^https?://(www\.)?#i', '', $url ), 45, '…' ) )
		);
	}

	protected function column_status( $item ) {
		return MDB_Ads_Admin::status_badge( $item );
	}

	protected function column_schedule( $item ) {
		if ( empty( $item->start_date ) && empty( $item->end_date ) ) {
			return '<span class="mdb-muted">' . esc_html__( 'Always on', 'mdb-ad-management' ) . '</span>';
		}

		$lines = array();
		if ( ! empty( $item->start_date ) ) {
			/* translators: %s: date and time */
			$lines[] = esc_html( sprintf( __( 'From %s', 'mdb-ad-management' ), MDB_Ads_Admin::format_date( $item->start_date ) ) );
		}
		if ( ! empty( $item->end_date ) ) {
			/* translators: %s: date and time */
			$lines[] = esc_html( sprintf( __( 'Until %s', 'mdb-ad-management' ), MDB_Ads_Admin::format_date( $item->end_date ) ) );
		}
		return implode( '<br />', $lines );
	}

	protected function column_impressions( $item ) {
		return esc_html( number_format_i18n( (int) $item->impressions ) );
	}

	protected function column_clicks( $item ) {
		return esc_html( number_format_i18n( (int) $item->clicks ) );
	}

	protected function column_ctr( $item ) {
		return esc_html( MDB_Ads_Admin::ctr( $item ) );
	}

	protected function column_shortcode( $item ) {
		return MDB_Ads_Admin::shortcode_field( $item->id );
	}

	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$toggle = (int) $item->status ? 'deactivate' : 'activate';

		return $this->row_actions(
			array(
				'edit'   => sprintf(
					'<a href="%s">%s</a>',
					esc_url( MDB_Ads_Admin::edit_url( $item->id ) ),
					esc_html__( 'Edit', 'mdb-ad-management' )
				),
				$toggle  => sprintf(
					'<a href="%s">%s</a>',
					esc_url( MDB_Ads_Admin::action_url( $toggle, $item->id ) ),
					'activate' === $toggle ? esc_html__( 'Activate', 'mdb-ad-management' ) : esc_html__( 'Deactivate', 'mdb-ad-management' )
				),
				'delete' => sprintf(
					'<a href="%s" class="submitdelete mdb-delete">%s</a>',
					esc_url( MDB_Ads_Admin::action_url( 'delete', $item->id ) ),
					esc_html__( 'Delete', 'mdb-ad-management' )
				),
			)
		);
	}
}
