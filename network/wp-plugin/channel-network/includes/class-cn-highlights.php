<?php
/**
 * FILE: class-cn-highlights.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-highlights.php
 *
 * Highlighted videos: the hand-picked section at the top of the network page.
 *
 * - The curator's list lives in one option (cn_highlights), in display order. Only an admin
 *   (manage_options) changes it: wp-admin → Channel Network → Highlights, or WP-CLI
 *   (`wp channel-network highlights …`).
 * - Signed-in members can suggest a video (POST /wp-json/channel-network/v1/highlight/suggest).
 *   Suggestions go into the same cn_suggestions table as channel suggestions, with
 *   kind = 'highlight', and appear in the Highlights tab's queue. Nothing is published until
 *   the curator approves it.
 * - Spam defences on suggestions: sign-in plus the REST nonce (WordPress refuses cookie-auth
 *   writes without it), a honeypot, 5 suggestions per member and 10 per IP per hour, the video
 *   must exist on YouTube, and Akismet when it's active.
 * - Members may tick "Suggest anonymously" (their choice, off by default). The row then stores no
 *   user id, IP hash or browser string, and the admin queue shows "Anonymous". The hourly rate
 *   limit still counts the member, in a hashed transient that expires and isn't tied to the row.
 * - As they accumulate (1.6.4): a highlight is "up front" for FRESH_DAYS after it was added (or
 *   brought back), then moves to the archive by itself. A pinned one stays up front until
 *   unpinned; "Archive now" moves one early. Nothing is deleted by aging: the page shows up to
 *   SHOW up-front highlights, and "See all highlights" expands every other one in place, newest
 *   first. If none are up front, the top one of the list still shows, so the section never empties.
 * - Any YouTube link form is accepted (watch?v=, youtu.be/, /live/, /shorts/, /embed/, with
 *   or without ?si= / ?is= / &t= tracking params) and reduced to the 11-character video id.
 */

defined( 'ABSPATH' ) || exit;

final class CN_Highlights {

	const OPTION   = 'cn_highlights';
	const MAX        = 500; // most kept; past this, the oldest archived (never pinned) go first
	const SHOW       = 9;   // most shown up front (the first is the feature)
	const FRESH_DAYS = 14;  // days a highlight stays up front unless pinned
	const KIND     = 'highlight';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	// ------------------------------------------------------------------------ the list

	/**
	 * Every highlight, in display order. Each: id, title, channel, channel_id, note, added (unix).
	 *
	 * @return array<int, array>
	 */
	public static function all() {
		$list = get_option( self::OPTION, [] );
		return array_values( array_filter( is_array( $list ) ? $list : [], static fn( $h ) => is_array( $h ) && self::is_video_id( $h['id'] ?? '' ) ) );
	}

	private static function save( array $list ) {
		$list = array_values( $list );
		// Over the cap, drop the oldest archived, unpinned ones; never a current or pinned one.
		while ( count( $list ) > self::MAX ) {
			$drop = null;
			foreach ( $list as $i => $h ) {
				if ( ! self::is_current( $h ) && empty( $h['pinned'] ) && ( null === $drop || self::fresh_from( $h ) < self::fresh_from( $list[ $drop ] ) ) ) {
					$drop = $i;
				}
			}
			if ( null === $drop ) {
				break;
			}
			array_splice( $list, $drop, 1 );
		}
		update_option( self::OPTION, $list, false );
	}

	/** When a highlight's up-front time started: when it was added, or last brought back. */
	public static function fresh_from( array $h ) {
		return max( (int) ( $h['added'] ?? 0 ), (int) ( $h['fresh_from'] ?? 0 ) );
	}

	/** Unix time it moves to the archive (0 = pinned, never). */
	public static function expires( array $h ) {
		return ! empty( $h['pinned'] ) ? 0 : self::fresh_from( $h ) + self::FRESH_DAYS * DAY_IN_SECONDS;
	}

	public static function is_current( array $h ) {
		return empty( $h['archived'] ) && ( ! empty( $h['pinned'] ) || time() < self::expires( $h ) );
	}

