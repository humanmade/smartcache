<?php

namespace Smartcache;

use Altis\Cloud;
use Exception;
use WP_CLI;
use WP_Post;

/**
 * Bootstrap function to set up the plugin.
 *
 * @return void
 */
function bootstrap() : void {
	add_action( 'template_redirect', __NAMESPACE__ . '\\set_cache_ttl' );
	add_action( 'pre_post_update', __NAMESPACE__ . '\\capture_post_permalink_before_update', 10, 2 );
	add_action( 'before_delete_post', __NAMESPACE__ . '\\capture_post_permalink_before_delete', 10, 1 );
	add_action( 'transition_post_status', __NAMESPACE__ . '\\on_transition_post_status', 10, 3 );
	add_action( 'smartcache.invalidate_urls', __NAMESPACE__ . '\\on_cron_invalidate_urls' );

	if ( defined( 'WP_CLI' ) && \WP_CLI ) {
		require_once __DIR__ . '/class-cli-command.php';
		WP_CLI::add_command( 'smartcache', __NAMESPACE__ . '\\CLI_Command' );
	}
}

/**
 * Check if the current request should be cached.
 *
 * @return boolean
 */
function should_cache_response() : bool {
	$should_cache = true;

	if ( is_user_logged_in() ) {
		$should_cache = false;
	}

	if ( in_array( $_SERVER['REQUEST_METHOD'], [ 'POST', 'DELETE', 'PUT' ] ) ) {
		$should_cache = false;
	}

	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$should_cache = false;
	}

	return apply_filters( 'smartcache.should_cache', $should_cache );
}

/**
 * Set the cache TTL depending on the curernt global scope.
 *
 * @return void
 */
function set_cache_ttl() : void {
	if ( ! should_cache_response() ) {
		return;
	}

	global $batcache;
	$max_age = absint( apply_filters( 'smartcache.max-age', DAY_IN_SECONDS * 14 ) ); // 14 days by default.
	if ( ! $batcache || ! is_object( $batcache ) ) {
		header( 'Cache-Control: s-maxage=' . $max_age . ', must-revalidate' );
	} else {
		header( 'Cache-Control: s-maxage=' . $max_age . ', max-age=' . $batcache->max_age . ', must-revalidate' );
	}
}

/**
 * Invalidate URLs on the CDN cache.
 *
 * @param array $urls
 * @return bool
 */
function invalidate_urls( array $urls ) : bool {
	if ( ! $urls ) {
		return true;
	}

	// Delete the URLs from Batcache (they need the full URL).
	array_map( __NAMESPACE__ . '\\batcache_clear_url', $urls );

	$urls = array_map( function ( string $url ) : string {
		$parts = parse_url( $url );
		$path = $parts['path'] ?? '/';

		$url = $path;
		if ( isset( $parts['query'] ) ) {
			$url .= '?' . $parts['query'];
		}
		return $url;
	}, $urls );

	try {
		$result = Cloud\purge_cdn_paths( $urls );
	} catch ( Exception $e ) {
		foreach ( $urls as $url ) {
			Log\insert_entry( $url, 'failed', $e->getMessage() );
		}
		return false;
	}

	foreach ( $urls as $url ) {
		Log\insert_entry( $url, $result ? 'succeeded' : 'failed' );
	}
	return $result;
}

/**
 * Clear cache for a given URL from Batcache
 *
 * This is taken from the batcache plugin.
 *
 * @param string $url
 * @return void
 */
function batcache_clear_url( string $url ) : void {
	if ( empty( $url ) ) {
		return;
	}


	// Remove all query params.
	$url = strtok( $url, '?' );

	if ( 0 === strpos( $url, 'https://' ) ) {
		$url = str_replace( 'https://', 'http://', $url );
	}

	if ( 0 !== strpos( $url, 'http://' ) ) {
		$url = 'http://' . $url;
	}

	$url_key = md5( $url );

	// Make sure batcache is set up as a global group.
	wp_cache_add_global_groups( 'batcache' );

	wp_cache_add( "{$url_key}_version", 0, 'batcache' );
	wp_cache_incr( "{$url_key}_version", 1, 'batcache' );
}

/**
 * Invalidate URLs on the CDN cache.
 *
 * @param array $urls
 * @return void
 */
function queue_invalidate_urls( array $urls ) : void {
	if ( ! $urls ) {
		return;
	}
	wp_schedule_single_event( time() + 5, 'smartcache.invalidate_urls', [ $urls ] );
}

/**
 * Callback function for the deferred invalidat urls cron.
 *
 * @param array $urls
 * @return void
 */
function on_cron_invalidate_urls( array $urls ) : void {
	invalidate_urls( $urls );
}

/**
 * Invalidate URLs when a post is updated.
 *
 * @param string $new_status New post status.
 * @param string $old_status Old post status.
 * @param WP_Post $post
 * @return void
 */
function on_transition_post_status( string $new_status, string $old_status, WP_Post $post ) : void {
	// Invalidate post URLS whenever an already published post is updated.
	// This ensures updates as well as delete/trash and any other status change are accounted for.
	if ( $old_status !== 'publish' ) {
		return;
	}


	queue_invalidate_urls( get_urls_to_invalidate_for_post( $post->ID ) );
}

/**
 * Capture a post's permalink before it is updated.
 *
 * When a post transitions out of published state, get_permalink() returns
 * a non-public URL (e.g. ?p=123). Capturing the permalink before the update
 * ensures we can invalidate the correct cached URL.
 *
 * @param int   $post_id Post ID.
 * @param array $data    Post data being updated.
 * @return void
 */
function capture_post_permalink_before_update( int $post_id, array $data ) : void {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_status !== 'publish' ) {
		return;
	}

	$permalink = get_permalink( $post_id );
	if ( ! $permalink ) {
		return;
	}

	captured_post_permalink( $post_id, $permalink );
}

/**
 * Capture a post's permalink before it is permanently deleted.
 *
 * Permanent deletion removes the post from the database before
 * transition_post_status fires, so get_permalink() would fail at that point.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function capture_post_permalink_before_delete( int $post_id ) : void {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_status !== 'publish' ) {
		return;
	}

	$permalink = get_permalink( $post_id );
	if ( ! $permalink ) {
		return;
	}

	captured_post_permalink( $post_id, $permalink );
}

/**
 * Registry for post permalinks captured before a status transition.
 *
 * Pass a permalink to store it for the given post ID.
 * Omit the permalink to retrieve a previously stored value.
 *
 * @param int         $post_id   Post ID.
 * @param string|null $permalink Permalink to store, or null to retrieve.
 * @return string|null The stored permalink, or null if not stored.
 */
function captured_post_permalink( int $post_id, ?string $permalink = null ) : ?string {
	static $permalinks = [];

	if ( $permalink !== null ) {
		$permalinks[ $post_id ] = $permalink;
	}

	return $permalinks[ $post_id ] ?? null;
}

/**
 * Get the URLs for a given post id.
 *
 * @param integer $post_id
 * @return string[]
 */
function get_urls_to_invalidate_for_post( int $post_id ) : array {
	// Use a pre-captured permalink if available; it will be correct even when
	// the post has already transitioned out of published state (at which point
	// get_permalink() would return a non-public URL like ?p=123).
	$permalink = captured_post_permalink( $post_id ) ?? get_permalink( $post_id );

	$urls = $permalink ? [ $permalink ] : [];

	$urls = apply_filters( 'smartcache.urls_to_invalidate_for_post', $urls, $post_id );

	return $urls;
}
