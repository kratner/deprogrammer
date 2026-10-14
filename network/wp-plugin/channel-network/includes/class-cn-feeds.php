<?php
/**
 * FILE: class-cn-feeds.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-feeds.php
 *
 * Fetches each channel's latest uploads in the background (WP-Cron) and caches them in the
 * cn_videos option. Page views only read the cache, so visitors never wait on YouTube.
 *
 * Two sources:
 *   rss  https://www.youtube.com/feeds/videos.xml?channel_id=…  (no key; goes down a few
 *        times a year, e.g. every channel 404'd in Oct 2026)
 *   api  YouTube Data API v3 playlistItems.list on the channel's uploads playlist (UU…).
 *        Needs a key; 1 quota unit per channel per refresh (47 channels every 30 min ≈
 *        2,300 of the free 10,000 units a day).
 * A channel that fails keeps its last good videos, so an outage shows slightly stale videos
 * rather than an empty page.
 *
 * Shorts: RSS marks them with a /shorts/ link. The API doesn't, so each new API video gets
 * one HEAD request to youtube.com/shorts/<id> (200 = Short, redirect = not), cached forever.
 *
 * Test mode: when CN_FIXTURE_DIR is defined, feeds are read from <dir>/<channelId>.xml
 * instead of the network (dev/docker-compose.yml sets it).
 */

defined( 'ABSPATH' ) || exit;

use WpOrg\Requests\Requests;

final class CN_Feeds {

	const OPTION_VIDEOS = 'cn_videos';
	const OPTION_SHORTS = 'cn_shorts';
	const LOCK          = 'cn_refresh_lock';
	const UA            = 'Mozilla/5.0 (compatible; ChannelNetwork/1.0; +https://deprogrammer.org/network/)';

	/** @return array{refreshed_at:int, channels:array} */
	public static function cache() {
		$c = get_option( self::OPTION_VIDEOS, [] );
		return is_array( $c ) ? $c + [ 'refreshed_at' => 0, 'channels' => [] ] : [ 'refreshed_at' => 0, 'channels' => [] ];
	}

