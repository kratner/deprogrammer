<?php
/**
 * FILE: class-cn-settings.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-settings.php
 *
 * Settings, stored in one option (cn_settings). The YouTube Data API key can instead be
 * defined in wp-config.php as CN_YT_API_KEY, which keeps it out of the database; the
 * constant wins when both are set.
 */

defined( 'ABSPATH' ) || exit;

final class CN_Settings {

	const OPTION = 'cn_settings';

	public static function defaults() {
		return [
			'source'          => 'auto', // auto = Data API when a key is set, else RSS. Or 'rss' / 'api'.
			'api_key'         => '',
			'refresh_minutes' => 30,
			'per_channel'     => 5,      // most videos any one channel gets in the stream (the top-weighted channel)
			'habit_strength'  => 10,     // ×0.1: how much Keith's viewing habits outweigh recency (0 = newest first)
			'max_age_days'    => 7,      // older videos drop out of the stream
			'exclude_shorts'  => 1,
			'field_guide'     => 0,      // "Know the hooks before you watch" section; on hold (Keith, 2026-10-13)
			'members_enabled' => 0,      // member sign-in (Google / email link), follows, saves
			'support_url'      => '',    // Ko-fi (or other) support page; empty = no support links
			'google_client_id' => '',    // OAuth client for Google sign-in; empty = Site Kit's Sign in with Google client
		];
	}

	public static function all() {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public static function get( $key ) {
		$all = self::all();
		$v   = $all[ $key ] ?? null;
		if ( in_array( $key, [ 'refresh_minutes', 'per_channel', 'max_age_days', 'exclude_shorts', 'habit_strength', 'field_guide', 'members_enabled' ], true ) ) {
			$v = (int) $v;
		}
		if ( 'refresh_minutes' === $key ) {
			$v = max( 15, $v ); // keep well inside the Data API's free quota
		}
		return $v;
	}

	public static function api_key() {
		if ( defined( 'CN_YT_API_KEY' ) && CN_YT_API_KEY ) {
			return (string) CN_YT_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	/** The source actually used: 'api' or 'rss'. */
	public static function effective_source() {
		$s = self::get( 'source' );
		if ( 'api' === $s || ( 'auto' === $s && '' !== self::api_key() ) ) {
			return '' !== self::api_key() ? 'api' : 'rss';
		}
		return 'rss';
	}

	public static function sanitize( $input ) {
		$d   = self::defaults();
		$out = self::all();
		$out['source']          = in_array( $input['source'] ?? '', [ 'auto', 'rss', 'api' ], true ) ? $input['source'] : $d['source'];
		$out['refresh_minutes'] = max( 15, min( 1440, absint( $input['refresh_minutes'] ?? $d['refresh_minutes'] ) ) );
		$out['per_channel']     = max( 1, min( 15, absint( $input['per_channel'] ?? $d['per_channel'] ) ) );
		$out['max_age_days']    = max( 1, min( 60, absint( $input['max_age_days'] ?? $d['max_age_days'] ) ) );
		$out['habit_strength']  = max( 0, min( 30, absint( $input['habit_strength'] ?? $d['habit_strength'] ) ) );
		$out['exclude_shorts']  = empty( $input['exclude_shorts'] ) ? 0 : 1;
		$out['field_guide']     = empty( $input['field_guide'] ) ? 0 : 1;
		$out['members_enabled'] = empty( $input['members_enabled'] ) ? 0 : 1;
		$sup = esc_url_raw( trim( (string) ( $input['support_url'] ?? '' ) ), [ 'https' ] );
		$out['support_url'] = 0 === strpos( $sup, 'https://' ) ? $sup : '';
		$gid = trim( (string) ( $input['google_client_id'] ?? '' ) );
		$out['google_client_id'] = preg_match( '/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $gid ) ? $gid : '';
		// An empty key field means "keep the saved key"; a lone "-" clears it.
		$key = trim( (string) ( $input['api_key'] ?? '' ) );
		if ( '-' === $key ) {
			$out['api_key'] = '';
		} elseif ( '' !== $key ) {
			$out['api_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );
		}
		return $out;
	}
}
