=== Parish Video Center ===
Contributors: stpacc
Tags: vimeo, video, sermons, homilies, church
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Syncs a Vimeo showcase into WordPress video posts with a gallery archive, single video pages, and VideoObject structured data.

== Description ==

Parish Video Center keeps a WordPress site in sync with a Vimeo showcase — perfect for weekly homilies, sermons, or messages. Videos become regular WordPress posts (a custom post type), so they get real permalinks, show up in search, and emit schema.org VideoObject structured data for Google video results.

* Automatic sync on a WP-Cron schedule (hourly, twice daily, daily, or weekly), plus a manual Sync Now button.
* Configurable naming: call your videos Homilies, Sermons, Messages — labels and the archive URL slug are settings.
* Video-hub landing page: split hero with the latest video's player beside a "Latest" badge, excerpt, and an Up Next list, then a "Browse all" thumbnail grid with duration badges and pagination.
* "More videos" recirculation grid at the bottom of every single video page, with thumbnail previews of other recent videos.
* Click-to-play player facade: pages load a poster image, and the Vimeo iframe is only injected on click (fast pages, no third-party requests until the visitor opts in; dnt=1 is set on the player).
* Videos removed from the showcase are unpublished (drafted), never deleted. Videos that return are re-published.
* Per-video "Don't overwrite with Vimeo data" lock so editors can customize a title, description, or thumbnail without sync reverting it.
* Thumbnails are sideloaded into the media library and refreshed when they change on Vimeo.
* VideoObject JSON-LD on single pages and ItemList JSON-LD on the archive.
* Google video sitemap at /video-sitemap.xml (advertised in robots.txt) and Open Graph / Twitter player tags so shared links unfurl as playable video cards.
* Updates itself from GitHub releases through the normal WordPress updates screen.
* Deep links: /your-slug/?v=<vimeo-id> redirects (301) to the matching video page.

== Installation ==

1. Upload the plugin and activate it.
2. Create a personal access token at developer.vimeo.com/apps with the "public" and "private" scopes, using the Vimeo account that owns the showcase.
3. Find your showcase ID: it is the number in the showcase URL, e.g. vimeo.com/showcase/1234567 → 1234567.
4. Go to the video post type menu → Settings, enter the token and showcase ID, and click "Test Connection".
5. Pick your labels (e.g. Homily / Homilies) and archive URL slug (e.g. homilies), then Save.
6. Click "Sync Now". Your videos appear at yoursite.org/<your-slug>/.

Security note: instead of storing the token in the database, you can define it in wp-config.php — `define( 'VIMEO_TOKEN', '...' );` — which overrides the settings field.

== Frequently Asked Questions ==

= Does deleting a video on Vimeo delete the WordPress post? =

No. When a video leaves the showcase, its post is set back to draft. Nothing is ever deleted by sync.

= Can I edit a synced post? =

Yes — check "Don't overwrite with Vimeo data" in the Vimeo Sync box on the edit screen, or sync will overwrite your changes on its next run.

= Visitors see an outdated page even though the videos updated =

The plugin purges the major page caches (WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, LiteSpeed, SiteGround Optimizer, Cache Enabler, Breeze, Hummingbird, Nginx Helper, Comet Cache, WP Engine, Pantheon) automatically after every sync that changes content, after a plugin update, and after saving settings. If your cache isn't on that list, hook the `svc_purge_page_cache` action and clear it there.

= What happens on uninstall? =

Plugin options and scheduled events are removed. Video posts and sideloaded media are left in place.

== Changelog ==

= 1.4.0 =
* Plugin updates now arrive through the normal WordPress updates screen, powered by GitHub releases (Update URI mechanism, no external service).
* Open Graph and Twitter player tags on single video pages so shared links unfurl as playable video cards; steps aside for Yoast/Rank Math/SEOPress/AIOSEO basics and only adds the video tags they lack. Filterable via svc_social_meta.
* Google video sitemap at /video-sitemap.xml with thumbnails, durations, and publication dates, advertised in robots.txt — submit it in Search Console for video rich results.

= 1.3.2 =
* WP Rocket-based caches (including AccelerateWP) now also get their minified assets and Used CSS (Remove Unused CSS) cleared, not just the page cache — stale optimized CSS was leaving new layouts unstyled for logged-out visitors. Autoptimize's asset cache is cleared too.

= 1.3.1 =
* Page caches are now purged automatically when the plugin changes public pages: after a sync that created, updated, unpublished, or re-thumbnailed anything; once after a plugin update (new templates/styles); and after saving settings. Supports the major cache plugins and hosts, with a svc_purge_page_cache action for anything else.

= 1.3.0 =
* The archive landing page is now a video hub: split hero (player left; "Latest" badge, title, date/duration, short excerpt, and an Up Next list right), a "Browse all" section heading, and duration badges on all thumbnail tiles.

= 1.2.0 =
* Recirculation module at the bottom of single video pages: a thumbnail grid of up to four other recent videos plus a link to the full gallery. Filterable via the svc_related_count hook (return 0 to disable).

= 1.1.0 =
* Generalized for any church: configurable video labels and archive slug.
* Settings moved under the video post type menu, with a Test Connection button.
* The saved Vimeo token is no longer echoed back into the settings form.
* Clearer Vimeo API error messages (401/403/404/429) and a weekly sync option.
* Setup notice in wp-admin until a token and showcase ID are configured.

= 1.0.0 =
* Initial release (as StPACC Video Center).
