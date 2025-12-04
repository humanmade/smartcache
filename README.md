# Smartcache

Smartcache is a WordPress plugin that sets intelligent cache lifetimes for your site. It sets Cache-Control headers for CDNs and reverse proxies to cache WordPress pages for extended periods (days+), while automatically handling cache invalidations when content is updated.

## Features

- **Long cache lifetimes**: Pages are cached for 14 days by default (configurable)
- **Automatic cache invalidation**: Clears cache when posts are updated, deleted, or change status
- **Smart caching rules**: Automatically skips caching for logged-in users, POST/PUT/DELETE requests, and authenticated requests
- **Batcache integration**: Works seamlessly with Batcache for additional caching layers
- **CDN cache purging**: Integrates with Altis Cloud to purge CDN paths on content updates
- **Admin logging**: View cache invalidation history in the WordPress admin

## Installation

1. Install via Composer:
   ```bash
   composer require humanmade/smartcache
   ```

2. Or install manually by cloning this repository into your `wp-content/plugins` directory.

3. The plugin will activate automatically if you're using a Composer-based setup, or you can activate it through the WordPress admin.

## Configuration

### Adjusting Cache TTL (Time to Live)

The default cache lifetime is 14 days. You can adjust this using the `smartcache.max-age` filter in your theme or plugin:

```php
// Set cache TTL to 7 days
add_filter( 'smartcache.max-age', function() {
    return 7 * DAY_IN_SECONDS;
} );

// Set cache TTL to 1 hour
add_filter( 'smartcache.max-age', function() {
    return HOUR_IN_SECONDS;
} );

// Set cache TTL to 30 days
add_filter( 'smartcache.max-age', function() {
    return 30 * DAY_IN_SECONDS;
} );
```

### Controlling What Gets Cached

You can control whether a response should be cached using the `smartcache.should_cache` filter:

```php
// Disable caching for specific pages
add_filter( 'smartcache.should_cache', function( $should_cache ) {
    if ( is_page( 'no-cache-page' ) ) {
        return false;
    }
    return $should_cache;
} );
```

By default, the plugin will not cache responses for:
- Logged-in users
- POST, PUT, or DELETE requests
- Requests with HTTP Authorization headers

### Customizing URLs to Invalidate

When a post is updated, the plugin invalidates its permalink by default. You can add additional URLs to invalidate using the `smartcache.urls_to_invalidate_for_post` filter:

```php
// Invalidate additional URLs when a post is updated
add_filter( 'smartcache.urls_to_invalidate_for_post', function( $urls, $post_id ) {
    // Invalidate the homepage
    $urls[] = home_url( '/' );
    
    // Invalidate category archives
    $categories = get_the_category( $post_id );
    foreach ( $categories as $category ) {
        $urls[] = get_category_link( $category->term_id );
    }
    
    return $urls;
}, 10, 2 );
```

## WP-CLI Commands

Smartcache includes WP-CLI commands for managing cache:

```bash
# View available commands
wp smartcache
```

## Requirements

- PHP 8.0 or higher
- WordPress (tested with modern versions)

## How It Works

1. **Cache Headers**: On each request, Smartcache sets a `Cache-Control` header with `s-maxage` for CDN/proxy caching and `must-revalidate`. When Batcache is available, it also sets `max-age` for browser caching
2. **Content Updates**: When a published post is updated, trashed, deleted, or changes to any other status, the plugin queues cache invalidation
3. **Invalidation**: URLs are purged from both Batcache (if available) and the CDN cache
4. **Logging**: All invalidation attempts are logged and viewable in the WordPress admin

## License

GPL-2.0-or-later

## Credits

Created by [Joe Hoyle](mailto:joehoyle@gmail.com) for [Human Made](https://humanmade.com)
