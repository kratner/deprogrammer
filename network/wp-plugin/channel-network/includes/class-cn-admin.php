<?php
/**
 * FILE: class-cn-admin.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-admin.php
 *
 * wp-admin → Network: Highlights (the hand-picked videos at the top of the page, plus the
 * members' highlight suggestions queue), Suggestions (channel review queue), Feeds
 * (per-channel health, refresh now), Settings (source, API key, stream options).
 *
 * Both suggestion queues have WordPress-style row actions (1.6.5): hover links under each row
 * (Approve / Quick approve / Reject / Spam / Delete for videos; Mark added / Reject / Duplicate /
 * Spam / Delete for channels), so one suggestion is handled in one click. Quick approve opens an
 * inline row, like Quick Edit, to set the note, title and position before the video goes live.
 * Bulk actions stay for working through many at once.
 */

defined( 'ABSPATH' ) || exit;

final class CN_Admin {

	const SLUG = 'channel-network';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_cn_suggestion_action', [ __CLASS__, 'handle_suggestion_action' ] );
		add_action( 'admin_post_cn_refresh', [ __CLASS__, 'handle_refresh' ] );
		add_action( 'admin_post_cn_highlight_action', [ __CLASS__, 'handle_highlight_action' ] );
		add_action( 'admin_post_cn_row_action', [ __CLASS__, 'handle_row_action' ] );
	}

	/** Row-action ops per queue, as label => op. */
	private static function row_ops( $kind ) {
		if ( 'feedback' === $kind ) {
			return [ 'Done' => 'added', 'Spam' => 'spam', 'Delete' => 'delete' ]; // 'added' is shown as "done" here
		}
		return 'highlight' === $kind
			? [ 'Approve' => 'approve', 'Reject' => 'rejected', 'Spam' => 'spam', 'Delete' => 'delete' ]
			: [ 'Add to lineup' => 'lineup', 'Reject' => 'rejected', 'Duplicate' => 'duplicate', 'Spam' => 'spam', 'Delete' => 'delete' ];
	}

	/** The hover links under a suggestion row (only the ones that change its status). */
	private static function row_actions( $kind, $r, $status ) {
		$links = [];
		foreach ( self::row_ops( $kind ) as $label => $op ) {
			if ( $op === $r->status || ( ( 'approve' === $op || 'lineup' === $op ) && 'added' === $r->status ) ) {
				continue;
			}
			if ( 'lineup' === $op ) { // opens the inline "Add to lineup" panel (no GET action)
				$links[] = sprintf( '<span class="cn-approve"><button type="button" class="button-link cn-qa-toggle" aria-expanded="false" aria-controls="cn-la-%1$d">%2$s</button></span>', (int) $r->id, esc_html( $label ) );
				continue;
			}
			$url = wp_nonce_url(
				add_query_arg( [ 'action' => 'cn_row_action', 'kind' => $kind, 'op' => $op, 'id' => (int) $r->id, 'return_status' => $status ], admin_url( 'admin-post.php' ) ),
				'cn_row_' . $op . '_' . (int) $r->id
			);
			$cls     = 'delete' === $op || 'spam' === $op ? 'trash' : ( 'approve' === $op || 'added' === $op ? 'cn-approve' : 'edit' ); // not 'approve': core CSS hides that (comments screen)
			$confirm = 'delete' === $op ? ' onclick="return confirm(\'Delete this suggestion permanently?\')"' : '';
			$links[] = sprintf( '<span class="%s"><a href="%s"%s>%s</a></span>', esc_attr( $cls ), esc_url( $url ), $confirm, esc_html( $label ) );
			if ( 'approve' === $op ) {
				$links[] = sprintf( '<span class="edit"><button type="button" class="button-link cn-qa-toggle" aria-expanded="false" aria-controls="cn-qa-%1$d">%2$s</button></span>', (int) $r->id, esc_html__( 'Quick approve', 'channel-network' ) );
			}
		}
		return $links ? '<div class="row-actions">' . implode( ' | ', $links ) . '</div>' : '';
	}

	/** One click on a row action: change that one suggestion and come back to the same list. */
	public static function handle_row_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		$kind = in_array( $_GET['kind'] ?? '', [ 'highlight', 'feedback' ], true ) ? sanitize_key( $_GET['kind'] ) : 'channel'; // phpcs:ignore WordPress.Security.NonceVerification -- verified below.
		$op   = sanitize_key( $_GET['op'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$id   = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'cn_row_' . $op . '_' . $id );
		if ( ! $id || 'lineup' === $op || ! in_array( $op, self::row_ops( $kind ), true ) ) {
			wp_die( 'Unknown action', 400 );
		}
		if ( 'approve' === $op ) {
			$msg = CN_Highlights::approve( [ $id ] ) ? 'Highlighted. It\'s on the page now.' : 'Not added: that video is already highlighted or YouTube didn\'t return it.';
		} elseif ( 'delete' === $op ) {
			$msg = CN_Suggestions::delete( [ $id ] ) ? 'Suggestion deleted.' : 'Nothing deleted.';
		} else {
			CN_Suggestions::set_status( [ $id ], $op );
			$msg = [ 'added' => 'feedback' === $kind ? 'Marked done.' : 'Marked added.', 'rejected' => 'Rejected.', 'duplicate' => 'Marked duplicate.', 'spam' => 'Marked spam.' ][ $op ] ?? 'Updated.';
		}
		$args = [ 'status' => sanitize_key( $_GET['return_status'] ?? 'new' ), 'cn_msg' => rawurlencode( $msg ) ]; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'highlight' === $kind ) {
			$args['tab'] = 'highlights';
		} elseif ( 'feedback' === $kind ) {
			$args['tab'] = 'feedback';
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public static function menu() {
		$new   = CN_Suggestions::counts()['new'] + CN_Suggestions::counts( CN_Highlights::KIND )['new'] + CN_Suggestions::counts( 'feedback' )['new'];
		$badge = $new ? ' <span class="awaiting-mod">' . (int) $new . '</span>' : '';
		add_menu_page( 'Channel Network', 'Channel Network' . $badge, 'manage_options', self::SLUG, [ __CLASS__, 'render' ], 'dashicons-video-alt3', 26 );
	}

	public static function register_settings() {
		register_setting( 'cn_settings', CN_Settings::OPTION, [ 'sanitize_callback' => [ 'CN_Settings', 'sanitize' ] ] );
	}

	private static function url( $args = [] ) {
		return add_query_arg( $args + [ 'page' => self::SLUG ], admin_url( 'admin.php' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'suggestions'; // phpcs:ignore WordPress.Security.NonceVerification
		$hl_new = CN_Suggestions::counts( CN_Highlights::KIND )['new'];
		$ch_new = CN_Suggestions::counts()['new'];
		$fb_new = CN_Suggestions::counts( 'feedback' )['new'];
		$tabs   = [
			'highlights'  => 'Highlights' . ( $hl_new ? " ($hl_new new)" : '' ),
			'suggestions' => 'Channel suggestions' . ( $ch_new ? " ($ch_new new)" : '' ),
			'feedback'    => 'Suggestion box' . ( $fb_new ? " ($fb_new new)" : '' ),
			'feeds'       => 'Feeds',
			'settings'    => 'Settings',
		];
		echo '<div class="wrap"><h1>Channel Network</h1>';
		self::page_notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( [ 'tab' => $k ] ) ), $k === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		if ( isset( $_GET['cn_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['cn_msg'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( 'highlights' === $tab ) {
			self::render_highlights();
		} elseif ( 'feedback' === $tab ) {
			self::render_feedback();
		} elseif ( 'feeds' === $tab ) {
			self::render_feeds();
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} else {
			self::render_suggestions();
		}
		echo '</div>';
	}

	/** Shows where the network page is, or how to create it. */
	private static function page_notice() {
		$pages = get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'private' ], 'meta_key' => CN_Page::META, 'meta_value' => '1', 'numberposts' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $pages ) {
			$p = $pages[0];
			printf(
				'<p>Network page: <a href="%s">%s</a> (%s) · <a href="%s">edit intro text</a></p>',
				esc_url( get_permalink( $p ) ),
				esc_html( get_the_title( $p ) ),
				esc_html( $p->post_status ),
				esc_url( get_edit_post_link( $p ) )
			);
		} else {
			echo '<div class="notice notice-warning"><p>No page is set up as the network page yet. Run <code>wp channel-network create-page</code>.</p></div>';
		}
	}

	private static function render_suggestions() {
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification
		$status = in_array( $status, array_merge( CN_Suggestions::STATUSES, [ 'all' ] ), true ) ? $status : 'new';
		$counts = CN_Suggestions::counts();
		$rows   = CN_Suggestions::query( $status );
		$cats   = CN_Lineup::category_labels();

		echo '<ul class="subsubsub">';
		$links = [];
		foreach ( array_merge( [ 'all' ], CN_Suggestions::STATUSES ) as $s ) {
			$n       = 'all' === $s ? array_sum( $counts ) - $counts['spam'] : $counts[ $s ];
			$links[] = sprintf( '<li><a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( self::url( [ 'status' => $s ] ) ), $s === $status ? ' class="current"' : '', esc_html( ucfirst( $s ) ), (int) $n );
		}
		echo implode( ' | </li>', $links ) . '</li></ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<p class="description"><strong>Add to lineup</strong> (hover a suggestion) looks the channel up on YouTube and puts it on the page right away: in the lineup, its category filter and the feeds. You can also add any channel yourself below.</p>';
		self::render_lineup_add_box();

		if ( ! $rows ) {
			echo '<p>No suggestions here.</p>';
			self::render_lineup_added();
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cn_suggestion_action' );
		echo '<input type="hidden" name="action" value="cn_suggestion_action"><input type="hidden" name="return_status" value="' . esc_attr( $status ) . '">';
		echo '<div class="tablenav top"><select name="do"><option value="">Bulk action</option><option value="added">Mark added</option><option value="rejected">Reject</option><option value="duplicate">Mark duplicate</option><option value="spam">Mark spam</option><option value="new">Back to new</option><option value="delete">Delete permanently</option></select> <input type="submit" class="button" value="Apply"></div>';
		echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.cn-cb\').forEach(c=>c.checked=this.checked)"></td><th>Submitted</th><th>Channel</th><th>Times suggested</th><th>Category</th><th>Why</th><th>Email</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$href = preg_match( '~^https?://~i', $r->channel_input ) ? $r->channel_input
				: ( 0 === strpos( $r->channel_input, '@' ) || 0 === strpos( $r->channel_input, 'UC' ) ? 'https://www.youtube.com/' . ( 0 === strpos( $r->channel_input, 'UC' ) ? 'channel/' : '' ) . $r->channel_input : '' );
			printf(
				'<tr><th class="check-column"><input class="cn-cb" type="checkbox" name="ids[]" value="%d"></th><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td></tr>',
				(int) $r->id,
				esc_html( get_date_from_gmt( $r->created_at, 'M j, Y g:i a' ) ),
				( $href ? '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">' . esc_html( $r->channel_input ) . '</a>' : esc_html( $r->channel_input ) ) . self::row_actions( 'channel', $r, $status ) . ( 'added' !== $r->status ? self::lineup_panel( $r ) : '' ),
				(int) $r->times,
				esc_html( $cats[ $r->category ] ?? '' ),
				esc_html( $r->reason ),
				$r->email ? '<a href="mailto:' . esc_attr( $r->email ) . '">' . esc_html( $r->email ) . '</a>' : '',
				esc_html( $r->status ),
				'spam' === $r->akismet && 'spam' !== $r->status ? ' <span class="description" style="color:#b32d2e">· may be spam (Akismet)</span>' : ''
			);
		}
		echo '</tbody></table></form>';
		self::quick_approve_assets();
		self::render_lineup_added();
	}

	// ------------------------------------------------------------- Suggestion box tab (1.6.6)

	private static function render_feedback() {
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification
		$status = in_array( $status, [ 'new', 'added', 'spam', 'all' ], true ) ? $status : 'new';
		$counts = CN_Suggestions::counts( 'feedback' );
		$rows   = CN_Suggestions::query( $status, 200, 'feedback' );
		echo '<p class="description">Short notes from visitors (up to ' . (int) CN_Suggestions::BRIEF . ' characters, no sign-in). Hover a row for Done, Spam and Delete. Nothing here is shown on the page.</p>';
		echo '<ul class="subsubsub">';
		$links = [];
		foreach ( [ 'new' => 'New', 'added' => 'Done', 'spam' => 'Spam', 'all' => 'All' ] as $s => $label ) {
			$n       = 'all' === $s ? array_sum( $counts ) - $counts['spam'] : $counts[ $s ];
			$links[] = sprintf( '<li><a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( self::url( [ 'tab' => 'feedback', 'status' => $s ] ) ), $s === $status ? ' class="current"' : '', esc_html( $label ), (int) $n );
		}
		echo implode( ' | </li>', $links ) . '</li></ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! $rows ) {
			echo '<p>' . ( 'new' === $status ? 'Nothing new in the suggestion box.' : 'Nothing here.' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th style="width:170px">Received</th><th>Suggestion</th><th style="width:90px">Status</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s%s%s</td><td>%s</td></tr>',
				esc_html( get_date_from_gmt( $r->created_at, 'M j, Y g:i a' ) ),
				nl2br( esc_html( $r->reason ) ),
				(int) $r->times > 1 ? ' <span class="description">(sent ' . (int) $r->times . ' times)</span>' : '',
				self::row_actions( 'feedback', $r, $status ),
				esc_html( 'added' === $r->status ? 'done' : $r->status ) . ( 'spam' === $r->akismet && 'spam' !== $r->status ? ' <span class="description" style="color:#b32d2e">· may be spam</span>' : '' )
			);
		}
		echo '</tbody></table>';
	}

	/** Category <select> for the lineup. */
	private static function category_select( $name, $selected = '' ) {
		$o = '<select name="' . esc_attr( $name ) . '"><option value="">Choose a category…</option>';
		foreach ( CN_Lineup::category_labels() as $k => $label ) {
			$o .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $selected, $k, false ), esc_html( $label ) );
		}
		return $o . '</select>';
	}

	/** Inline "Add to lineup" panel for one suggestion (inside the bulk form). */
	private static function lineup_panel( $r ) {
		$id = (int) $r->id;
		return sprintf(
			'<div class="cn-qa" id="cn-la-%1$d" hidden><p><label>Category<br>%2$s</label></p><p><label>Name on the page <span class="description">(optional; YouTube\'s name when empty)</span><br><input type="text" name="la[%1$d][name]" class="regular-text" maxlength="120"></label></p><p><button class="button button-primary" name="lineup_add" value="%1$d">Add to lineup</button> <button type="button" class="button cn-qa-cancel">Cancel</button></p></div>',
			$id,
			self::category_select( 'la[' . $id . '][category]', (string) $r->category )
		);
	}

	private static function render_lineup_add_box() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 0 18px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;max-width:900px">';
		wp_nonce_field( 'cn_suggestion_action' );
		echo '<input type="hidden" name="action" value="cn_suggestion_action"><input type="hidden" name="op" value="lineup_manual">';
		echo '<p style="margin-top:0"><strong>Add a channel to the lineup</strong></p>';
		echo '<p><label>YouTube channel <span class="description">(a channel link, @handle, or a link to any of its videos)</span><br><input type="text" name="lm_input" class="large-text" required placeholder="https://www.youtube.com/@channel"></label></p>';
		echo '<p><label>Category<br>' . self::category_select( 'lm_category' ) . '</label> &nbsp; <label>Name on the page <span class="description">(optional)</span><br><input type="text" name="lm_name" class="regular-text" maxlength="120"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in category_select().
		submit_button( 'Add to lineup', 'primary', 'submit', false );
		echo '</form>';
	}

	/** Channels added here (not in the shipped list), with Remove. */
	private static function render_lineup_added() {
		$added = CN_Lineup_Admin::all();
		if ( ! $added ) {
			return;
		}
		$cats = CN_Lineup::category_labels();
		echo '<h2 style="margin-top:28px">Added from wp-admin</h2><p class="description">These are on the page now. They\'re folded into the main lineup list at the next weekly review.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cn_suggestion_action' );
		echo '<input type="hidden" name="action" value="cn_suggestion_action">';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Channel</th><th>Category</th><th>Added</th><th></th></tr></thead><tbody>';
		foreach ( $added as $c ) {
			printf(
				'<tr><td><a href="%s" target="_blank" rel="noopener"><strong>%s</strong></a></td><td>%s</td><td>%s</td><td><button class="button-link-delete" name="op" value="lineup_remove|%s" onclick="return confirm(\'Take this channel off the page?\')">Remove</button></td></tr>',
				esc_url( $c['url'] ),
				esc_html( $c['name'] ),
				esc_html( $cats[ $c['category'] ] ?? $c['category'] ),
				esc_html( wp_date( 'M j, Y', (int) $c['added'] ) . ( ! empty( $c['by'] ) ? ' by ' . $c['by'] : '' ) ),
				esc_attr( $c['channelId'] )
			);
		}
		echo '</tbody></table></form>';
	}

	/** After a lineup change: fetch the new channel's videos and avatar now, not on the next cron. */
	private static function refresh_after_lineup_change() {
		if ( method_exists( 'CN_Feeds', 'refresh_all' ) ) {
			CN_Feeds::refresh_all();
		}
	}

	public static function handle_suggestion_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		check_admin_referer( 'cn_suggestion_action' );
		$op = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );
		$sid = absint( $_POST['lineup_add'] ?? 0 );
		if ( $sid || 'lineup_manual' === $op || 0 === strpos( $op, 'lineup_remove|' ) ) {
			if ( 0 === strpos( $op, 'lineup_remove|' ) ) {
				$n   = CN_Lineup_Admin::remove( substr( $op, 14 ) );
				$msg = $n ? 'Removed from the lineup.' : 'Nothing removed.';
			} else {
				if ( $sid ) {
					$s     = CN_Suggestions::get_many( [ $sid ] )[0] ?? null;
					$la    = (array) ( $_POST['la'][ $sid ] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below / in add().
					$input = $s ? $s->channel_input : '';
					$cat   = sanitize_key( $la['category'] ?? '' );
					$name  = (string) wp_unslash( $la['name'] ?? '' );
				} else {
					$input = (string) wp_unslash( $_POST['lm_input'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to a channel key in add().
					$cat   = sanitize_key( $_POST['lm_category'] ?? '' );
					$name  = (string) wp_unslash( $_POST['lm_name'] ?? '' );
				}
				$c = CN_Lineup_Admin::add( $input, $cat, $name, $sid );
				if ( is_wp_error( $c ) ) {
					$msg = 'Not added: ' . $c->get_error_message();
					if ( $sid && 'duplicate' === $c->get_error_code() ) {
						CN_Suggestions::set_status( [ $sid ], 'duplicate' );
						$msg .= ' The suggestion is marked duplicate.';
					}
				} else {
					if ( $sid ) {
						CN_Suggestions::set_status( [ $sid ], 'added' );
					}
					self::refresh_after_lineup_change();
					$msg = sprintf( 'Added %s to %s. It\'s on the page now.', $c['name'], CN_Lineup::category_labels()[ $c['category'] ] ?? $c['category'] );
				}
			}
			wp_safe_redirect( self::url( [ 'status' => sanitize_key( $_POST['return_status'] ?? 'new' ), 'cn_msg' => rawurlencode( $msg ) ] ) );
			exit;
		}
		$ids = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
		$do  = sanitize_key( $_POST['do'] ?? '' );
		$n   = 0;
		if ( $ids && 'delete' === $do ) {
			$n = CN_Suggestions::delete( $ids );
		} elseif ( $ids && $do ) {
			$n = CN_Suggestions::set_status( $ids, $do );
		}
		wp_safe_redirect( self::url( [ 'status' => sanitize_key( $_POST['return_status'] ?? 'new' ), 'cn_msg' => sprintf( '%d suggestion(s) updated.', $n ) ] ) );
		exit;
	}

	// -------------------------------------------------------------------- Highlights tab

	private static function render_highlights() {
		$list    = CN_Highlights::all();
		$post    = esc_url( admin_url( 'admin-post.php' ) );
		$waiting = CN_Suggestions::counts( CN_Highlights::KIND )['new'];
		if ( $waiting ) {
			self::render_hl_queue( $waiting ); // new suggestions first, so they can't be missed
		}
		echo '<h2' . ( $waiting ? ' style="margin-top:32px"' : '' ) . '>Highlighted on the page</h2>';
		printf( '<p class="description">Up to %1$d are shown up front; the first is the large feature. Each stays up front for %2$d days, then moves to the archive by itself (visitors see it under "See all highlights"). <strong>Pin</strong> keeps one up front; <strong>Archive now</strong> moves one early; <strong>Bring back</strong> puts an archived one up front for another %2$d days. Nothing is deleted unless you remove it.</p>', (int) CN_Highlights::SHOW, (int) CN_Highlights::FRESH_DAYS );

		// Add a video.
		echo '<form method="post" action="' . $post . '" style="margin:12px 0 20px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;max-width:900px">';
		wp_nonce_field( 'cn_highlight_action' );
		echo '<input type="hidden" name="action" value="cn_highlight_action"><input type="hidden" name="op" value="add">';
		echo '<p><label><strong>YouTube link</strong><br><input type="text" name="url" class="large-text" required placeholder="https://www.youtube.com/watch?v=… (any YouTube video link)"></label></p>';
		echo '<p><label>Note shown with it <span class="description">(optional, 280 characters)</span><br><input type="text" name="note" class="large-text" maxlength="280"></label></p>';
		echo '<p><label>Title <span class="description">(optional; fetched from YouTube when empty)</span><br><input type="text" name="title" class="large-text" maxlength="200"></label></p>';
		echo '<p><label><input type="radio" name="position" value="top" checked> Put it first</label> &nbsp; <label><input type="radio" name="position" value="bottom"> Put it last</label></p>';
		submit_button( 'Highlight this video', 'primary', 'submit', false );
		echo '</form>';

		if ( ! $list ) {
			echo '<p>No highlights yet, so the section is hidden on the page.</p>';
		} else {
			echo '<form method="post" action="' . $post . '">';
			wp_nonce_field( 'cn_highlight_action' );
			echo '<input type="hidden" name="action" value="cn_highlight_action">';
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:2em">#</th><th style="width:130px">Video</th><th>Title</th><th>Note</th><th>Added</th><th>On the page</th><th style="width:170px">Order</th></tr></thead><tbody>';
			$last  = count( $list ) - 1;
			$front = array_column( array_slice( CN_Highlights::current(), 0, CN_Highlights::SHOW ), 'id' );
			foreach ( $list as $i => $h ) {
				$c   = CN_Highlights::card( $h, $i );
				$pos = array_search( $h['id'], $front, true );
				$cur = CN_Highlights::is_current( $h );
				if ( false !== $pos ) {
					$state = ( 0 === $pos ? '<strong>Feature</strong>' : 'Up front' ) . ( ! empty( $h['pinned'] ) ? ' · pinned' : ( $cur ? ' · until ' . esc_html( wp_date( 'M j', CN_Highlights::expires( $h ) ) ) : ' · <span class="description">shown because nothing else is up front</span>' ) );
				} elseif ( $cur ) {
					$state = '<span class="description">Up front, but more than ' . (int) CN_Highlights::SHOW . ' are: waiting for room</span>';
				} else {
					$state = '<span class="description">Archived' . ( ! empty( $h['archived'] ) ? ' (by you)' : ' ' . esc_html( wp_date( 'M j', CN_Highlights::expires( $h ) ) ) ) . '</span>';
				}
				$state .= '<br>' . ( $cur
					? ( ! empty( $h['pinned'] ) ? '<button class="button-link" name="op" value="unpin|' . esc_attr( $h['id'] ) . '">Unpin</button>' : '<button class="button-link" name="op" value="pin|' . esc_attr( $h['id'] ) . '">Pin</button>' ) . ' | <button class="button-link" name="op" value="archive|' . esc_attr( $h['id'] ) . '">Archive now</button>'
					: '<button class="button-link" name="op" value="restore|' . esc_attr( $h['id'] ) . '">Bring back</button> | <button class="button-link" name="op" value="pin|' . esc_attr( $h['id'] ) . '">Pin</button>' );
				printf(
					'<tr><td>%1$d%2$s</td><td><a href="%3$s" target="_blank" rel="noopener"><img src="https://i.ytimg.com/vi/%4$s/mqdefault.jpg" width="120" height="68" alt="" style="display:block;border-radius:4px"></a></td><td><a href="%3$s" target="_blank" rel="noopener"><strong>%5$s</strong></a><br><span class="description">%6$s%7$s</span></td><td><input type="text" class="regular-text" name="notes[%4$s]" value="%8$s" maxlength="280"></td><td>%9$s</td><td>%12$s</td><td>%10$s %11$s <button class="button-link-delete" name="op" value="remove|%4$s" onclick="return confirm(\'Remove this highlight?\')">Remove</button></td></tr>',
					(int) $i + 1,
					'',
					esc_url( CN_Highlights::watch_url( $h['id'] ) ),
					esc_attr( $h['id'] ),
					esc_html( $h['title'] ),
					esc_html( $c['channel_name'] ),
					'' !== $c['cat'] ? esc_html( ' · ' . $c['cat_label'] ) : '',
					esc_attr( $h['note'] ?? '' ),
					esc_html( wp_date( 'M j, Y', (int) ( $h['added'] ?? 0 ) ) ),
					$i > 0 ? '<button class="button button-small" name="op" value="up|' . esc_attr( $h['id'] ) . '" aria-label="Move up">↑</button>' : '',
					$i < $last ? '<button class="button button-small" name="op" value="down|' . esc_attr( $h['id'] ) . '" aria-label="Move down">↓</button>' : '',
					$state // escaped above
				);
			}
			echo '</tbody></table><p><button class="button" name="op" value="notes">Save notes</button></p></form>';
		}
		if ( ! $waiting ) {
			self::render_hl_queue( 0 );
		}
	}

	/** Members' highlight suggestions (shown above the add form while any are new). */
	private static function render_hl_queue( $waiting ) {
		$post   = esc_url( admin_url( 'admin-post.php' ) );
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification
		$status = in_array( $status, array_merge( CN_Suggestions::STATUSES, [ 'all' ] ), true ) ? $status : 'new';
		$counts = CN_Suggestions::counts( CN_Highlights::KIND );
		$rows   = CN_Suggestions::query( $status, 200, CN_Highlights::KIND );
		echo $waiting
			? '<h2>' . esc_html( sprintf( _n( '%d suggested video is waiting for you', '%d suggested videos are waiting for you', $waiting, 'channel-network' ), $waiting ) ) . '</h2><p class="description">Hover a row (or tab to it) for Approve, Quick approve, Reject, Spam and Delete. Quick approve lets you add a note, adjust the title and choose where it goes before it\'s published.</p>'
			: '<h2 style="margin-top:32px">Suggested by members</h2>';
		if ( ! CN_Members::enabled() ) {
			echo '<p class="description">Member sign-in is off (Settings), so members can\'t suggest highlights right now.</p>';
		}
		echo '<ul class="subsubsub">';
		$links = [];
		foreach ( array_merge( [ 'all' ], CN_Suggestions::STATUSES ) as $s ) {
			$n       = 'all' === $s ? array_sum( $counts ) - $counts['spam'] : $counts[ $s ];
			$label   = 'added' === $s ? 'Approved' : ucfirst( $s );
			$links[] = sprintf( '<li><a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( self::url( [ 'tab' => 'highlights', 'status' => $s ] ) ), $s === $status ? ' class="current"' : '', esc_html( $label ), (int) $n );
		}
		echo implode( ' | </li>', $links ) . '</li></ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! $rows ) {
			echo '<p>No suggestions here.</p>';
			return;
		}
		echo '<form method="post" action="' . $post . '">';
		wp_nonce_field( 'cn_highlight_action' );
		echo '<input type="hidden" name="action" value="cn_highlight_action"><input type="hidden" name="op" value="bulk"><input type="hidden" name="return_status" value="' . esc_attr( $status ) . '">';
		echo '<div class="tablenav top"><select name="do"><option value="">Bulk action</option><option value="approve">Approve (highlight it)</option><option value="rejected">Reject</option><option value="duplicate">Mark duplicate</option><option value="spam">Mark spam</option><option value="new">Back to new</option><option value="delete">Delete permanently</option></select> <input type="submit" class="button" value="Apply"></div>';
		self::quick_approve_assets();
		echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.cn-hcb\').forEach(c=>c.checked=this.checked)"></td><th>Submitted</th><th style="width:130px">Video</th><th style="width:34%">Title</th><th>Times suggested</th><th>Why</th><th>Member</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$vid  = CN_Highlights::video_id( $r->channel_input );
			$user = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
			printf(
				'<tr><th class="check-column"><input class="cn-hcb" type="checkbox" name="ids[]" value="%d"></th><td>%s</td><td><a href="%s" target="_blank" rel="noopener"><img src="https://i.ytimg.com/vi/%s/mqdefault.jpg" width="120" height="68" alt="" style="display:block;border-radius:4px"></a></td><td>%s%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s%s</td></tr>',
				(int) $r->id,
				esc_html( get_date_from_gmt( $r->created_at, 'M j, Y g:i a' ) ),
				esc_url( $r->channel_input ),
				esc_attr( $vid ),
				'<strong>' . esc_html( $r->title ) . '</strong>',
				( CN_Highlights::is_highlighted( $vid ) ? ' <span class="description">(already highlighted)</span>' : '' )
					. '<br><a class="cn-q-url" href="' . esc_url( $r->channel_input ) . '" target="_blank" rel="noopener">' . esc_html( $r->channel_input ) . '</a>'
					. self::row_actions( 'highlight', $r, $status )
					. ( 'added' !== $r->status ? self::quick_approve_panel( $r ) : '' ),
				(int) $r->times,
				esc_html( $r->reason ),
				! empty( $r->anonymous ) ? '<span class="description">Anonymous (member\'s choice)</span>' : ( $user ? esc_html( $user->display_name . ' <' . $user->user_email . '>' ) : '<span class="description">deleted account</span>' ),
				esc_html( 'added' === $r->status ? 'approved' : $r->status ),
				'spam' === $r->akismet && 'spam' !== $r->status ? ' <span class="description" style="color:#b32d2e">· may be spam (Akismet)</span>' : ''
			);
		}
		echo '</tbody></table></form>';
	}

	/** Quick approve: an inline panel (like Quick Edit) inside the row's title cell. */
	private static function quick_approve_panel( $r ) {
		$id = (int) $r->id;
		return sprintf(
			'<div class="cn-qa" id="cn-qa-%1$d" hidden><p><label>Note shown with it <span class="description">(optional)</span><br><input type="text" name="qa[%1$d][note]" class="large-text" maxlength="280"></label></p>'
			. '<p><label>Title<br><input type="text" name="qa[%1$d][title]" class="large-text" maxlength="200" value="%2$s"></label></p>'
			. '<p><label><input type="radio" name="qa[%1$d][position]" value="top" checked> Put it first</label> &nbsp; <label><input type="radio" name="qa[%1$d][position]" value="bottom"> Put it last</label></p>'
			. '<p><button class="button button-primary" name="op" value="quick|%1$d">Approve and highlight</button> <button type="button" class="button cn-qa-cancel">Cancel</button></p></div>',
			$id,
			esc_attr( $r->title )
		);
	}

	private static function quick_approve_assets() {
		echo '<style>.cn-qa{margin:8px 0 4px;padding:10px 12px;background:#f6f7f7;border:1px solid #c3c4c7}.cn-qa p{margin:6px 0}.cn-q-url{font-size:12px;word-break:break-all;color:#50575e}.row-actions .cn-approve a{color:#007017;font-weight:600}</style>';
		echo '<script>document.addEventListener("click",function(e){var t=e.target.closest(".cn-qa-toggle,.cn-qa-cancel");if(!t)return;var p=t.classList.contains("cn-qa-cancel")?t.closest(".cn-qa"):document.getElementById(t.getAttribute("aria-controls"));if(!p)return;p.hidden=!p.hidden;var b=document.querySelector("[aria-controls=\'"+p.id+"\']");if(b)b.setAttribute("aria-expanded",String(!p.hidden));if(!p.hidden){var i=p.querySelector("input");if(i)i.focus();}});</script>';
	}

	public static function handle_highlight_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		check_admin_referer( 'cn_highlight_action' );
		$op   = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );
		[ $verb, $id ] = array_pad( explode( '|', $op, 2 ), 2, '' );
		$args = [ 'tab' => 'highlights' ];
		$msg  = '';
		if ( 'add' === $verb ) {
			$r   = CN_Highlights::add(
				wp_unslash( $_POST['url'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to a video id inside.
				[
					'title'    => wp_unslash( $_POST['title'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized inside.
					'note'     => wp_unslash( $_POST['note'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized inside.
					'position' => 'bottom' === ( $_POST['position'] ?? '' ) ? 'bottom' : 'top',
				]
			);
			$msg = is_wp_error( $r ) ? $r->get_error_message() : sprintf( 'Highlighted "%s".', $r['title'] );
		} elseif ( 'up' === $verb || 'down' === $verb ) {
			CN_Highlights::move( $id, 'up' === $verb ? -1 : 1 );
			$msg = 'Order updated.';
		} elseif ( 'remove' === $verb ) {
			$msg = CN_Highlights::remove( $id ) ? 'Highlight removed.' : 'Nothing removed.';
		} elseif ( 'quick' === $verb ) {
			$sid = absint( $id );
			$qa  = (array) ( $_POST['qa'][ $sid ] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitized in CN_Highlights::add().
			$n   = $sid ? CN_Highlights::approve(
				[ $sid ],
				[
					'note'     => (string) wp_unslash( $qa['note'] ?? '' ),
					'title'    => (string) wp_unslash( $qa['title'] ?? '' ),
					'position' => 'bottom' === ( $qa['position'] ?? '' ) ? 'bottom' : 'top',
				]
			) : 0;
			$msg            = $n ? 'Highlighted. It\'s on the page now.' : 'Not added: that video is already highlighted or YouTube didn\'t return it.';
			$args['status'] = sanitize_key( $_POST['return_status'] ?? 'new' );
		} elseif ( in_array( $verb, [ 'pin', 'unpin', 'archive', 'restore' ], true ) ) {
			CN_Highlights::set_state( $id, $verb );
			$msg = [ 'pin' => 'Pinned: it stays up front until you unpin it.', 'unpin' => 'Unpinned: it moves to the archive when its time is up.', 'archive' => 'Moved to the archive.', 'restore' => 'Brought back up front.' ][ $verb ];
		} elseif ( 'notes' === $verb ) {
			CN_Highlights::set_notes( array_map( static fn( $v ) => (string) wp_unslash( $v ), (array) ( $_POST['notes'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized inside.
			$msg = 'Notes saved.';
		} elseif ( 'bulk' === $verb ) {
			$ids = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
			$do  = sanitize_key( $_POST['do'] ?? '' );
			$n   = 0;
			if ( $ids && 'approve' === $do ) {
				$n   = CN_Highlights::approve( $ids );
				$msg = sprintf( '%d video(s) highlighted.', $n );
			} else {
				if ( $ids && 'delete' === $do ) {
					$n = CN_Suggestions::delete( $ids );
				} elseif ( $ids && $do ) {
					$n = CN_Suggestions::set_status( $ids, $do );
				}
				$msg = sprintf( '%d suggestion(s) updated.', $n );
			}
			$args['status'] = sanitize_key( $_POST['return_status'] ?? 'new' );
		}
		wp_safe_redirect( self::url( $args + [ 'cn_msg' => rawurlencode( $msg ) ] ) ); // encoded: redirects strip apostrophes
		exit;
	}

	private static function render_feeds() {
		$cache = CN_Feeds::cache();
		printf(
			'<p>Source: <strong>%s</strong> · Last refresh: <strong>%s</strong> · Refreshes every %d minutes in the background. Next run: %s</p>',
			esc_html( 'api' === CN_Settings::effective_source() ? 'YouTube Data API' : 'RSS feeds' ),
			$cache['refreshed_at'] ? esc_html( human_time_diff( $cache['refreshed_at'] ) . ' ago' ) : 'never',
			(int) CN_Settings::get( 'refresh_minutes' ),
			wp_next_scheduled( CN_Plugin::CRON_HOOK ) ? esc_html( human_time_diff( wp_next_scheduled( CN_Plugin::CRON_HOOK ) ) ) : 'not scheduled'
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cn_refresh' );
		echo '<input type="hidden" name="action" value="cn_refresh"><p><input type="submit" class="button button-primary" value="Refresh now"></p></form>';
		echo '<table class="widefat striped"><thead><tr><th>Channel</th><th>Status</th><th>Videos cached</th><th>Newest video</th><th>Last success</th><th>Error</th></tr></thead><tbody>';
		foreach ( CN_Lineup::channels() as $ch ) {
			$c = $cache['channels'][ $ch['channelId'] ] ?? null;
			$newest = CN_Feeds::last_upload( $ch['channelId'] );
			printf(
				'<tr><td><a href="%s" target="_blank" rel="noopener">%s</a></td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( $ch['url'] ),
				esc_html( $ch['name'] ),
				null === $c ? '—' : ( $c['ok'] ? '<span style="color:#008a20">OK</span>' : '<span style="color:#b32d2e">Failing</span>' ),
				$c ? count( $c['videos'] ) : 0,
				$newest ? esc_html( human_time_diff( $newest ) . ' ago' ) : '—',
				! empty( $c['success_at'] ) ? esc_html( human_time_diff( $c['success_at'] ) . ' ago' ) : 'never',
				esc_html( $c['error'] ?? '' )
			);
		}
		echo '</tbody></table>';
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		check_admin_referer( 'cn_refresh' );
		delete_transient( CN_Feeds::LOCK );
		$r = CN_Feeds::refresh_all();
		wp_safe_redirect( self::url( [ 'tab' => 'feeds', 'cn_msg' => sprintf( 'Refreshed: %d OK, %d failing.', $r['ok'], $r['failed'] ) ] ) );
		exit;
	}

	private static function render_settings() {
		$s        = CN_Settings::all();
		$constant = defined( 'CN_YT_API_KEY' ) && CN_YT_API_KEY;
		echo '<form method="post" action="options.php">';
		settings_fields( 'cn_settings' );
		$o = CN_Settings::OPTION;
		?>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Video source</th><td>
				<select name="<?php echo esc_attr( $o ); ?>[source]">
					<option value="auto" <?php selected( $s['source'], 'auto' ); ?>>Automatic (Data API if a key is set, else RSS)</option>
					<option value="api" <?php selected( $s['source'], 'api' ); ?>>YouTube Data API</option>
					<option value="rss" <?php selected( $s['source'], 'rss' ); ?>>RSS feeds</option>
				</select>
				<p class="description">Currently using: <strong><?php echo esc_html( 'api' === CN_Settings::effective_source() ? 'YouTube Data API' : 'RSS feeds' ); ?></strong></p>
			</td></tr>
			<tr><th scope="row">YouTube Data API key</th><td>
				<?php if ( $constant ) : ?>
					<p>Set in <code>wp-config.php</code> (<code>CN_YT_API_KEY</code>).</p>
				<?php else : ?>
					<input type="password" class="regular-text" name="<?php echo esc_attr( $o ); ?>[api_key]" value="" autocomplete="off" placeholder="<?php echo $s['api_key'] ? esc_attr( 'Saved (…' . substr( $s['api_key'], -4 ) . ')' ) : ''; ?>">
					<p class="description">Leave blank to keep the saved key; enter <code>-</code> to remove it. Defining <code>CN_YT_API_KEY</code> in wp-config.php keeps it out of the database.</p>
				<?php endif; ?>
			</td></tr>
			<tr><th scope="row">Refresh every</th><td><input type="number" min="15" max="1440" name="<?php echo esc_attr( $o ); ?>[refresh_minutes]" value="<?php echo (int) $s['refresh_minutes']; ?>"> minutes</td></tr>
			<tr><th scope="row">Habit influence</th><td><input type="number" min="0" max="30" name="<?php echo esc_attr( $o ); ?>[habit_strength]" value="<?php echo (int) $s['habit_strength']; ?>"> <span class="description">How strongly the "Top picks" order follows your viewing habits versus recency. 10 = default; 0 = newest first; 20 = habits dominate.</span></td></tr>
			<tr><th scope="row">Most videos per channel</th><td><input type="number" min="1" max="15" name="<?php echo esc_attr( $o ); ?>[per_channel]" value="<?php echo (int) $s['per_channel']; ?>"> <span class="description">Your most-watched channel gets this many slots in the stream; lighter channels get proportionally fewer (at least 1).</span></td></tr>
			<tr><th scope="row">Show videos from the last</th><td><input type="number" min="1" max="60" name="<?php echo esc_attr( $o ); ?>[max_age_days]" value="<?php echo (int) $s['max_age_days']; ?>"> days</td></tr>
			<tr><th scope="row">Member sign-in</th><td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[members_enabled]" value="1" <?php checked( $s['members_enabled'], 1 ); ?>> Let visitors sign in (Google or emailed link) to follow channels and save videos</label><p class="description">Accounts are created on this site only, as subscribers. Members: <?php echo (int) count( get_users( [ 'role' => 'subscriber', 'fields' => 'ID' ] ) ); ?>.</p></td></tr>
			<tr><th scope="row">Support link</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $o ); ?>[support_url]" value="<?php echo esc_attr( $s['support_url'] ); ?>" placeholder="https://ko-fi.com/…"><p class="description">Where "Support" links go (top bar, account menu, footer). Leave empty to hide them.</p></td></tr>
			<tr><th scope="row">Google client ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[google_client_id]" value="<?php echo esc_attr( $s['google_client_id'] ); ?>" placeholder="<?php echo esc_attr( CN_Members::google_client_id() ? 'Using Site Kit\'s: ' . CN_Members::google_client_id() : '…apps.googleusercontent.com' ); ?>"><p class="description">Leave empty to reuse Site Kit's Sign in with Google client. Its authorized JavaScript origins must include this site's address.</p></td></tr>
			<tr><th scope="row">Field guide</th><td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[field_guide]" value="1" <?php checked( $s['field_guide'], 1 ); ?>> Show "Know the hooks before you watch" (4 rotating Articles posts from this site) near the bottom of the page</label></td></tr>
			<tr><th scope="row">Shorts</th><td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[exclude_shorts]" value="1" <?php checked( $s['exclude_shorts'], 1 ); ?>> Leave YouTube Shorts out of the stream</label></td></tr>
		</table>
		<?php
		submit_button();
		echo '</form>';
	}
}
