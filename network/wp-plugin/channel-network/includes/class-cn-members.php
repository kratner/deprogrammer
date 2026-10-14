<?php
/**
 * FILE: class-cn-members.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-members.php
 *
 * Member accounts for the network page: sign in with Google or with an emailed link,
 * then follow channels and save videos.
 *
 * - Accounts are created by this plugin ONLY, on the site it's active on (role:
 *   subscriber). Multisite registration stays closed network-wide, so no other site's
 *   sign-up page opens.
 * - Google: the page renders Google's button (Google Identity Services) with the site's
 *   OAuth client ID; the ID token is checked server-side (audience, issuer, expiry,
 *   verified email) before anyone is signed in.
 * - Email link: a single-use token (only its hash is stored) valid for 20 minutes. The
 *   response never says whether an address has an account.
 * - Newsletter: an opt-in checkbox subscribes the address to Jetpack Newsletter, which
 *   sends its own confirmation email.
 * - Members never see wp-admin or the admin bar; they can delete their account from the
 *   page. Follows and saves are covered by WordPress's privacy export/erase tools.
 *
 * Dev: with CN_DEV_FAKE_GOOGLE defined, a credential "dev:<email>" stands in for a Google
 * token; with CN_DEV_MAIL_LOG defined, emails are appended to that file instead of sent.
 */

defined( 'ABSPATH' ) || exit;

final class CN_Members {

