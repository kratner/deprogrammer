<?php
/**
 * FILE: footer.php
 * PATH: network/wp-plugin/channel-network/templates/footer.php
 *
 * Footer and the in-page video player dialog, shared by network.php and history.php.
 */

defined( 'ABSPATH' ) || exit;
?>
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
