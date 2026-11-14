# featured-from-source

A WordPress plugin that gives a post a featured image taken from the page the post is based on. It checks that the picture is a real editorial image of a usable size and shape, copies it into the media library with its credit and provenance, and never replaces an image an editor chose.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured result: on a production news site, most imported posts had no usable first image (a spacer, an icon, or a picture of the wrong size or shape), so a "first image" picker spent its one attempt on the wrong thing; this plugin keeps trying candidates in a fixed order until one passes every check.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Hooks](#hooks)
- [WP-CLI](#wp-cli)
- [Data and uninstall](#data-and-uninstall)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: PHP 8.1 or later (tested on 8.1, 8.2, 8.3), WordPress 6.4 or later, the PHP `dom` extension. `intl` (international host names), `mbstring` (pages in other character sets) and, on PHP 8.1 only, `imagick` (AVIF dimensions) are used when present.

### Without Composer

1. Download the release zip, or copy this folder to `wp-content/plugins/featured-from-source/`.
2. Activate "Featured from source" under Plugins.

Nothing else is needed at runtime: the plugin has its own autoloader.

### With Composer

```sh
composer require indianopinion/featured-from-source
```

The package type is `wordpress-plugin`, so a Composer setup with `composer/installers` puts it in your plugins folder.

### As a must-use plugin

Put the plugin folder at `wp-content/mu-plugins/featured-from-source/` and add a one-line loader, `wp-content/mu-plugins/featured-from-source.php`:

```php
<?php
require_once __DIR__ . '/featured-from-source/featured-from-source.php';
```

A copy is in [examples/mu-plugin-loader.php](examples/mu-plugin-loader.php). Must-use plugins never run activation hooks; the queue event is scheduled on the first `init` instead.

## Quick start

1. Go to **Settings > Featured from source**.
2. Tick **"I take responsibility for the licence of every image this plugin copies to this site"**. Until you do, every run ends with `rights_not_acknowledged`.
3. Add the hosts you have the right to copy from to the **Allow list**, one per line (`example.com` covers `www.example.com` and `img.example.com`; `=www.example.com` is that host only).
4. Optionally set a **Placeholder attachment ID** for posts where nothing usable is found.
5. Give each post a source URL and a credit line. Either type them in the "Featured image from source" box on the edit screen, or have your importer write them at insert time:

```php
wp_insert_post([
    'post_title'  => 'Harbour reopens after storm',
    'post_status' => 'draft',
    'meta_input'  => [
        '_ffs_source_url'    => 'https://news.example.com/2026/11/harbour-reopens',
        '_ffs_source_credit' => 'Example Daily',
    ],
]);
```

The post is queued, and the next queue run (every 5 minutes by default, through WP-Cron) picks its image. To try one post now:

```sh
wp featured-from-source run 123
wp featured-from-source inspect https://news.example.com/2026/11/harbour-reopens
```

More in [examples/](examples/): importer handover, drafts from a feed with core `fetch_feed()`, and filter recipes.

## Configuration

Everything is on the settings screen and stored in one option, `ffs_settings`. Invalid values are refused with a message; nothing is silently clamped.

| Setting | Default | Range |
|---|---|---|
| Enabled (kill switch) | on | on, off |
| Rights acknowledged | off (nothing runs) | on, off |
| Host mode | `allow_list` | `allow_list`, `deny_list` |
| Allow list | empty | host entries |
| Deny list (always wins) | empty | host entries |
| Image CDN map | empty | lines `cdn.example.net = example.com, example.org` |
| Allow images from other hosts | off | on, off |
| Respect `robots.txt` | on | on, off |
| Request identity string | `featured-from-source/0.1.0 (+<site URL>)` | 1 to 200 printable ASCII characters |
| Post types | `post` | public post types |
| Source URL meta key | `_ffs_source_url` | meta key |
| Credit meta key | `_ffs_source_credit` | meta key |
| Minimum width, height | 600, 315 | 100 to 4000 |
| Aspect window (width / height) | 1.2 to 2.1 | 0.25 to 8.0, lower below upper |
| Maximum file size | 15 MiB | 1 to 50 MiB |
| Maximum pixels | 50 megapixels, sides up to 12000 | 1 to 100 megapixels |
| Accepted types | JPEG, PNG, WebP | plus GIF, AVIF (when the image editor supports them) |
| Page read limit | 1024 KiB | 256 to 4096 KiB |
| Body tags examined | 12 | 1 to 50 |
| Downloads per run | 4 | 1 to 10 |
| Default-art threshold | 3 pages | 2 to 20 |
| Reuse policy | `reject` | `reject`, `share`, `copy` |
| Use placeholder | on | on, off |
| Placeholder attachment | none (0) | image attachment ID |
| Placeholder when source or credit missing | off | on, off |
| Credit template | `Image: {credit}` | text with `{credit}`, `{source_url}`, `{source_host}`, `{licence}` |
| Batch size | 10 posts | 1 to 100 |
| Queue interval | 5 minutes | 1 to 60 minutes |
| Debug logging | off | on, off |

The constant `FEATURED_FROM_SOURCE_DISABLED` set to `true` in `wp-config.php` switches everything off, as does the `ffs_enabled` filter returning `false`. Queue entries are kept, not run.

## How it works

1. **When.** Saving a post of an enabled type (draft, pending, scheduled or published) that has no featured image, or has the placeholder, and has a source URL and a credit, queues it. So does adding either meta key later. Nothing runs on save or on a front-end request. A single recurring WP-Cron event works through the queue in batches.
2. **Who decides.** An editor's own featured image is never replaced, not even with `--force`. A plugin image is replaced only by a manual run with `force`. The placeholder is replaced as soon as a real picture is found.
3. **Fetch.** The page is fetched with the WordPress safe HTTP request plus the plugin's own address check on every hop (no private, loopback, link-local, metadata or other reserved addresses, IPv4 or IPv6, with the connection pinned to the checked address), ports 80 and 443 only, at most three redirects, each hop checked against the host lists. Only the first 1 MiB is read. `robots.txt` is fetched once per host per day and honoured.
4. **Candidates, in order:** `og:image` tags, then `twitter:image`, then images in the article body. In the body it reads lazy-load attributes and `srcset` before `src`, skips header, nav, footer, aside, share, related, promo, ad, sponsor, comments and author blocks, and skips tags that declare a small size.
5. **Checks before download:** scheme, the site's own host, the host lists and the image host rule (same registrable domain, a mapped CDN, or other allowed hosts if you said so), spacer and tracker file names (whole name only: `phone-pixel-10.jpg` passes), logos, icons and avatars (whole path tokens only: `logistics-hub.jpg` passes), labelled size hints, the page's own logos, and the outlet's default card (the same social image on three or more pages).
6. **Checks after download:** HTTP 200, byte cap, the `Content-Type` and the file's magic bytes must agree (no SVG, ever), real width and height from the file header, tracker pixels, decompression bombs, aspect window, minimum size, and whether the same picture is already another published post's image.
7. **The first candidate that passes every check wins.** Order is the source page's own preference; the plugin does not download everything and pick the biggest.
8. **Sideload.** The file goes into the media library through `media_handle_sideload()`, attached to the post, with the post title as title, the source's alt text (or the post title) as alt text, and the rendered credit as the caption. Provenance is written to the attachment before it is set as featured image; if that fails, the copy is deleted.
9. **Fallback.** When nothing passes, the placeholder is set (if configured). Transient failures (network, 429, 5xx) retry after 15 minutes, 2 hours and 12 hours; the placeholder waits for the last attempt.

Every run ends with one reason code, shown in plain words in the edit-screen box, stored in `_ffs_status`, counted per day on the settings page and listed in the recent runs table. Reason codes are part of the public interface:

`done`, `rights_not_acknowledged`, `no_source_url`, `no_credit`, `source_is_self`, `host_not_allowed`, `host_denied`, `host_backoff`, `robots_disallowed`, `robots_unavailable`, `post_opted_out`, `post_gone`, `not_html`, `no_candidates`, `no_usable_image`, `editor_image_kept`, `has_image`, `disabled`, `locked`, `network_error`, `unsafe_url`, `too_many_redirects`, `sideload_failed`, `provenance_failed`, `time_budget`, `not_eligible`, `http_<code>`. Per candidate: `bad_url`, `self_image`, `image_host_not_allowed`, `host_denied`, `tracker_url`, `furniture_url`, `hint_too_small`, `logo`, `default_art`, `robots_disallowed`, `too_large_bytes`, `http_<code>`, `bad_type`, `avif_unsupported`, `tracker_pixel`, `too_large_pixels`, `unreadable`, `bad_aspect`, `too_small`, `reused`, `download_limit`, `time_budget`.

The design and every decision are in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). The behaviour is specified case by case in [docs/PRD.md](docs/PRD.md).

## Hooks

Filters:

| Filter | Arguments | Use |
|---|---|---|
| `ffs_enabled` | bool | Kill switch |
| `ffs_should_process` | bool, post ID | Skip posts by any rule |
| `ffs_source` | `{url, credit, licence}`, post ID | Supply source and credit |
| `ffs_source_html` | null, post ID, URL | Hand over HTML you already fetched |
| `ffs_http_args` | args, URL, purpose (`page`, `image`, `robots`) | Change request arguments (cannot disable the safe-address check) |
| `ffs_host_allowed` | bool, host, purpose, post ID | Final say on a host after the lists; cannot override the deny list |
| `ffs_candidates` | list, context | Add, remove, reorder candidates |
| `ffs_tracker_names` | list | Extend the spacer and tracker names |
| `ffs_furniture_tokens` | list | Extend the logo, icon and avatar tokens |
| `ffs_candidate_reject` | null, candidate, context | Reject with your own reason |
| `ffs_size_rules` | `{min_width, min_height, min_ratio, max_ratio}`, post ID | Per-post size and aspect |
| `ffs_placeholder_id` | ID, post ID | Per-post placeholder |
| `ffs_credit_html` | HTML, attachment ID, post ID | Change the caption |
| `ffs_attachment_data` | `{title, alt, caption}`, post ID, candidate | Change attachment fields before insert |
| `ffs_public_suffixes` | list | Extend the multi-label suffix table (`co.uk`, `co.in`, ...) |
| `ffs_queue_max` | int (500) | Queue size |

Actions: `ffs_run_started` (post ID), `ffs_image_set` (post ID, attachment ID, record), `ffs_placeholder_set` (post ID, attachment ID, record), `ffs_run_finished` (record), `ffs_candidate_rejected` (candidate, reason, context), and `ffs_item_imported` (post ID, `{url, title, body, source_name}`), which an importer fires to hand a post over.

PHP functions in the `IndianOpinion\FeaturedFromSource` namespace: `queue_post(int $post_id): bool`, `run_post(int $post_id, array $args = []): RunRecord` (`force`, `dry_run`), `inspect_url(string $url): RunRecord` (dry run, for administrators and WP-CLI), `status(int $post_id): array`.

### Importers

The plugin knows no particular importer. An importer produces items with a URL, a title, a body and a source name, and hands a post over in one of three ways: meta at insert time, the `ffs_item_imported` action, or the `ffs_source` filter. For code that pulls items itself there is an adapter interface, `Import\ImporterAdapter`, and one reference adapter over WordPress core's `fetch_feed()`, `Import\CoreFeedAdapter`. `Import\Handover::createDrafts()` turns an adapter's items into drafts, never published posts. See [examples/core-feed-drafts.php](examples/core-feed-drafts.php).

## WP-CLI

```
wp featured-from-source run <id>... [--force] [--dry-run] [--format=table|json]
wp featured-from-source backfill [--post_type=<t>] [--status=<s>] [--after=<date>] [--before=<date>] [--limit=<n>] [--now] [--dry-run]
wp featured-from-source inspect <url> [--format=table|json]
wp featured-from-source status <id> [--format=json]
wp featured-from-source queue list|clear-locks
wp featured-from-source hosts list|allow <host>|deny <host>|remove <host>
```

Exit code 0 on success or dry run, 1 when any run ends `none` or with an error reason, 2 on bad arguments. `backfill` queues by default; `--now` runs in the foreground in batches. `--limit` is the number of posts examined.

With `DISABLE_WP_CRON`, run cron from the system, for example `*/5 * * * * wp cron event run --due-now --path=/var/www/site`. The settings page warns when the queue event is overdue by more than three intervals.

## Data and uninstall

| What | Where |
|---|---|
| Settings | option `ffs_settings` |
| Queue | option `ffs_queue` (not autoloaded), at most 500 entries |
| Locks | options `ffs_lock_<post ID>`, 120 seconds |
| Host backoff | transients `ffs_backoff_<hash>`, up to 6 hours |
| `robots.txt` cache | transients `ffs_robots_<hash>`, 24 hours |
| Default-art counts | option `ffs_default_art` (not autoloaded), at most 5000 entries |
| Recent runs | option `ffs_recent_runs` (not autoloaded), last 200 |
| Daily counters | option `ffs_counters`, 14 days |
| Post meta | `_ffs_source_url`, `_ffs_source_credit`, `_ffs_source_licence`, `_ffs_opt_out`, `_ffs_status` |
| Attachment meta | `_ffs_image_url`, `_ffs_image_key`, `_ffs_page_url`, `_ffs_credit`, `_ffs_licence`, `_ffs_fetched_at`, `_ffs_sha256`, `_ffs_kind`, `_ffs_version` |

Uninstalling deletes the settings, queue, locks, transients, default-art store, recent runs, counters, the cron event, and the `_ffs_status` and `_ffs_opt_out` post meta. It **keeps** the source URL, credit and licence post meta (editors typed them) and every attachment with its provenance meta (the credit must stay with the copied image).

## Limitations

- It does not decide whether you may use a picture. It enforces your rules (source URL, credit, host lists) and records provenance. You are responsible for the licence.
- It does not judge whether a picture matches the story. That needs a person. The edit-screen box shows what was tried and why, and has a "Find image now" button.
- It stores files as received. Pictures may carry camera metadata, including location; strip it in your image pipeline if you need to.
- It never fetches search engines, image search, stock libraries or third-party APIs. The only requests are the source page, its `robots.txt`, and candidate image files.
- Video platform watch pages are never fetched on purpose: keep such hosts off your allow list (or on your deny list). For a video source, the editor supplies the picture.
- The registrable-domain table is short and built in (common suffixes such as `co.uk`, `co.in`, `com.au`). Add yours with `ffs_public_suffixes`.
- AVIF needs PHP 8.2, or `imagick` on PHP 8.1, to read dimensions; otherwise AVIF candidates are skipped with `avif_unsupported`.

## FAQ

**Nothing happens.** Check the settings screen notices: the licence box must be ticked, and in allow-list mode the allow list must not be empty. Then run `wp featured-from-source run <id>` and read the reason.

**Why did it skip the obvious picture?** Open the box on the edit screen, or run `wp featured-from-source inspect <url>`: every candidate is listed with its reason.

**Can it replace an image I chose?** No. Not on save, not in a queue run, not with `--force`.

**What about the placeholder?** It is tracked by ID and never counts as a found picture. A later run that finds a real one replaces it.

**Does it slow down my site?** No. There is no front-end code path. The caption is ordinary attachment data.

**Does it work with media offload plugins?** Yes: files go through the core sideload function like any upload.

**Multisite?** Yes, with per-site settings and queues.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