	const META_GOOGLE = 'cn_google_sub';
	const META_FOLLOWS = 'cn_follows';
	const META_SAVED   = 'cn_saved';
	const MAX_SAVED    = 200;
	const LINK_TTL     = 20 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_consume_link' ], 1 );
		if ( self::enabled() ) {
			// Only once sign-in is on: with it off, existing subscribers on the site keep
			// their admin bar and wp-admin profile exactly as before.
			add_filter( 'show_admin_bar', [ __CLASS__, 'admin_bar' ] );
			add_action( 'admin_init', [ __CLASS__, 'keep_members_out_of_admin' ] );
		}
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
		if ( defined( 'CN_DEV_MAIL_LOG' ) ) {
			add_filter( 'pre_wp_mail', [ __CLASS__, 'dev_mail_log' ], 10, 2 );
		}
	}

	/** The Google OAuth client ID: our setting, else the one Site Kit's Sign in with Google uses. */
	public static function google_client_id() {
		$id = (string) CN_Settings::get( 'google_client_id' );
		if ( '' === $id ) {
			$sk = get_option( 'googlesitekit_sign-in-with-google_settings', [] );
			$id = is_array( $sk ) ? (string) ( $sk['clientID'] ?? '' ) : '';
		}
		return preg_match( '/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $id ) ? $id : '';
	}

	public static function enabled() {
		return (bool) CN_Settings::get( 'members_enabled' );
	}

	/** Members = signed-in users who can't edit posts (subscribers). */
	public static function is_member( $user = null ) {
		$user = $user ?: wp_get_current_user();
		return $user && $user->exists() && ! user_can( $user, 'edit_posts' );
	}

	public static function admin_bar( $show ) {
		return self::is_member() ? false : $show;
	}

	public static function keep_members_out_of_admin() {
		if ( wp_doing_ajax() || ! self::is_member() ) {
			return;
		}
		wp_safe_redirect( home_url( '/network/' ) );
		exit;
	}

	// ------------------------------------------------------------------ state for the page

	/** What the page needs to render a member's view. */
	public static function me() {
		$u = wp_get_current_user();
		if ( ! $u->exists() ) {
			return [ 'loggedIn' => false ];
		}
		$saved = get_user_meta( $u->ID, self::META_SAVED, true );
		return [
			'loggedIn' => true,
			'name'     => $u->display_name ?: $u->user_login,
			'avatar'   => get_avatar_url( $u->ID, [ 'size' => 64 ] ),
			'follows'  => array_values( (array) ( get_user_meta( $u->ID, self::META_FOLLOWS, true ) ?: [] ) ),
			'saved'    => array_values( is_array( $saved ) ? $saved : [] ),
			'isMember' => self::is_member( $u ),
		];
	}

	// ------------------------------------------------------------------------------- REST

	public static function register_routes() {
		$ns = 'channel-network/v1';
		$public = '__return_true';
		$member = static fn() => is_user_logged_in();
		register_rest_route( $ns, '/auth/google', [ 'methods' => 'POST', 'permission_callback' => $public, 'callback' => [ __CLASS__, 'auth_google' ] ] );
		register_rest_route( $ns, '/auth/email', [ 'methods' => 'POST', 'permission_callback' => $public, 'callback' => [ __CLASS__, 'auth_email' ] ] );
		register_rest_route( $ns, '/auth/logout', [ 'methods' => 'POST', 'permission_callback' => $member, 'callback' => [ __CLASS__, 'logout' ] ] );
		register_rest_route( $ns, '/me', [ 'methods' => 'GET', 'permission_callback' => $public, 'callback' => static fn() => new WP_REST_Response( self::me(), 200 ) ] );
		register_rest_route( $ns, '/follow', [ 'methods' => 'POST', 'permission_callback' => $member, 'callback' => [ __CLASS__, 'follow' ] ] );
		register_rest_route( $ns, '/save', [ 'methods' => 'POST', 'permission_callback' => $member, 'callback' => [ __CLASS__, 'save' ] ] );
		register_rest_route( $ns, '/account/delete', [ 'methods' => 'POST', 'permission_callback' => $member, 'callback' => [ __CLASS__, 'delete_account' ] ] );
	}

	private static function fail( $msg, $status = 400, $code = 'invalid' ) {
		return new WP_REST_Response( [ 'ok' => false, 'status' => $code, 'message' => $msg ], $status );
	}

	private static function rate_ok( $bucket, $limit, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'cn_rl_' . $bucket . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 24 );
		$n   = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $key, $n + 1, $window );
		return true;
	}

	public static function auth_google( WP_REST_Request $req ) {
		if ( ! self::enabled() ) {
			return self::fail( __( 'Sign-in is not available yet.', 'channel-network' ), 403, 'disabled' );
		}
		if ( ! self::rate_ok( 'g', 20, HOUR_IN_SECONDS ) ) {
			return self::fail( __( 'Too many attempts. Please try again later.', 'channel-network' ), 429, 'rate_limited' );
		}
		$claims = self::verify_google_token( (string) $req['credential'] );
		if ( is_wp_error( $claims ) ) {
			return self::fail( __( 'Google sign-in could not be verified. Please try again.', 'channel-network' ), 401, 'unverified' );
		}
		$user = self::find_or_create_user( $claims['email'], $claims['name'] ?? '', $claims['sub'] );
		if ( is_wp_error( $user ) ) {
			return self::fail( $user->get_error_message(), 403, $user->get_error_code() );
		}
		$created = (bool) get_user_meta( $user->ID, '_cn_just_created', true );
		delete_user_meta( $user->ID, '_cn_just_created' );
		if ( ! empty( $req['newsletter'] ) ) {
			self::subscribe_newsletter( $user->user_email );
		}
		// The REST nonce is bound to the session token in the logged-in cookie, which the
		// browser only sends from the next request on. Expose it to this request now so the
		// nonce we hand back is valid for that session (otherwise the next write fails 403).
		add_action(
			'set_logged_in_cookie',
			static function ( $cookie ) {
				$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
			}
		);
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		return new WP_REST_Response( [ 'ok' => true, 'status' => $created ? 'signed_up' : 'signed_in', 'me' => self::me(), 'nonce' => wp_create_nonce( 'wp_rest' ) ], 200 );
	}

	/** @return array|WP_Error verified claims */
	private static function verify_google_token( $credential ) {
		if ( defined( 'CN_DEV_FAKE_GOOGLE' ) && CN_DEV_FAKE_GOOGLE && 0 === strpos( $credential, 'dev:' ) ) {
			$email = sanitize_email( substr( $credential, 4 ) );
			return is_email( $email ) ? [ 'email' => $email, 'name' => strstr( $email, '@', true ), 'sub' => 'dev-' . md5( $email ) ] : new WP_Error( 'bad' );
		}
		$client = self::google_client_id();
		if ( '' === $client || strlen( $credential ) < 100 || strlen( $credential ) > 4096 ) {
			return new WP_Error( 'bad' );
		}
		$r = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $credential ), [ 'timeout' => 10 ] );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return new WP_Error( 'bad' );
		}
		$c = json_decode( wp_remote_retrieve_body( $r ), true );
		$ok = is_array( $c )
			&& ( $c['aud'] ?? '' ) === $client
			&& in_array( $c['iss'] ?? '', [ 'accounts.google.com', 'https://accounts.google.com' ], true )
			&& (int) ( $c['exp'] ?? 0 ) > time()
			&& in_array( $c['email_verified'] ?? '', [ true, 'true' ], true )
			&& is_email( $c['email'] ?? '' )
			&& preg_match( '/^[0-9]{5,40}$/', (string) ( $c['sub'] ?? '' ) );
		return $ok ? $c : new WP_Error( 'bad' );
	}

	/** Find a user by Google sub or email; create a subscriber on this site if none. */
	private static function find_or_create_user( $email, $name = '', $google_sub = '' ) {
		$email = sanitize_email( $email );
		$user  = null;
		if ( $google_sub ) {
			$found = get_users( [ 'blog_id' => 0, 'meta_key' => self::META_GOOGLE, 'meta_value' => $google_sub, 'number' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$user  = $found[0] ?? null;
		}
		if ( ! $user ) {
			$user = get_user_by( 'email', $email ) ?: null;
		}
		if ( $user ) {
			// Staff (anyone who can edit posts, on any site of the network) never get a session
			// through the public member sign-in; they use the normal WordPress login.
			if ( user_can( $user, 'edit_posts' ) || is_super_admin( $user->ID ) ) {
				return new WP_Error( 'staff', __( 'This address belongs to a staff account. Please sign in through the regular WordPress login.', 'channel-network' ) );
			}
			if ( $google_sub && ! get_user_meta( $user->ID, self::META_GOOGLE, true ) ) {
				update_user_meta( $user->ID, self::META_GOOGLE, $google_sub );
			}
		} else {
			$base  = sanitize_user( strtolower( strstr( $email, '@', true ) ), true ) ?: 'member';
			$base  = substr( preg_replace( '/[^a-z0-9]/', '', $base ), 0, 40 ) ?: 'member';
			$login = $base;
			for ( $i = 0; username_exists( $login ) && $i < 20; $i++ ) {
				$login = $base . wp_rand( 1000, 99999 );
			}
			$id = wp_insert_user(
				[
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 32, true, true ), // unused: members sign in without passwords
					'display_name' => $name ? sanitize_text_field( $name ) : $base,
					'role'         => 'subscriber',
				]
			);
			if ( is_wp_error( $id ) ) {
				return new WP_Error( 'create_failed', __( 'We could not create your account. Please try again.', 'channel-network' ) );
			}
			$user = get_user_by( 'id', $id );
			update_user_meta( $id, '_cn_just_created', 1 );
			if ( $google_sub ) {
				update_user_meta( $id, self::META_GOOGLE, $google_sub );
			}
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
			add_user_to_blog( get_current_blog_id(), $user->ID, 'subscriber' );
		}
		return $user;
	}

	public static function auth_email( WP_REST_Request $req ) {
		$generic = new WP_REST_Response( [ 'ok' => true, 'status' => 'sent', 'message' => __( 'Check your inbox for a sign-in link. It works once and expires in 20 minutes.', 'channel-network' ) ], 200 );
		if ( ! self::enabled() ) {
			return self::fail( __( 'Sign-in is not available yet.', 'channel-network' ), 403, 'disabled' );
		}
		if ( '' !== trim( (string) $req['website'] ) ) {
			return $generic; // honeypot
		}
		$email = sanitize_email( (string) $req['email'] );
		if ( ! is_email( $email ) ) {
			return self::fail( __( 'Please enter a valid email address.', 'channel-network' ), 400, 'invalid_email' );
		}
		if ( ! self::rate_ok( 'e', 5, HOUR_IN_SECONDS ) || ! self::rate_ok( 'em' . md5( strtolower( $email ) ), 3, HOUR_IN_SECONDS ) ) {
			return self::fail( __( 'Too many sign-in emails requested. Please try again in an hour.', 'channel-network' ), 429, 'rate_limited' );
		}
		$token = bin2hex( random_bytes( 32 ) );
		set_transient( 'cn_ml_' . hash( 'sha256', $token ), [ 'email' => $email, 'newsletter' => ! empty( $req['newsletter'] ) ? 1 : 0 ], self::LINK_TTL );
		$link = add_query_arg( 'cn_login', $token, home_url( '/network/' ) );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body = sprintf(
			/* translators: 1: site name, 2: link */
			__( "Here's your link to sign in to Deprogramming on %1\$s:\n\n%2\$s\n\nIt works once and expires in 20 minutes. If you didn't ask for it, ignore this email; nothing happens without the link.", 'channel-network' ),
			$site,
			$link
		);
		/* translators: %s: site name */
		self::mail( $email, sprintf( __( 'Your sign-in link for %s', 'channel-network' ), $site ), $body );
		return $generic;
	}

	/**
	 * Send as no-reply@<this site's domain>, envelope sender included. On a multisite
	 * WordPress otherwise sends as wordpress@<network domain> with the server account as
	 * the envelope, so the site's SPF, DKIM and DMARC never apply and links land in spam.
	 */
	private static function mail( $to, $subject, $body ) {
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$from = 'no-reply@' . $host;
		$set  = static function ( $phpmailer ) use ( $from ) {
			$phpmailer->Sender = $from; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		};
		add_action( 'phpmailer_init', $set );
		$sent = wp_mail( $to, $subject, $body, [ 'From: Deprogramming <' . $from . '>' ] );
		remove_action( 'phpmailer_init', $set );
		return $sent;
	}

	/** Visiting /network/?cn_login=<token>: sign in once, then redirect to a clean URL. */
	public static function maybe_consume_link() {
		if ( empty( $_GET['cn_login'] ) || ! self::enabled() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['cn_login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = 'cn_ml_' . hash( 'sha256', $token );
		$data  = preg_match( '/^[a-f0-9]{64}$/', $token ) ? get_transient( $key ) : false;
		$to    = home_url( '/network/' );
		if ( ! is_array( $data ) ) {
			wp_safe_redirect( add_query_arg( 'signin', 'expired', $to ) );
			exit;
		}
		delete_transient( $key ); // single use
		$user = self::find_or_create_user( $data['email'] );
		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( 'signin', 'staff' === $user->get_error_code() ? 'staff' : 'failed', $to ) );
			exit;
		}
		$created = (bool) get_user_meta( $user->ID, '_cn_just_created', true );
		delete_user_meta( $user->ID, '_cn_just_created' );
		if ( ! empty( $data['newsletter'] ) ) {
			self::subscribe_newsletter( $user->user_email );
		}
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		wp_safe_redirect( add_query_arg( 'signin', $created ? 'welcome' : 'ok', $to ) );
		exit;
	}

	public static function logout() {
		wp_logout();
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function follow( WP_REST_Request $req ) {
		$id = (string) $req['channelId'];
		if ( ! isset( CN_Lineup::by_id()[ $id ] ) ) {
			return self::fail( 'Unknown channel.' );
		}
		$uid  = get_current_user_id();
		$list = (array) ( get_user_meta( $uid, self::META_FOLLOWS, true ) ?: [] );
		$list = array_values( array_diff( $list, [ $id ] ) );
		if ( ! empty( $req['follow'] ) ) {
			$list[] = $id;
		}
		update_user_meta( $uid, self::META_FOLLOWS, $list );
		return new WP_REST_Response( [ 'ok' => true, 'follows' => $list ], 200 );
	}

	public static function save( WP_REST_Request $req ) {
		$vid = (string) $req['videoId'];
		if ( ! preg_match( '/^[\w-]{11}$/', $vid ) ) {
			return self::fail( 'Unknown video.' );
		}
		$uid   = get_current_user_id();
		$saved = get_user_meta( $uid, self::META_SAVED, true );
		$saved = array_values( array_filter( is_array( $saved ) ? $saved : [], static fn( $s ) => ( $s['id'] ?? '' ) !== $vid ) );
		if ( ! empty( $req['save'] ) ) {
			$ch = (string) $req['channelId'];
			array_unshift(
				$saved,
				[
					'id'        => $vid,
					'title'     => mb_substr( sanitize_text_field( (string) $req['title'] ), 0, 200 ),
					'channelId' => isset( CN_Lineup::by_id()[ $ch ] ) ? $ch : '',
					'channel'   => mb_substr( sanitize_text_field( (string) $req['channel'] ), 0, 100 ),
					'savedAt'   => time(),
				]
			);
			$saved = array_slice( $saved, 0, self::MAX_SAVED );
		}
		update_user_meta( $uid, self::META_SAVED, $saved );
		return new WP_REST_Response( [ 'ok' => true, 'saved' => $saved ], 200 );
	}

	public static function delete_account() {
		$user = wp_get_current_user();
		if ( ! self::is_member( $user ) ) {
			return self::fail( __( 'Staff accounts are managed in wp-admin.', 'channel-network' ), 403, 'staff' );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$uid = $user->ID;
		wp_logout();
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			remove_user_from_blog( $uid, get_current_blog_id() );
			if ( ! get_blogs_of_user( $uid ) ) {
				wpmu_delete_user( $uid );
			}
		} else {
			wp_delete_user( $uid );
		}
		return new WP_REST_Response( [ 'ok' => true, 'status' => 'deleted' ], 200 );
	}

	// ---------------------------------------------------------------------- newsletter

	private static function subscribe_newsletter( $email ) {
		if ( ! class_exists( 'Jetpack_Subscriptions' ) || ! method_exists( 'Jetpack_Subscriptions', 'init' ) ) {
			return;
		}
		$subs = Jetpack_Subscriptions::init();
		if ( $subs && method_exists( $subs, 'subscribe' ) ) {
			$subs->subscribe( $email, 0, true, [ 'source' => 'deprogramming-network' ] ); // WordPress.com emails a confirmation
		}
	}

	// --------------------------------------------------------------------------- dev

	public static function dev_mail_log( $null, $atts ) {
		file_put_contents( CN_DEV_MAIL_LOG, gmdate( 'c' ) . "\nTO: " . implode( ',', (array) $atts['to'] ) . "\nSUBJECT: " . $atts['subject'] . "\n" . $atts['message'] . "\n---\n", FILE_APPEND ); // phpcs:ignore
		return true;
	}

	// ------------------------------------------------------------------ privacy tools

	public static function register_exporter( $exporters ) {
		$exporters['channel-network-members'] = [ 'exporter_friendly_name' => __( 'Deprogramming: follows and saved videos', 'channel-network' ), 'callback' => [ __CLASS__, 'export' ] ];
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['channel-network-members'] = [ 'eraser_friendly_name' => __( 'Deprogramming: follows and saved videos', 'channel-network' ), 'callback' => [ __CLASS__, 'erase' ] ];
		return $erasers;
	}

	public static function export( $email, $page = 1 ) {
		$u = get_user_by( 'email', $email );
		if ( ! $u ) {
			return [ 'data' => [], 'done' => true ];
		}
		$saved = get_user_meta( $u->ID, self::META_SAVED, true );
		return [
			'data' => [
				[
					'group_id'    => 'channel-network-members',
					'group_label' => __( 'Deprogramming network', 'channel-network' ),
					'item_id'     => 'cn-member-' . $u->ID,
					'data'        => [
						[ 'name' => __( 'Followed channels', 'channel-network' ), 'value' => implode( ', ', (array) ( get_user_meta( $u->ID, self::META_FOLLOWS, true ) ?: [] ) ) ],
						[ 'name' => __( 'Saved videos', 'channel-network' ), 'value' => implode( ', ', array_column( is_array( $saved ) ? $saved : [], 'id' ) ) ],
						[ 'name' => __( 'Signed in with Google', 'channel-network' ), 'value' => get_user_meta( $u->ID, self::META_GOOGLE, true ) ? __( 'yes', 'channel-network' ) : __( 'no', 'channel-network' ) ],
					],
				],
			],
			'done' => true,
		];
	}

	public static function erase( $email, $page = 1 ) {
		$u = get_user_by( 'email', $email );
		$n = 0;
		if ( $u ) {
			foreach ( [ self::META_FOLLOWS, self::META_SAVED, self::META_GOOGLE ] as $k ) {
				$n += (int) delete_user_meta( $u->ID, $k );
			}
		}
		return [ 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
	}
}
