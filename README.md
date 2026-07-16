# Parish Video Center

A WordPress plugin that keeps your site in sync with a Vimeo showcase — built for weekly homilies, sermons, or messages, but happy to host any showcase.

Videos become real WordPress posts (a custom post type), so they get permalinks, show up in site search, and emit [schema.org VideoObject](https://schema.org/VideoObject) structured data for Google video results.

## Features

- **Automatic sync** on a WP-Cron schedule (hourly, twice daily, daily, or weekly), plus a manual *Sync Now* button and a *Test Connection* check.
- **Your vocabulary** — call them Homilies, Sermons, or Messages: the post labels and the archive URL slug are settings.
- **Video-hub landing page** — a split hero features the latest video (player beside a "Latest" badge, short excerpt, and an "Up Next" list), followed by a "Browse all" thumbnail grid with duration badges and pagination; clean single-video pages. A theme can override either template (`archive-svc_video.php` / `single-svc_video.php`).
- **Recirculation** — every single video page ends with a thumbnail grid of other recent videos and a link to the full gallery (count filterable via `svc_related_count`; return `0` to disable).
- **Click-to-play facade** — pages load only a poster image; the Vimeo iframe is injected on click (`dnt=1` set on the player), so no third-party requests until the visitor opts in.
- **Safe sync semantics** — videos removed from the showcase are unpublished (drafted), never deleted, and re-publish if they return. A per-video *"Don't overwrite with Vimeo data"* lock protects manual edits.
- **SEO** — VideoObject JSON-LD on single pages, ItemList JSON-LD on the archive. Thumbnails are sideloaded into the media library.
- **Deep links** — `/your-slug/?v=<vimeo-id>` 301s to the matching video page.

## Installation

1. Grab `parish-video-center.zip` from the [latest release](../../releases/latest) and install it via **Plugins → Add New → Upload Plugin**.
2. Create a personal access token at [developer.vimeo.com/apps](https://developer.vimeo.com/apps) with the **public** and **private** scopes, on the Vimeo account that owns the showcase.
3. Find your showcase ID — the number in the showcase URL: `vimeo.com/showcase/1234567` → `1234567`.
4. In wp-admin, open the video post type menu → **Settings**, enter the token and showcase ID, and click **Test Connection**.
5. Pick your labels (e.g. *Homily / Homilies*) and archive slug (e.g. `homilies`), save, then click **Sync Now**.

Your gallery appears at `yoursite.org/<your-slug>/`.

> **Tip:** instead of storing the token in the database, define it in `wp-config.php` — `define( 'VIMEO_TOKEN', '…' );` — which overrides the settings field.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Vimeo plan that supports showcases, and a personal access token

## License

[GPL-2.0-or-later](LICENSE)