	/** What the page shows up front, in the curator's order (never empty if any exist). */
	public static function current() {
		$all = self::all();
		$cur = array_values( array_filter( $all, [ __CLASS__, 'is_current' ] ) );
		return $cur ?: array_slice( $all, 0, 1 );
	}

	/** Everything not shown up front, newest first: for "See all highlights". */
	public static function archive( array $shown ) {
		$ids  = array_column( $shown, 'id' );
		$rest = array_values( array_filter( self::all(), static fn( $h ) => ! in_array( $h['id'], $ids, true ) ) );
		usort( $rest, static fn( $a, $b ) => self::fresh_from( $b ) <=> self::fresh_from( $a ) );
		return $rest;
	}

	/** Pin / unpin, archive now, or bring back (up front again for FRESH_DAYS). */
	public static function set_state( $id, $state ) {
		$list = self::all();
		foreach ( $list as &$h ) {
			if ( $h['id'] !== $id ) {
				continue;
			}
			if ( 'pin' === $state ) {
				$h['pinned'] = true;
				unset( $h['archived'] );
			} elseif ( 'unpin' === $state ) {
				unset( $h['pinned'] );
			} elseif ( 'archive' === $state ) {
				$h['archived'] = true;
				unset( $h['pinned'] );
			} elseif ( 'restore' === $state ) {
				unset( $h['archived'] );
				$h['fresh_from'] = time();
			}
		}
		unset( $h );
		self::save( $list );
	}

