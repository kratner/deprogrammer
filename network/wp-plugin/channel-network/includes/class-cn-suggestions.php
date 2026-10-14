<?php
/**
 * FILE: class-cn-suggestions.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-suggestions.php
 *
 * Visitor channel suggestions: the {prefix}cn_suggestions table, the public REST endpoint
 * POST /wp-json/channel-network/v1/suggest, and WordPress privacy export/erase hooks.
 *
 * Spam defences, in order:
 *   - honeypot field ("website") must be empty;
 *   - a signed form token must be at least 3 seconds and at most 2 days old;
 *   - at most 5 submissions per IP per hour;
 *   - Akismet, when it's active with a key. Spam is stored with status 'spam' but the
 *     submitter still sees "thanks", so bots learn nothing.
 * IPs are stored only as a keyed hash, and that hash and the user agent are wiped after
 * 30 days (prune_personal_data runs on the refresh cron).
 */

defined( 'ABSPATH' ) || exit;

final class CN_Suggestions {

	const DB_VERSION = '1';
	const STATUSES   = [ 'new', 'added', 'rejected', 'duplicate', 'spam' ];

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cn_suggestions';
	}

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_upgrade' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				channel_input varchar(300) NOT NULL,
				channel_key varchar(190) NOT NULL DEFAULT '',
				reason text NOT NULL,
				email varchar(190) NOT NULL DEFAULT '',
				category varchar(32) NOT NULL DEFAULT '',
				ip_hash char(64) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				akismet varchar(10) NOT NULL DEFAULT 'unchecked',
				status varchar(16) NOT NULL DEFAULT 'new',
				reviewed_at datetime NULL DEFAULT NULL,
				note varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY status (status),
				KEY channel_key (channel_key),
				KEY created_at (created_at),
				KEY email (email)
			) $charset;"
		);
		update_option( 'cn_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'cn_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function register_routes() {
		register_rest_route(
			'channel-network/v1',
			'/suggest',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'handle_submit' ],
				'args'                => [
					'channel'  => [ 'type' => 'string', 'required' => true ],
					'reason'   => [ 'type' => 'string', 'default' => '' ],
					'email'    => [ 'type' => 'string', 'default' => '' ],
					'category' => [ 'type' => 'string', 'default' => '' ],
					'website'  => [ 'type' => 'string', 'default' => '' ],
					'token'    => [ 'type' => 'string', 'default' => '' ],
				],
			]
		);
	}

	/** A token the form carries: issue time + HMAC, so direct POSTs without the page fail. */
	public static function form_token() {
		$t = (string) time();
		return $t . '.' . hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) . 'cn_form' );
	}

	private static function token_ok( $token ) {
		if ( ! preg_match( '/^(\d{10})\.([a-f0-9]{64})$/', (string) $token, $m ) ) {
			return false;
		}
		$age = time() - (int) $m[1];
		return hash_equals( hash_hmac( 'sha256', $m[1], wp_salt( 'nonce' ) . 'cn_form' ), $m[2] )
			&& $age >= 3 && $age <= 2 * DAY_IN_SECONDS;
	}

	private static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) . 'cn_ip' );
	}

	/**
	 * Normalize what a visitor typed to a comparable key: "uc…" id, "@handle", "v:<videoId>",
	 * or "" if it isn't recognizably a YouTube channel or video.
	 */
	public static function channel_key( $input ) {
		$s = trim( (string) $input );
		if ( preg_match( '/^(UC[\w-]{22})$/', $s, $m ) ) {
			return strtolower( $m[1] );
		}
		if ( preg_match( '/^@([\w.\-]{3,100})$/u', $s, $m ) ) {
			return '@' . strtolower( $m[1] );
		}
		if ( ! preg_match( '~^(https?://)?((www|m)\.)?(youtube\.com|youtu\.be)/~i', $s ) ) {
			return '';
		}
		$url = preg_match( '~^https?://~i', $s ) ? $s : 'https://' . $s;
		if ( preg_match( '~youtube\.com/channel/(UC[\w-]{22})~i', $url, $m ) ) {
			return strtolower( $m[1] );
		}
		if ( preg_match( '~youtube\.com/(@[^/?#\s]+)~iu', $url, $m ) ) {
			return strtolower( rawurldecode( $m[1] ) );
		}
		if ( preg_match( '~youtube\.com/(c|user)/([^/?#\s]+)~i', $url, $m ) ) {
			return strtolower( $m[1] . '/' . $m[2] );
		}
		if ( preg_match( '~(?:youtu\.be/|[?&]v=|/shorts/|/live/)([\w-]{11})~', $url, $m ) ) {
			return 'v:' . $m[1];
		}
		return '';
	}

	public static function handle_submit( WP_REST_Request $req ) {
		$thanks = new WP_REST_Response( [ 'ok' => true, 'status' => 'queued', 'message' => __( 'Thanks! Your suggestion is in the queue for review.', 'channel-network' ) ], 200 );

		// Bots: answer like a success, store nothing.
		if ( '' !== trim( (string) $req['website'] ) || ! self::token_ok( $req['token'] ) ) {
			return $thanks;
		}

		$channel = trim( sanitize_text_field( (string) $req['channel'] ) );
		$reason  = trim( sanitize_textarea_field( (string) $req['reason'] ) );
		$email   = trim( sanitize_email( (string) $req['email'] ) );
		$raw_em  = trim( (string) $req['email'] );
		$cat     = sanitize_key( (string) $req['category'] );
		$key     = self::channel_key( $channel );

		if ( '' === $key || strlen( $channel ) > 300 ) {
			return self::error( 'channel', __( 'Please enter a YouTube channel link, an @handle, or a link to one of its videos.', 'channel-network' ) );
		}
		if ( '' !== $raw_em && ( '' === $email || ! is_email( $email ) ) ) {
			return self::error( 'email', __( 'That email address doesn\'t look right. Leave it blank if you prefer.', 'channel-network' ) );
		}
		if ( mb_strlen( $reason ) > 1000 ) {
			return self::error( 'reason', __( 'Please keep the reason under 1,000 characters.', 'channel-network' ) );
		}
		if ( '' !== $cat && ! isset( CN_Lineup::category_labels()[ $cat ] ) ) {
			$cat = '';
		}

		$known = CN_Lineup::known_keys();
		if ( isset( $known[ $key ] ) ) {
			return new WP_REST_Response(
				[
					'ok'      => true,
					'status'  => 'listed',
					/* translators: %s: channel name */
					'message' => sprintf( __( '%s is already in the network. Thanks for the suggestion!', 'channel-network' ), $known[ $key ] ),
				],
				200
			);
		}

		$ip_hash = self::ip_hash();
		$rate    = 'cn_rate_' . substr( $ip_hash, 0, 32 );
		$count   = (int) get_transient( $rate );
		if ( $count >= 5 ) {
			return self::error( 'rate', __( 'You\'ve sent several suggestions already. Please try again in an hour.', 'channel-network' ), 429, 'rate_limited' );
		}
		set_transient( $rate, $count + 1, HOUR_IN_SECONDS );

		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$akismet = self::akismet_check( $channel, $reason, $email, $ua, $req );

		global $wpdb;
		$wpdb->insert(
			self::table(),
			[
				'created_at'    => current_time( 'mysql', true ),
				'channel_input' => $channel,
				'channel_key'   => $key,
				'reason'        => $reason,
				'email'         => $email,
				'category'      => $cat,
				'ip_hash'       => $ip_hash,
				'user_agent'    => $ua,
				'akismet'       => $akismet,
				'status'        => 'spam' === $akismet ? 'spam' : 'new',
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return $thanks;
	}

	private static function error( $field, $message, $status = 400, $code = 'invalid' ) {
		return new WP_REST_Response( [ 'ok' => false, 'status' => $code, 'field' => $field, 'message' => $message ], $status );
	}

	/** @return string 'spam' | 'ham' | 'unchecked' */
	private static function akismet_check( $channel, $reason, $email, $ua, WP_REST_Request $req ) {
		if ( ! class_exists( 'Akismet' ) || ! method_exists( 'Akismet', 'get_api_key' ) || ! Akismet::get_api_key() ) {
			return 'unchecked';
		}
		$query = http_build_query(
			[
				'blog'                 => home_url(),
				'user_ip'              => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				'user_agent'           => $ua,
				'referrer'             => (string) $req->get_header( 'referer' ),
				'permalink'            => home_url( '/network/' ),
				'comment_type'         => 'contact-form',
				'comment_author_email' => $email,
				'comment_content'      => $channel . "\n\n" . $reason,
				'blog_lang'            => get_locale(),
				'blog_charset'         => get_option( 'blog_charset' ),
			]
		);
		$response = Akismet::http_post( $query, 'comment-check' );
		if ( ! is_array( $response ) || ! isset( $response[1] ) ) {
			return 'unchecked';
		}
		return 'true' === trim( $response[1] ) ? 'spam' : ( 'false' === trim( $response[1] ) ? 'ham' : 'unchecked' );
	}

	/**
	 * Query suggestions, each with 'times' = how many non-spam suggestions share its channel.
	 *
	 * @return array<int, object>
	 */
	public static function query( $status = 'new', $limit = 200 ) {
		global $wpdb;
		$t     = self::table();
		$where = 'all' === $status ? "status <> 'spam'" : $wpdb->prepare( 'status = %s', $status );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is our table, $where is prepared above.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, (SELECT COUNT(*) FROM $t c WHERE c.channel_key = s.channel_key AND c.status <> 'spam') AS times
				 FROM $t s WHERE $where ORDER BY s.created_at DESC, s.id DESC LIMIT %d",
				$limit
			)
		);
	}

	public static function counts() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) n FROM $t GROUP BY status", OBJECT_K ); // phpcs:ignore
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( $rows as $s => $r ) {
			$out[ $s ] = (int) $r->n;
		}
		return $out;
	}

	public static function set_status( array $ids, $status, $note = '' ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return 0;
		}
		$n = 0;
		foreach ( array_map( 'absint', $ids ) as $id ) {
			$data = [ 'status' => $status, 'reviewed_at' => current_time( 'mysql', true ) ];
			if ( '' !== $note ) {
				$data['note'] = substr( sanitize_text_field( $note ), 0, 255 );
			}
			$n += (int) $wpdb->update( self::table(), $data, [ 'id' => $id ] );
		}
		return $n;
	}

	public static function delete( array $ids ) {
		global $wpdb;
		$n = 0;
		foreach ( array_map( 'absint', $ids ) as $id ) {
			$n += (int) $wpdb->delete( self::table(), [ 'id' => $id ] );
		}
		return $n;
	}

	/** Wipe IP hashes and user agents older than 30 days; delete spam older than 30 days. */
	public static function prune_personal_data() {
		global $wpdb;
		$t      = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET ip_hash = '', user_agent = '' WHERE created_at < %s AND (ip_hash <> '' OR user_agent <> '')", $cutoff ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE status = 'spam' AND created_at < %s", $cutoff ) ); // phpcs:ignore
	}

	public static function register_exporter( $exporters ) {
		$exporters['channel-network'] = [
			'exporter_friendly_name' => __( 'Channel suggestions', 'channel-network' ),
			'callback'               => [ __CLASS__, 'export_personal_data' ],
		];
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['channel-network'] = [
			'eraser_friendly_name' => __( 'Channel suggestions', 'channel-network' ),
			'callback'             => [ __CLASS__, 'erase_personal_data' ],
		];
		return $erasers;
	}

	public static function export_personal_data( $email, $page = 1 ) {
		global $wpdb;
		$t     = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE email = %s ORDER BY id", $email ) ); // phpcs:ignore
		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'group_id'    => 'channel-network',
				'group_label' => __( 'Channel suggestions', 'channel-network' ),
				'item_id'     => 'cn-suggestion-' . $r->id,
				'data'        => [
					[ 'name' => __( 'Submitted', 'channel-network' ), 'value' => $r->created_at . ' UTC' ],
					[ 'name' => __( 'Channel', 'channel-network' ), 'value' => $r->channel_input ],
					[ 'name' => __( 'Reason', 'channel-network' ), 'value' => $r->reason ],
					[ 'name' => __( 'Email', 'channel-network' ), 'value' => $r->email ],
				],
			];
		}
		return [ 'data' => $items, 'done' => true ];
	}

	public static function erase_personal_data( $email, $page = 1 ) {
		global $wpdb;
		$t = self::table();
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET email = '', ip_hash = '', user_agent = '' WHERE email = %s", $email ) ); // phpcs:ignore
		return [ 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
	}
}
