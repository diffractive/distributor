<?php
/**
 * Plugin Name:       Distributor (HKFP)
 * Description:       Makes it easy to syndicate and reuse content across your websites, whether inside of a multisite or across the web.
 * Version:           1.5.0
 * Author:            10up Inc.
 * Author URI:        https://distributorplugin.com
 * License:           GPLv2 or later
 * Text Domain:       distributor
 * Domain Path:       /lang/
 * GitHub Plugin URI: https://github.com/10up/distributor
 *
 * @package distributor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'DT_VERSION', '1.5.0' );
define( 'DT_PLUGIN_FILE', preg_replace( '#^.*plugins/(.*)$#i', '$1', __FILE__ ) );

// Define a constant if we're network activated to allow plugin to respond accordingly.
$active_plugins = get_site_option( 'active_sitewide_plugins' );

if ( is_multisite() && isset( $active_plugins[ plugin_basename( __FILE__ ) ] ) ) {
	define( 'DT_IS_NETWORK', true );
} else {
	define( 'DT_IS_NETWORK', false );
}

/**
 * PSR-4 autoloading
 */
spl_autoload_register(
	function( $class ) {
			// Project-specific namespace prefix.
			$prefix = 'Distributor\\';
			// Base directory for the namespace prefix.
			$base_dir = __DIR__ . '/includes/classes/';
			// Does the class use the namespace prefix?
			$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}
			$relative_class = substr( $class, $len );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
			// If the file exists, require it.
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Require PHP version 5.6 - throw an error if the plugin is activated on an older version.
 */
register_activation_hook(
	__FILE__,
	function() {
		if ( version_compare( PHP_VERSION, '5.6.0', '<' ) ) {
			wp_die(
				esc_html__( 'Distributor requires PHP version 5.6.', 'distributor' ),
				esc_html__( 'Error Activating', 'distributor' )
			);
		}
	}
);

/**
 * Tell the world this site supports Distributor. We need this for external connections.
 */
add_action(
	'send_headers',
	function() {
		if ( ! headers_sent() ) {
			header( 'X-Distributor: yes' );
		}
	}
);

/**
 * Set Distributor header in all API responses.
 */
add_filter(
	'rest_post_dispatch',
	function( $response ) {
		$response->header( 'X-Distributor', 'yes' );

		return $response;
	}
);

\Distributor\Connections::factory();

// Include in case we have composer issues.
require_once __DIR__ . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/external-connection-cpt.php';
require_once __DIR__ . '/includes/push-ui.php';
require_once __DIR__ . '/includes/pull-ui.php';
require_once __DIR__ . '/includes/rest-api.php';
require_once __DIR__ . '/includes/subscriptions.php';
require_once __DIR__ . '/includes/syndicated-post-ui.php';
require_once __DIR__ . '/includes/distributed-post-ui.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/template-tags.php';

if ( \Distributor\Utils\is_vip_com() ) {
	add_filter( 'dt_network_site_connection_enabled', '__return_false', 9 );
}

if ( class_exists( 'Puc_v4_Factory' ) ) {
	/**
	 * Enable updates if we have a valid license
	 */
	$valid_license = false;

	if ( ! DT_IS_NETWORK ) {
		$valid_license = \Distributor\Utils\get_settings()['valid_license'];
	} else {
		$valid_license = \Distributor\Utils\get_network_settings()['valid_license'];
	}

	if ( $valid_license ) {
		// @codingStandardsIgnoreStart
		$updateChecker = Puc_v4_Factory::buildUpdateChecker(
			'https://github.com/10up/distributor/',
			__FILE__,
			'distributor'
		);

		$updateChecker->addResultFilter(
			function( $plugin_info, $http_response = null ) {
				$plugin_info->icons = array(
					'svg' => plugins_url( '/assets/img/icon.svg', __FILE__ ),
				);
				return $plugin_info;
			}
		);
		// @codingStandardsIgnoreEnd
	}
}

/**
 * Register connections
 */
add_action(
	'init',
	function() {
		\Distributor\Connections::factory()->register( '\Distributor\ExternalConnections\WordPressExternalConnection' );
		\Distributor\Connections::factory()->register( '\Distributor\ExternalConnections\WordPressDotcomExternalConnection' );
		if (
			/**
			 * Filter whether the network connection type is enabled. Enabled by default, return false to disable.
			 *
			 * @since 1.0.0
			 *
			 * @param bool true Whether the network connection should be enabled.
			 */
			apply_filters( 'dt_network_site_connection_enabled', true )
		) {
			\Distributor\Connections::factory()->register( '\Distributor\InternalConnections\NetworkSiteConnection' );
		}
	},
	1
);

/**
 * We use setup functions to avoid unit testing WP_Mock strict mode errors.
 */
\Distributor\ExternalConnectionCPT\setup();
\Distributor\PushUI\setup();
\Distributor\PullUI\setup();
\Distributor\RestApi\setup();
\Distributor\Subscriptions\setup();
\Distributor\SyndicatedPostUI\setup();
\Distributor\DistributedPostUI\setup();
\Distributor\Settings\setup();

// test pull functionality
add_action('hkfp_distributor_pull_hook', function() {
	add_action('init', function() {
		\Distributor\Connections::factory()->register( '\Distributor\ExternalConnections\WordPressExternalConnection' );
		$external_connection_id = 262049;
		$external_connection = \Distributor\ExternalConnection::instantiate( $external_connection_id );
		$connection_now = $external_connection;
		$per_page = 10;
		$current_page = 1;
		$post_type = $connection_now->pull_post_type ?: 'post';

		$remote_get_args = [
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			'post_type'      => $post_type,
			'orderby'        => 'ID', // this is because of include/exclude truncation
			'order'          => 'DESC', // default but specifying to be safe
		];


		$sync_log = get_post_meta( $connection_now->id, 'dt_sync_log', true );

		$skipped     = array();
		$syndicated  = array();
		$total_items = false;

		foreach ( $sync_log as $old_post_id => $new_post_id ) {
			if ( false === $new_post_id ) {
				$skipped[] = (int) $old_post_id;
			} else {
				$syndicated[] = (int) $old_post_id;
			}
		}

		rsort( $skipped, SORT_NUMERIC );
		rsort( $syndicated, SORT_NUMERIC );

		// This is somewhat arbitrarily set to 200 and should probably be made filterable eventually.
		// IDs can get rather large and 400 easily exceeds typical header size limits.
		// todo: the problem is, posts will be pulled duplicated if there are more than 200 in skipped and syndicated combined
		$post_ids = array_slice( array_merge( $skipped, $syndicated ), 0, 200, true );

		$remote_get_args['post__not_in'] = $post_ids;

		$remote_get_args['meta_query'] = [
			[
				'key'     => 'dt_syndicate_time',
				'compare' => 'NOT EXISTS',
			],
		];

		$remote_get = $connection_now->remote_get( $remote_get_args );

		
		$posts = array_map(function( $wpobject ) use ( $post_type ) {
			return [
				'remote_post_id' => $wpobject->ID,
				'post_type'      => $post_type,
			];
		}, $remote_get['items']);
		// error_log(print_r($posts, true));

		// todo: need to have the posts in published state
		$new_posts  = $connection_now->pull( $posts );

		foreach ( $posts as $key => $post_array ) {
			if ( is_wp_error( $new_posts[ $key ] ) ) {
				continue;
			}
			\Distributor\Subscriptions\create_remote_subscription( $connection_now, $post_array['remote_post_id'], $new_posts[ $key ] );
		}
		$post_id_mappings = array();

		foreach ( $posts as $key => $post_array ) {
			if ( is_wp_error( $new_posts[ $key ] ) ) {
				continue;
			}
			$post_id_mappings[ $post_array['remote_post_id'] ] = $new_posts[ $key ];
		}

		$connection_now->log_sync( $post_id_mappings );
	});
});
// do_action('hkfp_distributor_pull_hook');