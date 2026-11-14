# featured-from-source: architecture

This document describes how the plugin is built and records every decision
taken while building it from [PRD.md](PRD.md), including the rulings on the
PRD's open questions (section 13).

## Modules

All classes live in `src/` under `IndianOpinion\FeaturedFromSource`. The main
file `featured-from-source.php` registers a small autoloader, loads
`src/functions.php`, and calls `Plugin::boot()`. No Composer at runtime.

| Module | Role |
|---|---|
| `Plugin` | Registers hooks: meta, cron schedule, save and meta triggers, the meta guard, REST, admin, WP-CLI, the importer handover action. |
| `Settings` | Defaults, validation (refuse, never clamp), the request identity string, the kill switch. |
| `Meta` | Registers post meta (`show_in_rest`, auth, sanitising), refuses over-long credits, reads `{url, credit, licence}` through `ffs_source`. |
| `Scheduler` | Eligibility, save and meta triggers, the queue event and its batches, the cron schedule, the overdue check. |
| `Queue` | One option, one entry per post, retry delays, the 500 entry bound. |
| `Lock` | Per-post lock by atomic `add_option`, 120 second expiry. |
| `Runner` | One run: the whole of PRD section 4 in order. Produces a `RunRecord`. |
| `RunContext`, `Judgement` | Mutable state of a run; the result of downloading one candidate. |
| `Http`, `HttpResult` | Every request: safe variant, hand-followed redirects with a check per hop, ports 80 and 443, the `ffs_http_args` filter with the safety re-applied after it. |
| `Backoff` | Per-host block after 429 or 503, from `Retry-After`. |
| `Robots`, `RobotsParser` | `robots.txt` fetch, 24 hour cache per origin, RFC 9309 matching. |
| `HostName`, `PublicSuffix`, `HostPolicy` | Host normalisation (IDN, ports, trailing dot), list matching, registrable domains, page and image host rules, the CDN map. |
| `Document` | Character set detection and conversion, tolerant DOM parse. |
| `CandidateFinder`, `FinderResult`, `Candidate` | Social tags, page logos, the article body region, per-tag URL choice. |
| `UrlRules` | Pure URL rules: bad URL, tracker names, furniture tokens, size hints. |
| `DefaultArt` | The bounded store of social images seen on many pages of one domain. |
| `ImageProbe`, `WebpHeader` | Type by magic bytes; dimensions from the header without decoding. |
| `Sideloader`, `CreditTemplate` | File name, alt text, caption, `media_handle_sideload()`, provenance, delete on failure. |
| `Status`, `Log` | Post status meta; debug lines, recent runs ring, daily counters. |
| `Backfill` | Finding, planning and queueing or running existing posts. |
| `Rest` | `POST /featured-from-source/v1/posts/<id>/run` and `GET .../status`. |
| `Admin\SettingsPage`, `Admin\MetaBox` | Settings screen, notices, recent runs, counters; the edit-screen box and its save. |
| `Cli\Command` | `wp featured-from-source ...`. |
| `Import\*` | `Item`, `ImporterAdapter`, the reference `CoreFeedAdapter` over `fetch_feed()`, and `Handover`. |

## Data flow of one run

```
run_post(id) / queue event / REST / WP-CLI
  -> Runner::run
     kill switch, post exists, rights acknowledged, opt-out, ffs_should_process
     lock (atomic add_option)
     current featured image: editor's -> editor_image_kept; plugin's -> has_image unless force
     Meta::source -> normalise -> no_source_url / no_credit
     source host is ours -> source_is_self
     HostPolicy::checkPage -> host_denied / host_not_allowed
     ffs_size_rules
     page: ffs_source_html, or (backoff, robots, Http::get with per-hop checks)
       status, content type (image/* -> one "direct" candidate), truncation
     Document::fromBytes -> DOM
     CandidateFinder::find (og, twitter, body; body tags pick their first URL that passes checkUrl)
     ffs_candidates
     DefaultArt::record (first og and first twitter of the page)
     for each candidate in order:
       checkUrl (bad_url, self, hosts, tracker, furniture, hints, logo, default art, backoff, robots, ffs_candidate_reject)
       download limit, 60 second hard stop
       judge: download to temp (byte cap), status, type and magic, dimensions,
              tracker pixel, bomb, aspect, minimum size, hash and reuse policy
       first pass wins -> Sideloader (or reuse) -> race and post_gone checks -> set_post_thumbnail
  -> finish: outcome (done, placeholder, none, skipped, retry), placeholder, queue, status, log, actions
  -> temp files removed, lock released (always, in finally)
```

## Decisions

### Rulings on the PRD's open questions

