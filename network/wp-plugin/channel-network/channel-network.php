<?php
/**
 * FILE: channel-network.php
 * PATH: network/wp-plugin/channel-network/channel-network.php
 *
 * Plugin Name:       Channel Network
 * Description:       The "Deprogramming" page: a curated network of YouTube news & politics channels with their latest videos, hand-picked highlights, plus visitor channel and member highlight suggestions.
 * Version:           1.6.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Keith Ratner
 * License:           GPL-2.0-or-later
 * Text Domain:       channel-network
 *
 * Activate per site (deprogrammer.org), not network-wide: the suggestions table and
 * settings belong to the site it's activated on. The lineup comes from data/channels.json,
 * a copy of the repo's channels.json made by scripts/build-plugin.sh. Edit the repo file,
 * never this copy.
 */

defined( 'ABSPATH' ) || exit;

define( 'CN_VERSION', '1.6.4' );
define( 'CN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CN_URL', plugin_dir_url( __FILE__ ) );

require_once CN_DIR . 'includes/class-cn-lineup.php';
require_once CN_DIR . 'includes/class-cn-settings.php';
require_once CN_DIR . 'includes/class-cn-feeds.php';
require_once CN_DIR . 'includes/class-cn-suggestions.php';
require_once CN_DIR . 'includes/class-cn-highlights.php';
require_once CN_DIR . 'includes/class-cn-page.php';
require_once CN_DIR . 'includes/class-cn-members.php';
require_once CN_DIR . 'includes/class-cn-admin.php';

register_activation_hook( __FILE__, [ 'CN_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CN_Plugin', 'deactivate' ] );

final class CN_Plugin {

	const CRON_HOOK = 'cn_refresh_feeds';

	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			// Refuse network activation: every site would get the table and cron job.
			deactivate_plugins( plugin_basename( __FILE__ ), true, true );
			wp_die( esc_html__( 'Channel Network must be activated on a single site (deprogrammer.org), not network-wide.', 'channel-network' ) );
		}
		CN_Suggestions::install();
		self::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'cn_interval', self::CRON_HOOK );
		}
	}

	public static function init() {
		add_filter( 'cron_schedules', static function ( $schedules ) {
			$schedules['cn_interval'] = [
				'interval' => CN_Settings::get( 'refresh_minutes' ) * MINUTE_IN_SECONDS,
				'display'  => 'Channel Network feed refresh',
			];
			return $schedules;
		} );
		add_action( self::CRON_HOOK, [ 'CN_Feeds', 'refresh_all' ] );
		add_action( self::CRON_HOOK, [ 'CN_Suggestions', 'prune_personal_data' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );

		CN_Suggestions::init();
		CN_Highlights::init();
		CN_Members::init();
		CN_Page::init();
		if ( is_admin() ) {
			CN_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once CN_DIR . 'includes/class-cn-cli.php';
			WP_CLI::add_command( 'channel-network', 'CN_CLI' );
		}
	}
}

CN_Plugin::init();
