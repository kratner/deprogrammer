<?php
/**
 * FILE: class-cn-admin.php
 * PATH: network/wp-plugin/channel-network/includes/class-cn-admin.php
 *
 * wp-admin → Network: Highlights (the hand-picked videos at the top of the page, plus the
 * members' highlight suggestions queue), Suggestions (channel review queue), Feeds
 * (per-channel health, refresh now), Settings (source, API key, stream options).
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
	}

	public static function menu() {
		$new   = CN_Suggestions::counts()['new'] + CN_Suggestions::counts( CN_Highlights::KIND )['new'];
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
		$tabs = [ 'highlights' => 'Highlights' . ( $hl_new ? " ($hl_new new)" : '' ), 'suggestions' => 'Channel suggestions', 'feeds' => 'Feeds', 'settings' => 'Settings' ];
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

		echo '<p class="description">Approve a suggestion by adding the channel to <code>channels.json</code> in the repo (<code>node scripts/channels.mjs add …</code>), deploy, then mark it <em>Added</em> here.</p>';

		if ( ! $rows ) {
			echo '<p>No suggestions here.</p>';
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
				$href ? '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">' . esc_html( $r->channel_input ) . '</a>' : esc_html( $r->channel_input ),
				(int) $r->times,
				esc_html( $cats[ $r->category ] ?? '' ),
				esc_html( $r->reason ),
				$r->email ? '<a href="mailto:' . esc_attr( $r->email ) . '">' . esc_html( $r->email ) . '</a>' : '',
				esc_html( $r->status ),
				'spam' === $r->akismet ? ' <span class="description">(Akismet: spam)</span>' : ''
			);
		}
		echo '</tbody></table></form>';
	}

	public static function handle_suggestion_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		check_admin_referer( 'cn_suggestion_action' );
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
		$list = CN_Highlights::all();
		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<h2>Highlighted on the page</h2>';
		printf( '<p class="description">The first one is shown large at the top of the page; up to %d are shown. Members can suggest videos below; nothing appears until you approve it.</p>', (int) CN_Highlights::SHOW );

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
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:2em">#</th><th style="width:130px">Video</th><th>Title</th><th>Note</th><th>Added</th><th style="width:170px">Order</th></tr></thead><tbody>';
			$last = count( $list ) - 1;
			foreach ( $list as $i => $h ) {
				$c = CN_Highlights::card( $h, $i );
				printf(
					'<tr><td>%1$d%2$s</td><td><a href="%3$s" target="_blank" rel="noopener"><img src="https://i.ytimg.com/vi/%4$s/mqdefault.jpg" width="120" height="68" alt="" style="display:block;border-radius:4px"></a></td><td><a href="%3$s" target="_blank" rel="noopener"><strong>%5$s</strong></a><br><span class="description">%6$s%7$s</span></td><td><input type="text" class="regular-text" name="notes[%4$s]" value="%8$s" maxlength="280"></td><td>%9$s</td><td>%10$s %11$s <button class="button-link-delete" name="op" value="remove|%4$s" onclick="return confirm(\'Remove this highlight?\')">Remove</button></td></tr>',
					(int) $i + 1,
					0 === $i ? '<br><span class="description">feature</span>' : ( $i >= CN_Highlights::SHOW ? '<br><span class="description">not shown</span>' : '' ),
					esc_url( CN_Highlights::watch_url( $h['id'] ) ),
					esc_attr( $h['id'] ),
					esc_html( $h['title'] ),
					esc_html( $c['channel_name'] ),
					'' !== $c['cat'] ? esc_html( ' · ' . $c['cat_label'] ) : '',
					esc_attr( $h['note'] ?? '' ),
					esc_html( wp_date( 'M j, Y', (int) ( $h['added'] ?? 0 ) ) ),
					$i > 0 ? '<button class="button button-small" name="op" value="up|' . esc_attr( $h['id'] ) . '" aria-label="Move up">↑</button>' : '',
					$i < $last ? '<button class="button button-small" name="op" value="down|' . esc_attr( $h['id'] ) . '" aria-label="Move down">↓</button>' : ''
				);
			}
			echo '</tbody></table><p><button class="button" name="op" value="notes">Save notes</button></p></form>';
		}

		// Members' suggestions.
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification
		$status = in_array( $status, array_merge( CN_Suggestions::STATUSES, [ 'all' ] ), true ) ? $status : 'new';
		$counts = CN_Suggestions::counts( CN_Highlights::KIND );
		$rows   = CN_Suggestions::query( $status, 200, CN_Highlights::KIND );
		echo '<h2 style="margin-top:32px">Suggested by members</h2>';
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
		echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.cn-hcb\').forEach(c=>c.checked=this.checked)"></td><th>Submitted</th><th style="width:130px">Video</th><th>Title</th><th>Times suggested</th><th>Why</th><th>Member</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$vid  = CN_Highlights::video_id( $r->channel_input );
			$user = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
			printf(
				'<tr><th class="check-column"><input class="cn-hcb" type="checkbox" name="ids[]" value="%d"></th><td>%s</td><td><a href="%s" target="_blank" rel="noopener"><img src="https://i.ytimg.com/vi/%s/mqdefault.jpg" width="120" height="68" alt="" style="display:block;border-radius:4px"></a></td><td>%s%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s%s</td></tr>',
				(int) $r->id,
				esc_html( get_date_from_gmt( $r->created_at, 'M j, Y g:i a' ) ),
				esc_url( $r->channel_input ),
				esc_attr( $vid ),
				esc_html( $r->title ),
				CN_Highlights::is_highlighted( $vid ) ? ' <span class="description">(already highlighted)</span>' : '',
				(int) $r->times,
				esc_html( $r->reason ),
				$user ? esc_html( $user->display_name . ' <' . $user->user_email . '>' ) : '<span class="description">deleted account</span>',
				esc_html( 'added' === $r->status ? 'approved' : $r->status ),
				'spam' === $r->akismet ? ' <span class="description">(Akismet: spam)</span>' : ''
			);
		}
		echo '</tbody></table></form>';
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
