<?php
/**
 * FILE: class-cn-cli.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-cli.php
 *
 * WP-CLI commands. On the server, always pass --url=https://deprogrammer.org/ so they run
 * against the right multisite site.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the Channel Network (Deprogramming page).
 */
class CN_CLI {

	/**
	 * Fetch the latest videos for every channel now.
	 *
	 * @when after_wp_load
	 */
	public function refresh() {
		delete_transient( CN_Feeds::LOCK );
		$r = CN_Feeds::refresh_all();
		foreach ( $r['errors'] as $name => $err ) {
			WP_CLI::warning( "$name: $err" );
		}
		$msg = sprintf( '%d OK, %d failing (source: %s).', $r['ok'], $r['failed'], $r['source'] );
		$r['failed'] ? WP_CLI::warning( $msg ) : WP_CLI::success( $msg );
	}

	/**
	 * Show feed and suggestion status.
	 *
	 * @when after_wp_load
	 */
	public function status() {
		$cache  = CN_Feeds::cache();
		$failed = array_filter( $cache['channels'], static fn( $c ) => ! $c['ok'] );
		WP_CLI::log( sprintf( 'Channels in lineup: %d', count( CN_Lineup::channels() ) ) );
		WP_CLI::log( sprintf( 'Source: %s', CN_Settings::effective_source() ) );
		WP_CLI::log( sprintf( 'Last refresh: %s', $cache['refreshed_at'] ? human_time_diff( $cache['refreshed_at'] ) . ' ago' : 'never' ) );
		WP_CLI::log( sprintf( 'Failing feeds: %d', count( $failed ) ) );
		WP_CLI::log( sprintf( 'Videos in stream: %d', count( CN_Feeds::stream() ) ) );
		$next = wp_next_scheduled( CN_Plugin::CRON_HOOK );
		WP_CLI::log( sprintf( 'Next scheduled refresh: %s', $next ? 'in ' . human_time_diff( $next ) : 'not scheduled' ) );
		foreach ( CN_Suggestions::counts() as $s => $n ) {
			WP_CLI::log( sprintf( 'Suggestions %s: %d', $s, $n ) );
		}
	}

	/**
	 * List visitor suggestions.
	 *
	 * [--status=<status>]
	 * : new, added, rejected, duplicate, spam, or all (all excludes spam).
	 * ---
	 * default: new
	 * ---
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options: [table, json, csv]
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function suggestions( $args, $assoc ) {
		$rows = CN_Suggestions::query( sanitize_key( $assoc['status'] ?? 'new' ), 1000 );
		$rows = array_map(
			static fn( $r ) => [
				'id'       => (int) $r->id,
				'created'  => $r->created_at,
				'channel'  => $r->channel_input,
				'key'      => $r->channel_key,
				'times'    => (int) $r->times,
				'category' => $r->category,
				'reason'   => $r->reason,
				'email'    => $r->email,
				'status'   => $r->status,
				'akismet'  => $r->akismet,
			],
			$rows
		);
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'id', 'created', 'channel', 'key', 'times', 'category', 'reason', 'email', 'status', 'akismet' ] );
	}

	/**
	 * Set the status of one or more suggestions.
	 *
	 * <id>...
	 * : Suggestion ids.
	 *
	 * --status=<status>
	 * : new, added, rejected, duplicate or spam.
	 *
	 * [--note=<note>]
	 * : Optional note, e.g. what the channel was added as.
	 *
	 * @subcommand set-status
	 * @when after_wp_load
	 */
	public function set_status( $args, $assoc ) {
		if ( ! in_array( $assoc['status'], CN_Suggestions::STATUSES, true ) ) {
			WP_CLI::error( 'Status must be one of: ' . implode( ', ', CN_Suggestions::STATUSES ) );
		}
		$n = CN_Suggestions::set_status( $args, $assoc['status'], $assoc['note'] ?? '' );
		WP_CLI::success( "$n suggestion(s) updated." );
	}

