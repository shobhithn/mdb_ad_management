<?php
/**
 * Plugin settings, stored as one option.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDB_Ads_Settings {

	const OPTION = 'mdb_ads_settings';

	public static function defaults() {
		return array(
			'auto_insert'  => 1,
			// 0 keeps the version 1 behaviour: every active ad goes into every post.
			'max_per_post' => 0,
			'label'        => 'ಜಾಹೀರಾತು',
		);
	}

	/**
	 * @param string|null $key One setting, or null for all of them.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		if ( null === $key ) {
			return $settings;
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public static function register() {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = (array) $input;

		return array(
			'auto_insert'  => empty( $input['auto_insert'] ) ? 0 : 1,
			'max_per_post' => min( 50, absint( isset( $input['max_per_post'] ) ? $input['max_per_post'] : 0 ) ),
			'label'        => sanitize_text_field( isset( $input['label'] ) ? $input['label'] : '' ),
		);
	}
}
