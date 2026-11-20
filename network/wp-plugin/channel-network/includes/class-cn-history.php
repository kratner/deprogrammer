<?php
/**
 * FILE: class-cn-history.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-history.php
 *
 * The full watch history at <network page>/history/: every network video the curator has
 * watched, grouped by day, with a draggable date scrubber on the right.
 *
 * Data (built by scripts/build-plugin.mjs from the private archive):
 *   data/history/index.json   days, per-category counts, channel table. Inlined into the
 *                             page here (with avatars added), so the layout can be computed
 *                             before any video data arrives.
 *   data/history/YYYY-MM.json one month of videos. assets/history.js fetches a month only
 *                             when it scrolls near; the URL carries a content hash, so the
 *                             files can be cached hard.
 *
 * Route: a rewrite rule per network page (<page uri>/history/ → page_id=N&cn_history=1),
 * flushed automatically when the page set or this plugin version changes. ?cn_history=1 on
 * the network page works too, before the rules are flushed.
 */

defined( 'ABSPATH' ) || exit;

final class CN_History {

	const QV = 'cn_history';

	private static $index = false;

	public static function init() {
		add_filter( 'query_vars', static function ( $vars ) {
			$vars[] = self::QV;
			return $vars;
		} );
		add_action( 'init', [ __CLASS__, 'rewrites' ], 20 );
		// The page list is cached; drop it when a page or its network-page flag changes.
		$forget = static function () {
			delete_transient( 'cn_history_pages' );
		};
		add_action( 'save_post_page', $forget );
		add_action( 'deleted_post', $forget );
		add_action( 'updated_post_meta', $forget );
		add_action( 'added_post_meta', $forget );
		add_action( 'template_redirect', [ __CLASS__, 'seo' ], 1 );
	}

	/** Network pages: id => uri. */
	private static function pages() {
		$ids = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'meta_key'       => CN_Page::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
			]
		);
		$out = [];
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = get_page_uri( $id );
		}
		return $out;
	}

	public static function rewrites() {
		$pages = get_transient( 'cn_history_pages' );
		if ( ! is_array( $pages ) ) {
			$pages = self::pages();
			set_transient( 'cn_history_pages', $pages, $pages ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		}
		foreach ( $pages as $id => $uri ) {
			if ( $uri ) {
				add_rewrite_rule( '^' . preg_quote( $uri, '#' ) . '/history/?$', 'index.php?page_id=' . (int) $id . '&' . self::QV . '=1', 'top' );
			}
		}
		// Flush once whenever the rules we add would change (new page, renamed slug, update).
		$sig = md5( CN_VERSION . wp_json_encode( $pages ) );
		if ( get_option( 'cn_history_rw' ) !== $sig ) {
			update_option( 'cn_history_rw', $sig, false );
			flush_rewrite_rules( false );
		}
	}

	/** Is this request the history view of a network page? */
	public static function is_view() {
		return CN_Page::is_network_page() && get_query_var( self::QV );
	}

	/** URL of the history view for a network page. */
	public static function url( $page_id = 0 ) {
		$page_id = $page_id ?: get_queried_object_id();
		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( get_permalink( $page_id ) ) . 'history/';
		}
		return add_query_arg( self::QV, '1', get_permalink( $page_id ) );
	}

	/** The history index, or null when no history has been built. */
	public static function index() {
		if ( false === self::$index ) {
			$file        = CN_DIR . 'data/history/index.json';
			$json        = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
			self::$index = ( is_array( $json ) && ! empty( $json['days'] ) ) ? $json : null;
		}
		return self::$index;
	}

	/** Total videos in the archive (0 when there is none). */
	public static function total() {
		$i = self::index();
		return $i ? (int) $i['total'] : 0;
	}

	/**
	 * The index as the page's JS gets it: category labels, channel URLs and avatars added,
	 * month file URLs instead of bare hashes.
	 */
	public static function client_data() {
		$i = self::index();
		if ( ! $i ) {
			return null;
		}
		$labels = CN_Lineup::category_labels();
		$byid   = [];
		foreach ( CN_Lineup::channels() as $c ) {
			$byid[ $c['channelId'] ] = $c;
		}
		$cats = [];
		foreach ( $i['cats'] as $key ) {
			$cats[] = [ $key, $labels[ $key ] ?? ucfirst( $key ) ];
		}
		$ch = [];
		foreach ( $i['ch'] as [ $id, $ci ] ) {
			$ch[] = [ $id, (int) $ci, $byid[ $id ]['url'] ?? 'https://www.youtube.com/channel/' . $id ];
		}
		$up = [];
		foreach ( $i['up'] as [ $id, $name, $chi ] ) {
			[ $mono, $hue ] = CN_Page::monogram( (string) $name );
			$up[] = [ $id, $name, (int) $chi, (string) CN_Feeds::avatar( $id ), $mono, $hue ];
		}
		$months = [];
		foreach ( $i['months'] as $m => $hash ) {
			$months[ $m ] = CN_URL . 'data/history/' . $m . '.json?v=' . rawurlencode( $hash );
		}
		return [
			'updated' => $i['updated'],
			'total'   => (int) $i['total'],
			'today'   => wp_date( 'Y-m-d' ),
			'cats'    => $cats,
			'ch'      => $ch,
			'up'      => $up,
			'days'    => $i['days'],
			'months'  => $months,
			'home'    => get_permalink( get_queried_object_id() ),
		];
	}

	/** Month file to preload: the one holding ?d=, else the newest. */
	public static function preload_url() {
		$d = self::client_data();
		if ( ! $d ) {
			return '';
		}
		$want = isset( $_GET['d'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['d'] ) ), 0, 7 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return $d['months'][ $want ] ?? reset( $d['months'] );
	}

	/** Title and canonical for the history view (Yoast runs on deprogrammer.org). */
	public static function seo() {
		if ( ! self::is_view() ) {
			return;
		}
		$title = static function () {
			/* translators: %s: site name */
			return sprintf( __( 'Watch history · Deprogramming · %s', 'channel-network' ), get_bloginfo( 'name' ) );
		};
		$url = static function () {
			return self::url();
		};
		add_filter( 'pre_get_document_title', $title, 99 );
		add_filter( 'wpseo_title', $title, 99 );
		add_filter( 'wpseo_opengraph_title', $title, 99 );
		add_filter( 'wpseo_canonical', $url, 99 );
		add_filter( 'wpseo_opengraph_url', $url, 99 );
		add_filter( 'get_canonical_url', $url, 99 );
		$desc = static function () {
			return sprintf(
				/* translators: %d: number of videos */
				__( 'All %d news videos the curator has watched from the Deprogramming network, day by day.', 'channel-network' ),
				self::total()
			);
		};
		add_filter( 'wpseo_metadesc', $desc, 99 );
		add_filter( 'wpseo_opengraph_desc', $desc, 99 );
	}
}