	/**
	 * Refresh every listed channel. Returns a summary for the CLI and admin screen.
	 *
	 * @return array{ok:int, failed:int, source:string, errors:array<string,string>, skipped?:bool}
	 */
	public static function refresh_all() {
		if ( get_transient( self::LOCK ) ) {
			return [ 'ok' => 0, 'failed' => 0, 'source' => '', 'errors' => [], 'skipped' => true ];
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$source   = CN_Settings::effective_source();
		$channels = CN_Lineup::channels();
		$cache    = self::cache();
		$now      = time();
		$errors   = [];
		$ok       = 0;

		$results = self::fetch_many( wp_list_pluck( $channels, 'channelId' ), $source );

		$fresh = [];
		foreach ( $channels as $ch ) {
			$id   = $ch['channelId'];
			$prev = $cache['channels'][ $id ] ?? [ 'videos' => [], 'success_at' => 0 ];
			$res  = $results[ $id ] ?? new WP_Error( 'cn_missing', 'No response' );
			if ( is_wp_error( $res ) ) {
				$errors[ $ch['name'] ] = $res->get_error_message();
				$fresh[ $id ]          = [
					'ok'         => false,
					'source'     => $source,
					'checked_at' => $now,
					'success_at' => (int) ( $prev['success_at'] ?? 0 ),
					'error'      => $res->get_error_message(),
					'videos'     => $prev['videos'] ?? [], // keep the last good copy
				];
			} else {
				++$ok;
				$fresh[ $id ] = [
					'ok'         => true,
					'source'     => $source,
					'checked_at' => $now,
					'success_at' => $now,
					'error'      => '',
					'videos'     => $res,
				];
			}
		}

		if ( 'api' === $source && CN_Settings::get( 'exclude_shorts' ) && ! defined( 'CN_FIXTURE_DIR' ) ) {
			self::classify_shorts( $fresh );
		}

		update_option( self::OPTION_VIDEOS, [ 'refreshed_at' => $now, 'channels' => $fresh ], false );
		self::maybe_refresh_meta();
		delete_transient( self::LOCK );

		return [ 'ok' => $ok, 'failed' => count( $errors ), 'source' => $source, 'errors' => $errors ];
	}

	/**
	 * Fetch many channels in parallel.
	 *
	 * @return array<string, array|WP_Error> channelId => videos or error
	 */
	private static function fetch_many( array $ids, $source ) {
		$out = [];
		if ( defined( 'CN_FIXTURE_DIR' ) ) {
			foreach ( $ids as $id ) {
				$file       = trailingslashit( CN_FIXTURE_DIR ) . $id . '.xml';
				$out[ $id ] = is_readable( $file )
					? self::parse_rss( (string) file_get_contents( $file ) )
					: new WP_Error( 'cn_http', 'HTTP 404 (no fixture)' );
			}
			return $out;
		}

		$requests = [];
		foreach ( $ids as $id ) {
			$requests[ $id ] = [
				'url'     => self::url_for( $id, $source ),
				'type'    => Requests::GET,
				'headers' => [ 'User-Agent' => self::UA, 'Accept-Language' => 'en-US,en;q=0.8' ],
			];
		}
		foreach ( array_chunk( $requests, 12, true ) as $batch ) {
			$responses = self::request_multiple( $batch );
			foreach ( $responses as $id => $r ) {
				if ( $r instanceof Exception ) {
					$out[ $id ] = new WP_Error( 'cn_http', $r->getMessage() );
				} elseif ( 200 !== (int) $r->status_code ) {
					$out[ $id ] = new WP_Error( 'cn_http', 'HTTP ' . $r->status_code . self::api_error_reason( $r->body ) );
				} else {
					$out[ $id ] = 'api' === $source ? self::parse_api( $r->body ) : self::parse_rss( $r->body );
				}
			}
		}
		return $out;
	}

	private static function request_multiple( array $batch, array $extra = [] ) {
		_wp_http_get_object(); // makes sure the Requests library is loaded
		return Requests::request_multiple(
			$batch,
			$extra + [
				'timeout'          => 15,
				'connect_timeout'  => 10,
				'follow_redirects' => true,
				'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			]
		);
	}

	private static function url_for( $channel_id, $source ) {
		if ( 'api' === $source ) {
			return add_query_arg(
				[
					'part'       => 'snippet,contentDetails',
					'maxResults' => 15,
					'playlistId' => 'UU' . substr( $channel_id, 2 ),
					'key'        => CN_Settings::api_key(),
				],
				'https://www.googleapis.com/youtube/v3/playlistItems'
			);
		}
		return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode( $channel_id );
	}

	/** Pull a human-readable reason out of a Data API error body, if there is one. */
	private static function api_error_reason( $body ) {
		$j = json_decode( (string) $body, true );
		$reason = $j['error']['errors'][0]['reason'] ?? ( $j['error']['message'] ?? '' );
		return $reason ? ' (' . sanitize_text_field( $reason ) . ')' : '';
	}

	/** @return array|WP_Error list of {id, title, published, short} */
	public static function parse_rss( $xml ) {
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( (string) $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $doc ) {
			return new WP_Error( 'cn_parse', 'Feed is not valid XML' );
		}
		$doc->registerXPathNamespace( 'a', 'http://www.w3.org/2005/Atom' );
		$videos = [];
		foreach ( $doc->xpath( '//a:entry' ) as $entry ) {
			$yt   = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
			$id   = (string) $yt->videoId;
			$href = '';
			foreach ( $entry->link as $link ) {
				if ( 'alternate' === (string) $link['rel'] ) {
					$href = (string) $link['href'];
				}
			}
			$published = strtotime( (string) $entry->published );
			if ( ! preg_match( '/^[\w-]{11}$/', $id ) || ! $published ) {
				continue;
			}
			$videos[] = [
				'id'        => $id,
				'title'     => wp_strip_all_tags( (string) $entry->title ),
				'published' => $published,
				'short'     => false !== strpos( $href, '/shorts/' ),
			];
		}
		return $videos;
	}

	/** @return array|WP_Error list of {id, title, published, short} */
	public static function parse_api( $json ) {
		$j = json_decode( (string) $json, true );
		if ( ! is_array( $j ) || ! isset( $j['items'] ) ) {
			return new WP_Error( 'cn_parse', 'Unexpected API response' );
		}
		$videos = [];
		foreach ( $j['items'] as $item ) {
			$id        = $item['contentDetails']['videoId'] ?? '';
			$published = strtotime( $item['contentDetails']['videoPublishedAt'] ?? '' );
			$title     = (string) ( $item['snippet']['title'] ?? '' );
			// Skip private/deleted entries and scheduled premieres that haven't aired.
			if ( ! preg_match( '/^[\w-]{11}$/', $id ) || ! $published || $published > time() + 300
				|| in_array( $title, [ 'Private video', 'Deleted video' ], true ) ) {
				continue;
			}
			$videos[] = [ 'id' => $id, 'title' => wp_strip_all_tags( $title ), 'published' => $published, 'short' => null ];
		}
		return $videos;
	}

	/** Fill in 'short' for API videos, using a permanent cache of earlier answers. */
	private static function classify_shorts( array &$fresh ) {
		$known   = get_option( self::OPTION_SHORTS, [] );
		$known   = is_array( $known ) ? $known : [];
		$cutoff  = time() - CN_Settings::get( 'max_age_days' ) * DAY_IN_SECONDS;
		$unknown = [];
		foreach ( $fresh as $ch ) {
			foreach ( $ch['videos'] as $v ) {
				if ( null === $v['short'] && ! isset( $known[ $v['id'] ] ) && $v['published'] >= $cutoff ) {
					$unknown[ $v['id'] ] = [
						'url'     => 'https://www.youtube.com/shorts/' . $v['id'],
						'type'    => Requests::HEAD,
						'headers' => [ 'User-Agent' => self::UA ],
					];
				}
			}
		}
		foreach ( array_chunk( array_slice( $unknown, 0, 200, true ), 20, true ) as $batch ) {
			foreach ( self::request_multiple( $batch, [ 'follow_redirects' => false ] ) as $vid => $r ) {
				if ( ! ( $r instanceof Exception ) && in_array( (int) $r->status_code, [ 200, 301, 302, 303, 307 ], true ) ) {
					$known[ $vid ] = 200 === (int) $r->status_code ? 1 : 0;
				}
			}
		}
		// Keep only ids still in the cache so the option doesn't grow forever.
		$live = [];
		foreach ( $fresh as &$ch ) {
			foreach ( $ch['videos'] as &$v ) {
				if ( null === $v['short'] && isset( $known[ $v['id'] ] ) ) {
					$v['short'] = (bool) $known[ $v['id'] ];
				}
				if ( isset( $known[ $v['id'] ] ) ) {
					$live[ $v['id'] ] = $known[ $v['id'] ];
				}
			}
			unset( $v );
		}
		unset( $ch );
		update_option( self::OPTION_SHORTS, $live, false );
	}

	/**
	 * The stream shown on the page, ranked by Keith's viewing habits blended with recency.
	 *
	 *   score = (weight/100 + 0.05)^S / (age_hours + 2)^1.3     S = habit_strength / 10
	 *
	 * With the default S = 1, a video from the most-watched channel stays level with a
	 * brand-new video from a light-rotation channel (weight ~5) for about 10 hours; from a
	 * mid channel (weight ~25), about 3 hours. S = 0 is plain newest-first.
	 * Each channel also gets slots in proportion to its weight: 1 + 4·weight/100, up to the
	 * per_channel setting (default 5).
	 *
	 * @return array<int, array> each video + 'channel' + 'score'
	 */
	public static function stream() {
		$cache    = self::cache();
		$now      = time();
		$cutoff   = $now - CN_Settings::get( 'max_age_days' ) * DAY_IN_SECONDS;
		$max_slot = CN_Settings::get( 'per_channel' );
		$shorts   = (bool) CN_Settings::get( 'exclude_shorts' );
		$strength = CN_Settings::get( 'habit_strength' ) / 10;
		$out      = [];
		foreach ( CN_Lineup::channels() as $ch ) {
			$w      = CN_Lineup::weight( $ch );
			$slots  = max( 1, min( $max_slot, (int) round( 1 + 4 * $w / 100 ) ) );
			$videos = $cache['channels'][ $ch['channelId'] ]['videos'] ?? [];
			$videos = array_filter( $videos, static fn( $v ) => $v['published'] >= $cutoff && ! ( $shorts && true === $v['short'] ) );
			usort( $videos, static fn( $a, $b ) => $b['published'] <=> $a['published'] );
			foreach ( array_slice( $videos, 0, $slots ) as $v ) {
				$age_h = max( 0, $now - $v['published'] ) / HOUR_IN_SECONDS;
				$out[] = $v + [
					'channel' => $ch,
					'score'   => ( ( $w / 100 + 0.05 ) ** $strength ) / ( ( $age_h + 2 ) ** 1.3 ),
				];
			}
		}
		usort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] ?: $b['published'] <=> $a['published'] );
		return $out;
	}

