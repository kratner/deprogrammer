<?php
/**
 * FILE: network.php
 * PATH: network/wp-plugin/channel-network/templates/network.php
 *
 * Full-page template for the Deprogramming network page (see CN_Page). Works without
 * JavaScript: every card is in the HTML, and network.js only adds filtering, "show more",
 * live relative times, the in-page player and AJAX form submission.
 */

defined( 'ABSPATH' ) || exit;

$cn_cats     = CN_Lineup::category_labels();
$cn_channels = CN_Lineup::channels();
$cn_watched  = CN_Lineup::watched();
// Uploads already shown under "Recently watched" don't repeat in the uploads feed.
$cn_seen     = array_flip( array_column( $cn_watched['items'], 'id' ) );
$cn_stream   = array_values( array_filter( CN_Feeds::stream(), static fn( $v ) => ! isset( $cn_seen[ $v['id'] ] ) ) );
$cn_cache    = CN_Feeds::cache();
$cn_latest   = CN_Feeds::latest();
$cn_hl       = array_slice( CN_Highlights::current(), 0, CN_Highlights::SHOW );
$cn_hl_more  = $cn_hl ? CN_Highlights::archive( $cn_hl ) : []; // "See all highlights", newest first
// Sections "Edit this page" can reorder and hide (keys match CN_Members::sanitize_layout()).
$cn_sections = [
	'highlights' => __( 'Highlighted', 'channel-network' ),
	'latest'  => __( 'The Latest', 'channel-network' ),
	'watched' => __( 'Recently watched', 'channel-network' ),
	'uploads' => __( 'Latest from the network', 'channel-network' ),
	'saved'   => __( 'Saved for later', 'channel-network' ),
	'lineup'  => __( 'The lineup', 'channel-network' ),
	'suggest' => __( 'Suggest a channel', 'channel-network' ),
];
if ( ! CN_Members::enabled() ) {
	unset( $cn_sections['saved'] );
}
if ( ! $cn_hl ) {
	unset( $cn_sections['highlights'] );
}
$cn_updated  = (int) $cn_cache['refreshed_at'];
$cn_guide    = CN_Settings::get( 'field_guide' ) ? CN_Page::field_guide() : [];
$cn_page     = get_queried_object();
$cn_intro    = trim( (string) $cn_page->post_content );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://i.ytimg.com">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://yt3.ggpht.com">
<script>/* Apply a saved light/dark choice before first paint (no flash). */try{var t=localStorage.getItem('cn-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'cn' ); ?>>
<?php wp_body_open(); ?>
<a class="cn-skip" href="#cn-stream"><?php esc_html_e( 'Skip to videos', 'channel-network' ); ?></a>

<header class="cn-top">
	<div class="cn-wrap cn-top-row">
		<div class="cn-brand">
			<a class="cn-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<img class="cn-logo-light" src="<?php echo esc_url( CN_URL . 'assets/logo/logo-light.svg' ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="137" height="34">
				<img class="cn-logo-dark" src="<?php echo esc_url( CN_URL . 'assets/logo/logo-dark.svg' ); ?>" alt="" aria-hidden="true" width="137" height="34">
				<img class="cn-logo-mark" src="<?php echo esc_url( CN_URL . 'assets/logo/mark.svg' ); ?>" alt="" aria-hidden="true" width="34" height="34">
			</a>
			<span class="cn-brand-sep" aria-hidden="true">/</span>
			<h1 class="cn-title"><?php echo esc_html( get_the_title( $cn_page ) ); ?></h1>
		</div>
		<div class="cn-top-actions">
			<?php if ( CN_Page::support_url() ) : ?>
				<a class="cn-support-link" href="<?php echo esc_url( CN_Page::support_url() ); ?>" target="_blank" rel="noopener" data-support="topbar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg><?php esc_html_e( 'Support', 'channel-network' ); ?></a>
			<?php endif; ?>
			<a class="cn-suggest-link" href="#cn-suggest"><?php esc_html_e( 'Suggest a channel', 'channel-network' ); ?></a>
			<?php if ( CN_Members::enabled() ) : ?>
				<div class="cn-signin-wrap">
					<button type="button" class="cn-signin" aria-expanded="false" aria-controls="cn-auth" hidden><?php esc_html_e( 'Sign in', 'channel-network' ); ?></button>
					<div class="cn-auth" id="cn-auth" role="dialog" aria-labelledby="cn-auth-h" hidden>
						<button type="button" class="cn-auth-close" aria-label="<?php esc_attr_e( 'Close', 'channel-network' ); ?>">×</button>
						<p class="cn-auth-h" id="cn-auth-h"><?php esc_html_e( 'Sign in to Deprogramming', 'channel-network' ); ?></p>
						<p class="cn-auth-sub"><?php esc_html_e( 'Follow channels and save videos for later. Free, no password.', 'channel-network' ); ?></p>
						<div class="cn-gsi" id="cn-gsi"></div>
						<p class="cn-auth-or"><span><?php esc_html_e( 'or', 'channel-network' ); ?></span></p>
						<form class="cn-auth-form" novalidate>
							<label for="cn-auth-email"><?php esc_html_e( 'Email me a sign-in link', 'channel-network' ); ?></label>
							<div class="cn-auth-row">
								<input id="cn-auth-email" name="email" type="email" autocomplete="email" required maxlength="190" placeholder="you@example.com">
								<button type="submit" class="cn-btn cn-btn-primary"><?php esc_html_e( 'Send link', 'channel-network' ); ?></button>
							</div>
							<div class="cn-hp" aria-hidden="true"><label for="cn-auth-website">Website</label><input id="cn-auth-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
							<label class="cn-auth-check"><input type="checkbox" name="newsletter" id="cn-auth-newsletter" value="1"> <?php esc_html_e( 'Also email me the weekly roundup (you\'ll confirm by email; unsubscribe anytime)', 'channel-network' ); ?></label>
							<p class="cn-auth-status" role="status" aria-live="polite"></p>
						</form>
						<p class="cn-auth-fine"><?php
							$cn_pp = CN_Page::privacy_url();
							esc_html_e( 'We store your name and email to run your account, plus the channels you follow and videos you save.', 'channel-network' );
							if ( $cn_pp ) {
								echo ' <a href="' . esc_url( $cn_pp ) . '">' . esc_html__( 'Privacy policy', 'channel-network' ) . '</a>';
							}
						?></p>
					</div>
				</div>
				<div class="cn-account" hidden>
					<button type="button" class="cn-account-btn" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e( 'Your account', 'channel-network' ); ?>"><img alt="" width="32" height="32"></button>
					<div class="cn-account-menu" role="menu" hidden>
						<p class="cn-account-name"></p>
						<button type="button" role="menuitem" data-act="mine"><?php esc_html_e( 'My network', 'channel-network' ); ?></button>
						<button type="button" role="menuitem" data-act="saved"><?php esc_html_e( 'Saved videos', 'channel-network' ); ?></button>
						<?php if ( CN_Page::support_url() ) : ?>
							<a role="menuitem" href="<?php echo esc_url( CN_Page::support_url() ); ?>" target="_blank" rel="noopener" data-support="account_menu"><?php esc_html_e( 'Support the project', 'channel-network' ); ?></a>
						<?php endif; ?>
						<button type="button" role="menuitem" data-act="logout"><?php esc_html_e( 'Sign out', 'channel-network' ); ?></button>
						<button type="button" role="menuitem" data-act="delete" class="cn-danger"><?php esc_html_e( 'Delete my account', 'channel-network' ); ?></button>
					</div>
				</div>
			<?php endif; ?>
			<button type="button" class="cn-theme" aria-label="<?php esc_attr_e( 'Switch between light and dark mode', 'channel-network' ); ?>" hidden>
				<svg class="cn-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
				<svg class="cn-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
			</button>
		</div>
	</div>
	<nav class="cn-wrap cn-filters-row" aria-label="<?php esc_attr_e( 'Filter by category', 'channel-network' ); ?>">
		<button type="button" class="cn-chip" data-filter="all" aria-pressed="true"><?php esc_html_e( 'All', 'channel-network' ); ?></button>
		<?php if ( CN_Members::enabled() ) : ?>
			<button type="button" class="cn-chip cn-chip-mine" data-filter="mine" aria-pressed="false" hidden><?php esc_html_e( 'My network', 'channel-network' ); ?></button>
		<?php endif; ?>
		<?php foreach ( $cn_cats as $key => $label ) : ?>
			<button type="button" class="cn-chip" data-filter="<?php echo esc_attr( $key ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</nav>
</header>

<div class="cn-wrap cn-intro-band">
	<?php if ( '' !== $cn_intro ) : ?>
		<p class="cn-intro"><?php echo esc_html( wp_strip_all_tags( apply_filters( 'the_content', $cn_intro ) ) ); ?></p>
	<?php endif; ?>
	<p class="cn-meta">
		<?php
		/* translators: %d: number of channels */
		echo esc_html( sprintf( _n( '%d channel', '%d channels', count( $cn_channels ), 'channel-network' ), count( $cn_channels ) ) );
		if ( $cn_updated ) {
			echo ' · ';
			printf(
				/* translators: %s: relative time */
				esc_html__( 'updated %s', 'channel-network' ),
				'<time data-ago datetime="' . esc_attr( gmdate( 'c', $cn_updated ) ) . '">' . esc_html( CN_Page::ago( $cn_updated ) ) . '</time>'
			);
		}
		?>
	</p>
	<button type="button" class="cn-edit-btn" aria-expanded="false" aria-controls="cn-edit">
		<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>
		<?php esc_html_e( 'Edit this page', 'channel-network' ); ?>
	</button>
</div>

<section class="cn-wrap cn-edit" id="cn-edit" aria-labelledby="cn-edit-h" hidden>
	<div class="cn-edit-card">
		<div class="cn-edit-head">
			<h2 class="cn-edit-h" id="cn-edit-h"><?php esc_html_e( 'Edit this page', 'channel-network' ); ?></h2>
			<p class="cn-edit-note"></p>
		</div>
		<div class="cn-edit-grid">
			<fieldset class="cn-edit-group">
				<legend><?php esc_html_e( 'Sections', 'channel-network' ); ?></legend>
				<p class="cn-edit-hint"><?php esc_html_e( 'Drag to reorder, or use the arrows. Untick to hide.', 'channel-network' ); ?></p>
				<ol class="cn-edit-list">
					<?php foreach ( $cn_sections as $key => $label ) : ?>
						<li class="cn-edit-item" data-key="<?php echo esc_attr( $key ); ?>" draggable="true">
							<span class="cn-grip" aria-hidden="true">⋮⋮</span>
							<label><input type="checkbox" checked data-show="<?php echo esc_attr( $key ); ?>"> <?php echo esc_html( $label ); ?></label>
							<span class="cn-edit-moves">
								<button type="button" data-move="-1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s section */ __( 'Move %s up', 'channel-network' ), $label ) ); ?>">↑</button>
								<button type="button" data-move="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s section */ __( 'Move %s down', 'channel-network' ), $label ) ); ?>">↓</button>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			</fieldset>
			<div class="cn-edit-side">
				<fieldset class="cn-edit-group">
					<legend><label for="cn-edit-cat"><?php esc_html_e( 'Open the page on', 'channel-network' ); ?></label></legend>
					<select id="cn-edit-cat" class="cn-edit-select">
						<option value="all"><?php esc_html_e( 'All categories', 'channel-network' ); ?></option>
						<?php if ( CN_Members::enabled() ) : ?>
							<option value="mine" data-members-only><?php esc_html_e( 'My network (channels I follow)', 'channel-network' ); ?></option>
						<?php endif; ?>
						<?php foreach ( $cn_cats as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</fieldset>
				<fieldset class="cn-edit-group">
					<legend><?php esc_html_e( 'Video layout', 'channel-network' ); ?></legend>
					<label class="cn-radio"><input type="radio" name="cn-density" value="cards" checked> <?php esc_html_e( 'Big cards', 'channel-network' ); ?></label>
					<label class="cn-radio"><input type="radio" name="cn-density" value="compact"> <?php esc_html_e( 'Compact list', 'channel-network' ); ?></label>
				</fieldset>
			</div>
		</div>
		<div class="cn-edit-actions">
			<button type="button" class="cn-btn cn-edit-reset"><?php esc_html_e( 'Reset to default', 'channel-network' ); ?></button>
			<button type="button" class="cn-btn cn-btn-primary cn-edit-done"><?php esc_html_e( 'Done', 'channel-network' ); ?></button>
		</div>
	</div>
</section>

<main id="cn-main">
	<?php if ( $cn_hl ) :
		$cn_f   = CN_Highlights::card( $cn_hl[0], 0 );
		$cn_fimg = 'https://i.ytimg.com/vi/' . $cn_f['id'];
		?>
	<section class="cn-wrap cn-section cn-highlights" id="cn-highlights" data-section="highlights" aria-labelledby="cn-hl-h">
		<div class="cn-stream-head">
			<div>
				<h2 class="cn-h2" id="cn-hl-h"><?php esc_html_e( 'Highlighted', 'channel-network' ); ?></h2>
				<p class="cn-sub"><?php esc_html_e( 'Hand-picked by the curator', 'channel-network' ); ?></p>
			</div>
			<?php if ( CN_Members::enabled() ) : ?>
				<button type="button" class="cn-btn cn-hl-suggest-btn" aria-expanded="false" aria-controls="cn-hl-form" hidden><?php esc_html_e( 'Suggest a video', 'channel-network' ); ?></button>
			<?php endif; ?>
		</div>
		<?php if ( CN_Members::enabled() ) : ?>
			<form class="cn-hl-form" id="cn-hl-form" novalidate hidden>
				<p class="cn-hl-form-intro"><?php esc_html_e( 'Seen something everyone should watch? Suggest it for this section. The curator reviews every suggestion before anything is shown.', 'channel-network' ); ?></p>
				<div class="cn-field">
					<label for="cn-hl-url"><?php esc_html_e( 'YouTube video link', 'channel-network' ); ?> <span class="cn-req"><?php esc_html_e( '(required)', 'channel-network' ); ?></span></label>
					<input id="cn-hl-url" name="url" type="text" inputmode="url" autocomplete="off" required maxlength="300" placeholder="https://www.youtube.com/watch?v=…">
				</div>
				<div class="cn-field">
					<label for="cn-hl-reason"><?php esc_html_e( 'Why it stands out', 'channel-network' ); ?> <span class="cn-opt"><?php esc_html_e( '(optional)', 'channel-network' ); ?></span></label>
					<textarea id="cn-hl-reason" name="reason" rows="2" maxlength="500"></textarea>
				</div>
				<label class="cn-auth-check cn-hl-anon"><input type="checkbox" name="anonymous" id="cn-hl-anonymous" value="1"> <span><?php esc_html_e( 'Suggest anonymously', 'channel-network' ); ?><span class="cn-hl-anon-hint"><?php esc_html_e( 'The curator won\'t see who sent it, and you won\'t be credited if it\'s highlighted.', 'channel-network' ); ?></span></span></label>
				<div class="cn-hp" aria-hidden="true"><label for="cn-hl-website">Website</label><input id="cn-hl-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
				<p class="cn-form-actions">
					<button type="submit" class="cn-btn cn-btn-primary"><?php esc_html_e( 'Send suggestion', 'channel-network' ); ?></button>
					<button type="button" class="cn-btn cn-hl-cancel"><?php esc_html_e( 'Cancel', 'channel-network' ); ?></button>
				</p>
				<p class="cn-form-status" role="status" aria-live="polite"></p>
			</form>
		<?php endif; ?>
		<article class="cn-card cn-hl-feature" data-cat="<?php echo esc_attr( $cn_f['cat'] ); ?>" data-ch="<?php echo esc_attr( $cn_f['channel_id'] ); ?>" data-ts="0">
			<a class="cn-thumb" href="<?php echo esc_url( CN_Highlights::watch_url( $cn_f['id'] ) ); ?>" data-video="<?php echo esc_attr( $cn_f['id'] ); ?>" data-title="<?php echo esc_attr( $cn_f['title'] ); ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
				<img src="<?php echo esc_url( $cn_fimg . '/hqdefault.jpg' ); ?>" srcset="<?php echo esc_attr( $cn_fimg . '/mqdefault.jpg 320w, ' . $cn_fimg . '/hqdefault.jpg 480w, ' . $cn_fimg . '/sddefault.jpg 640w' ); ?>" sizes="(max-width: 759px) 100vw, 60vw" alt="" width="480" height="270" loading="eager" fetchpriority="high" decoding="async" data-hi>
				<span class="cn-tag"><?php echo esc_html( $cn_f['cat_label'] ); ?></span>
				<span class="cn-play" aria-hidden="true"></span>
			</a>
			<?php if ( CN_Members::enabled() ) : ?>
				<button type="button" class="cn-save" aria-pressed="false" aria-label="<?php esc_attr_e( 'Save for later', 'channel-network' ); ?>" data-video="<?php echo esc_attr( $cn_f['id'] ); ?>" data-title="<?php echo esc_attr( $cn_f['title'] ); ?>" data-channel-id="<?php echo esc_attr( $cn_f['channel_id'] ); ?>" data-channel="<?php echo esc_attr( $cn_f['channel_name'] ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg></button>
			<?php endif; ?>
			<div class="cn-hl-body">
				<h3 class="cn-card-title"><a href="<?php echo esc_url( CN_Highlights::watch_url( $cn_f['id'] ) ); ?>" data-video="<?php echo esc_attr( $cn_f['id'] ); ?>" data-title="<?php echo esc_attr( $cn_f['title'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $cn_f['title'] ); ?></a></h3>
				<?php if ( '' !== $cn_f['note'] ) : ?>
					<p class="cn-hl-note"><?php echo esc_html( $cn_f['note'] ); ?></p>
				<?php endif; ?>
				<p class="cn-card-meta">
					<?php echo CN_Page::avatar_html( $cn_f['avatar_channel'], 28, 'cn-avatar cn-avatar-sm', true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
					<a class="cn-card-channel" href="<?php echo esc_url( $cn_f['channel_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $cn_f['channel_name'] ); ?></a>
				</p>
			</div>
		</article>
		<?php if ( count( $cn_hl ) > 1 ) : ?>
			<ol class="cn-grid cn-hl-grid">
				<?php foreach ( array_slice( $cn_hl, 1 ) as $cn_i => $h ) :
					$cn_card = CN_Highlights::card( $h, $cn_i + 1 );
					include CN_DIR . 'templates/card.php';
				endforeach; ?>
			</ol>
		<?php endif; ?>
		<?php if ( $cn_hl_more ) :
			/* translators: %d: number of earlier highlights */
			$cn_hl_label = sprintf( _n( 'See all highlights (%d more)', 'See all highlights (%d more)', count( $cn_hl_more ), 'channel-network' ), count( $cn_hl_more ) );
			?>
			<div class="cn-hl-all">
				<p class="cn-hl-all-row"><button type="button" class="cn-btn cn-hl-all-btn" aria-expanded="false" aria-controls="cn-hl-archive" data-label="<?php echo esc_attr( $cn_hl_label ); ?>" data-label-open="<?php esc_attr_e( 'Hide earlier highlights', 'channel-network' ); ?>"><?php echo esc_html( $cn_hl_label ); ?></button></p>
				<div class="cn-hl-archive" id="cn-hl-archive" hidden>
					<h3 class="cn-hl-archive-h"><?php esc_html_e( 'Earlier highlights', 'channel-network' ); ?></h3>
					<ol class="cn-grid cn-hl-grid cn-hl-archive-grid">
						<?php foreach ( $cn_hl_more as $cn_i => $h ) :
							$cn_card              = CN_Highlights::card( $h, 100 + $cn_i ); // lazy images
							$cn_card['time_html'] = '<span class="cn-hl-when">' . esc_html( sprintf( /* translators: %s: date */ __( 'Highlighted %s', 'channel-network' ), wp_date( 'M j', CN_Highlights::fresh_from( $h ) ) ) ) . '</span>';
							include CN_DIR . 'templates/card.php';
						endforeach; ?>
					</ol>
					<p class="cn-more-row cn-hl-more-row" hidden><button type="button" class="cn-btn cn-hl-more"><?php esc_html_e( 'Show more', 'channel-network' ); ?></button></p>
				</div>
			</div>
		<?php endif; ?>
	</section>
	<?php endif; ?>
	<?php if ( $cn_latest ) : ?>
	<section class="cn-wrap cn-section cn-feed cn-rail-section" id="cn-the-latest" data-section="latest" data-no-lead data-hide-empty aria-labelledby="cn-the-latest-h">
		<div class="cn-stream-head">
			<div>
				<h2 class="cn-h2" id="cn-the-latest-h"><?php esc_html_e( 'The Latest', 'channel-network' ); ?></h2>
				<p class="cn-sub"><?php esc_html_e( 'Newest uploads across the network, newest first', 'channel-network' ); ?></p>
			</div>
		</div>
		<ol class="cn-grid cn-rail" data-page-size="40">
			<?php foreach ( $cn_latest as $cn_i => $v ) :
				$ch      = $v['channel'];
				$cn_card = [
					'id'             => $v['id'],
					'title'          => $v['title'],
					'cat'            => $ch['category'],
					'channel_id'     => $ch['channelId'],
					'cat_label'      => $cn_cats[ $ch['category'] ] ?? '',
					'ts'             => (int) $v['published'],
					'avatar_channel' => $ch,
					'channel_name'   => $ch['name'],
					'channel_url'    => $ch['url'],
					'time_html'      => '<time data-ago datetime="' . esc_attr( gmdate( 'c', $v['published'] ) ) . '">' . esc_html( CN_Page::ago( $v['published'] ) ) . '</time>',
					'index'          => $cn_i < 4 ? $cn_i : $cn_i + 20,
				];
				include CN_DIR . 'templates/card.php';
			endforeach; ?>
		</ol>
	</section>
	<?php endif; ?>

	<?php if ( $cn_watched['items'] ) : ?>
	<section class="cn-wrap cn-section cn-feed" id="cn-stream" data-section="watched" data-hide-empty aria-labelledby="cn-watched-h">
		<div class="cn-stream-head">
			<div>
				<h2 class="cn-h2" id="cn-watched-h"><?php esc_html_e( 'Recently watched', 'channel-network' ); ?></h2>
				<p class="cn-sub"><?php
					echo esc_html(
						$cn_watched['updated']
							/* translators: %s: date like "Oct 13" */
							? sprintf( __( 'Picked from the curator\'s own viewing · updated %s', 'channel-network' ), wp_date( 'M j', strtotime( $cn_watched['updated'] . ' 12:00' ) ) )
							: __( 'Picked from the curator\'s own viewing', 'channel-network' )
					);
				?></p>
			</div>
		</div>
		<ol class="cn-grid" data-page-size="13">
			<?php foreach ( $cn_watched['items'] as $cn_i => $w ) :
				$cn_up  = $w['uploaderId'] ?? $w['channelId'];
				$cn_own = $cn_up === $w['channelId'];
				$cn_card = [
					'id'             => $w['id'],
					'title'          => $w['title'],
					'cat'            => $w['channel']['category'],
					'channel_id'     => $w['channel']['channelId'],
					'cat_label'      => $cn_cats[ $w['channel']['category'] ] ?? '',
					'ts'             => 0,
					'avatar_channel' => $cn_own ? $w['channel'] : [ 'name' => $w['uploaderName'] ?? $w['channel']['name'], 'channelId' => $cn_up ],
					'channel_name'   => $w['uploaderName'] ?? $w['channel']['name'],
					'channel_url'    => $cn_own ? $w['channel']['url'] : 'https://www.youtube.com/channel/' . $cn_up,
					'time_html'      => '',
					'index'          => $cn_i,
				];
				include CN_DIR . 'templates/card.php';
			endforeach; ?>
		</ol>
		<p class="cn-more-row" hidden><button type="button" class="cn-btn cn-more"><?php esc_html_e( 'Show more', 'channel-network' ); ?></button></p>
	</section>
	<?php endif; ?>

	<section class="cn-wrap cn-section cn-feed" id="<?php echo $cn_watched['items'] ? 'cn-latest' : 'cn-stream'; ?>" data-section="uploads" data-sortable aria-labelledby="cn-latest-h">
		<div class="cn-stream-head">
			<h2 class="cn-h2" id="cn-latest-h"><?php echo $cn_watched['items'] ? esc_html__( 'Latest from the network', 'channel-network' ) : esc_html__( 'Latest', 'channel-network' ); ?></h2>
			<div class="cn-sort" role="group" aria-label="<?php esc_attr_e( 'Sort videos', 'channel-network' ); ?>" hidden>
				<button type="button" class="cn-sort-btn" data-sort="top" aria-pressed="true" title="<?php esc_attr_e( 'Ranked by how much the curator watches each channel, blended with how new each video is', 'channel-network' ); ?>"><?php esc_html_e( 'Top picks', 'channel-network' ); ?></button>
				<button type="button" class="cn-sort-btn" data-sort="newest" aria-pressed="false"><?php esc_html_e( 'Newest', 'channel-network' ); ?></button>
			</div>
		</div>
		<?php if ( ! $cn_stream ) : ?>
			<p class="cn-empty"><?php esc_html_e( 'New videos are on their way. Check back in a few minutes.', 'channel-network' ); ?></p>
		<?php else : ?>
			<ol class="cn-grid" data-page-size="24">
				<?php foreach ( $cn_stream as $cn_i => $v ) :
					$ch      = $v['channel'];
					$cn_card = [
						'id'             => $v['id'],
						'title'          => $v['title'],
						'cat'            => $ch['category'],
						'channel_id'     => $ch['channelId'],
						'cat_label'      => $cn_cats[ $ch['category'] ] ?? '',
						'ts'             => (int) $v['published'],
						'avatar_channel' => $ch,
						'channel_name'   => $ch['name'],
						'channel_url'    => $ch['url'],
						'time_html'      => '<time data-ago datetime="' . esc_attr( gmdate( 'c', $v['published'] ) ) . '">' . esc_html( CN_Page::ago( $v['published'] ) ) . '</time>',
						'index'          => $cn_watched['items'] ? $cn_i + 8 : $cn_i,
					];
					include CN_DIR . 'templates/card.php';
				endforeach; ?>
			</ol>
			<p class="cn-more-row" hidden><button type="button" class="cn-btn cn-more"><?php esc_html_e( 'Show more', 'channel-network' ); ?></button></p>
			<p class="cn-empty cn-filter-empty" hidden><?php esc_html_e( 'No new videos in this category this week.', 'channel-network' ); ?></p>
		<?php endif; ?>
	</section>

	<?php if ( CN_Members::enabled() ) : ?>
	<section class="cn-wrap cn-section" id="cn-saved" data-section="saved" aria-labelledby="cn-saved-h" hidden>
		<div class="cn-stream-head"><h2 class="cn-h2" id="cn-saved-h"><?php esc_html_e( 'Saved for later', 'channel-network' ); ?></h2></div>
		<ol class="cn-saved-list"></ol>
	</section>
	<?php endif; ?>

	<section class="cn-wrap cn-section" id="cn-lineup" data-section="lineup" aria-labelledby="cn-lineup-h">
		<h2 class="cn-h2" id="cn-lineup-h"><?php esc_html_e( 'The lineup', 'channel-network' ); ?></h2>
		<div class="cn-lineup">
			<?php foreach ( $cn_cats as $key => $label ) :
				$in = CN_Lineup::by_weight( array_filter( $cn_channels, static fn( $c ) => $c['category'] === $key ) );
				if ( ! $in ) {
					continue;
				}
				?>
				<div class="cn-lineup-group" data-cat="<?php echo esc_attr( $key ); ?>">
					<h3 class="cn-h3"><?php echo esc_html( $label ); ?> <span class="cn-count"><?php echo (int) count( $in ); ?></span></h3>
					<ul class="cn-channels">
						<?php foreach ( $in as $c ) :
							$last = CN_Feeds::last_upload( $c['channelId'] );
							?>
							<li>
								<a class="cn-channel" href="<?php echo esc_url( $c['url'] ); ?>" target="_blank" rel="noopener">
									<?php echo CN_Page::avatar_html( $c, 40, 'cn-avatar cn-avatar-lg' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
									<span class="cn-channel-text">
										<span class="cn-channel-name"><?php echo esc_html( $c['name'] ); ?></span>
										<?php if ( ! empty( $c['network'] ) ) : ?>
											<span class="cn-badge"><?php echo esc_html( $c['network'] ); ?></span>
										<?php endif; ?>
										<?php if ( $last ) : ?>
											<span class="cn-channel-last"><?php esc_html_e( 'Last upload', 'channel-network' ); ?> <time data-ago datetime="<?php echo esc_attr( gmdate( 'c', $last ) ); ?>"><?php echo esc_html( CN_Page::ago( $last ) ); ?></time></span>
										<?php endif; ?>
									</span>
								</a>
								<?php if ( CN_Members::enabled() ) : ?>
									<button type="button" class="cn-follow" aria-pressed="false" data-channel-id="<?php echo esc_attr( $c['channelId'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s channel */ __( 'Follow %s', 'channel-network' ), $c['name'] ) ); ?>"><span class="cn-follow-on"><?php esc_html_e( 'Follow', 'channel-network' ); ?></span><span class="cn-follow-off"><?php esc_html_e( 'Following', 'channel-network' ); ?></span></button>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<?php if ( $cn_guide ) : ?>
		<section class="cn-section cn-guide" aria-labelledby="cn-guide-h">
			<div class="cn-wrap">
				<p class="cn-eyebrow"><?php esc_html_e( 'Field guide', 'channel-network' ); ?></p>
				<h2 class="cn-h2" id="cn-guide-h"><?php esc_html_e( 'Know the hooks before you watch', 'channel-network' ); ?></h2>
				<p class="cn-guide-sub">
					<?php
					/* translators: %s: site name */
					printf( esc_html__( 'Short reads from the %s archive on how news, algorithms and our own brains pull us in.', 'channel-network' ), esc_html( get_bloginfo( 'name' ) ) );
					?>
				</p>
				<ul class="cn-guide-list">
					<?php foreach ( $cn_guide as $p ) : ?>
						<li>
							<a class="cn-guide-item" href="<?php echo esc_url( get_permalink( $p ) ); ?>">
								<span class="cn-guide-title"><?php echo esc_html( get_the_title( $p ) ); ?></span>
								<span class="cn-guide-excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $p->post_excerpt ? $p->post_excerpt : strip_shortcodes( $p->post_content ) ), 22 ) ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
	<?php endif; ?>

	<section class="cn-wrap cn-section" id="cn-suggest" data-section="suggest" aria-labelledby="cn-suggest-h">
		<div class="cn-suggest">
			<div class="cn-suggest-copy">
				<h2 class="cn-h2" id="cn-suggest-h"><?php esc_html_e( 'Suggest a channel', 'channel-network' ); ?></h2>
				<p><?php esc_html_e( 'Know a channel that belongs here? Send it in. Every suggestion is reviewed by hand.', 'channel-network' ); ?></p>
			</div>
			<form class="cn-form" method="post" action="<?php echo esc_url( rest_url( 'channel-network/v1/suggest' ) ); ?>" novalidate>
				<div class="cn-field">
					<label for="cn-channel"><?php esc_html_e( 'YouTube channel', 'channel-network' ); ?> <span class="cn-req"><?php esc_html_e( '(required)', 'channel-network' ); ?></span></label>
					<input id="cn-channel" name="channel" type="text" inputmode="url" autocomplete="off" required maxlength="300" placeholder="https://www.youtube.com/@channel" aria-describedby="cn-channel-help">
					<p class="cn-help" id="cn-channel-help"><?php esc_html_e( 'A channel link, an @handle, or a link to any of its videos.', 'channel-network' ); ?></p>
				</div>
				<div class="cn-field">
					<label for="cn-category"><?php esc_html_e( 'Category', 'channel-network' ); ?> <span class="cn-opt"><?php esc_html_e( '(optional)', 'channel-network' ); ?></span></label>
					<select id="cn-category" name="category">
						<option value=""><?php esc_html_e( 'Not sure', 'channel-network' ); ?></option>
						<?php foreach ( $cn_cats as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="cn-field">
					<label for="cn-reason"><?php esc_html_e( 'Why it belongs', 'channel-network' ); ?> <span class="cn-opt"><?php esc_html_e( '(optional, a sentence or two)', 'channel-network' ); ?></span></label>
					<textarea id="cn-reason" name="reason" rows="2" maxlength="<?php echo (int) CN_Suggestions::BRIEF; ?>"></textarea>
				</div>
				<div class="cn-hp" aria-hidden="true">
					<label for="cn-website">Website</label>
					<input id="cn-website" name="website" type="text" tabindex="-1" autocomplete="off">
				</div>
				<input type="hidden" name="token" value="<?php echo esc_attr( CN_Suggestions::form_token() ); ?>">
				<p class="cn-form-actions">
					<button type="submit" class="cn-btn cn-btn-primary"><?php esc_html_e( 'Send suggestion', 'channel-network' ); ?></button>
				</p>
				<p class="cn-form-status" role="status" aria-live="polite"></p>
			</form>
		</div>
		<?php $cn_contact = (string) apply_filters( 'cn_contact_url', 'https://substack.com/@keithratner' ); ?>
		<div class="cn-suggest cn-feedback" id="cn-feedback">
			<div class="cn-suggest-copy">
				<h2 class="cn-h2" id="cn-feedback-h"><?php esc_html_e( 'Suggestion box', 'channel-network' ); ?></h2>
				<p>
					<?php
					/* translators: %d: character limit */
					echo esc_html( sprintf( __( 'An idea for this page? Keep it short, %d characters. The curator reads every one.', 'channel-network' ), CN_Suggestions::BRIEF ) );
					if ( '' !== $cn_contact ) {
						echo ' ' . sprintf(
							/* translators: %s: link to the curator on Substack */
							esc_html__( 'Want to talk? Find me on %s.', 'channel-network' ),
							'<a href="' . esc_url( $cn_contact ) . '" target="_blank" rel="noopener">Substack</a>'
						);
					}
					?>
				</p>
			</div>
			<form class="cn-form cn-feedback-form" method="post" action="<?php echo esc_url( rest_url( 'channel-network/v1/feedback' ) ); ?>" novalidate aria-labelledby="cn-feedback-h">
				<div class="cn-field">
					<label for="cn-fb-message"><?php esc_html_e( 'Your suggestion', 'channel-network' ); ?></label>
					<textarea id="cn-fb-message" name="message" rows="3" required maxlength="<?php echo (int) CN_Suggestions::BRIEF; ?>" aria-describedby="cn-fb-count"></textarea>
					<p class="cn-help cn-fb-count" id="cn-fb-count" aria-live="polite"><?php echo (int) CN_Suggestions::BRIEF; ?> <?php esc_html_e( 'characters left', 'channel-network' ); ?></p>
				</div>
				<div class="cn-hp" aria-hidden="true"><label for="cn-fb-website">Website</label><input id="cn-fb-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
				<input type="hidden" name="token" value="<?php echo esc_attr( CN_Suggestions::form_token() ); ?>">
				<p class="cn-form-actions"><button type="submit" class="cn-btn cn-btn-primary"><?php esc_html_e( 'Send', 'channel-network' ); ?></button></p>
				<p class="cn-form-status" role="status" aria-live="polite"></p>
			</form>
		</div>
	</section>
</main>

<?php if ( CN_Page::support_url() ) : ?>
<aside class="cn-wrap" aria-label="<?php esc_attr_e( 'Support the project', 'channel-network' ); ?>">
	<div class="cn-support-band">
	<p><?php esc_html_e( 'Deprogramming is free and ad-free. If it saves you time, you can chip in to keep it going.', 'channel-network' ); ?></p>
	<a class="cn-btn cn-btn-primary" href="<?php echo esc_url( CN_Page::support_url() ); ?>" target="_blank" rel="noopener" data-support="band"><?php esc_html_e( 'Support on Ko-fi', 'channel-network' ); ?></a>
	</div>
</aside>
<?php endif; ?>

<footer class="cn-footer">
	<div class="cn-wrap">
		<p>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
			<?php if ( CN_Page::privacy_url() ) : ?>
				· <a href="<?php echo esc_url( CN_Page::privacy_url() ); ?>"><?php esc_html_e( 'Privacy', 'channel-network' ); ?></a>
			<?php endif; ?>
			<?php if ( CN_Page::support_url() ) : ?>
				· <a href="<?php echo esc_url( CN_Page::support_url() ); ?>" target="_blank" rel="noopener" data-support="footer"><?php esc_html_e( 'Support', 'channel-network' ); ?></a>
			<?php endif; ?>
		</p>
		<p class="cn-fine"><?php esc_html_e( 'Videos, titles and thumbnails belong to their creators and play from YouTube. Inclusion in the network is not an endorsement of every video.', 'channel-network' ); ?></p>
		<p class="cn-endorse">
			<?php // The Four Ounces endorsement (kratner/four-ounces-brand, logo/endorsement-on-*.svg): the 4oz mark in one quiet colour. ?>
			<svg viewBox="0 0 104 72" width="19" height="13" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 12 L4 44 H32 M26 26 V62"/><path d="M76 32 H98 L76 62 H98"/></g><g transform="translate(52 47) scale(0.43) translate(-50 -50)" fill="currentColor"><path d="M41.80 11.60 L37.50 12.55 L34.05 13.70 L30.00 15.55 L27.80 16.85 L24.75 19.10 L21.60 22.10 L19.35 24.85 L17.30 27.90 L15.75 30.90 L14.10 34.95 L12.80 39.25 L11.80 43.90 L11.25 48.10 L11.00 52.40 L11.20 57.10 L11.70 60.40 L12.60 64.00 L14.20 68.15 L15.55 70.80 L17.45 73.75 L19.95 77.00 L22.75 79.90 L26.55 83.15 L30.00 85.60 L33.15 87.50 L37.25 89.50 L40.05 90.65 L44.00 91.80 L47.00 92.35 L50.30 92.65 L54.75 92.55 L58.45 92.00 L61.40 91.25 L66.40 89.35 L69.45 87.75 L72.40 85.85 L75.10 83.85 L78.60 80.80 L81.15 78.20 L83.85 75.00 L86.25 71.70 L88.25 68.25 L89.65 65.20 L90.50 62.95 L91.50 59.35 L92.05 56.35 L92.35 52.95 L92.30 48.60 L91.45 45.45 L90.10 41.85 L88.30 38.10 L87.85 36.70 L87.25 35.55 L86.50 34.50 L84.95 33.05 L83.90 32.40 L82.65 31.90 L81.40 31.65 L79.95 31.65 L78.35 32.05 L76.95 32.80 L75.50 34.20 L74.60 35.70 L74.00 37.65 L73.90 38.40 L73.95 40.40 L74.25 41.80 L74.75 43.05 L74.85 43.85 L74.85 46.65 L74.60 48.85 L74.85 53.10 L74.55 55.95 L74.15 57.75 L73.60 59.45 L72.35 62.25 L71.40 64.00 L70.35 65.60 L67.65 68.95 L64.70 71.90 L63.10 73.25 L61.10 74.65 L59.30 75.75 L57.70 76.50 L55.05 77.40 L53.35 77.75 L51.15 78.00 L49.55 78.00 L47.20 77.75 L45.40 77.40 L43.30 76.85 L41.10 76.00 L37.15 74.00 L33.35 71.50 L31.60 70.10 L29.00 67.70 L26.95 65.20 L25.70 63.25 L24.65 61.15 L23.80 58.85 L23.25 56.70 L22.80 53.60 L22.65 51.60 L22.80 47.25 L23.40 42.80 L24.10 39.55 L24.95 36.70 L26.00 34.10 L27.55 31.15 L29.45 28.60 L31.70 26.35 L33.30 25.10 L35.25 23.85 L37.20 22.85 L39.00 22.10 L41.70 21.20 L44.20 20.55 L46.90 20.10 L50.35 19.80 L53.85 19.85 L57.10 20.25 L60.60 21.15 L63.00 22.10 L66.00 23.70 L68.20 25.35 L70.40 27.45 L70.75 27.35 L71.75 26.30 L71.75 26.05 L72.35 25.65 L72.50 25.30 L72.95 24.95 L73.00 24.70 L73.35 24.50 L73.40 24.30 L73.80 24.00 L75.50 22.05 L75.45 21.75 L73.15 19.40 L71.65 18.10 L68.90 16.05 L65.40 14.15 L60.90 12.45 L57.00 11.55 L53.95 11.15 L51.65 11.00 L47.00 11.05Z"/></g></svg>
			<?php esc_html_e( 'A Four Ounces project', 'channel-network' ); ?>
		</p>
	</div>
</footer>

<dialog class="cn-player" aria-label="<?php esc_attr_e( 'Video player', 'channel-network' ); ?>">
	<div class="cn-player-inner">
		<div class="cn-player-bar">
			<p class="cn-player-title"></p>
			<a class="cn-player-yt" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'Open on YouTube', 'channel-network' ); ?></a>
			<button type="button" class="cn-player-close" aria-label="<?php esc_attr_e( 'Close', 'channel-network' ); ?>">×</button>
		</div>
		<div class="cn-player-frame"></div>
	</div>
</dialog>
<?php wp_footer(); ?>
</body>
</html>