**D1. Public suffix list (open question 1).** Ruling: a short built-in
table of common multi-part suffixes, filterable; the full list is not bundled.
`PublicSuffix::BUILT_IN` holds 136 suffixes (United Kingdom, India,
Australia and New Zealand, South and East Asia, the Middle East, Africa, the
Americas, parts of Europe). `ffs_public_suffixes` extends or replaces it.
Reason: the full list is large and needs a refresh process; the plugin only
needs registrable domains for the image host rule and the default-art store,
where a missing suffix fails safe (two outlets on one suffix would look like
one site only if the operator allows that suffix's sites at all). IP
addresses and two-label hosts are returned unchanged.

**D2. Image dimensions (open question 2).** Ruling: a small WebP header
reader for VP8, VP8L and VP8X; AVIF through `getimagesize` on PHP 8.2 and
later, or Imagick when present, else AVIF candidates are skipped with a logged
reason. `WebpHeader` reads the first 30 bytes and is used for every WebP file
on every PHP version, so results do not depend on the PHP build. JPEG, PNG and
GIF use `wp_getimagesize()`. AVIF on PHP 8.1 uses `Imagick::pingImage()`
(header only); without Imagick the candidate is rejected with
`avif_unsupported` and a debug log line. AVIF is off by default.

**D3. Queue storage (open question 3).** Ruling: 500 entries in the option,
oldest dropped with a log line; filterable. The bound is the `ffs_queue_max`
filter (default 500). When an add would exceed it, the oldest entry (first in
queue order) is dropped and an `error_log` line is always written, debug or
not, because it is lost work. Reason: the queue option is read and rewritten
on every add and every run; at 500 entries it stays a few tens of KiB. A
custom table is not needed at this size and is not planned; a site that needs
thousands in flight should run backfill in slices with `--limit`.

**D4. `robots.txt` (open question 4).** Ruling: fetch once per host per
day, cached; honour disallow for the plugin's user agent; only the source page
and image URLs are ever fetched, no crawling. Details:
- Cache key is the origin (scheme, host, port); transient for 24 hours,
  whatever the result.
- The product token is the request identity string up to the first `/` or
  space, lowercased (`featured-from-source` by default). A group naming the
  token is used and replaces `*`, as RFC 9309 says; groups with the same name
  are merged. With no such group, `*` applies.
- Only `User-agent`, `Allow` and `Disallow` are honoured. `Crawl-delay`,
  `Sitemap`, `Host` and `Noindex` are ignored. `*` and `$` wildcards work;
  the longest pattern wins; on a tie, `Allow` wins; percent escapes compare
  case-insensitively. The file is read up to 500 KiB.
- 4xx means allow all. 5xx, 429 or a network error means disallow all for that
  origin until the cache expires, and the run ends `robots_unavailable`,
  which is retryable (PRD case 64). Too many redirects means allow all.
- When the host is in backoff, the robots state is not cached, so the backoff
  does not turn into a day of "disallow all".
- Image URLs are checked against their own origin. When HTML comes from
  `ffs_source_html`, the page itself is not fetched, so the page's
  `robots.txt` is not checked; images still are.

**D5. Admin calls (open question 5).** Ruling: REST with nonce and
capability checks; no admin-ajax. `POST /featured-from-source/v1/posts/<id>/run`
(`force`, `dry_run`) and `GET .../status`. The permission callback requires a
valid `wp_rest` nonce in `X-WP-Nonce` and `edit_post` on that post. The meta
box script uses `wp.apiFetch`, which sends the nonce, and works in both
editors because the box is a classic meta box, which the block editor shows.

### Other decisions

**D6. Reason codes beyond the PRD list.** The PRD lists reasons in section 4
and 4.12 and says they are public. Added, each for a case the PRD describes
without naming a code: `has_image` (a plugin image and no `force`, case 18),
`host_backoff` (case 66, retryable), `robots_unavailable` (case 9 5xx,
retryable per case 64), `network_error` (case 64), `unsafe_url` (private
address or port other than 80 and 443, case 23 and section 8),
`too_many_redirects` (case 22), `not_eligible` (`ffs_should_process`, wrong
type or status), `avif_unsupported` (D2), and `download_limit` (recorded on
candidates never fetched, case 52).

**D7. Aspect before minimum size.** The PRD gives 1200 by 152 as a
`bad_aspect` example, which is also below the minimum height. Shape is
checked first so that the example holds; both are real-file checks, so
nothing else changes.

**D8. Attempts.** Case 20 lists three delays (15 minutes, 2 hours, 12 hours)
and test 20 expects all three before the final attempt. So a post gets four
attempts: the first and three retries. The fourth retryable failure is final
and may set the placeholder.

**D9. Which final reasons set the placeholder.** Every final reason meaning
"no picture could be obtained" sets it when configured: `no_candidates`,
`no_usable_image`, page 4xx, `not_html`, host and robots refusals, exhausted
retries, `source_is_self`, `unsafe_url`, `too_many_redirects`,
`provenance_failed`. Never for `rights_not_acknowledged`, `disabled`,
`post_opted_out` (the editor asked for no image), `locked`,
`editor_image_kept`, `has_image`, `post_gone`, `not_eligible`. Missing source
URL or credit only with its own setting (case 2).

**D10. Image redirect hops.** Case 48 says each hop is checked "as in case
23". For images, intermediate hops must pass the safe-address check, the port
rule and the deny list; the image host rule (case 6) applies to the final
host. Applying the page allow list to an image CDN's intermediate hop would
refuse ordinary CDN redirects.

**D11. URL reuse lookup.** Case 54 compares "same source image URL without
query string". Attachments carry one extra meta key, `_ffs_image_key`, the MD5
of the bare URL, so the lookup is an exact meta match instead of a `LIKE`.

**D12. Default-art store.** Records the first `og` and the first `twitter`
candidate of each page before checks, so the third page that uses the same
image is the one rejected (test 45). Dry runs (including `inspect`) do not
record. Each image keeps `max(5, threshold)` page hashes so a threshold above
5 still works.

**D13. Caption template.** Literal text is escaped as well as values, so a
template cannot carry markup; `ffs_credit_html` is the place for custom HTML.
The template must contain `{credit}`, because the credit is never dropped
(case 8).

**D14. Refusing an over-long credit.** Meta sanitise callbacks cannot refuse,
so `add_post_metadata` and `update_post_metadata` are filtered: a credit over
200 characters (after sanitising) is not written. The REST schema also says
`maxLength: 200`, and the meta box refuses with a notice.

**D15. Status meta.** `_ffs_status` also carries `source_url` and up to 20
`tried` entries (bare URL, kind, reason), because the meta box shows the last
run's candidates. Locked, disabled and dry runs never write it.

**D16. Save budget.** A save queues only when the post has a source URL and a
credit, so a site where most posts have none pays one meta read and no option
write.

**D17. `inspect_url`.** Copies nothing, so it needs neither a credit nor the
rights acknowledgement; the kill switch, host policy and `robots.txt` still
apply. It is reachable from PHP and WP-CLI only, not REST.

**D18. Character sets.** `iso-8859-1`, `latin1` and `us-ascii` are read as
Windows-1252, as browsers do. After conversion every non-ASCII character is
written as a numeric entity before parsing, so a `<meta charset>` in the page
can never make libxml decode the text a second time.

**D19. Type agreement.** A response with a missing or non-image
`Content-Type` is `bad_type` even when the bytes are an image: case 50 says
both must agree.

**D20. Importer interface.** The plugin knows no importer. It offers three
handovers: meta at insert time, the `ffs_item_imported` action (post ID and
`{url, title, body, source_name}`), and the `ffs_source` filter. For code
that pulls items itself, `Import\ImporterAdapter` has one reference
implementation, `Import\CoreFeedAdapter` over `fetch_feed()`.
`Import\Handover::createDrafts()` creates drafts only, skipping URLs already
used as a source; an editor publishes.

**D21. Lock take-over.** An expired lock is deleted and added again; only one
of two racing processes wins the add, because the options table's unique key
makes `add_option` atomic.

**D22. `clear-locks`.** Clears every `ffs_lock_` row, not only queued posts.

**D23. HTTP.** Cookies are stripped, redirects are followed by hand
(`redirection` 0), and `reject_unsafe_urls`, method and stream target are
re-applied after `ffs_http_args`. Ports other than 80 and 443 end with
`unsafe_url`, also for redirect hops.

**D24. Header images.** Case 33 skips images inside `<header>`, including an
article's own header. This is kept as written; an operator who wants the
article header picture can add it through `ffs_candidates`.

**D25. Backfill.** `--limit` is the number of posts examined, newest first.
Backfill finds posts by the source URL meta key, so posts whose source comes
only from `ffs_source` are not found; queue those with `queue_post()`. While
the kill switch is on, backfill does nothing and exits 1. `--now` frees the
runtime object cache after each batch.

**D26. Time.** `Clock` wraps `time()` with a seam that only tests set. The
60 second hard stop is checked before each download.

**D27. Retry-After.** Seconds or an HTTP date; at least 1 second, at most 6
hours, 15 minutes when absent or unreadable.

## Testing

PHPUnit 10 runs against `tests/stubs/`, a small stand-in for the WordPress
functions the plugin calls, with an in-memory options table, posts, meta,
hooks and a fake HTTP transport that serves fixture pages and images from
`WP::$routes`. Images are header-only fixtures built in
`tests/Fixtures.php`. `tests/Behaviour/` has one test method per PRD case
(`testCase01` to `testCase77`), plus uninstall, settings validation, meta box
escaping, REST, WP-CLI and importer tests. `tests/Unit/` covers the robots
parser, WebP headers, registrable domains and URL resolution.

PHPStan runs at level 8 against the `php-stubs/wordpress-stubs` and
`php-stubs/wp-cli-stubs` packages. phpcs uses PSR-12 with a 180 character
line limit.
