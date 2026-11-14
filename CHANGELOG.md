# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/). Reason codes, hooks and the PHP
functions are the public interface.

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Featured image from the post's source page, in a fixed order: `og:image`,
  `twitter:image`, then the article body (lazy-load attributes and `srcset`
  before `src`, `<picture>` sources, `<noscript>` fallbacks).
- Rights acknowledgement: nothing runs until the operator accepts
  responsibility for licences. Source URL and credit required per post.
- Host policy: allow-list or deny-list mode, deny list always wins, whole
  host or registrable domain matching, IDN hosts, image host rule with an
  image CDN map.
- `robots.txt` support with a 24 hour cache per origin.
- Checks before download: bad URLs, the site's own images, spacer and
  tracker names, logos and icons, labelled size hints, the page's own
  logos, an outlet's default card.
- Checks after download: byte cap, content type and magic bytes agree, real
  dimensions from the header (with a built-in WebP reader), tracker pixels,
  decompression bombs, aspect window, minimum size, reuse policy.
- Sideload through `media_handle_sideload()` with title, alt text, a credit
  caption from a template, and provenance meta; the copy is deleted if
  provenance cannot be written.
- Placeholder fallback, upgraded later when a real picture appears. An
  editor's own featured image is never replaced.
- Queue on one WP-Cron event with batches, per-post locks, retries at 15
  minutes, 2 hours and 12 hours, per-host backoff from `Retry-After`, and a
  kill switch (setting, constant, filter).
- Edit-screen box (both editors) with a "Find image now" button over REST.
- Settings screen with notices, recent runs and daily counters per reason.
- WP-CLI: `run`, `backfill`, `inspect`, `status`, `queue`, `hosts`.
- Importer handover: meta at insert, the `ffs_item_imported` action, the
  `ffs_source` filter, an adapter interface with a reference adapter over
  core `fetch_feed()` that creates drafts only.
- Uninstall that keeps editorial meta and every copied image with its credit.

[Unreleased]: https://github.com/IndianOpinion/featured-from-source/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/featured-from-source/releases/tag/v0.1.0