	/**
	 * "The Latest": every channel's uploads from the last $hours, newest first, in strict
	 * time order (no habit weighting). At most $per_channel per channel so one prolific
	 * channel can't fill the strip.
	 */
	public static function latest( $limit = 40, $hours = 48, $per_channel = 3 ) {
		$cache  = self::cache();
		$cutoff = time() - $hours * HOUR_IN_SECONDS;
		$shorts = (bool) CN_Settings::get( 'exclude_shorts' );
		$out    = [];
		foreach ( CN_Lineup::channels() as $ch ) {
			$videos = $cache['channels'][ $ch['channelId'] ]['videos'] ?? [];
			$videos = array_filter( $videos, static fn( $v ) => $v['published'] >= $cutoff && ! ( $shorts && true === $v['short'] ) );
			usort( $videos, static fn( $a, $b ) => $b['published'] <=> $a['published'] );
			foreach ( array_slice( $videos, 0, $per_channel ) as $v ) {
				$out[] = $v + [ 'channel' => $ch ];
			}
		}
		usort( $out, static fn( $a, $b ) => $b['published'] <=> $a['published'] );
		return array_slice( $out, 0, $limit );
	}

	const OPTION_META = 'cn_channel_meta';

	/**
	 * Channel avatars, refreshed once a day through the Data API (channels.list, 1 unit per
	 * 50 channels). Without a key, avatars stay empty and the page shows monograms.
	 * In fixture mode they come from <CN_FIXTURE_DIR>/_channels.json.
	 */
	public static function maybe_refresh_meta( $force = false ) {
		$meta = get_option( self::OPTION_META, [] );
		$ids  = wp_list_pluck( CN_Lineup::channels(), 'channelId' );
		$have = is_array( $meta['channels'] ?? null ) ? $meta['channels'] : [];
		$missing = array_diff( $ids, array_keys( $have ) );
		if ( ! $force && ! $missing && ( time() - (int) ( $meta['fetched_at'] ?? 0 ) ) < DAY_IN_SECONDS ) {
			return;
		}
		if ( defined( 'CN_FIXTURE_DIR' ) ) {
			$file = trailingslashit( CN_FIXTURE_DIR ) . '_channels.json';
			$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : [];
			update_option( self::OPTION_META, [ 'fetched_at' => time(), 'channels' => is_array( $data ) ? $data : [] ], false );
			return;
		}
		$key = CN_Settings::api_key();
		if ( '' === $key ) {
			return;
		}
		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			$r = wp_remote_get(
				add_query_arg( [ 'part' => 'snippet', 'id' => implode( ',', $chunk ), 'key' => $key ], 'https://www.googleapis.com/youtube/v3/channels' ),
				[ 'timeout' => 15, 'headers' => [ 'User-Agent' => self::UA ] ]
			);
			$j = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
			foreach ( $j['items'] ?? [] as $it ) {
				$t = $it['snippet']['thumbnails'] ?? [];
				$have[ $it['id'] ] = [ 'avatar' => esc_url_raw( $t['medium']['url'] ?? ( $t['default']['url'] ?? '' ) ) ];
			}
		}
		update_option( self::OPTION_META, [ 'fetched_at' => time(), 'channels' => $have ], false );
	}

	/** Avatar URL for a channel, or '' (the page falls back to a monogram). */
	public static function avatar( $channel_id ) {
		$meta = get_option( self::OPTION_META, [] );
		return (string) ( $meta['channels'][ $channel_id ]['avatar'] ?? '' );
	}

	/** Newest upload time per channel (unix ts), for the lineup directory. */
	public static function last_upload( $channel_id ) {
		$videos = self::cache()['channels'][ $channel_id ]['videos'] ?? [];
		return $videos ? max( array_column( $videos, 'published' ) ) : 0;
	}

	/**
	 * Called on page view. If the cache is older than the refresh interval, start a refresh
	 * in the background with a non-blocking loopback request (the viewer isn't kept waiting).
	 * This works even when WP-Cron is disabled, as it is on deprogrammer.org's host
	 * (DISABLE_WP_CRON, and the server cron only runs the main site every 6 hours). WP-Cron
	 * stays scheduled too, for hosts where it runs.
	 */
	public static function maybe_queue_refresh() {
		$age = time() - (int) self::cache()['refreshed_at'];
		if ( $age < CN_Settings::get( 'refresh_minutes' ) * MINUTE_IN_SECONDS || get_transient( self::LOCK ) || get_transient( 'cn_kick' ) ) {
			return;
		}
		set_transient( 'cn_kick', 1, 2 * MINUTE_IN_SECONDS ); // one kick at a time
		$t = (string) time();
		wp_remote_post(
			rest_url( 'channel-network/v1/refresh' ),
			[
				'blocking'  => false,
				'timeout'   => 0.01,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => [ 't' => $t, 'sig' => hash_hmac( 'sha256', $t, wp_salt( 'auth' ) . 'cn_refresh' ) ],
			]
		);
	}

	/** REST target for the loopback above. Signed, so outsiders can't trigger refreshes. */
	public static function register_routes() {
		register_rest_route(
			'channel-network/v1',
			'/refresh',
			[
				'methods'             => 'POST',
				'permission_callback' => static function ( WP_REST_Request $r ) {
					$t = (string) $r['t'];
					return preg_match( '/^\d{10}$/', $t ) && abs( time() - (int) $t ) < 300
						&& hash_equals( hash_hmac( 'sha256', $t, wp_salt( 'auth' ) . 'cn_refresh' ), (string) $r['sig'] );
				},
				'callback'            => static function () {
					ignore_user_abort( true );
					if ( function_exists( 'set_time_limit' ) ) {
						@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
					$r = self::refresh_all();
					if ( time() - (int) get_option( 'cn_last_prune', 0 ) > DAY_IN_SECONDS ) {
						CN_Suggestions::prune_personal_data();
						update_option( 'cn_last_prune', time(), false );
					}
					return new WP_REST_Response( [ 'ok' => $r['ok'], 'failed' => $r['failed'] ], 200 );
				},
			]
		);
	}
}

add_action( 'rest_api_init', [ 'CN_Feeds', 'register_routes' ] );
