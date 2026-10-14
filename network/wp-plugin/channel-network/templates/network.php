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
			<a class="cn-suggest-link" href="#cn-suggest"><?php esc_html_e( 'Suggest a channel', 'channel-network' ); ?></a>
			<button type="button" class="cn-theme" aria-label="<?php esc_attr_e( 'Switch between light and dark mode', 'channel-network' ); ?>" hidden>
				<svg class="cn-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
				<svg class="cn-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
			</button>
		</div>
	</div>
	<nav class="cn-wrap cn-filters-row" aria-label="<?php esc_attr_e( 'Filter by category', 'channel-network' ); ?>">
		<button type="button" class="cn-chip" data-filter="all" aria-pressed="true"><?php esc_html_e( 'All', 'channel-network' ); ?></button>
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
</div>

<main id="cn-main">
	<?php if ( $cn_watched['items'] ) : ?>
	<section class="cn-wrap cn-section cn-feed" id="cn-stream" data-hide-empty aria-labelledby="cn-watched-h">
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

	<section class="cn-wrap cn-section cn-feed" id="<?php echo $cn_watched['items'] ? 'cn-latest' : 'cn-stream'; ?>" data-sortable aria-labelledby="cn-latest-h">
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

	<section class="cn-wrap cn-section" id="cn-lineup" aria-labelledby="cn-lineup-h">
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

	<section class="cn-wrap cn-section" id="cn-suggest" aria-labelledby="cn-suggest-h">
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
					<label for="cn-reason"><?php esc_html_e( 'Why it belongs', 'channel-network' ); ?> <span class="cn-opt"><?php esc_html_e( '(optional)', 'channel-network' ); ?></span></label>
					<textarea id="cn-reason" name="reason" rows="3" maxlength="1000"></textarea>
				</div>
				<div class="cn-field">
					<label for="cn-email"><?php esc_html_e( 'Your email', 'channel-network' ); ?> <span class="cn-opt"><?php esc_html_e( '(optional)', 'channel-network' ); ?></span></label>
					<input id="cn-email" name="email" type="email" autocomplete="email" maxlength="190" aria-describedby="cn-email-help">
					<p class="cn-help" id="cn-email-help">
						<?php
						$cn_privacy = CN_Page::privacy_url();
						esc_html_e( 'Only used to tell you if the channel is added. Never shared or added to a mailing list.', 'channel-network' );
						if ( $cn_privacy ) {
							echo ' <a href="' . esc_url( $cn_privacy ) . '">' . esc_html__( 'Privacy policy', 'channel-network' ) . '</a>';
						}
						?>
					</p>
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
	</section>
</main>

<footer class="cn-footer">
	<div class="cn-wrap">
		<p>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
			<?php if ( CN_Page::privacy_url() ) : ?>
				· <a href="<?php echo esc_url( CN_Page::privacy_url() ); ?>"><?php esc_html_e( 'Privacy', 'channel-network' ); ?></a>
			<?php endif; ?>
		</p>
		<p class="cn-fine"><?php esc_html_e( 'Videos, titles and thumbnails belong to their creators and play from YouTube. Inclusion in the network is not an endorsement of every video.', 'channel-network' ); ?></p>
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
