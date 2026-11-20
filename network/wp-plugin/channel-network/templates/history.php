<?php
/**
 * FILE: history.php
 * PATH: network/wp-plugin/channel-network/templates/history.php
 *
 * The full watch history (see CN_History). The page ships only the index (days and counts);
 * assets/history.js lays out every day up front from those counts, renders just the rows
 * near the viewport, fetches one month of videos at a time as they come into range, and
 * drives the date scrubber on the right.
 */

defined( 'ABSPATH' ) || exit;

$cn_page    = get_queried_object();
$cn_data    = CN_History::client_data();
$cn_home    = get_permalink( $cn_page );
$cn_preload = CN_History::preload_url();
$cn_cats    = $cn_data ? $cn_data['cats'] : [];
$cn_counts  = [];
if ( $cn_data ) {
	foreach ( $cn_data['days'] as $cn_d ) {
		foreach ( $cn_d[2] as $cn_ci => $cn_n ) {
			$cn_counts[ $cn_ci ] = ( $cn_counts[ $cn_ci ] ?? 0 ) + $cn_n;
		}
	}
}
$cn_fmt = static function ( $ymd, $fmt = 'M j, Y' ) {
	return wp_date( $fmt, strtotime( $ymd . ' 12:00' ) );
};
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://i.ytimg.com">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://yt3.ggpht.com">
<?php if ( $cn_preload ) : ?>
<link rel="preload" as="fetch" type="application/json" crossorigin="anonymous" href="<?php echo esc_url( $cn_preload ); ?>">
<?php endif; ?>
<script>/* Apply a saved light/dark choice before first paint (no flash). */try{var t=localStorage.getItem('cn-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'cn cn-history-page' ); ?>>
<?php wp_body_open(); ?>
<a class="cn-skip" href="#cn-hist"><?php esc_html_e( 'Skip to videos', 'channel-network' ); ?></a>

<header class="cn-top">
	<div class="cn-wrap cn-top-row">
		<div class="cn-brand">
			<a class="cn-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<img class="cn-logo-light" src="<?php echo esc_url( CN_URL . 'assets/logo/logo-light.svg' ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="137" height="34">
				<img class="cn-logo-dark" src="<?php echo esc_url( CN_URL . 'assets/logo/logo-dark.svg' ); ?>" alt="" aria-hidden="true" width="137" height="34">
				<img class="cn-logo-mark" src="<?php echo esc_url( CN_URL . 'assets/logo/mark.svg' ); ?>" alt="" aria-hidden="true" width="34" height="34">
			</a>
			<span class="cn-brand-sep" aria-hidden="true">/</span>
			<a class="cn-crumb" href="<?php echo esc_url( $cn_home ); ?>"><?php echo esc_html( get_the_title( $cn_page ) ); ?></a>
			<span class="cn-brand-sep cn-crumb-sep" aria-hidden="true">/</span>
			<h1 class="cn-title"><?php esc_html_e( 'Watch history', 'channel-network' ); ?></h1>
		</div>
		<div class="cn-top-actions">
			<a class="cn-back-link" href="<?php echo esc_url( $cn_home ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg><span><?php esc_html_e( 'Back to the network', 'channel-network' ); ?></span></a>
			<button type="button" class="cn-theme" aria-label="<?php esc_attr_e( 'Switch between light and dark mode', 'channel-network' ); ?>" hidden>
				<svg class="cn-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
				<svg class="cn-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
			</button>
		</div>
	</div>
	<?php if ( $cn_data ) : ?>
	<nav class="cn-wrap cn-filters-row" aria-label="<?php esc_attr_e( 'Filter by category', 'channel-network' ); ?>">
		<button type="button" class="cn-chip" data-filter="all" aria-pressed="true"><?php esc_html_e( 'All', 'channel-network' ); ?> <span class="cn-chip-n"><?php echo esc_html( number_format_i18n( $cn_data['total'] ) ); ?></span></button>
		<?php foreach ( $cn_cats as $cn_ci => [ $cn_key, $cn_label ] ) :
			if ( empty( $cn_counts[ $cn_ci ] ) ) {
				continue;
			} ?>
			<button type="button" class="cn-chip" data-filter="<?php echo esc_attr( $cn_key ); ?>" aria-pressed="false"><?php echo esc_html( $cn_label ); ?> <span class="cn-chip-n"><?php echo esc_html( number_format_i18n( $cn_counts[ $cn_ci ] ) ); ?></span></button>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>
</header>

<main id="cn-hist-main">
	<div class="cn-wrap cn-intro-band">
		<p class="cn-intro"><?php
		if ( $cn_data ) {
			echo esc_html(
				sprintf(
					/* translators: 1: number of videos, 2: date like "Oct 7, 2026" */
					__( 'Every news video the curator has watched from the network, newest first: %1$s videos since %2$s.', 'channel-network' ),
					number_format_i18n( $cn_data['total'] ),
					$cn_fmt( end( $cn_data['days'] )[0] )
				)
			);
		} else {
			esc_html_e( 'No watch history has been published yet.', 'channel-network' );
		}
		?></p>
		<?php if ( $cn_data ) : ?>
			<p class="cn-meta"><?php
				/* translators: %s: date like "Nov 19" */
				echo esc_html( sprintf( __( 'updated %s', 'channel-network' ), $cn_fmt( $cn_data['updated'], 'M j' ) ) );
			?></p>
		<?php endif; ?>
	</div>

	<?php if ( $cn_data ) : ?>
	<div class="cn-wrap">
		<div class="cn-hist" id="cn-hist" tabindex="-1" aria-label="<?php esc_attr_e( 'Watch history, newest first', 'channel-network' ); ?>">
			<noscript><p class="cn-empty"><?php esc_html_e( 'The full history needs JavaScript. The latest videos are on the network page.', 'channel-network' ); ?> <a href="<?php echo esc_url( $cn_home ); ?>"><?php esc_html_e( 'Go to the network', 'channel-network' ); ?></a></p></noscript>
		</div>
		<p class="cn-hist-end" hidden><?php
			/* translators: %s: date like "Oct 7, 2026" */
			echo esc_html( sprintf( __( 'That\'s everything since %s.', 'channel-network' ), $cn_fmt( end( $cn_data['days'] )[0] ) ) );
		?> <a href="<?php echo esc_url( $cn_home ); ?>"><?php esc_html_e( 'Back to the network', 'channel-network' ); ?></a></p>
	</div>

	<div class="cn-scrub" hidden>
		<div class="cn-scrub-track">
			<div class="cn-scrub-labels" aria-hidden="true"></div>
			<div class="cn-scrub-hover" aria-hidden="true"><span></span></div>
			<div class="cn-scrub-thumb" role="slider" tabindex="0" aria-label="<?php esc_attr_e( 'Jump to a date', 'channel-network' ); ?>" aria-orientation="vertical" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<span class="cn-scrub-bubble"></span>
			</div>
		</div>
	</div>
	<script type="application/json" id="cn-history-data"><?php echo wp_json_encode( $cn_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
	<?php endif; ?>
</main>

<?php include CN_DIR . 'templates/footer.php'; ?>
<?php wp_footer(); ?>
</body>
</html>
