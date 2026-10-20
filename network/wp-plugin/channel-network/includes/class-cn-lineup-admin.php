<?php
/**
 * FILE: class-cn-lineup-admin.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-lineup-admin.php
 *
 * Channels added to the lineup from wp-admin (1.6.7). The shipped lineup lives in
 * data/channels.json (built from the private repo list); channels Keith adds in wp-admin are
 * stored in the option cn_lineup_added and merged in through the `cn_lineup` filter, so they
 * appear in the lineup, the category filters and the feeds on the next refresh, with no deploy.
 *
 * - A channel is looked up from anything a visitor might paste: a channel URL, an @handle, a
 *   UC… id, or a link to one of its videos (YouTube Data API when a key is set, else the
 *   channel page / oEmbed).
 * - New channels get the median habit weight of the shipped lineup.
 * - `wp channel-network lineup-added` lists them, so they can be folded into the private
 *   channels.json at the next weekly review (then removed here; the shipped copy takes over).
 */

defined( 'ABSPATH' ) || exit;

final class CN_Lineup_Admin {

	const OPTION = 'cn_lineup_added';

	public static function init() {
		add_filter( 'cn_lineup', [ __CLASS__, 'merge' ] );
	}

	/** @return array<int, array> name, url, channelId, category, weight, added, by, suggestion */
	public static function all() {
		$list = get_option( self::OPTION, [] );
		return is_array( $list ) ? array_values( array_filter( $list, static fn( $c ) => is_array( $c ) && preg_match( '/^UC[\w-]{22}$/', $c['channelId'] ?? '' ) ) ) : [];
	}

	/** `cn_lineup` filter: append wp-admin additions the shipped file doesn't already have. */
	public static function merge( array $data ) {
		$have = array_flip( array_column( $data['channels'] ?? [], 'channelId' ) );
		$cats = array_column( $data['categories'] ?? [], 'key' );
		foreach ( self::all() as $c ) {
			if ( ! isset( $have[ $c['channelId'] ] ) && in_array( $c['category'], $cats, true ) ) {
				$data['channels'][] = [
					'name'      => $c['name'],
					'url'       => $c['url'],
					'channelId' => $c['channelId'],
					'category'  => $c['category'],
					'weight'    => (int) $c['weight'],
					'addedInAdmin' => true,
				];
			}
		}
		return $data;
	}

