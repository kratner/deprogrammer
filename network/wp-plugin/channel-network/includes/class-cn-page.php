<?php
/**
 * FILE: class-cn-page.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-page.php
 *
 * Renders the network page with its own look: any page with post meta _cn_network_page = 1
 * uses templates/network.php instead of the theme's template, and the theme's styles are
 * dropped on it. wp_head()/wp_footer() still run, so SEO tags, analytics and the cookie
 * banner keep working. The page's own editor content is shown as the intro text.
 */

defined( 'ABSPATH' ) || exit;

final class CN_Page {

	const META = '_cn_network_page';

	public static function init() {
		add_filter( 'template_include', [ __CLASS__, 'template' ], 99 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ], 100 );
	}

	public static function is_network_page() {
		return is_page() && (bool) get_post_meta( get_queried_object_id(), self::META, true );
	}

	public static function template( $template ) {
		if ( self::is_network_page() ) {
			// Native emoji render fine everywhere we care about; WordPress's emoji-to-image
			// script only adds weight here.
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			CN_Feeds::maybe_queue_refresh();
			return CN_DIR . 'templates/network.php';
		}
		return $template;
	}

	public static function assets() {
		if ( ! self::is_network_page() ) {
			return;
		}
		// Drop theme and block styles; this page brings its own.
		foreach ( wp_styles()->queue as $handle ) {
			if ( ! in_array( $handle, [ 'admin-bar', 'dashicons' ], true ) && ! self::is_plugin_ui_style( $handle ) ) {
				wp_dequeue_style( $handle );
			}
		}
		wp_enqueue_style( 'channel-network-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400..800&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'channel-network', CN_URL . 'assets/network.css', [ 'channel-network-inter' ], CN_VERSION );
		if ( CN_Members::enabled() && CN_Members::google_client_id() && ! is_user_logged_in() ) {
			wp_enqueue_script( 'google-gsi', 'https://accounts.google.com/gsi/client', [], null, [ 'strategy' => 'async', 'in_footer' => true ] ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_script( 'channel-network', CN_URL . 'assets/network.js', [], CN_VERSION, [ 'strategy' => 'defer', 'in_footer' => true ] );
		wp_localize_script(
			'channel-network',
			'CN',
			[
				'suggestUrl' => esc_url_raw( rest_url( 'channel-network/v1/suggest' ) ),
				'rest'       => esc_url_raw( rest_url( 'channel-network/v1/' ) ),
				'members'    => CN_Members::enabled(),
				'clientId'   => CN_Members::google_client_id(),
				'me'         => CN_Members::enabled() ? CN_Members::me() : [ 'loggedIn' => false ],
				'nonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			]
		);
	}

	/** Keep styles of site-wide UI that must still look right here (cookie banner etc.). */
	private static function is_plugin_ui_style( $handle ) {
		return (bool) preg_match( '/^(wpconsent|jetpack-|akismet)/', $handle );
	}

	/** "3h ago" style text; the page's JS refreshes it client-side. */
	public static function ago( $ts ) {
		$diff = time() - (int) $ts;
		if ( $diff < 60 ) {
			return __( 'just now', 'channel-network' );
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			return sprintf( '%dm ago', floor( $diff / 60 ) );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			return sprintf( '%dh ago', floor( $diff / HOUR_IN_SECONDS ) );
		}
		if ( $diff < 7 * DAY_IN_SECONDS ) {
			return sprintf( '%dd ago', floor( $diff / DAY_IN_SECONDS ) );
		}
		return wp_date( 'M j', (int) $ts );
	}

	/** The site's privacy policy URL, falling back to a page with the slug privacy-policy. */
	public static function privacy_url() {
		$url = get_privacy_policy_url();
		if ( ! $url ) {
			$p   = get_page_by_path( 'privacy-policy' );
			$url = ( $p && 'publish' === $p->post_status ) ? get_permalink( $p ) : '';
		}
		return $url;
	}

	/** Channel avatar: the real YouTube avatar when known, else a coloured monogram. */
	public static function avatar_html( array $channel, $size = 32, $class = 'cn-avatar', $eager = false ) {
		$url = CN_Feeds::avatar( $channel['channelId'] );
		if ( $url ) {
			return sprintf(
				'<img class="%s" src="%s" alt="" width="%d" height="%d" loading="%s" decoding="async" referrerpolicy="no-referrer">',
				esc_attr( $class ),
				esc_url( $url ),
				(int) $size,
				(int) $size,
				$eager ? 'eager' : 'lazy'
			);
		}
		[ $mono, $hue ] = self::monogram( $channel['name'] );
		return sprintf( '<span class="%s cn-mono" style="--h:%d" aria-hidden="true">%s</span>', esc_attr( $class ), (int) $hue, esc_html( $mono ) );
	}

	/** Two-letter monogram + a stable hue for a channel's badge. */
	public static function monogram( $name ) {
		$words = preg_split( '/[\s\-–—:&|]+/u', preg_replace( '/^(the|late night with)\s+/i', '', $name ), -1, PREG_SPLIT_NO_EMPTY );
		$a     = mb_substr( $words[0] ?? $name, 0, 1 );
		$b     = isset( $words[1] ) ? mb_substr( $words[1], 0, 1 ) : mb_substr( $words[0] ?? '', 1, 1 );
		return [ mb_strtoupper( $a . $b ), crc32( $name ) % 360 ];
	}

	/**
	 * Field guide: a few evergreen Deprogrammer.org articles (biases, disinformation),
	 * rotating daily so the section doesn't feel frozen.
	 *
	 * @return WP_Post[]
	 */
	public static function field_guide( $count = 4 ) {
		$cat = get_category_by_slug( 'articles' );
		$ids = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'cat'            => $cat ? $cat->term_id : 0,
				'no_found_rows'  => true,
			]
		);
		if ( ! $ids ) {
			return [];
		}
		mt_srand( (int) gmdate( 'Ymd' ) );
		shuffle( $ids );
		mt_srand();
		return array_map( 'get_post', array_slice( $ids, 0, $count ) );
	}
}
