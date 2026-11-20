<?php
/**
 * Ad image files: saving uploads and removing files when an ad is deleted or its image replaced.
 *
 * Images are written straight into wp-content/uploads/YYYY/MM, as in version 1. They are not
 * added to the Media Library, so deleting an ad never removes something a post might use.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDB_Ads_Images {

	/** Allowed image types. SVG is left out on purpose because it can carry scripts. */
	const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * Saves one uploaded file.
	 *
	 * @param array $file One file in the $_FILES shape (name, type, tmp_name, error, size).
	 * @return string|WP_Error Public URL of the saved image.
	 */
	public static function store( array $file ) {
		if ( ! self::was_uploaded( $file ) ) {
			return new WP_Error( 'mdb_ads_no_file', __( 'Choose an image.', 'mdb-ad-management' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$result = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::MIMES,
			)
		);

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'mdb_ads_upload', $result['error'] );
		}

		return $result['url'];
	}

	/**
	 * Whether the visitor picked a file for this input at all.
	 */
	public static function was_uploaded( $file ) {
		return is_array( $file ) && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'];
	}

	/**
	 * Removes an ad's image file from the server.
	 *
	 * The file is left alone when another ad still uses it or it belongs to the Media Library.
	 *
	 * @param string $url Image URL stored on the ad.
	 * @return bool False only when the file could not be found inside the uploads folder or
	 *              could not be removed; true when it is gone or was deliberately kept.
	 */
	public static function delete( $url ) {
		if ( '' === (string) $url || MDB_Ads_DB::image_in_use( $url ) || attachment_url_to_postid( $url ) ) {
			return true;
		}

		$path = self::path_from_url( $url );
		if ( ! $path ) {
			return false;
		}
		if ( ! file_exists( $path ) ) {
			return true;
		}

		wp_delete_file_from_directory( $path, wp_get_upload_dir()['basedir'] );

		return ! file_exists( $path );
	}

	/**
	 * Maps an uploads URL to its file path. Returns null for URLs outside the uploads folder.
	 */
	private static function path_from_url( $url ) {
		$uploads = wp_get_upload_dir();

		// Ignore http/https differences between when the ad was saved and now.
		$base = preg_replace( '#^https?:#i', '', trailingslashit( $uploads['baseurl'] ) );
		$url  = preg_replace( '#^https?:#i', '', $url );

		if ( 0 !== strpos( $url, $base ) ) {
			return null;
		}

		$relative = rawurldecode( substr( $url, strlen( $base ) ) );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return null;
		}

		return trailingslashit( $uploads['basedir'] ) . $relative;
	}
}
