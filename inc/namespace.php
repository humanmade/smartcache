<?php

namespace Smartcache;

use Altis\Cloud;
use Exception;
use WP_CLI;
use WP_Post;

/**
 * Short lifetime, used by frequently updated content.
 *
 * (5 minutes.)
 */
const LIFETIME_SHORT = 300;

/**
 * Medium lifetime, used by most regular content.
 *
 * (6 hours.)
 */
const LIFETIME_MEDIUM = 21600;

/**
 * Long lifetime, used by rarely updated or old content.
 *
 * (14 days.)
 */
const LIFETIME_LONG = 1209600;

/**
 * Bootstrap function to set up the plugin.
 *
 * @return void
 */
function bootstrap() : void {
	add_action( 'template_redirect', __NAMESPACE__ . '\\set_cache_ttl' );
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
 * Check if the given post is "old".
 *
 * Old content is unlikely to change frequently.
 */
function is_old_content( WP_Post $post ) {
	$old_threshold = apply_filters( 'smartcache.old_threshold', 7 * DAY_IN_SECONDS );
	return apply_filters( 'smartcache.is_old_post', $post->post_date_gmt < ( time() - $old_threshold ) );
}

function is_new_content( WP_Post $post ) {
	return apply_filters( 'smartcache.is_new_post', $post->post_date_gmt > ( time() - DAY_IN_SECONDS ) );
}

/**
 * Get the default lifetime for the current page.
 *
 * Determines an appropriate lifetime based on the age and type of the content.
 *
 * This can be overridden by setting a different max age manually.
 *
 * @return int One of LIFETIME_SHORT, LIFETIME_MEDIUM, or LIFETIME_LONG.
 */
function get_default_lifetime() : int {
	// The home (i.e. post list page) is likely to change more frequently,
	// and feed readers should always receive fresh content.
	if ( is_home() || is_feed() ) {
		return LIFETIME_SHORT;
	}

	// Single content depends on how old the content is.
	if ( is_singular() ) {
		$post = get_queried_object();

		// If the post was published today, cache it for a shorter time.
		// This accounts for fixes to the content, new comments, etc.
		if ( is_new_content( $post ) ) {
			return LIFETIME_SHORT;
		}

		// If the post is older than 7 days, cache it for longer.
		// Also, pages are likely to change less frequently.
		if ( is_old_content( $post ) || is_page() ) {
			return LIFETIME_LONG;
		}

		return LIFETIME_MEDIUM;
	}

	// Date-based archives won't change after the period is over.
	if ( is_date() ) {
		$is_current = $is_current = get_query_var( 'year' ) === date( 'Y' );
		if ( is_month() || is_day() ) {
			$is_current = $is_current && get_query_var( 'monthnum' ) === date( 'm' );
		}
		if ( is_day() ) {
			$is_current = $is_current && get_query_var( 'day' ) === date( 'd' );
		}

		return $is_current ? LIFETIME_MEDIUM : LIFETIME_LONG;
	}

	// 404 pages never change, except on publication.
	if ( is_404() ) {
		return LIFETIME_LONG;
	}

	// Other archive pages are likely to change less frequently.
	if ( is_archive() || is_search() ) {
		return LIFETIME_MEDIUM;
	}

	// Default to medium lifetime for other pages.
	return LIFETIME_MEDIUM;
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
	$max_age = absint( apply_filters( 'smartcache.max-age', get_default_lifetime() ) );
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
	wp_schedule_single_event( time() + 5, 'logcache.invalidate_urls', [ $urls ] );
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

	// Is this a new post? If so, don't send any invalidations.
	$skip_invalidation = apply_filters( 'smartcache.should_invalidate', ! is_new_content( $post ), $post );
	if ( $skip_invalidation ) {
		return;
	}

	queue_invalidate_urls( get_urls_to_invalidate_for_post( $post->ID ) );
}

/**
 * Get the URLs for a given post id.
 *
 * @param integer $post_id
 * @return string[]
 */
function get_urls_to_invalidate_for_post( int $post_id ) : array {
	$urls = [
		get_permalink( $post_id ),
	];

	$urls = apply_filters( 'smartcache.urls_to_invalidate_for_post', $urls, $post_id );

	return $urls;
}
