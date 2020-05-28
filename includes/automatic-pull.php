<?php
/**
 * Automatic Pull functionality
 *
 * @package  distributor
 */

namespace Distributor\AutomaticPull;

/**
 * Pull Action Entry Point
 *
 * @since 0.8
 */
function initiate_pull() {
	// Get External Connection ID
	global $wpdb;
	$sql = "SELECT * FROM wp_posts WHERE post_type = 'dt_ext_connection' AND post_status = 'publish' LIMIT 1;"; // get the one and only one external connection
	$results = $wpdb->get_results($sql);
	$external_connection_id = $results[0]->ID;

	// Instantiate connection object and define useful params
	\Distributor\Connections::factory()->register( '\Distributor\ExternalConnections\WordPressExternalConnection' );
	$external_connection = \Distributor\ExternalConnection::instantiate( $external_connection_id );
	// exit if no connection found
	if ($external_connection instanceof \WP_ERROR) {
		return false;
	}
	$connection_now = $external_connection;
	// per_page is capped at 100: https://developer.wordpress.org/rest-api/using-the-rest-api/pagination/
	$per_page = 100; // in theory in order to miss any posts, there has to be more than 100 new posts from hkfp in a day
	$current_page = 1;
	$post_type = $connection_now->pull_post_type ?: 'post';
	$remote_get_args = [
		'posts_per_page' => $per_page,
		'paged'          => $current_page,
		'post_type'      => $post_type,
		'orderby'        => 'ID', // this is because of include/exclude truncation
		'order'          => 'DESC', // default but specifying to be safe
	];

	// add to dt_pull_post_args to make posts pulled become published right away
	add_filter( 'dt_pull_post_args', function($post_array, $remote_post_id, $post, $conn) {
		$post_array['post_status'] = 'publish';
		return $post_array;
	}, 10, 4 );

	// Core Actions
	$fetched_posts = fetch_new_posts($connection_now, $remote_get_args, $post_type);
	if (count($fetched_posts) > 0) {
		pull_new_posts($connection_now, $fetched_posts);
	}
}

function fetch_new_posts($connection_now, $remote_get_args, $post_type) {
	// get the list of already synced posts
	$sync_log = get_post_meta( $connection_now->id, 'dt_sync_log', true );
	$skipped     = array();
	$syndicated  = array();
	foreach ( $sync_log as $old_post_id => $new_post_id ) {
		if ( false === $new_post_id ) {
			$skipped[] = (int) $old_post_id;
		} else {
			$syndicated[] = (int) $old_post_id;
		}
	}
	// rsorted: will query latest posts not in the latest skipped/syndicated
	rsort( $skipped, SORT_NUMERIC );
	rsort( $syndicated, SORT_NUMERIC );
	// This is somewhat arbitrarily set to 200 and should probably be made filterable eventually.
	// IDs can get rather large and 400 easily exceeds typical header size limits.
	$post_ids = array_slice( array_merge( $skipped, $syndicated ), 0, 200, true );

	$remote_get_args['post__not_in'] = $post_ids;
	$remote_get_args['meta_query'] = [
		[
			'key'     => 'dt_syndicate_time',
			'compare' => 'NOT EXISTS',
		],
	];

	// fetch posts from main site
	$remote_get = $connection_now->remote_get( $remote_get_args );

	// restructure the posts array for the pull action
	$posts = array_map(function( $wpobject ) use ( $post_type ) {
		return [
			'remote_post_id' 			=> $wpobject->ID,
			'post_type'      			=> $post_type,
			'post_date'		 			=> $wpobject->post_date,
			'post_date_gmt'				=> $wpobject->post_date,
			'post_modified'				=> $wpobject->post_modified,
			'post_modified_gmt'			=> $wpobject->post_modified_gmt,
		];
	}, $remote_get['items']);

	return $posts;
}

function pull_new_posts($connection_now, $posts) {
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
}