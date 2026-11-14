=== Featured from source ===
Contributors: indianopinion
Tags: featured image, og:image, sideload, credit, provenance
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Gives a post a credited featured image from the page it is based on, with size, shape, host and provenance checks.

== Description ==

For a post that stores the URL of the page it is based on and a credit line, the plugin finds a featured image on that page, checks that it is a real editorial picture of a usable size and shape, copies it into the media library with the credit attached, and sets it as the featured image. When nothing usable is found it can set a placeholder instead.

Written from scratch; not derived from any third-party plugin.

* Candidates in a fixed order: `og:image`, `twitter:image`, then the article body.
* Rejects tracking pixels, spacers, logos, icons, avatars and an outlet's default card, before downloading where possible and again after, by measuring the real file.
* Minimum size and an aspect-ratio window on the real file.
* Copies only from hosts you allow, with a source URL and a credit, and records provenance on the attachment.
* Never replaces an image an editor chose.
* Runs in the background on WP-Cron, never on a front-end request, with a kill switch.
* Respects `robots.txt`.
* WP-CLI: `wp featured-from-source run|backfill|inspect|status|queue|hosts`.

The plugin does not decide whether you may use a picture. Nothing runs until you tick "I take responsibility for the licence of every image this plugin copies to this site" in the settings.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip.
2. Activate the plugin.
3. Go to Settings > Featured from source, tick the licence box, and add the hosts you may copy from to the allow list.
4. Give posts a source URL and a credit in the "Featured image from source" box, or have your importer write the `_ffs_source_url` and `_ffs_source_credit` meta.

As a must-use plugin: put the folder in `wp-content/mu-plugins/` and add a loader file that requires `featured-from-source/featured-from-source.php`.

== Frequently Asked Questions ==

= Nothing happens. =

Tick the licence box and fill in the allow list. Then run `wp featured-from-source run <post ID>` and read the reason.

= Will it replace a featured image I chose? =

No, never.

= What does uninstall remove? =

Settings, queue, locks, caches, counters, the cron event and the plugin's status meta. It keeps the source URL, credit and licence meta, and every copied image with its credit.

== Changelog ==

= 0.1.0 =
* First release.
