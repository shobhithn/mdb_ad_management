<?php
/**
 * Updates from GitHub Releases.
 *
 * Publishing a release at https://github.com/shobhithn/mdb_ad_management/releases with a tag
 * newer than the installed version (e.g. v2.0.1) makes WordPress show its usual
 * "There is a new version… Update now" link, the "View details" popup and the auto-update toggle.
 *
 * Wired through the "Update URI" plugin header (WordPress 5.8+), so wordpress.org is never asked
 * about this plugin and a same-named plugin there can never replace it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDB_Ads_Updater {

	const REPO      = 'shobhithn/mdb_ad_management';
	const CACHE_KEY = 'mdb_ads_github_release';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );

		// "Check again" on Dashboard > Updates should see a release published a minute ago.
		add_action(
			'load-update-core.php',
			function () {
				if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					delete_site_transient( self::CACHE_KEY );
				}
			}
		);
	}

	private static function plugin_file() {
		return plugin_basename( MDB_ADS_FILE );
	}

	private static function slug() {
		return dirname( self::plugin_file() );
	}

	/**
	 * Tells WordPress about the latest release. WordPress compares the versions itself.
	 *
	 * @param array|false $update      Update data from an earlier filter, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin path relative to the plugins folder.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::plugin_file() !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}

		return array(
			'slug'         => self::slug(),
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '5.8',
			'requires_php' => '7.0',
		);
	}

	/**
	 * Fills the "View version x.y.z details" popup.
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'MDB Ad Management',
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => 'SHOBHITH N',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'requires'      => '5.8',
			'requires_php'  => '7.0',
			'last_updated'  => $release['date'],
			'sections'      => array(
				'changelog' => $release['notes'],
			),
		);
	}

	/**
	 * GitHub's source zip unpacks to "shobhithn-mdb_ad_management-1a2b3c4/". Rename it to the
	 * plugin's folder, otherwise WordPress would install it as a different plugin and deactivate this one.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || self::plugin_file() !== $hook_extra['plugin'] ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . self::slug() . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}

		return new WP_Error( 'mdb_ads_update_folder', __( 'The update was downloaded but its folder could not be renamed. Nothing was changed.', 'mdb-ad-management' ) );
	}

	/**
	 * The latest published release, cached for 6 hours. A failed lookup (GitHub down, rate limit,
	 * no release yet) is cached for an hour so it can never slow the site down.
	 *
	 * @return array|null version, package, url, date, notes.
	 */
	private static function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$release = null;
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}

		set_site_transient( self::CACHE_KEY, $release ? $release : array(), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * @param mixed $data Decoded GitHub "release" JSON.
	 * @return array|null
	 */
	private static function parse_release( $data ) {
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
			return null;
		}

		// Prefer a .zip attached to the release; otherwise GitHub's own source zip.
		$package = isset( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '';
		foreach ( isset( $data['assets'] ) ? (array) $data['assets'] : array() as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}

		if ( 0 !== strpos( $package, 'https://' ) ) {
			return null;
		}

		$notes = isset( $data['body'] ) ? trim( (string) $data['body'] ) : '';

		return array(
			'version' => $version,
			'package' => $package,
			'url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : 'https://github.com/' . self::REPO . '/releases',
			'date'    => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'notes'   => '' !== $notes ? wpautop( esc_html( $notes ) ) : '<p>' . esc_html__( 'No release notes.', 'mdb-ad-management' ) . '</p>',
		);
	}
}
