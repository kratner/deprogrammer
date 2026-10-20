<?php
/**
 * FILE: class-cn-lineup.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-lineup.php
 *
 * Reads the lineup (categories + channels) from data/channels.json.
 */

defined( 'ABSPATH' ) || exit;

final class CN_Lineup {

	private static $data = null;

	private static function load() {
		if ( null === self::$data ) {
			$file = CN_DIR . 'data/channels.json';
			$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
			self::$data = is_array( $json ) ? $json : [ 'categories' => [], 'channels' => [] ];
			self::$data = apply_filters( 'cn_lineup', self::$data );
		}
		return self::$data;
	}

	/** Forget the loaded lineup (after wp-admin adds or removes a channel). */
	public static function reset() {
		self::$data    = null;
		self::$watched = null;
	}

	/** @return array<int, array{key:string,label:string}> in display order. */
	public static function categories() {
		return self::load()['categories'] ?? [];
	}

	/** @return array<string,string> key => label */
	public static function category_labels() {
		return wp_list_pluck( self::categories(), 'label', 'key' );
	}

	/** @return array<int, array> each with name, url, channelId, category, optional network. */
	public static function channels() {
		return self::load()['channels'] ?? [];
	}

	private static $watched = null;

	/**
	 * Keith's recently watched news videos (data/watched.json, built by
	 * `channels.mjs watched` + build-plugin.mjs), most recent watch first. Items whose
	 * channel has since left the lineup are dropped.
	 *
	 * @return array{updated:string, items:array<int, array>}
	 */
	public static function watched() {
		if ( null === self::$watched ) {
			$file = CN_DIR . 'data/watched.json';
			$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
			$byid = self::by_id();
			$items = [];
			foreach ( ( is_array( $json ) ? ( $json['items'] ?? [] ) : [] ) as $it ) {
				if ( isset( $byid[ $it['channelId'] ?? '' ] ) && preg_match( '/^[\w-]{11}$/', $it['id'] ?? '' ) ) {
					$items[] = $it + [ 'channel' => $byid[ $it['channelId'] ] ];
				}
			}
			self::$watched = [ 'updated' => (string) ( $json['updated'] ?? '' ), 'items' => $items ];
		}
		return self::$watched;
	}

	/** Habit weight 1-100 (from Keith's viewing history; see scripts/channels.mjs weights). */
	public static function weight( array $channel ) {
		return max( 1, min( 100, (int) ( $channel['weight'] ?? 50 ) ) );
	}

	/** Channels, heaviest habit weight first (name breaks ties). */
	public static function by_weight( array $channels ) {
		usort( $channels, static fn( $a, $b ) => self::weight( $b ) <=> self::weight( $a ) ?: strcasecmp( $a['name'], $b['name'] ) );
		return $channels;
	}

	/** @return array<string, array> channelId => channel */
	public static function by_id() {
		return array_column( self::channels(), null, 'channelId' );
	}

	/**
	 * Normalized keys that identify a listed channel: its UC id, its @handle and any folded-in
	 * fan channel ids. Used to tell suggesters a channel is already in the network.
	 *
	 * @return array<string,string> key => channel name
	 */
	public static function known_keys() {
		$keys = [];
		foreach ( self::channels() as $c ) {
			$keys[ strtolower( $c['channelId'] ) ] = $c['name'];
			if ( preg_match( '~/(@[^/?#]+)~', $c['url'], $m ) ) {
				$keys[ strtolower( rawurldecode( $m[1] ) ) ] = $c['name'];
			}
			foreach ( $c['mergedFanChannels'] ?? [] as $f ) {
				$keys[ strtolower( $f['channelId'] ) ] = $c['name'];
			}
		}
		return $keys;
	}
}
