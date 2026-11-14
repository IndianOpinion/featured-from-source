# featured-from-source: product requirements

Status: draft for build. Target release 0.1.0.

A WordPress plugin. For a post that stores the URL of the page it is based on
and a credit line, it finds a featured image on that page, checks that the
image is a real editorial picture of a usable size and shape, copies it into
the media library with the credit attached, and sets it as the featured image.
When nothing usable is found it can set a placeholder instead.

Written from scratch; not derived from any third-party plugin.

## 1. Problem

Sites that write about other pages (news digests, link blogs, curated
round-ups, imported drafts) need a picture for every post. Doing it by hand
is slow. Doing it with a naive "take the first image" rule goes wrong in
predictable ways:

- The first `<img>` on a page is often a 1 by 1 tracking pixel, a lazy-load
  spacer, a share button, a site logo or a UI icon.
- Lazy-loading pages keep a spacer in `src` and the real picture in a data
  attribute or `srcset`, so a plain `src` reader picks the spacer.
- The page's social image is sometimes itself a spacer, a site-wide default
  card, a logo slate, or a library picture from a different story.
- A picture can be the right file and the wrong shape: a very wide, thin banner
  (for example 1200 by 152) passes a "wide enough" test and looks broken in
  every card.
- Copying a picture without recording where it came from and who made it
  leaves the site unable to answer a rights query.

Most imported posts had no usable image: the first `<img>` was a spacer or a
UI icon, or the picture was too small or the wrong shape. A "first image"
picker without the checks below regularly spends its single attempt on a
spacer or an icon.

## 2. Goals and non-goals

Goals:

1. Pick one featured image per post from the post's source page, in a fixed,
   documented order: `og:image`, then `twitter:image`, then the first usable
   image in the article body.
2. Reject tracking pixels, spacers, logos, icons, avatars, sprites and
   site-wide default art before downloading where possible, and again after
   downloading by measuring the real file.
3. Enforce a minimum size and an aspect-ratio window on the real file.
4. Sideload only with a stored source URL and credit, only from hosts the
   operator allows, and record provenance on the attachment.
5. Show the credit wherever the theme prints the featured image caption.
6. Fall back to a configured placeholder, and upgrade the placeholder to a
   real picture on a later run if one becomes available.
7. Never replace a featured image an editor chose.
8. Run in the background, never on a front-end request, with a kill switch.

Non-goals:

- Deciding whether the operator has the right to use a picture. The plugin
  enforces the operator's own rules (source URL, credit, host lists); the
  operator takes responsibility for the licence. See 4.1.
- Generating, cropping, upscaling or editing images. The file is stored as
  downloaded. WordPress makes its usual intermediate sizes.
- Fetching from search engines, image search, stock libraries or any
  third-party API. The only network reads are the source page, its
  `robots.txt`, and the candidate image files.
- Video pages. A video platform watch page is never fetched or parsed; the
  default deny list contains no such host by name, so the operator must keep
  such hosts off the allow list (see 4.1 and 14).
- Rewriting post content or in-body images. The plugin reads the source page
  only; it never changes `post_content`.
- Integration code for any particular importer. Importers hand over the
  source URL and credit through post meta or the `ffs_source` filter.
- Judging whether a picture matches the story's subject. That needs a model
  or a human. The plugin gives the editor a one-click "find again" and a
  clear reason code instead.

## 3. Users and setting

- Installed by a site operator on a self-hosted WordPress 6.4 or later site,
  as a normal plugin or a must-use plugin (a one-line loader stub, documented
  in the README).
- Used by editors, who see the result in a meta box on the post edit screen
  and can enter or correct the source URL and credit there.
- Fed by importers or scripts that create posts and write the source URL and
  credit into post meta at insert time.
- Runs inside WP-Cron (or a real cron calling `wp cron event run`), and on
  demand from WP-CLI and the meta box button.

## 4. Behaviour

Terms:

- Source URL: the URL of the page the post is based on, read per 4.2.
- Credit: a short plain-text line naming the image owner or the publication,
  for example "Example Daily". Read per 4.2.
- Page host: the host of the final source page URL after redirects.
- Image host: the host of a candidate image URL after redirects.
- Registrable domain: the host reduced to its registered name using the
  public suffix rules (`news.example.co.uk` gives `example.co.uk`).
- Candidate: one image URL found on the page, with its kind (`og`,
  `twitter`, `body`, `direct`), its document position, and any size hints.
- Plugin image: an attachment this plugin sideloaded (it carries the
  provenance meta in section 6).
- Placeholder: the attachment ID set in settings as the fallback.
- Run: one attempt to pick an image for one post.

Cases are numbered once across the whole section. Section 12 has one test per
case with the same number.

### 4.1 Rights and consent

1. The plugin does nothing until the operator ticks the settings checkbox
   "I take responsibility for the licence of every image this plugin copies
   to this site". Until then every run ends with reason
   `rights_not_acknowledged`, and an admin notice on the plugin's own
   settings screen (not site-wide) says so. The timestamp and user ID of the
   acknowledgement are stored in settings.
2. A run sideloads only when the post has both a valid source URL (absolute
   `http` or `https`, at most 2048 characters) and a non-empty credit after
   trimming and sanitising. Missing source URL ends with
   `no_source_url`; missing credit ends with `no_credit`. Neither case
   fetches anything. Neither case sets the placeholder unless the setting
   "placeholder when source or credit is missing" is on (default off),
   because a missing credit is an editorial gap the editor should fix.
3. Host policy mode, one of:
   - `allow_list` (default): the page host must match the allow list.
   - `deny_list`: any page host is allowed unless it matches the deny list.
   With `allow_list` and an empty allow list, every run ends with
   `host_not_allowed` and the settings screen says the list is empty.
4. The deny list always wins. A page host or an image host that matches the
   deny list ends the run (page) or rejects the candidate (image), in either
   mode.