	public static function is_video_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[\w-]{11}$/', $id );
	}

	/**
	 * Reduce anything that points at one YouTube video to its 11-character id, or ''.
	 * Accepts a bare id, watch?v=, youtu.be/, /live/, /shorts/, /embed/, /v/, on www., m.,
	 * music. or youtube-nocookie.com, with any tracking parameters.
	 */
	public static function video_id( $input ) {
		$s = trim( (string) $input );
		if ( self::is_video_id( $s ) ) {
			return $s;
		}
		if ( ! preg_match( '~^(https?://)?([a-z0-9-]+\.)?(youtube\.com|youtube-nocookie\.com|youtu\.be)(/|$)~i', $s ) ) {
			return '';
		}
		$url   = preg_match( '~^https?://~i', $s ) ? $s : 'https://' . $s;
		$parts = wp_parse_url( $url );
		$host  = strtolower( $parts['host'] ?? '' );
		$path  = $parts['path'] ?? '';
		if ( 'youtu.be' === $host && preg_match( '~^/([\w-]{11})(?:[/?#]|$)~', $path, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '~^/(?:live|shorts|embed|v|e)/([\w-]{11})(?:[/?#]|$)~', $path, $m ) ) {
			return $m[1];
		}
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			if ( isset( $q['v'] ) && self::is_video_id( (string) $q['v'] ) ) {
				return (string) $q['v'];
			}
		}
		return '';
	}

	public static function watch_url( $id ) {
		return 'https://www.youtube.com/watch?v=' . $id;
	}

	public static function is_highlighted( $id ) {
		return in_array( $id, array_column( self::all(), 'id' ), true );
	}

	/**
	 * Title and channel for a video: the Data API when a key is set (it also gives the
	 * channel id, so a fan channel files under its parent), else YouTube's oEmbed (no key).
	 *
	 * @return array{title:string, channel:string, channel_id:string, handle:string}|null null if YouTube doesn't know the video.
	 */
	public static function lookup( $id ) {
		if ( ! self::is_video_id( $id ) ) {
			return null;
		}
		$ck  = 'cn_hl_meta_' . $id;
		$hit = get_transient( $ck );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$out = null;
		$key = CN_Settings::api_key();
		if ( '' !== $key ) {
			$r = wp_remote_get( add_query_arg( [ 'part' => 'snippet', 'id' => $id, 'key' => $key ], 'https://www.googleapis.com/youtube/v3/videos' ), [ 'timeout' => 8 ] );
			$j = 200 === wp_remote_retrieve_response_code( $r ) ? json_decode( wp_remote_retrieve_body( $r ), true ) : null;
			$sn = $j['items'][0]['snippet'] ?? null;
			if ( $sn ) {
				$out = [
					'title'      => (string) ( $sn['title'] ?? '' ),
					'channel'    => (string) ( $sn['channelTitle'] ?? '' ),
					'channel_id' => (string) ( $sn['channelId'] ?? '' ),
					'handle'     => '',
				];
			}
		}
		if ( null === $out ) {
			$r = wp_remote_get( add_query_arg( [ 'url' => rawurlencode( self::watch_url( $id ) ), 'format' => 'json' ], 'https://www.youtube.com/oembed' ), [ 'timeout' => 8 ] );
			$j = 200 === wp_remote_retrieve_response_code( $r ) ? json_decode( wp_remote_retrieve_body( $r ), true ) : null;
			if ( is_array( $j ) && ! empty( $j['title'] ) ) {
				$handle = preg_match( '~youtube\.com/(@[^/?#]+)~i', (string) ( $j['author_url'] ?? '' ), $m ) ? strtolower( rawurldecode( $m[1] ) ) : '';
				$cid    = preg_match( '~youtube\.com/channel/(UC[\w-]{22})~', (string) ( $j['author_url'] ?? '' ), $m ) ? $m[1] : '';
				$out    = [ 'title' => (string) $j['title'], 'channel' => (string) ( $j['author_name'] ?? '' ), 'channel_id' => $cid, 'handle' => $handle ];
			}
		}
		if ( null !== $out ) {
			$out = array_map( static fn( $v ) => sanitize_text_field( $v ), $out );
			set_transient( $ck, $out, DAY_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * Add a video. Title/channel come from YouTube unless given.
	 *
	 * @param array $args title, channel, note, position ('top' | 'bottom').
	 * @return array|WP_Error the new highlight.
	 */
	public static function add( $input, array $args = [] ) {
		$id = self::video_id( $input );
		if ( '' === $id ) {
			return new WP_Error( 'invalid', __( 'That isn\'t a link to a YouTube video.', 'channel-network' ) );
		}
		if ( self::is_highlighted( $id ) ) {
			$list = self::all();
			$i    = array_search( $id, array_column( $list, 'id' ), true );
			if ( false !== $i && ! self::is_current( $list[ $i ] ) ) {
				// Archived: highlighting it again brings it back up front, at the top (or bottom).
				$item = $list[ $i ];
				unset( $item['archived'] );
				$item['fresh_from'] = time();
				if ( '' !== trim( (string) ( $args['note'] ?? '' ) ) ) {
					$item['note'] = mb_substr( trim( sanitize_text_field( (string) $args['note'] ) ), 0, 280 );
				}
				array_splice( $list, $i, 1 );
				if ( 'bottom' === ( $args['position'] ?? 'top' ) ) {
					$list[] = $item;
				} else {
					array_unshift( $list, $item );
				}
				self::save( $list );
				return $item;
			}
			return new WP_Error( 'duplicate', __( 'That video is already highlighted.', 'channel-network' ) );
		}
		$meta  = self::lookup( $id );
		$title = trim( sanitize_text_field( (string) ( $args['title'] ?? '' ) ) ) ?: ( $meta['title'] ?? '' );
		if ( '' === $title ) {
			return new WP_Error( 'not_found', __( 'YouTube didn\'t return that video (private, removed, or the link is wrong). Enter a title to add it anyway.', 'channel-network' ) );
		}
		$item = [
			'id'         => $id,
			'title'      => mb_substr( $title, 0, 200 ),
			'channel'    => mb_substr( trim( sanitize_text_field( (string) ( $args['channel'] ?? '' ) ) ) ?: ( $meta['channel'] ?? '' ), 0, 120 ),
			'channel_id' => (string) ( $meta['channel_id'] ?? '' ),
			'handle'     => (string) ( $meta['handle'] ?? '' ),
			'note'       => mb_substr( trim( sanitize_text_field( (string) ( $args['note'] ?? '' ) ) ), 0, 280 ),
			'added'      => time(),
		];
		$list = self::all();
		if ( 'bottom' === ( $args['position'] ?? 'top' ) ) {
			$list[] = $item;
		} else {
			array_unshift( $list, $item );
		}
		self::save( $list );
		return $item;
	}

	public static function remove( $id ) {
		$list = self::all();
		$keep = array_values( array_filter( $list, static fn( $h ) => $h['id'] !== $id ) );
		self::save( $keep );
		return count( $list ) - count( $keep );
	}

	/** Move one place up (-1) or down (+1). */
	public static function move( $id, $dir ) {
		$list = self::all();
		$i    = array_search( $id, array_column( $list, 'id' ), true );
		$j    = false === $i ? false : $i + ( $dir < 0 ? -1 : 1 );
		if ( false === $i || $j < 0 || $j >= count( $list ) ) {
			return false;
		}
		[ $list[ $i ], $list[ $j ] ] = [ $list[ $j ], $list[ $i ] ];
		self::save( $list );
		return true;
	}

	/** @param array<string,string> $notes id => note */
	public static function set_notes( array $notes ) {
		$list = self::all();
		foreach ( $list as &$h ) {
			if ( isset( $notes[ $h['id'] ] ) ) {
				$h['note'] = mb_substr( trim( sanitize_text_field( (string) $notes[ $h['id'] ] ) ), 0, 280 );
			}
		}
		unset( $h );
		self::save( $list );
	}

	/**
	 * The lineup channel a highlight belongs to, if any: by channel id (fan channels file
	 * under their parent), else by @handle.
	 */
	public static function lineup_channel( array $h ) {
		$cid = (string) ( $h['channel_id'] ?? '' );
		foreach ( CN_Lineup::channels() as $c ) {
			if ( '' !== $cid && ( $c['channelId'] === $cid || in_array( $cid, array_column( $c['mergedFanChannels'] ?? [], 'channelId' ), true ) ) ) {
				return $c;
			}
			if ( ! empty( $h['handle'] ) && preg_match( '~/(@[^/?#]+)~', $c['url'], $m ) && strtolower( rawurldecode( $m[1] ) ) === $h['handle'] ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * What the page template needs for one highlight (same keys as templates/card.php).
	 */
	public static function card( array $h, $index ) {
		$h   += [ 'channel' => '', 'channel_id' => '', 'handle' => '', 'note' => '' ];
		$lc   = self::lineup_channel( $h );
		$own  = $lc && ( '' === $h['channel_id'] || $lc['channelId'] === $h['channel_id'] );
		$cats = CN_Lineup::category_labels();
		$name = '' !== $h['channel'] ? $h['channel'] : ( $lc['name'] ?? '' );
		$url  = $own ? $lc['url'] : ( '' !== $h['channel_id'] ? 'https://www.youtube.com/channel/' . $h['channel_id'] : ( '' !== $h['handle'] ? 'https://www.youtube.com/' . $h['handle'] : self::watch_url( $h['id'] ) ) );
		return [
			'id'             => $h['id'],
			'title'          => $h['title'],
			'cat'            => $lc['category'] ?? '',
			'channel_id'     => $lc['channelId'] ?? $h['channel_id'],
			'cat_label'      => $lc ? ( $cats[ $lc['category'] ] ?? __( 'Highlight', 'channel-network' ) ) : __( 'Highlight', 'channel-network' ),
			'ts'             => 0,
			'avatar_channel' => $own ? $lc : [ 'name' => '' !== $name ? $name : 'YouTube', 'channelId' => (string) $h['channel_id'] ],
			'channel_name'   => $name,
			'channel_url'    => $url,
			'time_html'      => '',
			'index'          => $index,
			'note'           => (string) $h['note'],
		];
	}

	// ---------------------------------------------------------------- member suggestions

	public static function register_routes() {
		register_rest_route(
			'channel-network/v1',
			'/highlight/suggest',
			[
				'methods'             => 'POST',
				// Cookie-authenticated REST requests only count as signed in when they carry
				// a valid wp_rest nonce, so this also enforces the nonce.
				'permission_callback' => static fn() => is_user_logged_in(),
				'callback'            => [ __CLASS__, 'handle_suggest' ],
				'args'                => [
					'url'     => [ 'type' => 'string', 'required' => true ],
					'reason'  => [ 'type' => 'string', 'default' => '' ],
					'website' => [ 'type' => 'string', 'default' => '' ],
				],
			]
		);
	}

	private static function reply( $ok, $status, $message, $code = 200, $field = '' ) {
		$body = [ 'ok' => $ok, 'status' => $status, 'message' => $message ];
		if ( $field ) {
			$body['field'] = $field;
		}
		return new WP_REST_Response( $body, $code );
	}

	private static function rate_ok( $bucket, $limit ) {
		$key = 'cn_rate_hl_' . substr( hash_hmac( 'sha256', $bucket, wp_salt( 'auth' ) ), 0, 24 );
		$n   = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	public static function handle_suggest( WP_REST_Request $req ) {
		$thanks = self::reply( true, 'queued', __( 'Thanks! Your suggestion is in the queue. The curator reviews every one by hand.', 'channel-network' ) );
		if ( ! CN_Members::enabled() ) {
			return self::reply( false, 'disabled', __( 'Suggestions aren\'t open right now.', 'channel-network' ), 403 );
		}
		if ( '' !== trim( (string) $req['website'] ) ) {
			return $thanks; // honeypot: look like success, store nothing
		}
		$user   = wp_get_current_user();
		$raw    = trim( (string) $req['url'] );
		$reason = trim( sanitize_textarea_field( (string) $req['reason'] ) );
		$id     = strlen( $raw ) <= 300 ? self::video_id( $raw ) : '';
		if ( '' === $id ) {
			return self::reply( false, 'invalid', __( 'Please paste a link to a YouTube video.', 'channel-network' ), 400, 'url' );
		}
		if ( mb_strlen( $reason ) > 500 ) {
			return self::reply( false, 'invalid', __( 'Please keep the note under 500 characters.', 'channel-network' ), 400, 'reason' );
		}
		if ( self::is_highlighted( $id ) ) {
			return self::reply( true, 'listed', __( 'That video is already highlighted. Thanks!', 'channel-network' ) );
		}
		if ( CN_Suggestions::user_has_pending( $user->ID, self::KIND, 'v:' . $id ) ) {
			return self::reply( true, 'queued', __( 'You already suggested that one. It\'s in the queue.', 'channel-network' ) );
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! self::rate_ok( 'u' . $user->ID, 5 ) || ! self::rate_ok( 'ip' . $ip, 10 ) ) {
			return self::reply( false, 'rate_limited', __( 'You\'ve sent several suggestions already. Please try again in an hour.', 'channel-network' ), 429 );
		}
		$meta = self::lookup( $id );
		if ( null === $meta ) {
			return self::reply( false, 'not_found', __( 'We couldn\'t find that video on YouTube. Is it public?', 'channel-network' ), 400, 'url' );
		}
		$anonymous = in_array( strtolower( (string) $req['anonymous'] ), [ '1', 'true', 'on', 'yes' ], true );
		CN_Suggestions::insert_highlight( $user, self::watch_url( $id ), 'v:' . $id, $meta['title'], $reason, $req, $anonymous );
		return $anonymous
			? self::reply( true, 'queued', __( 'Thanks! Your suggestion is in the queue, sent anonymously. The curator reviews every one by hand.', 'channel-network' ) )
			: $thanks;
	}

	/**
	 * Approve highlight suggestions: add each video (newest approval on top) and mark the
	 * suggestion 'added'. Returns how many were added.
	 */
	public static function approve( array $suggestion_ids, array $args = [] ) {
		$n = 0;
		foreach ( CN_Suggestions::get_many( $suggestion_ids ) as $s ) {
			if ( self::KIND !== $s->kind ) {
				continue;
			}
			// $args (from Quick approve): note, position, and a title that overrides the suggested one.
			$r = self::add( $s->channel_input, [ 'title' => '' !== trim( (string) ( $args['title'] ?? '' ) ) ? $args['title'] : $s->title ] + $args );
			if ( ! is_wp_error( $r ) || 'duplicate' === $r->get_error_code() ) {
				CN_Suggestions::set_status( [ (int) $s->id ], 'added' );
				$n += is_wp_error( $r ) ? 0 : 1;
			}
		}
		return $n;
	}
}
