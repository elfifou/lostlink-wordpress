=== Lostlink – 404 Monitor & Smart Redirects ===
Contributors: elfifou
Tags: 404, redirect, 301, broken links, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tracks the 404 errors of your site and helps you fix them with 301 redirects.

== Description ==

Lostlink records every 404 served by WordPress, aggregated per URL:

* total, daily and weekly hit counts, and a 14-day daily history;
* the referers linking to each broken URL;
* URL variants (case, trailing slash, query string) counted as one broken URL;
* asset 404s (images, CSS, JS, fonts) ignored by default, and configurable exclusion patterns;
* bounded storage: row limit, referer cap and retention, purged by an hourly cron.

The dashboard (Tools > Lostlink) lists the broken URLs with their priority (Critical, High, Medium, Low), 14-day trend and referers, with search, sorting, CSV export, and actions to mark a URL as fixed, ignore it or delete it. Thresholds, exclusions and retention are configurable in its Settings tab.

Each broken URL can be fixed with a 301 (or 302, 307, 308) redirect, created in the Redirection plugin when it is set up, or in the plugin's own redirects otherwise (configurable). The query string of the requests is passed on in both cases. Redirect loops are blocked; redirect chains and targets that are themselves broken are reported. Built-in redirects only apply to URLs that return a 404, never hiding an existing page. Built-in redirects can be edited (target and type, with the same checks) or deleted from the Redirects tab; those created in Redirection are managed there. If a URL fixed by a redirect returns a 404 again (its redirect was deleted or disabled), it is put back on the dashboard.

404 pages served by a page cache plugin without running WordPress are counted too: the 404 page then includes a small script reporting each view (automatic when a page cache plugin is installed, configurable for caches outside WordPress). Only visitors running JavaScript are counted this way.

The fix and edit forms open in a dialog from the lists ("Use" fills in a suggested target), and work as plain pages without JavaScript.

The fix page suggests the published content whose URL is closest to the broken one (posts, pages, custom post types, their archives, categories, tags and custom taxonomies), including content whose slug moved to another path. A sitemap can be added as a source, for the pages that are not WordPress content; it is only fetched from public addresses or the site itself. Plugins can add candidates with the `lostlink_candidate_paths` filter.

On multilingual sites (Polylang), broken URLs and redirects keep their language prefix (/en/old-page), and the suggestions in the language of the broken URL come first, with their language shown. Other multilingual plugins can tell the language of a path with the `lostlink_path_language` filter.

The 404s the Redirection plugin logged before Lostlink was installed can be imported from the dashboard (or WP-CLI), so the history is not lost when switching. Hits logged later by both plugins are not counted twice.

== Frequently Asked Questions ==

= Are all 404s recorded? =

Only the 404s served by WordPress itself. Requests the web server answers without loading WordPress are not seen, such as missing images, CSS and JS files on nginx setups that serve static files directly. 404 pages served by a page cache are counted through a script, so only for visitors running JavaScript, which leaves out most bots.

= How much data is kept? =

By default at most 10,000 broken URLs (the least recently hit are purged first), 50 referers per URL, deleted after 90 days without hits, and 14 days of daily history. The limits are configurable in the Settings tab, and the purge runs hourly with WP-Cron: on a site without visits, or with WP-Cron disabled and no system cron, it is delayed.

= What do the built-in redirects match? =

One exact path each, compared case-insensitively and with or without a trailing slash; the query string of the request is passed on. Wildcards and regular expressions are not supported: use the Redirection plugin for them. A built-in redirect only applies when the URL returns a 404.

= Which targets are suggested? =

The 5,000 most recently modified published posts and pages and the 2,000 most used terms, to bound the time of a rebuild on large sites, plus the sitemap URLs when one is set (up to 50 files and 20,000 URLs). Suggestions need permalinks other than "Plain". The list is cached and rebuilt when content changes.

= Is my multilingual site supported? =

Polylang is supported. With other multilingual plugins, including WPML, recording and redirects work as on any site, and the language of the suggestions can be provided with the `lostlink_path_language` filter. Post type archives are only suggested in the current language.

= Does it work on multisite? =

Each site of a network has its own data and settings, but it is not tested on multisite, and uninstalling only removes the data of the current site.

== WP-CLI ==

* `wp lostlink list [--limit=<n>] [--format=<format>]`
* `wp lostlink referers <path>`
* `wp lostlink suggest <path> [--limit=<n>] [--min-score=<percent>]`
* `wp lostlink import-redirection`
* `wp lostlink maintenance`

== Translations ==

A French translation is included.