5. Host list entries match on a whole host or a whole registrable domain.
   An entry `example.com` matches `example.com` and `www.example.com` and
   `img.example.com`, never `badexample.com` or `example.com.evil.net`. An
   entry starting with `=` (`=www.example.com`) matches that exact host only.
   Entries are lowercased, IDN hosts are compared in their ASCII (punycode)
   form, a trailing dot is removed, and ports are ignored.
6. Image host rule. A candidate image host must be one of: the page host's
   registrable domain; a host the operator maps as a CDN of that domain in
   the "image CDN map" setting (one line per CDN: `cdn.example.net =
   example.com, example.org`); or, when the setting "allow images from other
   hosts" is on (default off), any host that is on the allow list (in
   `allow_list` mode) or not on the deny list (in `deny_list` mode). A
   candidate that fails is rejected with `image_host_not_allowed`. Reason:
   outlets ship library pictures from unrelated stories and third-party
   embeds, and the operator's licence covers the source, not every host the
   page touches.
7. Per-post override. A post meta flag "do not fetch an image for this post"
   (set from the meta box) ends every run with `post_opted_out`.
8. The credit is attached to the attachment (section 6) and shown as the
   attachment caption using the credit template (case 72). The credit is
   never dropped, shortened below its stored form, or replaced by the
   plugin, and it stays on the attachment if the plugin is uninstalled.
9. `robots.txt`. When "respect robots.txt" is on (default on), the plugin
   fetches the page host's `/robots.txt` once per host per 24 hours, and
   skips a source page whose path is disallowed for the plugin's request
   identity token or for `*`, ending with `robots_disallowed`. Image URLs
   are checked the same way against their own host. Longest match wins
   between `Allow` and `Disallow`; `*` and `$` wildcards are supported. A
   `robots.txt` that returns 4xx means "allow all". A 5xx or network error
   means "disallow all" for that host until the next check (fail closed:
   the operator asked for robots to be respected). The cached result is
   kept for 24 hours either way.

### 4.2 Reading the source URL and credit

10. The source URL is read from the post meta key set in settings (default
    `_ffs_source_url`). The credit is read from the meta key set in settings
    (default `_ffs_source_credit`). An optional licence note is read from
    `_ffs_source_licence` and copied to the attachment if present.
11. The `ffs_source` filter receives the array `{url, credit, licence}` and
    the post ID, and may supply or change any of the three. This is how an
    importer that stores its own meta hands it over without the plugin
    knowing the importer.
