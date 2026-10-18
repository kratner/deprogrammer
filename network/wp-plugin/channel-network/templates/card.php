<?php
/**
 * FILE: card.php
 * PATH: network/wp-plugin/channel-network/templates/card.php
 *
 * One video card, shared by both feeds. Expects $cn_card:
 *   id, title, cat (category key), cat_label, ts (unix, for the Newest sort; 0 if n/a),
 *   avatar_channel (array for CN_Page::avatar_html), channel_name, channel_url,
 *   time_html (already escaped; '' for none), index (position in its feed, for image loading priority),
 *   note (optional; the curator's note on a highlight).
 */

defined( 'ABSPATH' ) || exit;

$cn_watch = 'https://www.youtube.com/watch?v=' . $cn_card['id'];
$cn_img   = 'https://i.ytimg.com/vi/' . $cn_card['id'];
$cn_load  = $cn_card['index'] < 4 ? 'loading="eager"' . ( 0 === $cn_card['index'] ? ' fetchpriority="high"' : '' ) : 'loading="lazy"';
?>
<li class="cn-card" data-cat="<?php echo esc_attr( $cn_card['cat'] ); ?>" data-ch="<?php echo esc_attr( $cn_card['channel_id'] ?? '' ); ?>" data-ts="<?php echo (int) $cn_card['ts']; ?>">
	<a class="cn-thumb" href="<?php echo esc_url( $cn_watch ); ?>" data-video="<?php echo esc_attr( $cn_card['id'] ); ?>" data-title="<?php echo esc_attr( $cn_card['title'] ); ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
		<img src="<?php echo esc_url( $cn_img . '/mqdefault.jpg' ); ?>" srcset="<?php echo esc_attr( $cn_img . '/mqdefault.jpg 320w, ' . $cn_img . '/hqdefault.jpg 480w' ); ?>" sizes="(max-width: 599px) 168px, (max-width: 1100px) 50vw, 33vw" alt="" width="320" height="180" <?php echo $cn_load; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed strings. ?> decoding="async">
		<span class="cn-thumb-bg" style="--bg:url('<?php echo esc_url( $cn_img . '/mqdefault.jpg' ); ?>')"></span>
		<span class="cn-tag"><?php echo esc_html( $cn_card['cat_label'] ); ?></span>
		<span class="cn-play" aria-hidden="true"></span>
	</a>
	<?php if ( CN_Members::enabled() ) : ?>
		<button type="button" class="cn-save" aria-pressed="false" aria-label="<?php esc_attr_e( 'Save for later', 'channel-network' ); ?>" data-video="<?php echo esc_attr( $cn_card['id'] ); ?>" data-title="<?php echo esc_attr( $cn_card['title'] ); ?>" data-channel-id="<?php echo esc_attr( $cn_card['channel_id'] ?? '' ); ?>" data-channel="<?php echo esc_attr( $cn_card['channel_name'] ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg></button>
	<?php endif; ?>
	<div class="cn-card-body">
		<?php echo CN_Page::avatar_html( $cn_card['avatar_channel'], 36, 'cn-avatar', $cn_card['index'] < 8 ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		<div class="cn-card-text">
			<h3 class="cn-card-title"><a href="<?php echo esc_url( $cn_watch ); ?>" data-video="<?php echo esc_attr( $cn_card['id'] ); ?>" data-title="<?php echo esc_attr( $cn_card['title'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $cn_card['title'] ); ?></a></h3>
			<?php if ( ! empty( $cn_card['note'] ) ) : ?>
				<p class="cn-hl-note"><?php echo esc_html( $cn_card['note'] ); ?></p>
			<?php endif; ?>
			<p class="cn-card-meta">
				<a class="cn-card-channel" href="<?php echo esc_url( $cn_card['channel_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $cn_card['channel_name'] ); ?></a>
				<?php if ( '' !== $cn_card['time_html'] ) : ?>
					<span class="cn-dot" aria-hidden="true">·</span>
					<?php echo $cn_card['time_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller. ?>
				<?php endif; ?>
			</p>
		</div>
	</div>
</li>