	/**
	 * List, add, remove or reorder highlighted videos.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list, add, remove, up, down, or suggestions (members' highlight suggestions).
	 * ---
	 * default: list
	 * ---
	 *
	 * [<video>]
	 * : A YouTube video link or id (for add, remove, up, down).
	 *
	 * [--title=<title>]
	 * : Title to use instead of YouTube's (add).
	 *
	 * [--note=<note>]
	 * : Short note shown with the video (add).
	 *
	 * [--bottom]
	 * : Add it last instead of first.
	 *
	 * [--status=<status>]
	 * : For suggestions: new, added, rejected, duplicate, spam, or all.
	 * ---
	 * default: new
	 * ---
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options: [table, json, csv]
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp channel-network highlights add "https://www.youtube.com/live/fttOhpURQGg?si=x" --note="Worth the whole stream"
	 *     wp channel-network highlights up fttOhpURQGg
	 *
	 * @when after_wp_load
	 */
	public function highlights( $args, $assoc ) {
		$action = $args[0] ?? 'list';
		$video  = $args[1] ?? '';
		$format = $assoc['format'] ?? 'table';
		if ( 'list' === $action ) {
			$rows = array_map(
				static fn( $h, $i ) => [ 'pos' => $i + 1, 'id' => $h['id'], 'title' => $h['title'], 'channel' => CN_Highlights::card( $h, $i )['channel_name'], 'note' => $h['note'] ?? '', 'added' => wp_date( 'Y-m-d', (int) ( $h['added'] ?? 0 ) ) ],
				CN_Highlights::all(),
				array_keys( CN_Highlights::all() )
			);
			WP_CLI\Utils\format_items( $format, $rows, [ 'pos', 'id', 'title', 'channel', 'note', 'added' ] );
			return;
		}
		if ( 'suggestions' === $action ) {
			$rows = array_map(
				static fn( $r ) => [ 'id' => (int) $r->id, 'created' => $r->created_at, 'video' => $r->channel_input, 'title' => $r->title, 'times' => (int) $r->times, 'reason' => $r->reason, 'user_id' => (int) $r->user_id, 'status' => $r->status, 'akismet' => $r->akismet ],
				CN_Suggestions::query( sanitize_key( $assoc['status'] ?? 'new' ), 1000, CN_Highlights::KIND )
			);
			WP_CLI\Utils\format_items( $format, $rows, [ 'id', 'created', 'video', 'title', 'times', 'reason', 'user_id', 'status', 'akismet' ] );
			return;
		}
		$id = CN_Highlights::video_id( $video );
		if ( '' === $id ) {
			WP_CLI::error( 'Give a YouTube video link or id.' );
		}
		switch ( $action ) {
			case 'add':
				$r = CN_Highlights::add( $id, [ 'title' => $assoc['title'] ?? '', 'note' => $assoc['note'] ?? '', 'position' => isset( $assoc['bottom'] ) ? 'bottom' : 'top' ] );
				if ( is_wp_error( $r ) ) {
					WP_CLI::error( $r->get_error_message() );
				}
				WP_CLI::success( sprintf( 'Highlighted %s: "%s" (%s).', $r['id'], $r['title'], $r['channel'] ) );
				break;
			case 'remove':
				CN_Highlights::remove( $id ) ? WP_CLI::success( "Removed $id." ) : WP_CLI::warning( "$id isn't highlighted." );
				break;
			case 'up':
			case 'down':
				CN_Highlights::move( $id, 'up' === $action ? -1 : 1 ) ? WP_CLI::success( "Moved $id $action." ) : WP_CLI::warning( "Couldn't move $id $action." );
				break;
			default:
				WP_CLI::error( "Unknown action '$action'. Use list, add, remove, up, down or suggestions." );
		}
	}

	/**
	 * Create the network page (as a draft) if it doesn't exist yet.
	 *
	 * [--title=<title>]
	 * ---
	 * default: Deprogramming
	 * ---
	 *
	 * [--slug=<slug>]
	 * ---
	 * default: network
	 * ---
	 *
	 * @subcommand create-page
	 * @when after_wp_load
	 */
	public function create_page( $args, $assoc ) {
		$existing = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => CN_Page::META, 'meta_value' => '1', 'numberposts' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $existing ) {
			WP_CLI::success( sprintf( 'Network page already exists: #%d (%s) %s', $existing[0]->ID, $existing[0]->post_status, get_permalink( $existing[0] ) ) );
			return;
		}
		$slug  = sanitize_title( $assoc['slug'] ?? 'network' );
		$title = $assoc['title'] ?? 'Deprogramming';
		if ( get_page_by_path( $slug ) ) {
			WP_CLI::error( "A page with the slug '$slug' already exists. Pick another --slug or set meta " . CN_Page::META . '=1 on that page.' );
		}
		$intro = '<!-- wp:paragraph --><p>The independent voices, legal analysts and newsrooms worth your time, in one place. New videos from every channel in the lineup, updated throughout the day.</p><!-- /wp:paragraph -->';
		$id    = wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $intro,
				'meta_input'   => [ CN_Page::META => '1' ],
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Created draft page #%d. Preview: %s', $id, get_preview_post_link( $id ) ) );
	}
}
