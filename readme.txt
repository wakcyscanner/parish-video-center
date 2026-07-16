=== Parish Video Center ===
Contributors: stpacc
Tags: vimeo, video, sermons, homilies, church
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Syncs a Vimeo showcase into WordPress video posts with a gallery archive, single video pages, and VideoObject structured data.

== Description ==

Parish Video Center keeps a WordPress site in sync with a Vimeo showcase — perfect for weekly homilies, sermons, or messages. Videos become regular WordPress posts (a custom post type), so they get real permalinks, show up in search, and emit schema.org VideoObject structured data for Google video results.

* Automatic sync on a WP-Cron schedule (hourly, twice daily, daily, or weekly), plus a manual Sync Now button.
* Configurable naming: call your videos Homilies, Sermons, Messages — labels and the archive URL slug are settings.
* Gallery archive page with the newest video as a hero, then a thumbnail grid with pagination.
* "More videos" recirculation grid at the bottom of every single video page, with thumbnail previews of other recent videos.
* Click-to-play player facade: pages load a poster image, and the Vimeo iframe is only injected on click (fast pages, no third-party requests until the visitor opts in; dnt=1 is set on the player).
* Videos removed from the showcase are unpublished (drafted), never deleted. Videos that return are re-published.
* Per-video "Don't overwrite with Vimeo data" lock so editors can customize a title, description, or thumbnail without sync reverting it.
* Thumbnails are sideloaded into the media library and refreshed when they change on Vimeo.
* VideoObject JSON-LD on single pages and ItemList JSON-LD on the archive.
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

= What happens on uninstall? =

Plugin options and scheduled events are removed. Video posts and sideloaded media are left in place.

== Changelog ==

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
