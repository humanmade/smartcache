# Smartcache

Smartcache integrates with Batcache to smartly tune the cache lifetime for your content.


## Behaviour

By default, caching plugins like Batcache will cache for a static 5 minutes for all content.

Smartcache dynamically tunes this cache lifetime to match the type of content. It breaks content into three buckets: frequently updated, regular content, and infrequently updated content.

Frequently updated content is cached for 5 minutes. This content is:

* The "home" page - this is the page showing the list of posts, not necessarily the "front page" for sites using a static home page.
* Content (posts, pages, etc) published in the past 24 hours

Regular content is cached for 6 hours. This content is:

* Most archive pages (including categories, tags, author pages)
* Date archive pages, except the current one (today/current month/current year)
* Search pages

Infrequently updated content is cached for 14 days. This content is:

* Pages (except those published recently)
* Date archive pages which aren't the current one
* 404 pages


## Overriding behaviour

Smartcache has a variety of filters available. These include:

* `smartcache.old_threshold` - Filter how long a post must be published before it's considered old (infrequently updated). Default is 7 days.
* `smartcache.is_old_post` - Filter whether a specific post is considered old (infrequently updated). (Default true for posts older than the old threshold.)
* `smartcache.is_new_post` - Filter whether a specific post is considered new (frequently updated). (Default true if published in previous 24 hours.)
* `smartcache.max-age` - Filter the maximum lifetime for the current page directly.
* `smartcache.should_cache` - Filter whether a page should be cached at all. (Only affects whether Smartcache generates a header, but may be overridden by other behaviour or by the cache itself.)
* `smartcache.urls_to_invalidate_for_post` - Filter which URLs to invalidate for a given post.
