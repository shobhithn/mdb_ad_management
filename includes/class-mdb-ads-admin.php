<?php
/**
 * Admin screens: the ads list, Add New, Edit and Settings, plus the handlers behind them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDB_Ads_Admin {

	const SLUG          = 'mdb-ad-management';
	const SLUG_NEW      = 'mdb-ad-management-new';
	const SLUG_SETTINGS = 'mdb-ad-management-settings';
	const CAP           = 'manage_options';

	/** Actions the list accepts, from a row link or the bulk menu. */
	const LIST_ACTIONS = array( 'activate', 'deactivate', 'reset', 'delete' );

	const ACCEPT = 'image/jpeg,image/png,image/gif,image/webp';

	/** Page hooks of the plugin's screens. */
	private static $hooks = array();

	/** @var MDB_Ads_List_Table|null */
	private static $table = null;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( 'MDB_Ads_Settings', 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_mdb_ads_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_mdb_ads_update', array( __CLASS__, 'handle_update' ) );
		add_filter( 'set_screen_option_mdb_ads_per_page', array( __CLASS__, 'save_per_page' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( MDB_ADS_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	public static function menu() {
		self::$hooks['list'] = add_menu_page(
			__( 'MDB Ad Management', 'mdb-ad-management' ),
			__( 'MDB Ad Management', 'mdb-ad-management' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_list_page' ),
			'dashicons-megaphone'
		);

		add_submenu_page(
			self::SLUG,
			__( 'All Ads', 'mdb-ad-management' ),
			__( 'All Ads', 'mdb-ad-management' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_list_page' )
		);

		self::$hooks['new'] = add_submenu_page(
			self::SLUG,
			__( 'Add New Ads', 'mdb-ad-management' ),
			__( 'Add New', 'mdb-ad-management' ),
			self::CAP,
			self::SLUG_NEW,
			array( __CLASS__, 'render_add_page' )
		);

		self::$hooks['settings'] = add_submenu_page(
			self::SLUG,
			__( 'Ad Settings', 'mdb-ad-management' ),
			__( 'Settings', 'mdb-ad-management' ),
			self::CAP,
			self::SLUG_SETTINGS,
			array( __CLASS__, 'render_settings_page' )
		);

		add_action( 'load-' . self::$hooks['list'], array( __CLASS__, 'load_list_page' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'mdb-ad-management-admin-css', MDB_ADS_URL . 'admin.css', array(), MDB_ADS_VERSION );
		wp_enqueue_style( 'lightbox', MDB_ADS_URL . 'lightbox.css', array(), MDB_ADS_VERSION );
		wp_enqueue_script( 'lightbox', MDB_ADS_URL . 'lightbox.js', array( 'jquery' ), MDB_ADS_VERSION, true );
		wp_enqueue_script( 'mdb-ad-management-admin-js', MDB_ADS_URL . 'admin.js', array(), MDB_ADS_VERSION, true );
		wp_localize_script(
			'mdb-ad-management-admin-js',
			'mdbAdsAdmin',
			array(
				'confirmDelete'     => __( 'Delete this ad and its image? This cannot be undone.', 'mdb-ad-management' ),
				/* translators: %d: number of selected ads */
				'confirmBulkDelete' => __( 'Delete the %d selected ad(s) and their images? This cannot be undone.', 'mdb-ad-management' ),
				'endBeforeStart'    => __( 'The end date must be after the start date.', 'mdb-ad-management' ),
				'placeholderUrl'    => 'https://example.com',
				'placeholderTel'    => '+91 98765 43210',
			)
		);
	}

	public static function save_per_page( $status, $option, $value ) {
		return min( 200, max( 1, absint( $value ) ) );
	}

	public static function plugin_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ) . '">' . esc_html__( 'Settings', 'mdb-ad-management' ) . '</a>'
		);
		return $links;
	}

	/* ---------------------------------------------------------------------
	 * Request and URL helpers
	 * ------------------------------------------------------------------- */

	public static function request_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		return isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : '';
	}

	public static function request_key( $key ) {
		return sanitize_key( self::request_text( $key ) );
	}

	/**
	 * URL of the ads list, keeping the current filter, search, sorting and page.
	 */
	public static function list_url( array $args = array() ) {
		$keep = array();
		foreach ( array( 'ad_status', 's', 'orderby', 'order', 'paged' ) as $key ) {
			$value = self::request_text( $key );
			if ( '' !== $value ) {
				$keep[ $key ] = rawurlencode( $value );
			}
		}

		return add_query_arg( array_merge( $keep, $args ), admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function edit_url( $id ) {
		return self::list_url(
			array(
				'action' => 'edit',
				'ad'     => (int) $id,
			)
		);
	}

	/**
	 * Signed URL for a single-ad action (activate, deactivate, reset, delete).
	 */
	public static function action_url( $action, $id ) {
		return self::list_url(
			array(
				'action'   => $action,
				'ad'       => (int) $id,
				'_wpnonce' => wp_create_nonce( 'bulk-mdb_ads' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Display helpers shared by the list and the edit screen
	 * ------------------------------------------------------------------- */

	public static function status_badge( $ad ) {
		$state  = MDB_Ads_DB::state( $ad );
		$labels = array(
			'active'    => __( 'Active', 'mdb-ad-management' ),
			'inactive'  => __( 'Inactive', 'mdb-ad-management' ),
			'scheduled' => __( 'Scheduled', 'mdb-ad-management' ),
			'expired'   => __( 'Expired', 'mdb-ad-management' ),
		);

		return sprintf( '<span class="mdb-status mdb-status-%1$s">%2$s</span>', esc_attr( $state ), esc_html( $labels[ $state ] ) );
	}

	public static function format_date( $mysql_date ) {
		return mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mysql_date );
	}

	public static function ctr( $ad ) {
		$impressions = (int) $ad->impressions;
		return $impressions > 0 ? number_format_i18n( (int) $ad->clicks / $impressions * 100, 2 ) . '%' : '—';
	}

	public static function shortcode_field( $id ) {
		return sprintf(
			'<input type="text" class="mdb-shortcode" readonly value="%s" aria-label="%s" />',
			esc_attr( '[mdb_ad id="' . (int) $id . '"]' ),
			esc_attr__( 'Shortcode', 'mdb-ad-management' )
		);
	}

	/** Converts a stored date to the value a datetime-local input expects. */
	private static function input_date( $mysql_date ) {
		return $mysql_date ? str_replace( ' ', 'T', substr( $mysql_date, 0, 16 ) ) : '';
	}

	private static function upload_hint() {
		/* translators: %s: maximum upload size, e.g. 8 MB */
		return sprintf( __( 'JPG, PNG, GIF or WebP, up to %s.', 'mdb-ad-management' ), size_format( wp_max_upload_size() ) );
	}

	private static function timezone_hint() {
		/* translators: %s: site timezone, e.g. Asia/Kolkata */
		return sprintf( __( 'Optional. Times use the site timezone (%s).', 'mdb-ad-management' ), wp_timezone_string() );
	}

	/* ---------------------------------------------------------------------
	 * Notices, carried across the redirect after each action
	 * ------------------------------------------------------------------- */

	private static function add_notice( $message, $type = 'success' ) {
		$key       = 'mdb_ads_notices_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	private static function render_notices() {
		$key     = 'mdb_ads_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		delete_transient( $key );

		if ( ! MDB_Ads_DB::is_current() && ! MDB_Ads_DB::install() ) {
			global $wpdb;
			$notices[] = array(
				'type'    => 'error',
				/* translators: %s: database error message */
				'message' => sprintf( __( 'The database update for MDB Ad Management could not finish, so scheduling and impression counts are paused. Ads still show as before. Database error: %s', 'mdb-ad-management' ), $wpdb->last_error ? $wpdb->last_error : '—' ),
			);
		}

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Ads list
	 * ------------------------------------------------------------------- */

	/**
	 * Runs before any output, so actions can redirect afterwards.
	 */
	public static function load_list_page() {
		if ( 'edit' === self::request_key( 'action' ) ) {
			return;
		}

		require_once MDB_ADS_DIR . 'includes/class-mdb-ads-list-table.php';

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'mdb_ads_per_page',
			)
		);

		self::$table = new MDB_Ads_List_Table();
		self::process_list_action();
		self::$table->prepare_items();
	}

	private static function process_list_action() {
		$action = self::$table->current_action();

		if ( ! in_array( $action, self::LIST_ACTIONS, true ) ) {
			// After a search or an empty bulk submit, drop the form's housekeeping fields from the URL.
			if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				exit;
			}
			return;
		}

		check_admin_referer( 'bulk-mdb_ads' );

		$ids   = isset( $_REQUEST['ad'] ) ? wp_parse_id_list( wp_unslash( $_REQUEST['ad'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$count = count( $ids );

		if ( ! $ids ) {
			self::add_notice( __( 'Select at least one ad first.', 'mdb-ad-management' ), 'warning' );
		} elseif ( 'delete' === $action ) {
			self::delete_ads( $ids );
		} elseif ( 'reset' === $action ) {
			MDB_Ads_DB::reset_stats( $ids );
			/* translators: %s: number of ads */
			self::add_notice( sprintf( _n( 'Statistics reset for %s ad.', 'Statistics reset for %s ads.', $count, 'mdb-ad-management' ), number_format_i18n( $count ) ) );
		} else {
			$activate = 'activate' === $action;
			MDB_Ads_DB::set_status( $ids, $activate );
			self::add_notice(
				$activate
					/* translators: %s: number of ads */
					? sprintf( _n( '%s ad activated.', '%s ads activated.', $count, 'mdb-ad-management' ), number_format_i18n( $count ) )
					/* translators: %s: number of ads */
					: sprintf( _n( '%s ad deactivated.', '%s ads deactivated.', $count, 'mdb-ad-management' ), number_format_i18n( $count ) )
			);
		}

		wp_safe_redirect( self::list_url() );
		exit;
	}

	/**
	 * Deletes ads and their image files.
	 */
	private static function delete_ads( array $ids ) {
		$deleted = 0;
		$kept    = array();

		foreach ( $ids as $id ) {
			$ad = MDB_Ads_DB::get( $id );
			if ( ! $ad || ! MDB_Ads_DB::delete( $id ) ) {
				continue;
			}

			++$deleted;

			// The row is gone first, so the "still used by another ad" check no longer counts it.
			if ( ! MDB_Ads_Images::delete( $ad->image_url ) ) {
				$kept[] = $ad->image_url;
			}
		}

		if ( $deleted ) {
			/* translators: %s: number of ads */
			self::add_notice( sprintf( _n( '%s ad and its image deleted.', '%s ads and their images deleted.', $deleted, 'mdb-ad-management' ), number_format_i18n( $deleted ) ) );
		} else {
			self::add_notice( __( 'Nothing was deleted. The ads may already be gone.', 'mdb-ad-management' ), 'error' );
		}

		if ( $kept ) {
			self::add_notice(
				/* translators: %s: list of image URLs */
				sprintf( _n( 'This image file could not be removed from the server, so delete it by hand: %s', 'These image files could not be removed from the server, so delete them by hand: %s', count( $kept ), 'mdb-ad-management' ), implode( ', ', $kept ) ),
				'warning'
			);
		}
	}

	public static function render_list_page() {
		if ( 'edit' === self::request_key( 'action' ) ) {
			self::render_edit_page( absint( self::request_text( 'ad' ) ) );
			return;
		}

		$search = self::request_text( 's' );
		$view   = self::$table->current_view();
		?>
		<div class="wrap mdb-ads">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Ads', 'mdb-ad-management' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_NEW ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'mdb-ad-management' ); ?></a>
			<?php if ( '' !== $search ) : ?>
				<span class="subtitle">
					<?php
					/* translators: %s: search term */
					printf( esc_html__( 'Search results for: %s', 'mdb-ad-management' ), '<strong>' . esc_html( $search ) . '</strong>' );
					?>
				</span>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<?php
			$broken = MDB_Ads_DB::counts()['broken'];
			if ( $broken && 'broken' !== $view ) :
				?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: number of ads */
							esc_html( _n( '%s ad has a link that does not work, usually a phone number typed where a website address belongs. Visitors who tap it get an error page.', '%s ads have links that do not work, usually phone numbers typed where a website address belongs. Visitors who tap them get an error page.', $broken, 'mdb-ad-management' ) ),
							esc_html( number_format_i18n( $broken ) )
						);
						?>
						<a href="<?php echo esc_url( add_query_arg( 'ad_status', 'broken', admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>"><?php esc_html_e( 'Show them', 'mdb-ad-management' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php self::$table->views(); ?>

			<form id="mdb-ads-list" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( 'all' !== $view ) : ?>
					<input type="hidden" name="ad_status" value="<?php echo esc_attr( $view ); ?>">
				<?php endif; ?>
				<?php
				self::$table->search_box( __( 'Search Ads', 'mdb-ad-management' ), 'mdb-ads' );
				self::$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Edit
	 * ------------------------------------------------------------------- */

	private static function render_edit_page( $id ) {
		$ad   = MDB_Ads_DB::get( $id );
		$back = self::list_url();

		if ( $ad ) {
			$link_type  = self::link_type( $ad->link_url );
			$link_value = 'tel' === $link_type ? substr( $ad->link_url, 4 ) : $ad->link_url;
			$broken     = MDB_Ads_DB::link_is_broken( $ad->link_url );
			$typed      = rawurldecode( preg_replace( '#^https?://#i', '', $ad->link_url ) );
			$found      = $broken ? self::phone_in_link( $ad->link_url ) : '';

			if ( $found ) {
				$link_type  = 'tel';
				$link_value = $found;
			}
		}
		?>
		<div class="wrap mdb-ads mdb-edit">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Edit Ad', 'mdb-ad-management' ); ?></h1>
			<a href="<?php echo esc_url( $back ); ?>" class="page-title-action"><?php esc_html_e( 'Back to all ads', 'mdb-ad-management' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<?php if ( ! $ad ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'This ad no longer exists.', 'mdb-ad-management' ); ?></p></div>
			<?php else : ?>
				<?php if ( $broken ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							/* translators: %s: the text saved as the link */
							printf( esc_html__( 'This ad\'s link does not work: "%s" is not a website address, so visitors who tap the ad get an error page.', 'mdb-ad-management' ), esc_html( $typed ) );
							echo ' ';
							if ( $found ) {
								/* translators: %s: phone number */
								printf( esc_html__( 'The phone number %s has been filled in below as a "Phone call" link. Check it, then click Update Ad.', 'mdb-ad-management' ), '<strong>' . esc_html( $found ) . '</strong>' );
							} else {
								esc_html_e( 'Enter the correct website address or phone number below, or set the link to "No link".', 'mdb-ad-management' );
							}
							?>
						</p>
					</div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="mdb_ads_update">
					<input type="hidden" name="id" value="<?php echo (int) $ad->id; ?>">
					<input type="hidden" name="_back" value="<?php echo esc_url( $back ); ?>">
					<?php wp_nonce_field( 'mdb_ads_update_' . $ad->id ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mdb-title"><?php esc_html_e( 'Title', 'mdb-ad-management' ); ?></label></th>
							<td>
								<input type="text" id="mdb-title" name="ad[title]" class="regular-text" value="<?php echo esc_attr( $ad->title ); ?>">
								<p class="description"><?php esc_html_e( 'Used to find the ad here and as the image\'s alt text.', 'mdb-ad-management' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mdb-image"><?php esc_html_e( 'Image', 'mdb-ad-management' ); ?></label></th>
							<td class="mdb-image-field">
								<div class="mdb-image-preview">
									<img src="<?php echo esc_url( $ad->image_url ); ?>" alt="<?php echo esc_attr( $ad->title ); ?>">
								</div>
								<input type="file" id="mdb-image" name="ad_image" class="mdb-image-input" accept="<?php echo esc_attr( self::ACCEPT ); ?>">
								<p class="description">
									<?php echo esc_html( self::upload_hint() ); ?>
									<?php esc_html_e( 'Leave empty to keep the current image. A new image replaces it, and the old file is deleted from the server.', 'mdb-ad-management' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mdb-link"><?php esc_html_e( 'Link', 'mdb-ad-management' ); ?></label></th>
							<td>
								<?php self::render_link_field( 'ad', 'mdb-link', $link_type, $link_value ); ?>
								<p class="description"><?php esc_html_e( 'Phone call opens the dialler when the ad is tapped on a phone.', 'mdb-ad-management' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'mdb-ad-management' ); ?></th>
							<td>
								<label><input type="checkbox" name="ad[status]" value="1" <?php checked( (int) $ad->status, 1 ); ?>> <?php esc_html_e( 'Active', 'mdb-ad-management' ); ?></label>
								<p class="description">
									<?php
									/* translators: %s: status badge, e.g. Scheduled */
									printf( esc_html__( 'Right now: %s', 'mdb-ad-management' ), self::status_badge( $ad ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge is escaped.
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Schedule', 'mdb-ad-management' ); ?></th>
							<td>
								<div class="mdb-dates">
									<p>
										<label for="mdb-start"><?php esc_html_e( 'Starts', 'mdb-ad-management' ); ?></label>
										<input type="datetime-local" id="mdb-start" name="ad[start]" class="mdb-start" value="<?php echo esc_attr( self::input_date( $ad->start_date ) ); ?>">
									</p>
									<p>
										<label for="mdb-end"><?php esc_html_e( 'Ends', 'mdb-ad-management' ); ?></label>
										<input type="datetime-local" id="mdb-end" name="ad[end]" class="mdb-end" value="<?php echo esc_attr( self::input_date( $ad->end_date ) ); ?>">
									</p>
								</div>
								<p class="description"><?php echo esc_html( self::timezone_hint() ); ?> <?php esc_html_e( 'Leave both empty to show the ad until you deactivate it.', 'mdb-ad-management' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Statistics', 'mdb-ad-management' ); ?></th>
							<td>
								<p class="mdb-stats">
									<?php
									printf(
										/* translators: 1: impressions, 2: clicks, 3: click-through rate */
										esc_html__( '%1$s impressions · %2$s clicks · %3$s CTR', 'mdb-ad-management' ),
										esc_html( number_format_i18n( (int) $ad->impressions ) ),
										esc_html( number_format_i18n( (int) $ad->clicks ) ),
										esc_html( self::ctr( $ad ) )
									);
									?>
								</p>
								<label><input type="checkbox" name="ad[reset_stats]" value="1"> <?php esc_html_e( 'Reset impressions and clicks to zero when saving', 'mdb-ad-management' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Shortcode', 'mdb-ad-management' ); ?></th>
							<td>
								<?php echo self::shortcode_field( $ad->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field is escaped. ?>
								<p class="description"><?php esc_html_e( 'Paste into a post, page or widget to show this ad there.', 'mdb-ad-management' ); ?></p>
							</td>
						</tr>
					</table>

					<div class="mdb-edit-actions">
						<?php submit_button( __( 'Update Ad', 'mdb-ad-management' ), 'primary', 'submit', false ); ?>
						<a href="<?php echo esc_url( self::action_url( 'delete', $ad->id ) ); ?>" class="submitdelete mdb-delete"><?php esc_html_e( 'Delete ad', 'mdb-ad-management' ); ?></a>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_update() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		self::check_request( 'mdb_ads_update_' . $id );

		$back = isset( $_POST['_back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['_back'] ) ), self::list_url() ) : self::list_url();
		$edit = self::edit_url( $id );
		$ad   = MDB_Ads_DB::get( $id );

		if ( ! $ad ) {
			self::add_notice( __( 'That ad no longer exists.', 'mdb-ad-management' ), 'error' );
			self::redirect( $back );
		}

		$input = isset( $_POST['ad'] ) && is_array( $_POST['ad'] ) ? wp_unslash( $_POST['ad'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field.
		$data  = self::validate( $input );

		if ( is_wp_error( $data ) ) {
			/* translators: %s: what is wrong */
			self::add_notice( sprintf( __( 'The ad was not updated. %s', 'mdb-ad-management' ), $data->get_error_message() ), 'error' );
			self::redirect( $edit );
		}

		$data['status'] = empty( $input['status'] ) ? 0 : 1;

		if ( ! empty( $input['reset_stats'] ) ) {
			$data['clicks']      = 0;
			$data['impressions'] = 0;
		}

		$file     = isset( $_FILES['ad_image'] ) ? $_FILES['ad_image'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- handled by wp_handle_upload().
		$replaced = MDB_Ads_Images::was_uploaded( $file );

		if ( $replaced ) {
			$url = MDB_Ads_Images::store( $file );
			if ( is_wp_error( $url ) ) {
				/* translators: %s: upload error */
				self::add_notice( sprintf( __( 'The ad was not updated. %s', 'mdb-ad-management' ), $url->get_error_message() ), 'error' );
				self::redirect( $edit );
			}
			$data['image_url'] = $url;
		}

		if ( false === MDB_Ads_DB::update( $id, $data ) ) {
			if ( $replaced ) {
				MDB_Ads_Images::delete( $data['image_url'] );
			}
			self::add_notice( __( 'The ad could not be saved to the database. Nothing was changed.', 'mdb-ad-management' ), 'error' );
			self::redirect( $edit );
		}

		if ( $replaced && ! MDB_Ads_Images::delete( $ad->image_url ) ) {
			/* translators: %s: image URL */
			self::add_notice( sprintf( __( 'The old image file could not be removed from the server, so delete it by hand: %s', 'mdb-ad-management' ), $ad->image_url ), 'warning' );
		}

		self::add_notice( __( 'Ad updated.', 'mdb-ad-management' ) );
		self::redirect( $back );
	}

	/* ---------------------------------------------------------------------
	 * Add New
	 * ------------------------------------------------------------------- */

	public static function render_add_page() {
		?>
		<div class="wrap mdb-ads mdb-add">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Add New Ads', 'mdb-ad-management' ); ?></h1>
			<a href="<?php echo esc_url( self::list_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Back to all ads', 'mdb-ad-management' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="mdb_ads_add">
				<?php wp_nonce_field( 'mdb_ads_add' ); ?>

				<div id="mdb-add-rows">
					<?php self::render_add_row( 0 ); ?>
				</div>

				<p><button type="button" class="button" id="mdb-add-row"><?php esc_html_e( 'Add another ad', 'mdb-ad-management' ); ?></button></p>
				<p class="description">
					<?php echo esc_html( self::upload_hint() ); ?>
					<?php esc_html_e( 'New ads start as active. The upload limit applies to all images together.', 'mdb-ad-management' ); ?>
				</p>

				<?php submit_button( __( 'Save Ads', 'mdb-ad-management' ) ); ?>
			</form>

			<template id="mdb-row-template"><?php self::render_add_row( '__i__' ); ?></template>
		</div>
		<?php
	}

	/**
	 * One ad in the Add New form.
	 *
	 * @param int|string $index Row key; '__i__' in the template that admin.js copies.
	 */
	private static function render_add_row( $index ) {
		$name = 'ads[' . $index . ']';
		$id   = 'mdb-row-' . $index;
		?>
		<div class="mdb-row">
			<div class="mdb-row-head">
				<h2 class="mdb-row-title">
					<?php
					/* translators: %s: ad number in the form */
					printf( esc_html__( 'Ad %s', 'mdb-ad-management' ), '<span class="mdb-row-number">1</span>' );
					?>
				</h2>
				<button type="button" class="button-link mdb-row-remove"><?php esc_html_e( 'Remove', 'mdb-ad-management' ); ?></button>
			</div>
			<div class="mdb-row-body">
				<div class="mdb-image-field">
					<div class="mdb-image-preview"><span><?php esc_html_e( 'No image selected', 'mdb-ad-management' ); ?></span></div>
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-image"><?php esc_html_e( 'Image', 'mdb-ad-management' ); ?></label>
					<input type="file" id="<?php echo esc_attr( $id ); ?>-image" name="ad_images[<?php echo esc_attr( $index ); ?>]" class="mdb-image-input" accept="<?php echo esc_attr( self::ACCEPT ); ?>" required>
				</div>
				<div class="mdb-fields">
					<p>
						<label for="<?php echo esc_attr( $id ); ?>-title"><?php esc_html_e( 'Title', 'mdb-ad-management' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id ); ?>-title" name="<?php echo esc_attr( $name ); ?>[title]" class="widefat">
					</p>
					<p>
						<label for="<?php echo esc_attr( $id ); ?>-link"><?php esc_html_e( 'Link', 'mdb-ad-management' ); ?></label>
						<?php self::render_link_field( $name, $id . '-link' ); ?>
					</p>
					<div class="mdb-dates">
						<p>
							<label for="<?php echo esc_attr( $id ); ?>-start"><?php esc_html_e( 'Starts', 'mdb-ad-management' ); ?></label>
							<input type="datetime-local" id="<?php echo esc_attr( $id ); ?>-start" name="<?php echo esc_attr( $name ); ?>[start]" class="mdb-start">
						</p>
						<p>
							<label for="<?php echo esc_attr( $id ); ?>-end"><?php esc_html_e( 'Ends', 'mdb-ad-management' ); ?></label>
							<input type="datetime-local" id="<?php echo esc_attr( $id ); ?>-end" name="<?php echo esc_attr( $name ); ?>[end]" class="mdb-end">
						</p>
					</div>
					<p class="description"><?php echo esc_html( self::timezone_hint() ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	public static function handle_add() {
		self::check_request( 'mdb_ads_add' );

		$rows   = isset( $_POST['ads'] ) && is_array( $_POST['ads'] ) ? wp_unslash( $_POST['ads'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field.
		$files  = self::uploaded_files( 'ad_images' );
		$added  = 0;
		$failed = 0;
		$number = 0;

		foreach ( $rows as $key => $row ) {
			++$number;

			$row  = is_array( $row ) ? $row : array();
			$file = isset( $files[ $key ] ) ? $files[ $key ] : array();

			// A row left completely blank is ignored.
			$typed = self::field( $row, 'title' ) . self::field( $row, 'link' ) . self::field( $row, 'start' ) . self::field( $row, 'end' );
			if ( '' === $typed && ! MDB_Ads_Images::was_uploaded( $file ) ) {
				continue;
			}

			$data = self::validate( $row );
			$url  = is_wp_error( $data ) ? $data : MDB_Ads_Images::store( $file );

			if ( is_wp_error( $url ) ) {
				++$failed;
				/* translators: 1: ad number in the form, 2: what is wrong */
				self::add_notice( sprintf( __( 'Ad %1$d was not saved. %2$s', 'mdb-ad-management' ), $number, $url->get_error_message() ), 'error' );
				continue;
			}

			$data['image_url'] = $url;
			$data['status']    = 1;

			if ( ! MDB_Ads_DB::insert( $data ) ) {
				MDB_Ads_Images::delete( $url );
				++$failed;
				/* translators: %d: ad number in the form */
				self::add_notice( sprintf( __( 'Ad %d could not be saved to the database.', 'mdb-ad-management' ), $number ), 'error' );
				continue;
			}

			++$added;
		}

		if ( $added ) {
			/* translators: %s: number of ads */
			self::add_notice( sprintf( _n( '%s ad added.', '%s ads added.', $added, 'mdb-ad-management' ), number_format_i18n( $added ) ) );
			self::redirect( self::list_url() );
		}

		if ( ! $failed ) {
			self::add_notice( __( 'Fill in at least one ad.', 'mdb-ad-management' ), 'warning' );
		}
		self::redirect( admin_url( 'admin.php?page=' . self::SLUG_NEW ) );
	}

	/**
	 * Splits a multi-file input ($_FILES[field][part][key]) into one $_FILES-style array per key.
	 */
	private static function uploaded_files( $field ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- nonce checked by the caller; files go through wp_handle_upload().
		if ( empty( $_FILES[ $field ]['name'] ) || ! is_array( $_FILES[ $field ]['name'] ) ) {
			return array();
		}

		$files = array();
		foreach ( $_FILES[ $field ]['name'] as $key => $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$file = array();
			foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $part ) {
				$file[ $part ] = isset( $_FILES[ $field ][ $part ][ $key ] ) ? $_FILES[ $field ][ $part ][ $key ] : '';
			}
			$files[ $key ] = $file;
		}
		// phpcs:enable

		return $files;
	}

	/* ---------------------------------------------------------------------
	 * Shared form handling
	 * ------------------------------------------------------------------- */

	/**
	 * Checks permission and nonce. Also catches uploads bigger than post_max_size, which
	 * arrive with an empty $_POST and would otherwise fail with a confusing nonce error.
	 */
	private static function check_request( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage ads.', 'mdb-ad-management' ), 403 );
		}

		if ( empty( $_POST ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			/* translators: %s: maximum upload size */
			self::add_notice( sprintf( __( 'The upload was larger than the server allows (%s in total). Use smaller images or add fewer ads at a time.', 'mdb-ad-management' ), size_format( wp_max_upload_size() ) ), 'error' );
			$referer = wp_get_referer();
			self::redirect( $referer ? $referer : self::list_url() );
		}

		check_admin_referer( $nonce_action );
	}

	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	private static function field( array $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
	}

	/**
	 * Validates the fields shared by Add New and Edit.
	 *
	 * @param array $input Raw (unslashed) form fields: title, link_type, link, start, end.
	 * @return array|WP_Error Columns ready for the database.
	 */
	private static function validate( array $input ) {
		$title = sanitize_text_field( self::field( $input, 'title' ) );
		$link  = self::normalize_link( self::field( $input, 'link_type' ), self::field( $input, 'link' ) );
		$start = self::parse_date( self::field( $input, 'start' ) );
		$end   = self::parse_date( self::field( $input, 'end' ) );

		if ( is_wp_error( $link ) ) {
			return $link;
		}
		if ( false === $start || false === $end ) {
			return new WP_Error( 'mdb_ads_date', __( 'Enter dates as YYYY-MM-DD HH:MM.', 'mdb-ad-management' ) );
		}
		if ( $start && $end && $end <= $start ) {
			return new WP_Error( 'mdb_ads_dates', __( 'The end date must be after the start date.', 'mdb-ad-management' ) );
		}

		return array(
			'title'      => $title,
			'link_url'   => $link,
			'start_date' => $start,
			'end_date'   => $end,
		);
	}

	/* ---------------------------------------------------------------------
	 * Links: website, phone call, or none
	 * ------------------------------------------------------------------- */

	/**
	 * @return string 'url', 'tel' or 'none'.
	 */
	public static function link_type( $url ) {
		if ( '' === (string) $url ) {
			return 'none';
		}
		return 0 === stripos( $url, 'tel:' ) ? 'tel' : 'url';
	}

	/**
	 * Strips spaces, dashes, dots and brackets from a phone number.
	 *
	 * @return string Digits with an optional leading +, or '' when it is not a phone number.
	 */
	private static function normalize_phone( $raw ) {
		$phone = preg_replace( array( '/^tel:/i', '/[\s().\-]+/' ), '', (string) $raw );
		return preg_match( '/^\+?\d{6,15}$/', $phone ) ? $phone : '';
	}

	/**
	 * Finds a phone number inside a link saved by version 1, e.g. "http://LOTUS%20TRAVEL%209538538313".
	 */
	private static function phone_in_link( $url ) {
		$text = rawurldecode( preg_replace( '#^https?://#i', '', (string) $url ) );
		return preg_match( '/\+?\d[\d\s\-]{4,}\d/', $text, $match ) ? self::normalize_phone( $match[0] ) : '';
	}

	/**
	 * @param string $type 'url', 'tel' or 'none'. Anything else is treated as 'url'.
	 * @param string $raw  What was typed.
	 * @return string|WP_Error The link to store ('' for none).
	 */
	private static function normalize_link( $type, $raw ) {
		if ( 'none' === $type ) {
			return '';
		}

		if ( 'tel' === $type ) {
			$phone = self::normalize_phone( $raw );
			return $phone ? 'tel:' . $phone : new WP_Error( 'mdb_ads_phone', __( 'Enter the phone number in digits, like 9876543210 or +91 98765 43210.', 'mdb-ad-management' ) );
		}

		if ( '' === $raw ) {
			return new WP_Error( 'mdb_ads_link', __( 'Enter the website address, or set the link to "No link".', 'mdb-ad-management' ) );
		}
		if ( self::normalize_phone( $raw ) ) {
			return new WP_Error( 'mdb_ads_link', __( 'That is a phone number. Set the link to "Phone call" instead of "Website".', 'mdb-ad-management' ) );
		}

		$url = $raw;
		if ( '/' !== $url[0] && ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( '' === $url || MDB_Ads_DB::link_is_broken( $url ) ) {
			return new WP_Error(
				'mdb_ads_link',
				/* translators: %s: what was typed */
				sprintf( __( '"%s" is not a website address. Enter the full address, like https://example.com.', 'mdb-ad-management' ), $raw )
			);
		}

		return $url;
	}

	/**
	 * Link type menu plus the address/number box. admin.js adjusts the box to the chosen type.
	 */
	private static function render_link_field( $name, $id, $type = 'url', $value = '' ) {
		$types = array(
			'url'  => __( 'Website', 'mdb-ad-management' ),
			'tel'  => __( 'Phone call', 'mdb-ad-management' ),
			'none' => __( 'No link', 'mdb-ad-management' ),
		);
		?>
		<span class="mdb-link">
			<select name="<?php echo esc_attr( $name ); ?>[link_type]" class="mdb-link-type" aria-label="<?php esc_attr_e( 'Link type', 'mdb-ad-management' ); ?>">
				<?php foreach ( $types as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[link]" class="mdb-link-value" value="<?php echo esc_attr( $value ); ?>" placeholder="https://example.com">
		</span>
		<?php
	}

	/**
	 * Parses a datetime-local value ("2026-11-20T09:30").
	 *
	 * @return string|null|false MySQL datetime, null when empty, false when invalid.
	 */
	private static function parse_date( $value ) {
		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( 'T', ' ', $value );
		foreach ( array( 'Y-m-d H:i', 'Y-m-d H:i:s' ) as $format ) {
			$date = DateTime::createFromFormat( '!' . $format, $value );
			if ( $date && $date->format( $format ) === $value ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function render_settings_page() {
		$settings = MDB_Ads_Settings::get();
		$option   = MDB_Ads_Settings::OPTION;
		?>
		<div class="wrap mdb-ads">
			<h1><?php esc_html_e( 'Ad Settings', 'mdb-ad-management' ); ?></h1>
			<?php
			settings_errors();
			self::render_notices();
			?>

			<form method="post" action="options.php">
				<?php settings_fields( $option ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic placement', 'mdb-ad-management' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[auto_insert]" value="1" <?php checked( $settings['auto_insert'], 1 ); ?>>
								<?php esc_html_e( 'Insert active ads between the paragraphs of single posts', 'mdb-ad-management' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Turn this off to show ads only where you place the shortcode.', 'mdb-ad-management' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdb-max-per-post"><?php esc_html_e( 'Ads per post', 'mdb-ad-management' ); ?></label></th>
						<td>
							<input type="number" id="mdb-max-per-post" name="<?php echo esc_attr( $option ); ?>[max_per_post]" value="<?php echo esc_attr( $settings['max_per_post'] ); ?>" min="0" max="50" step="1" class="small-text">
							<p class="description"><?php esc_html_e( 'How many ads to place in each post, chosen at random. 0 places every active ad in every post.', 'mdb-ad-management' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdb-label"><?php esc_html_e( 'Label under ads', 'mdb-ad-management' ); ?></label></th>
						<td>
							<input type="text" id="mdb-label" name="<?php echo esc_attr( $option ); ?>[label]" value="<?php echo esc_attr( $settings['label'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Small text shown under every ad. Leave empty to show none.', 'mdb-ad-management' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shortcode', 'mdb-ad-management' ); ?></th>
						<td>
							<p><code>[mdb_ad]</code> <?php esc_html_e( 'one random active ad', 'mdb-ad-management' ); ?></p>
							<p><code>[mdb_ad count="3"]</code> <?php esc_html_e( 'three random active ads', 'mdb-ad-management' ); ?></p>
							<p><code>[mdb_ad id="5"]</code> <?php esc_html_e( 'one particular ad (its shortcode is on the ads list and the edit screen)', 'mdb-ad-management' ); ?></p>
							<p class="description"><?php esc_html_e( 'Inactive, scheduled and expired ads are never shown.', 'mdb-ad-management' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