12. The source URL is normalised before use: trimmed, entities decoded,
    fragment removed, scheme and host lowercased. A source URL whose host is
    the site's own host ends with `source_is_self`. Reason: a wrong fallback
    (the post's own permalink or GUID) would credit the picture to our own
    site and fetch our own page.
13. The meta box shows the source URL and credit as editable fields. Both
    are registered post meta with `show_in_rest` true, an auth callback that
    requires `edit_post` on that post, and sanitising callbacks
    (`esc_url_raw` for the URL, plain text for the credit, at most 200
    characters, no HTML).

### 4.3 When a run happens

14. Save trigger. When a post of an enabled post type (default `post`) is
    saved with status `draft`, `pending`, `future` or `publish`, has no
    featured image (or has the placeholder), and is not opted out, the plugin
    queues a run. It never runs inline on save. Autosaves, revisions,
    `auto-draft`, `trash` and `inherit` are ignored.
15. Queueing is idempotent: one pending queue entry per post. Saving the
    same post ten times while queued leaves one entry. The queue is a single
    recurring WP-Cron event (`ffs_process_queue`, every 5 minutes by
    default) that processes up to the batch size (default 10 posts) in queue
    order, then stops. There is no event per post.
16. Insert trigger. A post inserted programmatically with the source URL and
    credit meta present at insert time is queued the same way. Meta added
    after insert queues the post when `added_post_meta` or
    `updated_post_meta` fires for either configured key.
17. Manual trigger. The meta box button "Find image now" (needs `edit_post`
    and a nonce) runs immediately for that post over AJAX or REST and shows
    the result. WP-CLI `run` does the same (5.6).
18. A post that already has a featured image that is not the placeholder is
    never queued and never changed by an automatic run. It is changed only by
    a manual run with `force`, and only when the current featured image is a
    plugin image or the placeholder. An editor's own choice is never
    replaced, even with `force`; the run ends with `editor_image_kept`.
19. Per-post lock. A run takes a lock for the post (an atomic option add with
    a 120-second expiry). A second run for the same post while the lock is
    held ends with `locked` and does nothing. An expired lock is taken over.
20. Retry. A run that ends with a retryable reason (4.9) is requeued with
    backoff: 15 minutes, 2 hours, 12 hours. After the third failed attempt
    the post is marked final with the last reason and, if configured, gets
    the placeholder. Non-retryable reasons are final at once.
21. Kill switch. Setting "enabled" off, the constant
    `FEATURED_FROM_SOURCE_DISABLED` defined true, or the `ffs_enabled`
    filter returning false stops all automatic and manual runs. Queue
    entries are kept, not run, and not dropped. Every attempted run ends
    with `disabled`.

### 4.4 Fetching the source page

22. The page is fetched with the WordPress HTTP API using the safe request
    variant (which refuses private, loopback and link-local addresses),
    method GET, timeout 10 seconds, at most 3 redirects, the request identity
    string from settings (default `featured-from-source/<version>
    (+<site URL>)`), and `Accept: text/html,application/xhtml+xml`.
23. Each redirect hop is checked again: scheme must be `http` or `https`,
    the host must pass the safe-address check and the host policy (4.1). A
    redirect to a denied or disallowed host ends with `host_not_allowed`.
    The final URL after redirects is the page URL used for relative URL
    resolution and for the image host rule.
24. Only the first 1 MiB of the body is read (setting, 256 KiB to 4 MiB).
    Social image tags live in `<head>`, so a truncated page is still parsed;
    the body-image step uses whatever body arrived. Truncation is noted in
    the run record, not treated as an error.
25. A response with `Content-Type` `image/*` means the source URL is itself
    an image. It becomes the only candidate, kind `direct`, and goes straight
    to the download checks (4.7).
26. Any other non-HTML content type ends with `not_html`. A missing content
    type is treated as HTML if the body starts (after whitespace and a BOM)
    with `<`.
27. Character set: taken from the header, else the `<meta charset>` or
    `http-equiv` tag, else UTF-8. Bytes are converted to UTF-8 before
    parsing; invalid sequences are substituted, not fatal.
28. An importer that already holds the page HTML can pass it through the
    `ffs_source_html` filter (arguments: `null`, post ID, source URL). A
    non-null string is used instead of fetching, with the source URL as the
    page URL. The host policy and `robots.txt` rules still apply to images.

### 4.5 Finding candidates

29. The HTML is parsed with a tolerant DOM parser (PHP `DOMDocument` with
    internal error collection, no network access, no external entities). The
    parse never fails the run: a document that yields no DOM yields no
    candidates and ends with `no_candidates`.
30. Base URL: the first `<base href>` if it is absolute `http(s)`, else the
    final page URL. Every candidate URL is resolved against it, including
    protocol-relative (`//cdn.example.com/a.jpg`) and root-relative forms.
    Entities are decoded and surrounding whitespace removed.
31. Order of candidates, each group in document order:
    1. `og:image`, `og:image:url`, `og:image:secure_url` (meta `property`
       or `name`, case-insensitive). Repeated `og:image` tags each give a
       candidate. `og:image:width`, `og:image:height`, `og:image:alt` and
       `og:image:type` that follow an `og:image` tag (before the next one)
       attach to it as hints.
    2. `twitter:image` and `twitter:image:src` (meta `name` or
       `property`), with `twitter:image:alt` as the alt hint.
    3. Body images (case 33).
    A URL already in the list is not added again; the first kind wins.
32. The `ffs_candidates` filter receives the ordered list and the context
    (post ID, page URL, the DOM) and may add, remove or reorder. This is
    where an operator can add, for example, structured-data images.
33. Body region. The article body is the first match of: `<article>`; an
    element with `itemprop="articleBody"`; `<main>`; an element with
    `role="main"`; else `<body>`. Inside it, `<img>` elements that sit
    inside `<header>`, `<nav>`, `<footer>`, `<aside>`, `<form>`, `<button>`,
    `<a>` whose only child is the image and whose link leaves the page host
    (a sponsored tile), or an element whose class or id contains a whole
    word `share`, `social`, `related`, `promo`, `advert`, `ad`, `sponsor`,
    `comments` or `author` are skipped.
34. Per `<img>` tag, the best URL is chosen from, in order: the lazy-load
    attributes `data-src`, `data-lazy-src`, `data-original`,
    `data-hi-res-src`, `data-full-src`, `data-image`; the widest entry of
    `data-srcset` then `srcset` (a `2x`-style density descriptor ranks
    above any `w` width, since it means "the large one"); then `src`. The
    first of those that passes the URL checks (4.6) is the tag's URL. A
    `<picture>` element contributes its `<source srcset>` entries the same
    way, before its own `<img>`. A `<noscript>` image right after a
    lazy-load tag is read too.
35. A tag whose own `width` or `height` attribute is below the minimum
    width or height (4.8) is skipped as a whole, whatever its attributes
    hold. Reason: share buttons and badges declare small sizes and carry no
    size hint in their URL.
36. A tag where every attribute holds a spacer (spacer in both `src` and the
    lazy attribute) has no usable image. It is skipped, and the next tag is
    tried. It is never "the first image".
37. At most 12 body tags are examined (setting). "First body image" means
    the first tag whose URL passes the URL checks; later tags are kept as
    further candidates, in order, up to the download limit (case 52).

### 4.6 URL checks (before download)

Each failing candidate is recorded with its reason and the next one is tried.

38. Scheme. Only `http` and `https`. `data:`, `blob:`, `javascript:`,
    `file:` and empty values are rejected (`bad_url`). URLs over 2048
    characters are rejected (`bad_url`).
39. Spacers and trackers by file name. The file name without its extension,
    lowercased, is matched as a WHOLE name against a list: `spacer`,
    `blank`, `pixel`, `placeholder`, `transparent`, `empty`, `dummy`,
    `noimage`, `no-image`, `no_image`, `lazy`, `lazyload`, `loading`,
    `grey`, `gray`, `dot`, `px`, `clear`, `1x1`, `nxn` forms up to two
    digits each (`1x1`, `2x2`, `10x10`), each optionally followed by a
    separator and up to four digits (`spacer-2`, `pixel_1`) or preceded by
    `nxn` (`1x1_spacer`). A name that only contains one of these words
    (`phone-pixel-10`, `blank-verse-poet`) is NOT rejected. Reason
    `tracker_url`. The list is extendable with `ffs_tracker_names`.
40. Site furniture by path. A path ending in `.svg` or `.ico` is rejected.
    A path with a whole segment or token (split on `/`, `_`, `-`, `.`)
    equal to `logo`, `logos`, `icon`, `icons`, `favicon`, `avatar`,
    `avatars`, `sprite`, `sprites`, `emoji`, `badge`, `button`, `btn`,
    `arrow`, `caret`, `lightbox`, `watermark`, `gravatar` or `banner-ad` is
    rejected (`furniture_url`). Tokens are whole: `logistics` or
    `iconic-bridge` pass. Extendable with `ffs_furniture_tokens`.
41. Self and page references. A candidate whose URL, without query string
    and fragment, equals the source page URL, or whose host is the site's
    own host, is rejected (`self_image`). Reason: some pages point their
    social image at the page itself or at a mirror of our own copy.
42. Explicit size hints in the URL. Only labelled forms count, so a numeric
    id is never read as a size: query `size=WxH` or `size=W:H`; query `w`,
    `width`, `h`, `height` with a positive integer value; path tokens
    `width-N`, `height-N`, `square_N`; a path token `WxH` of 2 to 4 digits
    each. A hint below the minimum width or height rejects the candidate
    (`hint_too_small`). Hints above the minimum prove nothing and are not
    trusted; the real file decides.
43. `og:image:width` and `og:image:height` hints (case 31) below the
    minimum reject the candidate the same way (`hint_too_small`). Missing
    or non-numeric hints are ignored.
44. Page logo. The plugin collects the page's own logo URLs: `<link
    rel="icon">`, `<link rel="apple-touch-icon">`, `<link rel="mask-icon">`,
    any `logo` property in JSON-LD on the page (a JSON-LD block that fails
    to decode is ignored), `og:logo`, and `<img>` inside `<header>` or with
    `logo` as a whole word in its class, id or alt. A candidate equal to any
    of these (compared without query string and fragment) is rejected
    (`logo`).
45. Site default art. For each page registrable domain, the plugin keeps a
    count of distinct source pages on which each candidate URL (without
    query string) was the winning or first `og`/`twitter` candidate. When
    the same image URL has been the social image of 3 or more distinct
    source pages from that domain (setting), it is the outlet's default
    card and is rejected (`default_art`). The store is bounded (section 6).
    Reason: outlets use one branded slab for every story without a picture.
46. Host rule (case 6) and deny list (case 4) are applied to the image host
    here. Failing ends the candidate with `image_host_not_allowed` or
    `host_denied`.
47. `ffs_candidate_reject` filter: receives `null`, the candidate and the
    context; returning a non-empty string rejects the candidate with that
    string as the reason.

### 4.7 Download checks

48. The candidate is downloaded to a temporary file with the safe HTTP
    request variant, timeout 15 seconds, at most 3 redirects (each hop
    checked as in case 23, with the image host rule applied to the final
    host), streamed to disk, and stopped once it passes the maximum file
    size (default 15 MiB; setting). Over the limit: `too_large_bytes`,
    the temporary file is deleted.
49. HTTP status other than 200: `http_<code>` for that candidate. 429 and
    503 also set a host backoff (case 66).
50. Type. The response `Content-Type` and the file's magic bytes must both
    say JPEG, PNG or WebP (defaults), or GIF or AVIF if enabled in settings
    and supported by the server's image editor. A mismatch (an HTML error
    page served as `.jpg`, an SVG with an image extension) is rejected
    (`bad_type`). SVG is never accepted.
51. Real size. The pixel width and height are read from the file (without
    decoding the whole image). An image of 10 by 10 pixels or less in both
    axes is a tracker (`tracker_pixel`). An image over the maximum pixel
    count (default 50 megapixels) or with either side over 12000 pixels is
    rejected (`too_large_pixels`), to avoid decompression bombs. Unreadable
    dimensions: `unreadable`.
52. At most 4 candidates are downloaded per run (setting, 1 to 10). After
    that the run ends with `no_usable_image` and the reasons of all tried
    candidates are recorded. Candidates rejected by URL checks do not count
    toward this limit.
53. Content hash. The SHA-256 of the file is computed. If an existing plugin
    image already has that hash: when it belongs to this post, it is reused
    (no new attachment); when it belongs to another published post, the
    reuse policy applies (case 54).
54. Reuse policy, setting, one of `reject` (default), `share`, `copy`.
    `reject` treats a picture already used as another post's featured
    image (same hash, or same source image URL without query string) as
    library or default art and rejects it (`reused`). `share` sets the
    existing attachment as this post's featured image. `copy` sideloads a
    new attachment anyway. The first post to use a picture keeps it; only
    later posts are affected.

### 4.8 Size and aspect

55. Minimum size, settings: width 600 and height 315 by default (both
    required). Failing: `too_small`. Settings accept 100 to 4000.
56. Aspect window, settings: width divided by height between 1.2 and 2.1 by
    default. Outside it: `bad_aspect`. A 1200 by 152 banner (ratio 7.9)
    fails; a 1080 by 1350 portrait (0.8) fails at defaults; a 1200 by 675
    picture (1.78) passes. Both bounds are settings (0.25 to 8.0, lower
    below upper).
57. The checks use the real file only. URL and tag hints can reject early
    (4.6) but never accept.
58. The `ffs_size_rules` filter receives `{min_width, min_height,
    min_ratio, max_ratio}` and the post ID, so a post type or category can
    use other rules.
59. The first candidate, in candidate order, that passes every check wins.
    The plugin does not download all candidates and pick the biggest; order
    is the editorial preference the source page itself expressed.

### 4.9 Outcomes and fallback

60. Success. The winner is sideloaded (4.10), set as featured image, and the
    post status meta is `done` with the candidate kind and URL.
61. No usable image. When every candidate failed, or there were none, the
    run ends with `no_candidates` or `no_usable_image`. If "use placeholder"
    is on (default on) and a placeholder attachment is configured and
    exists, the placeholder is set as featured image and the status is
    `placeholder`. Otherwise no featured image is set and the status is
    `none`.
62. The `ffs_placeholder_id` filter receives the configured ID and the post
    ID and may return another attachment ID (for example per category) or 0
    for none. A returned ID that is not an image attachment is ignored and
    logged.
63. Upgrade. A post whose featured image is the placeholder is still
    eligible (case 14). A later run that finds a real picture replaces the
    placeholder. The placeholder is never treated as a real picture by any
    check, even though it passes the size test.
64. Retryable reasons: network error or timeout fetching the page, HTTP
    429 or 5xx on the page, `robots.txt` 5xx, `sideload_failed`, and
    `time_budget` when no candidate was judged. `locked` is not retried,
    because the lock holder will finish the run. On a retryable end, the placeholder is not set
    until the last attempt (case 20), so a short outage does not leave
    posts on the placeholder.
65. Final reasons (no retry): `rights_not_acknowledged`, `no_source_url`,
    `no_credit`, `source_is_self`, `host_not_allowed`, `host_denied`,
    `robots_disallowed`, `post_opted_out`, HTTP 401, 403, 404, 410 and other
    4xx on the page, `not_html`, `no_candidates`, `no_usable_image`,
    `editor_image_kept`, `disabled`.
66. Host backoff. A 429 or 503 from a host, on the page or an image, blocks
    that host for the `Retry-After` value (seconds or HTTP date), capped at
    6 hours, or 15 minutes when absent. Queued posts for a blocked host are
    skipped in the batch (not counted, not marked) and keep their place.
67. Race with the editor. Right before setting the featured image the
    plugin reads the post's featured image again. If an editor set one
    during the run (and it is not the placeholder), the plugin does not
    overwrite it; the new attachment stays in the library attached to the
    post, and the status is `editor_image_kept`.
68. A post that was deleted or trashed during the run ends with
    `post_gone`; any temporary file is deleted; any attachment already
    created is left attached to the trashed post (WordPress removes it with
    the post).

### 4.10 Sideloading and credit

69. The file is added to the media library with `media_handle_sideload`
    (so offload and media plugins see a normal upload), attached to the
    post (`post_parent`).
70. File name: the source file's base name, sanitised, if it has at least
    one run of three or more letters; else the post slug. The extension
    always matches the real type found in case 50, never the URL.
71. Attachment title: the post title. Alt text: the candidate's own alt
    hint (`og:image:alt`, `twitter:image:alt`, or the `<img alt>`) if
    non-empty and not equal to the page's site name; else the post title.
    The alt text is plain text, at most 250 characters.
72. Caption: the credit template rendered with escaped values. Default
    `Image: {credit}`. Placeholders `{credit}`, `{source_url}`,
    `{source_host}`, `{licence}`; unknown placeholders are left as typed.
    `{credit}` is linked to the source page URL with `rel="nofollow
    noopener"`. The caption is stored as `post_excerpt` of the attachment,
    which is what themes print as the featured image caption. The
    `ffs_credit_html` filter may change the rendered caption.
73. Provenance meta on the attachment (section 6) is written before the
    attachment is set as featured image. If writing the provenance fails,
    the attachment is deleted and the run ends with `provenance_failed`:
    a plugin image without its credit must not exist.
74. The temporary file is always removed, on success and on every failure
    path.
75. The `ffs_image_set` action fires after success with the post ID,
    attachment ID and the run record. `ffs_placeholder_set` fires after the
    placeholder is set. `ffs_run_finished` fires after every run with the
    record.

### 4.11 Backfill

76. WP-CLI `backfill` (5.6) queues or runs existing posts that have a
    source URL and credit and no featured image (or the placeholder), with
    filters for post type, status, date range and a limit. `--dry-run`
    lists what would be queued with the reason a post would be skipped.
77. Backfill respects the host backoff, the lock, the kill switch and the
    batch size. It never touches posts with an editor's featured image.

### 4.12 Failure modes summary

| Failure | Behaviour | Why |
|---|---|---|
| Network error, timeout, 5xx, 429 on the page | Retry with backoff, placeholder only after the last attempt | Transient; do not leave posts on the placeholder |
| 4xx on the page, not HTML, no candidates | Final, placeholder if configured | Retrying will not help |
| Candidate download fails or is rejected | Next candidate | One bad image must not end the run |
| `robots.txt` unreachable | Disallow that host for the cache period (fail closed) | The operator asked for robots to be respected |
| DOM parse problems | Parse what is there; no candidates means `no_candidates` | Real pages are malformed |
| Sideload fails (disk, permissions, image editor) | `sideload_failed`, retried with the normal backoff | Usually an environment fault to fix |
| Provenance write fails | Delete the attachment, `provenance_failed` | Never keep an uncredited copy (fail closed) |
| Editor sets an image mid-run | Keep the editor's image | Editors win |
| Plugin disabled mid-queue | Queue kept, nothing runs | Kill switch must be instant and lossless |

## 5. Public interface

### 5.1 Plugin

- Slug and text domain `featured-from-source`. Main file
  `featured-from-source.php`. Namespace
  `IndianOpinion\FeaturedFromSource`. Hook and option prefix `ffs_`.
- Must-use loader: a one-file stub that `require`s the main file, documented
  in the README.

### 5.2 PHP API (namespace functions)

- `queue_post(int $post_id): bool` adds the post to the queue; false if it
  is not eligible (reason available from `status()`).
- `run_post(int $post_id, array $args = []): RunRecord` runs now. `$args`:
  `force` (bool, case 18), `dry_run` (bool: fetch and check, download to a
  temporary file, report, sideload nothing, change nothing).
- `inspect_url(string $url, array $args = []): RunRecord` runs the whole
  pipeline for a URL without a post, dry run only; `credit` is not needed.
  Host policy and `robots.txt` still apply. For administrators and WP-CLI.
- `status(int $post_id): array` returns the post status meta (section 6).

### 5.3 Data shapes

- Candidate: `{url, kind (og|twitter|body|direct), position, alt,
  hint_width, hint_height, hint_type}`.
- RunRecord (immutable value object with `toArray()`): `{post_id,
  source_url, page_url, started_at, finished_at, outcome
  (done|placeholder|none|skipped|retry), reason, attempt, attachment_id,
  winner (Candidate or null), tried: [{candidate, reason, width, height,
  bytes}], truncated (bool), dry_run (bool)}`.
- Reason codes: the strings listed in sections 4 and 4.12. They are part of
  the public interface and change only with a major version.

### 5.4 Filters

| Filter | Arguments | Use |
|---|---|---|
| `ffs_enabled` | bool | Kill switch |
| `ffs_should_process` | bool, post ID | Skip posts by any rule |
| `ffs_source` | `{url, credit, licence}`, post ID | Supply source and credit |
| `ffs_source_html` | null, post ID, URL | Hand over already fetched HTML |
| `ffs_http_args` | args, URL, purpose (`page`, `image`, `robots`) | Change HTTP args (never disables the safe-address check) |
| `ffs_host_allowed` | bool, host, purpose, post ID | Final say on a host after the lists; cannot override the deny list |
| `ffs_candidates` | list, context | Add, remove, reorder |
| `ffs_tracker_names` | list | Extend case 39 |
| `ffs_furniture_tokens` | list | Extend case 40 |
| `ffs_candidate_reject` | null, candidate, context | Custom rejection |
| `ffs_size_rules` | rules, post ID | Per-post size and aspect |
| `ffs_placeholder_id` | ID, post ID | Per-post placeholder |
| `ffs_credit_html` | HTML, attachment ID, post ID | Change the caption |
| `ffs_attachment_data` | array, post ID, candidate | Change title, alt, caption before insert |
| `ffs_public_suffixes` | list | Extend the multi-label suffix list |

### 5.5 Actions

`ffs_run_started` (post ID), `ffs_image_set` (post ID, attachment ID,
record), `ffs_placeholder_set` (post ID, attachment ID, record),
`ffs_run_finished` (record), `ffs_candidate_rejected` (candidate, reason,
context).

### 5.6 WP-CLI

Command `wp featured-from-source`:

- `run <id>... [--force] [--dry-run] [--format=table|json]`
- `backfill [--post_type=<t>] [--status=<s>] [--after=<date>]
  [--before=<date>] [--limit=<n>] [--now] [--dry-run]` (queues by default,
  `--now` runs in the foreground in batches)
- `inspect <url> [--format=table|json]`
- `status <id> [--format=json]`
- `queue list|clear-locks`
- `hosts list|allow <host>|deny <host>|remove <host>`

Exit code 0 on success or dry run, 1 when any run ends `none` or with an
error reason, 2 on bad arguments.

### 5.7 Admin

- Settings page under Settings, capability `manage_options`, nonce on save,
  every field validated (section 7).
- Meta box on enabled post types: source URL, credit, licence note, opt-out
  checkbox, current status and reason in plain words, the last run's tried
  candidates with reasons, and the "Find image now" button. Works in the
  block editor and the classic editor.
- A "recent runs" table on the settings page (last 200 runs).

## 6. Data

| What | Where | Retention |
|---|---|---|
| Settings | one option `ffs_settings` (autoloaded, small) | Until uninstall |
| Queue | one option `ffs_queue` (not autoloaded): post ID, due time, attempt | Entry removed when final |
| Locks | options `ffs_lock_<post ID>` with expiry | 120 s |
| Host backoff | transients `ffs_backoff_<hash>` | Up to 6 h |
| `robots.txt` cache | transients `ffs_robots_<hash>` | 24 h |
| Default-art counts | option `ffs_default_art` (not autoloaded): per registrable domain, image URL hash to a set of up to 5 page URL hashes | Capped at 5000 image entries, least recently seen dropped |
| Recent runs | option `ffs_recent_runs` (not autoloaded), ring of 200 records without HTML | Ring |
| Daily counters | option `ffs_counters`: per day, per reason | 14 days |
| Post meta | `_ffs_source_url`, `_ffs_source_credit`, `_ffs_source_licence`, `_ffs_opt_out`, `_ffs_status` (`{outcome, reason, attempt, at, attachment_id, kind, image_url}`) | With the post |
| Attachment meta | `_ffs_image_url`, `_ffs_page_url`, `_ffs_credit`, `_ffs_licence`, `_ffs_fetched_at` (UTC ISO 8601), `_ffs_sha256`, `_ffs_kind`, `_ffs_version` | With the attachment |

Uninstall (`uninstall.php`) deletes the settings, queue, locks, transients,
default-art store, recent runs, counters, the cron event, and the
`_ffs_status` and `_ffs_opt_out` post meta. It keeps the source URL, credit
and licence post meta (editorial content the editor typed) and every
attachment and its provenance meta (the credit must stay with the copied
image). The README says so.

## 7. Configuration and sensible defaults

| Setting | Default | Range |
|---|---|---|
| Enabled | on | on, off |
| Rights acknowledged | off (nothing runs until on) | on, off |
| Host mode | `allow_list` | `allow_list`, `deny_list` |
| Allow list | empty | host entries |
| Deny list | empty | host entries |
| Image CDN map | empty | lines `cdn = domain, domain` |
| Allow images from other hosts | off | on, off |
| Respect `robots.txt` | on | on, off |
| Request identity string | `featured-from-source/<version> (+<site URL>)` | 1 to 200 printable characters |
| Post types | `post` | public post types |
| Source URL meta key | `_ffs_source_url` | meta key |
| Credit meta key | `_ffs_source_credit` | meta key |
| Min width, min height | 600, 315 | 100 to 4000 |
| Aspect window | 1.2 to 2.1 | 0.25 to 8.0 |
| Max file size | 15 MiB | 1 to 50 MiB |
| Max pixels | 50 megapixels, sides up to 12000 | fixed upper bound 100 megapixels |
| Accepted types | JPEG, PNG, WebP | plus GIF, AVIF |
| Page read limit | 1 MiB | 256 KiB to 4 MiB |
| Body tags examined | 12 | 1 to 50 |
| Downloads per run | 4 | 1 to 10 |
| Default-art threshold | 3 pages | 2 to 20 |
| Reuse policy | `reject` | `reject`, `share`, `copy` |
| Use placeholder | on | on, off |
| Placeholder attachment | none | image attachment ID |
| Placeholder when source or credit missing | off | on, off |
| Credit template | `Image: {credit}` | text with placeholders |
| Batch size | 10 posts | 1 to 100 |
| Queue interval | 5 minutes | 1 to 60 minutes |
| Debug logging | off | on, off |

Invalid values are refused on save with a message, never silently clamped.

## 8. Security and privacy

- SSRF. Every request (page, redirect hops, `robots.txt`, images) uses the
  safe HTTP request variant, which refuses private, loopback, link-local and
  reserved addresses. Only ports 80 and 443. The `ffs_http_args` filter
  cannot turn this off. The `inspect` path is administrator-only and
  WP-CLI-only for the same reason.
- No SVG, no file whose magic bytes disagree with its type, no execution of
  anything downloaded. Temporary files live in the WordPress temporary
  directory and are always removed.
- HTML parsing never loads external resources or entities. JSON-LD is
  decoded with depth and size limits (64 KiB per block).
- Admin surfaces: `manage_options` for settings and host lists; `edit_post`
  on the post for the meta box, the button and the meta fields; nonces on
  every form and AJAX or REST call; every output escaped; credit, alt and
  licence stored as plain text.
- Privacy. The plugin sends no data anywhere except the HTTP requests to
  source hosts, which carry the request identity string and no cookies.
  Downloaded images may carry camera metadata (including location); the
  plugin stores files as received, and the README tells operators who need
  metadata stripped to use their image pipeline for it.
- Rights. The plugin records provenance and enforces the operator's rules.
  It does not grant or check a licence. The settings screen and README say
  plainly that the operator is responsible.

## 9. Performance budget

- Front-end requests: zero work and zero queries added. The plugin has no
  front-end code path; the caption is plain attachment data.
- Save: one meta read and at most one option write to queue.
- One run: at most 1 page fetch, 1 `robots.txt` fetch per host per day, and
  at most 4 image downloads; wall time target under 30 seconds, hard stop
  at 60 seconds (remaining candidates recorded as `time_budget`).
- Batch: stops early when the PHP time limit is within 10 seconds.
- Memory: HTML capped at the read limit; images streamed to disk and never
  held fully in memory; dimensions read from the header.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later. Single site and multisite
  (per-site settings and queues; network activation activates per site).
- Works with persistent object caches (locks use atomic option adds, not
  the object cache alone).
- Works with media offload plugins because it sideloads through the core
  media function.
- Page caches are unaffected: nothing renders on the front end.
- WP-Cron or a real system cron. With `DISABLE_WP_CRON` the operator must
  run cron; the settings page warns when the queue event is overdue by more
  than three intervals.
- No Composer at runtime.

## 11. Observability

- Post meta status and the meta box show the outcome and the reason in
  plain words for every post.
- Recent runs table: time, post, outcome, reason, winner kind, tried count.
- Daily counters per reason for 14 days, shown on the settings page.
- With debug logging on, one `error_log` line per run and per rejected
  candidate, prefix `[featured-from-source]`, with post ID, reason and the
  candidate URL without query string.
- WP-CLI `status` and `inspect` print the full record.
- Actions in 5.5 let an operator forward records to their own monitoring.

## 12. Acceptance tests

Each test T<n> covers case <n>. Tests use a stub layer and a fake HTTP
transport that serves fixture pages and images; no network.

1. Rights box off: run ends `rights_not_acknowledged`, no HTTP call made.
2. No source URL: `no_source_url`; no credit: `no_credit`; no HTTP call; no
   placeholder at defaults; placeholder set when the setting is on.
3. Allow-list mode with empty list: `host_not_allowed`. With the host
   listed: proceeds. Deny-list mode with empty list: proceeds.
4. Host on both lists: denied, for the page and for an image.
5. Entry `example.com` matches `www.example.com` and not `badexample.com`
   or `example.com.evil.net`; `=www.example.com` matches only itself; IDN
   and trailing-dot forms match.
6. Image on an unrelated host rejected `image_host_not_allowed`; on a
   mapped CDN accepted; on an unrelated host with "other hosts" on and the
   host allowed, accepted.
7. Opted-out post: `post_opted_out`, nothing fetched.
8. Credit stored on attachment and caption; uninstall leaves it.
9. Disallowed path skipped; 404 robots means allow; 500 robots means
   disallow and is cached; `Allow` longer match wins.
10. Meta keys from settings are read; licence copied to the attachment.
11. `ffs_source` filter supplies URL and credit for a post without meta.
12. Source URL equal to the site host: `source_is_self`.
13. Meta fields: user without `edit_post` cannot write; HTML in credit
    stripped; credit over 200 characters refused.
14. Save as draft queues; autosave, revision, auto-draft do not; post with
    an editor image does not.
15. Ten saves leave one queue entry; batch of 10 processes 10 and stops.
16. Meta added after insert queues the post.
17. Button with nonce and capability runs; without nonce refused.
18. Editor image kept without and with `force`; plugin image replaced with
    `force`.
19. Concurrent run with lock held ends `locked`; expired lock taken over.
20. Retryable failures requeue at 15 min, 2 h, 12 h, then final with
    placeholder.
21. Setting off, constant, and filter each stop runs; queue preserved.
22. Request uses the safe variant, the identity string and the timeout.
23. Redirect to a denied host stops; redirect to a private address refused;
    final URL used as base.
24. Page longer than the limit is truncated and still yields `og:image`.
25. Source URL serving `image/jpeg` becomes a `direct` candidate.
26. PDF content type: `not_html`; missing type with HTML body parsed.
27. ISO-8859-1 page with a non-ASCII alt converts correctly.
28. `ffs_source_html` string is used and no page fetch happens.
29. Garbage HTML gives `no_candidates`, no exception.
30. `<base href>`, protocol-relative and root-relative URLs resolve.
31. Order is og, twitter, body; repeated og tags kept in order; duplicate
    URL kept once with the first kind; og hints attach to the right tag.
32. `ffs_candidates` can add and reorder.
33. Images in header, nav, footer, aside, share and related blocks are
    skipped; `<article>` preferred over `<main>` over `<body>`.
34. Lazy attribute beats `src` spacer; widest `srcset` chosen; `2x` beats
    `800w`; `<picture>` source used; `<noscript>` read.
35. Tag with `width="19"` skipped even with a good-looking URL.
36. Spacer in both `src` and `data-original`: tag skipped, next tag used.
37. Only the first 12 body tags are examined.
38. `data:`, `javascript:`, empty and over-long URLs rejected `bad_url`.
39. `pixel.gif`, `1x1_spacer.png`, `spacer-2.gif` rejected; `phone-pixel-10.jpg`
    and `blank-verse-poet.jpg` accepted.
40. `site-logo.png`, `/icons/x.png`, `a.svg` rejected; `logistics-hub.jpg`
    and `iconic-bridge.jpg` accepted.
41. og image pointing at the page URL or at the site's own host rejected
    `self_image`.
42. `?size=50:50`, `?w=120`, `width-100`, `/80x80/` rejected; a numeric id
    `/156248968.jpg` not read as a size; a large hint does not accept a
    small file.
43. `og:image:width=100` rejects; non-numeric ignored.
44. og image equal to the JSON-LD publisher logo rejected `logo`; malformed
    JSON-LD ignored.
45. Same og image on 3 distinct pages of one domain rejected `default_art`
    on the third; store stays within its cap.
46. Image host on the deny list rejected `host_denied`.
47. Filter returning a string rejects with that reason.
48. File over the byte cap aborted mid-stream, temp file removed.
49. 404 image moves to the next candidate; 429 sets host backoff.
50. HTML served as `image/jpeg` rejected `bad_type`; SVG rejected; GIF
    rejected at defaults, accepted when enabled.
51. 1 by 1 PNG `tracker_pixel`; 20000 by 20000 header `too_large_pixels`;
    truncated file `unreadable`.
52. Fifth downloadable candidate never fetched at default 4.
53. Same hash on the same post reuses the attachment, no new upload.
54. `reject`, `share`, `copy` each behave as specified; first post keeps
    the picture.
55. 500 by 300 fails `too_small`; 600 by 315 passes.
56. 1200 by 152 `bad_aspect`; 1080 by 1350 fails; 1200 by 675 passes.
57. A candidate with a large URL hint but a small real file fails.
58. Filter changes rules for one post only.
59. First passing candidate wins over a larger later one.
60. Success sets the featured image and `done` status with kind and URL.
61. All fail: placeholder set and status `placeholder`; with no
    placeholder, status `none` and no featured image.
62. Filter returns another placeholder; a non-image ID is ignored.
63. Placeholder replaced by a later successful run; placeholder never
    accepted as a candidate result.
64. Timeout retried without setting the placeholder until the last attempt.
65. Each final reason is not retried.
66. `Retry-After: 120` blocks the host 120 s; absent header 15 min; cap 6 h;
    queued posts for that host keep their place.
67. Editor sets an image during the run: kept, `editor_image_kept`.
68. Post trashed during the run: `post_gone`, temp file removed.
69. Sideload goes through the core media function with `post_parent` set.
70. Opaque name `156248968.jpg` becomes the post slug; a `.jpg` URL that is
    really PNG gets `.png`.
71. Alt from `og:image:alt`; falls back to post title when empty or equal
    to the site name.
72. Caption is the escaped template with a `nofollow noopener` link; each
    placeholder renders; an unknown placeholder is left as typed.
73. Provenance write failure deletes the attachment, `provenance_failed`.
74. Temp file removed on every outcome.
75. Each action fires once with the documented arguments.
76. Backfill dry run lists eligible posts and skip reasons; queues the rest.
77. Backfill skips editor images, respects backoff and the kill switch.

Plus: uninstall test for section 6, settings validation test for section 7,
and an output-escaping test for the meta box.

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`:

1. Registrable domain: ship a full public suffix list snapshot (size,
   refresh) or a short built-in list of common multi-label suffixes plus
   the `ffs_public_suffixes` filter.
2. Reading image dimensions: core `wp_getimagesize` on the temporary file
   is the default; confirm it reads AVIF and WebP headers on PHP 8.1 or
   document the fallback.
3. Queue storage: one option is fine for thousands of entries; state the
   limit at which a custom table would be needed, and whether to move to
   one.
4. `robots.txt` parsing: which non-standard directives (if any) to honour,
   and how the plugin's identity token is matched against group names.
5. Whether the meta box result refresh uses REST or admin AJAX, given the
   block editor and classic editor both need it.

## 14. Lessons from production

Written as rules for the builder.

1. Match tracker and spacer names on the whole file name, never a
   substring. A photo of a phone named after its model is not a tracker.
2. Read a tag's declared `width` and `height` first. Furniture announces
   itself there and nowhere else.
3. Lazy-loading pages hide the real picture in data attributes and
   `srcset`. Read them before `src`.
4. A tag whose every attribute is a spacer has no image. Move on; do not
   let it spend the "first image" attempt.
5. Never let one bad candidate end the run. A picker that tries only the
   first tag spends its single attempt on a UI icon and falls to the
   placeholder.
6. Size hints count only in labelled forms. Never read a numeric id as a
   size. Hints may reject early; only the real file accepts.
7. The social image itself can be a spacer, a logo or the outlet's default
   card. Check it like any other candidate.
8. Pages ship library pictures from other stories in the social image
   slot. Keep the image on the source's own domain or its declared CDN, and
   treat a picture already used for another post as suspect.
9. A date in the image file name is weak evidence. Honest file photos are
   dated too. Do not reject on a date.
10. Alt text on the source proves nothing about the subject. Use it as alt
    text, never as evidence.
11. Size is not enough; check shape. A 1200 by 152 banner is wide enough
    and useless.
12. The placeholder passes every size test. Track it by ID and never count
    it as a found image.
13. An editor's hand-picked featured image is theirs. Never replace it, not
    even with a force flag.
14. Never resolve a missing source URL to the post's own permalink or GUID.
    It credits your own site and fetches your own page.
15. Registrable domains need multi-label suffixes (`co.uk`, `co.in`,
    `com.au`) or two outlets on one suffix look like one site.
16. Do not use regular expressions on a whole HTML page; a long page can
    exhaust the backtracking limit and lose the result silently. Use the
    DOM parser and a byte cap.
17. Do not retry what cannot succeed. A 404 or a refused host is final;
    only transient failures retry, and only with backoff.
18. Never fetch a video platform watch page. If the source is a video, the
    editor supplies the picture.
19. Never keep a copied image without its credit. If provenance cannot be
    written, delete the copy.