	/**
	 * Look a channel up from whatever was pasted.
	 *
	 * @return array{channelId:string,name:string,url:string}|WP_Error
	 */
	public static function resolve( $input ) {
		$key = CN_Suggestions::channel_key( $input );
		if ( '' === $key ) {
			return new WP_Error( 'invalid', 'That isn\'t a YouTube channel link, @handle or video link.' );
		}
		$api = CN_Settings::api_key();
		$cid = '';
		if ( 0 === strpos( $key, 'v:' ) ) { // a video: find its channel
			$vid = substr( $key, 2 );
			if ( '' !== $api ) {
				$j   = self::get_json( add_query_arg( [ 'part' => 'snippet', 'id' => $vid, 'key' => $api ], 'https://www.googleapis.com/youtube/v3/videos' ) );
				$cid = (string) ( $j['items'][0]['snippet']['channelId'] ?? '' );
			}
			if ( '' === $cid ) {
				$o = self::get_json( add_query_arg( [ 'url' => 'https://www.youtube.com/watch?v=' . $vid, 'format' => 'json' ], 'https://www.youtube.com/oembed' ) );
				$author = (string) ( $o['author_url'] ?? '' );
				if ( '' === $author ) {
					return new WP_Error( 'not_found', 'YouTube didn\'t return that video (private, removed, or the link is wrong).' );
				}
				return self::resolve( $author );
			}
		} elseif ( 0 === strpos( $key, 'uc' ) ) {
			$cid = preg_match( '~(UC[\w-]{22})~', (string) $input, $mm ) ? $mm[1] : '';
		}
		// Channel details by id or handle.
		$args = '' !== $cid ? [ 'id' => $cid ] : ( 0 === strpos( $key, '@' ) ? [ 'forHandle' => ltrim( $key, '@' ) ] : ( 0 === strpos( $key, 'user/' ) ? [ 'forUsername' => substr( $key, 5 ) ] : [] ) );
		if ( '' !== $api && $args ) { // legacy /c/ names have no API lookup: the page fallback handles them
			$j    = self::get_json( add_query_arg( $args + [ 'part' => 'snippet', 'key' => $api ], 'https://www.googleapis.com/youtube/v3/channels' ) );
			$it   = $j['items'][0] ?? null;
			if ( $it ) {
				$handle = (string) ( $it['snippet']['customUrl'] ?? '' );
				return [
					'channelId' => (string) $it['id'],
					'name'      => (string) $it['snippet']['title'],
					'url'       => '' !== $handle ? 'https://www.youtube.com/' . ( '@' === $handle[0] ? $handle : '@' . $handle ) : 'https://www.youtube.com/channel/' . $it['id'],
				];
			}
		}
		// No key (or the API missed): read the channel page.
		$url  = '' !== $cid ? 'https://www.youtube.com/channel/' . $cid : 'https://www.youtube.com/' . $key;
		$r    = wp_remote_get( $url, [ 'timeout' => 10, 'headers' => [ 'User-Agent' => 'Mozilla/5.0 (channel-network)', 'Accept-Language' => 'en-US,en;q=0.8' ], 'cookies' => [ new WP_Http_Cookie( [ 'name' => 'CONSENT', 'value' => 'YES+1' ] ) ] ] );
		$html = is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
		// Only the page's own id: the canonical link, else "externalId". A bare "channelId" is often
		// another channel shown on the page (e.g. @TheYoungTurks' page lists "TYT Live" first).
		$own = preg_match( '~<link rel="canonical" href="https?://www\.youtube\.com/channel/(UC[\w-]{22})"~', $html, $m ) ? $m[1]
			: ( preg_match( '~"externalId":"(UC[\w-]{22})"~', $html, $m ) ? $m[1] : '' );
		if ( '' !== $own && preg_match( '~<meta property="og:title" content="([^"]+)"~', $html, $t ) ) {
			return [
				'channelId' => $own,
				'name'      => html_entity_decode( $t[1], ENT_QUOTES ),
				'url'       => 0 === strpos( $key, '@' ) ? 'https://www.youtube.com/' . $key : 'https://www.youtube.com/channel/' . $own,
			];
		}
		return new WP_Error( 'not_found', 'Couldn\'t find that channel on YouTube. Check the link and try again.' );
	}

	/**
	 * Add a channel to the lineup.
	 *
	 * @return array|WP_Error the stored channel
	 */
	public static function add( $input, $category, $name = '', $suggestion_id = 0 ) {
		if ( ! isset( CN_Lineup::category_labels()[ $category ] ) ) {
			return new WP_Error( 'category', 'Pick a category for the channel.' );
		}
		$c = self::resolve( $input );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$known = CN_Lineup::known_keys();
		if ( isset( $known[ strtolower( $c['channelId'] ) ] ) ) {
			return new WP_Error( 'duplicate', sprintf( '%s is already in the lineup.', $known[ strtolower( $c['channelId'] ) ] ) );
		}
		$weights = array_map( [ 'CN_Lineup', 'weight' ], CN_Lineup::channels() );
		sort( $weights );
		$median = $weights ? $weights[ intdiv( count( $weights ), 2 ) ] : 50;
		$item   = [
			'name'       => mb_substr( '' !== trim( (string) $name ) ? trim( sanitize_text_field( (string) $name ) ) : $c['name'], 0, 120 ),
			'url'        => esc_url_raw( $c['url'] ),
			'channelId'  => $c['channelId'],
			'category'   => $category,
			'weight'     => max( 1, (int) $median ),
			'added'      => time(),
			'by'         => wp_get_current_user()->user_login,
			'suggestion' => (int) $suggestion_id,
		];
		$list   = self::all();
		$list[] = $item;
		update_option( self::OPTION, $list, false );
		CN_Lineup::reset();
		return $item;
	}

	public static function remove( $channel_id ) {
		$list = self::all();
		$keep = array_values( array_filter( $list, static fn( $c ) => $c['channelId'] !== $channel_id ) );
		update_option( self::OPTION, $keep, false );
		CN_Lineup::reset();
		return count( $list ) - count( $keep );
	}

	private static function get_json( $url ) {
		$r = wp_remote_get( $url, [ 'timeout' => 10 ] );
		return is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
	}
}
