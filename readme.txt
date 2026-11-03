=== Lostlink – 404 Monitor & Smart Redirects ===
Contributors: elfifou
Tags: 404, redirect, 301, broken links, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds the broken URLs that cost you visitors, ranks them by priority and fixes them with the right 301 redirect in one click.

== Description ==

Every 404 on your site is a lost visitor, and links from other sites that lead nowhere. Lostlink shows which broken URLs matter most, suggests the page to send them to, and creates the redirect in one click.

= Find every broken URL =

* Every 404 served by WordPress is recorded, aggregated per URL: total, daily and weekly hits, first and last seen, and a 14-day history.
* The referers linking to each broken URL show where the broken links are.
* Variants of a URL (case, trailing slash, query string) count as one.
* Missing images, CSS, JS and fonts are left out by default, and exclusion patterns (paths, globs or regular expressions) keep the bot noise out.
* 404 pages served by a page cache, without running WordPress, are counted too.

= Know what to fix first =

The dashboard (Tools > Lostlink) ranks the broken URLs by priority, Critical, High, Medium or Low, from how often and how recently they are hit, with a 14-day trend chart. Search, sorting, bulk actions and CSV export included. The thresholds are settings.

= Fix in one click =

* For each broken URL, Lostlink suggests the published content whose address is closest, including posts whose slug moved to another path: "Use", then "Fix".
* Redirects are 301 by default (302, 307 and 308 available), and keep the query string of the request, so campaign parameters survive.
* Redirect loops are blocked; redirect chains and targets that are themselves broken are reported before you save.
* A URL fixed by a redirect that returns a 404 again (its redirect was deleted or disabled) comes back on the dashboard.

= Works with Redirection, or on its own =

Lostlink creates its redirects in the Redirection plugin when it is set up, so you keep managing them in one place. Without it, Lostlink serves its own redirects, which only apply to URLs that return a 404: they can never hide an existing page.

Switching from Redirection's 404 log? Import its history from the dashboard, without counting any hit twice.

= Multilingual =

On Polylang sites, broken URLs and redirects keep their language prefix, and the suggestions in the language of the broken URL come first.

= Made to last =

* Storage is capped and purged every hour, so anonymous traffic and bots can't grow the tables without limit.
* Modern, strictly typed PHP 8.1 code, checked with PHPStan at its strictest level and the WordPress Coding Standards, and covered by over 150 automated tests.
* Translated into French.

= For developers =

WP-CLI commands:

* `wp lostlink list [--limit=<n>] [--format=<format>]`: the broken URLs, most hit first.
* `wp lostlink referers <path>`: the referers of a broken URL.
* `wp lostlink suggest <path> [--limit=<n>] [--min-score=<percent>]`: the suggested targets.
* `wp lostlink import-redirection`: imports the 404 log of the Redirection plugin.
* `wp lostlink maintenance`: runs the hourly purge now.

Filters: `lostlink_should_record` (skip some 404s), `lostlink_candidate_paths` (add redirect targets), `lostlink_path_language` (language of a path, for multilingual plugins other than Polylang), `lostlink_capability` and `lostlink_settings_capability` (who can use the dashboard and change the settings).

= Privacy =

Lostlink stores, in its own tables, the path of each URL that returned a 404 (without its query string), the referers sent by the browsers, hit counts and dates, and for each redirect the ID of the user who created it. It stores no IP address, no user agent and no cookie, and sends nothing to any third-party service.

A referer is the address of the page a visitor came from, and can contain personal data in its query string: referers are deleted after 90 days without a hit, and capped per URL (both settings). Lostlink adds a suggested paragraph to the privacy policy guide (Settings > Privacy). Deleting the plugin removes all its data.

== Installation ==

1. In Plugins > Add New Plugin, search for "Lostlink", then install and activate it.
2. 404s are recorded from now on. Open Tools > Lostlink to see them.
3. If the Redirection plugin logged 404s before, the dashboard offers to import them.
4. Adjust the thresholds, exclusions and retention in the Settings tab if needed.

== Frequently Asked Questions ==

= Do I need the Redirection plugin? =

No. Lostlink has its own redirects. When Redirection is set up, Lostlink creates the redirects there by default, which can be changed in the settings.

= Does it slow my site down? =

Only 404s cost anything: a few database writes per 404, and a lookup of the built-in redirects. Other pages are not affected, and no script is added to them.

= Are all 404s recorded? =

Only the 404s served by WordPress itself. Requests the web server answers without loading WordPress are not seen, such as missing images, CSS and JS files on nginx setups that serve static files directly. 404 pages served by a page cache are counted through a script, so only for visitors running JavaScript, which leaves out most bots.

= How much data is kept? =

By default at most 10,000 broken URLs (the least recently hit are purged first), 50 referers per URL, deleted after 90 days without hits, and 14 days of daily history. The limits are configurable in the Settings tab, and the purge runs hourly with WP-Cron: on a site without visits, or with WP-Cron disabled and no system cron, it is delayed.

= What do the built-in redirects match? =

One exact path each, compared case-insensitively and with or without a trailing slash; the query string of the request is passed on. Wildcards and regular expressions are not supported: use the Redirection plugin for them. A built-in redirect only applies when the URL returns a 404.

= Which targets are suggested? =

The 5,000 most recently modified published posts and pages and the 2,000 most used terms, to bound the time of a rebuild on large sites, plus the URLs of a sitemap when one is set in the settings (up to 50 files and 20,000 URLs). Suggestions need permalinks other than "Plain".

= Is my multilingual site supported? =

Polylang is supported. With other multilingual plugins, including WPML, recording and redirects work as on any site, and the language of the suggestions can be provided with the `lostlink_path_language` filter. Post type archives are only suggested in the current language.

= Does it work on multisite? =

Each site of a network has its own data and settings, but it is not tested on multisite, and uninstalling only removes the data of the current site.

== Screenshots ==

1. The dashboard: broken URLs ranked by priority, with their trend and referers.
2. Fixing a broken URL: the suggested targets, and the redirect form.
3. The built-in redirects.
4. The settings.

== Changelog ==

= 1.0.0 =
* First release.
