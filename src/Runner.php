<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * One run for one post: the whole of section 4 in order.
 *
 * @phpstan-import-type SettingsArray from Settings
 */
final class Runner
{
    public const HARD_LIMIT = 60;
    public const PAGE_TIMEOUT = 10;
    public const IMAGE_TIMEOUT = 15;
    public const MAX_ATTEMPTS = 4;
    public const RUN_STATUSES = ['draft', 'pending', 'future', 'publish'];

    /**
     * Run now for a post.
     *
     * @param array{force?: bool, dry_run?: bool, attempt?: int} $args
     */
    public function run(int $postId, array $args = []): RunRecord
    {
        $settings = Settings::get();
        $ctx = new RunContext(
            $postId,
            max(1, (int) ($args['attempt'] ?? Queue::attemptOf($postId))),
            (bool) ($args['force'] ?? false),
            (bool) ($args['dry_run'] ?? false),
            Clock::now(),
            $settings
        );
        do_action('ffs_run_started', $postId);
        try {
            $reason = $this->runPost($ctx);
            return $this->finish($ctx, $reason);
        } finally {
            $this->cleanup($ctx);
        }
    }

    /**
     * Run the whole pipeline for a URL with no post. Dry run only.
     */
    public function inspect(string $url): RunRecord
    {
        $ctx = new RunContext(0, 1, false, true, Clock::now(), Settings::get());
        try {
            if (!Settings::enabled()) {
                return $this->finish($ctx, Reason::DISABLED);
            }
            $ctx->sourceUrl = Url::normaliseSource($url);
            if ($ctx->sourceUrl === '') {
                return $this->finish($ctx, Reason::NO_SOURCE_URL);
            }
            $reason = $this->pipeline($ctx, null);
            return $this->finish($ctx, $reason);
        } finally {
            $this->cleanup($ctx);
        }
    }

    private function runPost(RunContext $ctx): string
    {
        if (!Settings::enabled()) {
            return Reason::DISABLED;
        }
        $post = get_post($ctx->postId);
        if (!$post instanceof \WP_Post || $post->post_status === 'trash') {
            return Reason::POST_GONE;
        }
        if (!$ctx->settings['rights_acknowledged']) {
            return Reason::RIGHTS_NOT_ACKNOWLEDGED;
        }
        if (Meta::optedOut($ctx->postId)) {
            return Reason::POST_OPTED_OUT;
        }
        if (!(bool) apply_filters('ffs_should_process', true, $ctx->postId)) {
            return Reason::NOT_ELIGIBLE;
        }
        if (!$ctx->dryRun) {
            if (!Lock::acquire($ctx->postId)) {
                return Reason::LOCKED;
            }
            $ctx->lockTaken = true;
        }
        $ctx->placeholderId = self::placeholderFor($ctx->postId);
        $ctx->startThumb = (int) get_post_thumbnail_id($ctx->postId);
        if ($ctx->startThumb > 0 && $ctx->startThumb !== $ctx->placeholderId) {
            if (!self::isPluginImage($ctx->startThumb)) {
                return Reason::EDITOR_IMAGE_KEPT;
            }
            if (!$ctx->force) {
                return Reason::HAS_IMAGE;
            }
        }
        $source = Meta::source($ctx->postId);
        $ctx->sourceUrl = $source['url'];
        $ctx->credit = $source['credit'];
        $ctx->licence = $source['licence'];
        if ($ctx->sourceUrl === '') {
            return Reason::NO_SOURCE_URL;
        }
        if ($ctx->credit === '') {
            return Reason::NO_CREDIT;
        }
        $html = apply_filters('ffs_source_html', null, $ctx->postId, $ctx->sourceUrl);
        return $this->pipeline($ctx, is_string($html) ? $html : null);
    }

    /**
     * From a normalised source URL to a winner (or a reason).
     */
    private function pipeline(RunContext $ctx, ?string $html): string
    {
        $settings = $ctx->settings;
        $policy = new HostPolicy($settings);
        $userAgent = Settings::userAgent($settings);
        $http = new Http($userAgent);
        $robots = $settings['respect_robots'] ? new Robots($http, $userAgent, $policy) : null;

        $sourceHost = HostName::ofUrl($ctx->sourceUrl);
        if (in_array($sourceHost, self::siteHosts(), true)) {
            return Reason::SOURCE_IS_SELF;
        }
        $reason = $policy->checkPage($sourceHost, $ctx->postId);
        if ($reason !== null) {
            return $reason;
        }
        $rules = apply_filters('ffs_size_rules', $ctx->sizeRules, $ctx->postId);
        if (is_array($rules)) {
            foreach (['min_width', 'min_height'] as $key) {
                if (isset($rules[$key]) && is_numeric($rules[$key])) {
                    $ctx->sizeRules[$key] = max(1, (int) $rules[$key]);
                }
            }
            foreach (['min_ratio', 'max_ratio'] as $key) {
                if (isset($rules[$key]) && is_numeric($rules[$key])) {
                    $ctx->sizeRules[$key] = (float) $rules[$key];
                }
            }
        }

        $ctx->pageUrl = $ctx->sourceUrl;
        $ctx->pageHost = $sourceHost;
        $direct = false;
        $headerCharset = '';
        if ($html === null) {
            if (Backoff::isBlocked($sourceHost)) {
                return Reason::HOST_BACKOFF;
            }
            if ($robots !== null) {
                $reason = $robots->check($ctx->sourceUrl);
                if ($reason !== null) {
                    return $reason;
                }
            }
            $limit = $settings['page_read_kib'] * 1024;
            $result = $http->get(
                $ctx->sourceUrl,
                'page',
                [
                    'timeout' => self::PAGE_TIMEOUT,
                    'limit_response_size' => $limit,
                    'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
                ],
                fn (string $host, int $hop): ?string => $hop === 0 ? null : $policy->checkPageHop($host, $ctx->postId)
            );
            if ($result->error !== null) {
                return $result->error === Reason::HOST_DENIED ? Reason::HOST_NOT_ALLOWED : $result->error;
            }
            if ($result->code !== 200) {
                return Reason::http($result->code);
            }
            $ctx->pageUrl = $result->url;
            $ctx->pageHost = HostName::ofUrl($result->url);
            if ($robots !== null && $ctx->pageUrl !== $ctx->sourceUrl) {
                $reason = $robots->check($ctx->pageUrl);
                if ($reason !== null) {
                    return $reason;
                }
            }
            $type = $result->mediaType();
            $body = $result->body;
            $ctx->truncated = strlen($body) >= $limit;
            if (str_starts_with($type, 'image/')) {
                $direct = true;
            } elseif ($type === '') {
                if (!str_starts_with(ltrim(str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body), '<')) {
                    return Reason::NOT_HTML;
                }
            } elseif (!in_array($type, ['text/html', 'application/xhtml+xml'], true)) {
                return Reason::NOT_HTML;
            }
            $html = $body;
            $headerCharset = $result->charset();
        }

        $art = new DefaultArt($settings['default_art_threshold']);
        $pageDomain = PublicSuffix::registrable($ctx->pageHost);
        $check = fn (Candidate $c, array $logos): ?string => $this->checkUrl($c, $ctx, $policy, $robots, $art, $pageDomain, $logos);

        if ($direct) {
            $candidates = [new Candidate($ctx->pageUrl, 'direct', 1)];
            $ctx->dom = null;
        } else {
            $ctx->dom = Document::fromBytes($html, $headerCharset);
            $candidates = [];
            if ($ctx->dom !== null) {
                $finder = new CandidateFinder($settings['body_tags'], $ctx->sizeRules['min_width'], $ctx->sizeRules['min_height']);
                $found = $finder->find($ctx->dom, $ctx->pageUrl, $check);
                $ctx->logos = $found->logos;
                $ctx->siteName = $found->siteName;
                $candidates = $found->candidates;
                foreach ($found->rejected as [$candidate, $reason]) {
                    $this->reject($ctx, $candidate, $reason);
                }
            }
            $candidates = $this->filterCandidates($candidates, $ctx);
            if (!$ctx->dryRun) {
                $this->recordSocial($art, $pageDomain, $candidates, $ctx->pageUrl);
            }
        }

        try {
            $reason = $this->judgeAll($candidates, $ctx, $policy, $check);
        } finally {
            $art->save();
        }
        if ($reason !== null) {
            return $reason;
        }
        if ($ctx->winner === null) {
            return $candidates === [] && $ctx->tried === [] ? Reason::NO_CANDIDATES : Reason::NO_USABLE_IMAGE;
        }
        return Reason::DONE;
    }

    /**
     * @param list<Candidate> $candidates
     * @param callable(Candidate, array<string, true>): ?string $check
     */
    private function judgeAll(array $candidates, RunContext $ctx, HostPolicy $policy, callable $check): ?string
    {
        $limit = $ctx->settings['downloads_per_run'];
        $judged = 0;
        foreach ($candidates as $index => $candidate) {
            $reason = $check($candidate, $ctx->logos);
            if ($reason !== null) {
                $this->reject($ctx, $candidate, $reason);
                continue;
            }
            if ($ctx->downloads >= $limit) {
                foreach (array_slice($candidates, $index) as $rest) {
                    $ctx->tried($rest, Reason::DOWNLOAD_LIMIT);
                }
                return null;
            }
            if (Clock::now() - $ctx->startedAt >= self::HARD_LIMIT) {
                foreach (array_slice($candidates, $index) as $rest) {
                    $ctx->tried($rest, Reason::TIME_BUDGET);
                }
                return $judged === 0 ? Reason::TIME_BUDGET : null;
            }
            $ctx->downloads++;
            $judged++;
            $judgement = $this->judge($candidate, $ctx, $policy);
            if ($judgement->reason !== null) {
                $this->reject($ctx, $candidate, $judgement->reason, $judgement->width, $judgement->height, $judgement->bytes);
                continue;
            }
            $ctx->tried($candidate, Reason::DONE, $judgement->width, $judgement->height, $judgement->bytes);
            $ctx->winner = $candidate;
            if ($ctx->dryRun) {
                return null;
            }
            return $this->apply($candidate, $judgement, $ctx);
        }
        return null;
    }

    /**
     * Pre-download checks (cases 38 to 47).
     *
     * @param array<string, true> $logos
     */
    private function checkUrl(
        Candidate $c,
        RunContext $ctx,
        HostPolicy $policy,
        ?Robots $robots,
        DefaultArt $art,
        string $pageDomain,
        array $logos
    ): ?string {
        $url = $c->url;
        if (UrlRules::isBadUrl($url)) {
            return Reason::BAD_URL;
        }
        $host = HostName::ofUrl($url);
        $bare = Url::bare($url);
        if ($c->kind !== 'direct' && ($bare === Url::bare($ctx->pageUrl) || $bare === Url::bare($ctx->sourceUrl))) {
            return Reason::SELF_IMAGE;
        }
        if (in_array($host, self::siteHosts(), true)) {
            return Reason::SELF_IMAGE;
        }
        $reason = $policy->checkImage($host, $ctx->pageHost, $ctx->postId);
        if ($reason !== null) {
            return $reason;
        }
        $names = apply_filters('ffs_tracker_names', UrlRules::TRACKER_NAMES);
        if (UrlRules::isTrackerName($url, is_array($names) ? array_values(array_filter($names, 'is_string')) : UrlRules::TRACKER_NAMES)) {
            return Reason::TRACKER_URL;
        }
        $tokens = apply_filters('ffs_furniture_tokens', UrlRules::FURNITURE_TOKENS);
        if (UrlRules::isFurniture($url, is_array($tokens) ? array_values(array_filter($tokens, 'is_string')) : UrlRules::FURNITURE_TOKENS)) {
            return Reason::FURNITURE_URL;
        }
        $rules = $ctx->sizeRules;
        [$hintW, $hintH] = UrlRules::sizeHints($url);
        if (UrlRules::hintTooSmall($hintW, $hintH, $rules['min_width'], $rules['min_height'])) {
            return Reason::HINT_TOO_SMALL;
        }
        if (UrlRules::hintTooSmall($c->hintWidth, $c->hintHeight, $rules['min_width'], $rules['min_height'])) {
            return Reason::HINT_TOO_SMALL;
        }
        if (isset($logos[$bare])) {
            return Reason::LOGO;
        }
        if ($art->isDefaultArt($pageDomain, $url)) {
            return Reason::DEFAULT_ART;
        }
        if (Backoff::isBlocked($host)) {
            return Reason::HOST_BACKOFF;
        }
        if ($robots !== null) {
            $reason = $robots->check($url);
            if ($reason !== null) {
                return $reason;
            }
        }
        $custom = apply_filters('ffs_candidate_reject', null, $c, $ctx->filterContext());
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }
        return null;
    }

    /**
     * Download and measure one candidate (cases 48 to 56, 53, 54).
     */
    private function judge(Candidate $candidate, RunContext $ctx, HostPolicy $policy): Judgement
    {
        $settings = $ctx->settings;
        $tmp = wp_tempnam('ffs-image');
        if (!is_string($tmp) || $tmp === '') {
            return new Judgement(Reason::SIDELOAD_FAILED);
        }
        $ctx->tempFiles[] = $tmp;
        $maxBytes = $settings['max_file_mib'] * 1024 * 1024;
        $http = new Http(Settings::userAgent($settings));
        $candidateHost = HostName::ofUrl($candidate->url);
        $result = $http->get(
            $candidate->url,
            'image',
            [
                'timeout' => self::IMAGE_TIMEOUT,
                'stream' => true,
                'filename' => $tmp,
                'limit_response_size' => $maxBytes + 1,
                'headers' => ['Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.8'],
            ],
            static fn (string $host, int $hop): ?string => $hop === 0 ? null : $policy->checkImageHop($host)
        );
        $fail = function (string $reason, ?int $w = null, ?int $h = null, ?int $bytes = null) use ($tmp, $ctx): Judgement {
            self::unlink($tmp);
            $ctx->tempFiles = array_values(array_diff($ctx->tempFiles, [$tmp]));
            return new Judgement($reason, '', '', $w, $h, $bytes);
        };
        if ($result->error !== null) {
            return $fail($result->error);
        }
        $finalHost = HostName::ofUrl($result->url);
        if ($finalHost !== $candidateHost) {
            $reason = $policy->checkImage($finalHost, $ctx->pageHost, $ctx->postId);
            if ($reason !== null) {
                return $fail($reason);
            }
        }
        if ($result->code !== 200) {
            return $fail(Reason::http($result->code));
        }
        clearstatcache(true, $tmp);
        $bytes = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($bytes > $maxBytes) {
            return $fail(Reason::TOO_LARGE_BYTES, null, null, $bytes);
        }
        $mimeType = ImageProbe::typeFromMime($result->mediaType());
        $magicType = ImageProbe::typeFromMagic(ImageProbe::head($tmp, 64));
        if ($mimeType === '' || $magicType === '' || $mimeType !== $magicType || !in_array($magicType, self::acceptedTypes($settings), true)) {
            return $fail(Reason::BAD_TYPE, null, null, $bytes);
        }
        try {
            $size = ImageProbe::dimensions($tmp, $magicType);
        } catch (UnsupportedImageException $e) {
            Log::debug($ctx->postId, Reason::AVIF_UNSUPPORTED . ': ' . $e->getMessage(), $candidate->url);
            return $fail(Reason::AVIF_UNSUPPORTED, null, null, $bytes);
        }
        if ($size === null) {
            return $fail(Reason::UNREADABLE, null, null, $bytes);
        }
        [$width, $height] = $size;
        if ($width <= 10 && $height <= 10) {
            return $fail(Reason::TRACKER_PIXEL, $width, $height, $bytes);
        }
        if ($width > Settings::MAX_SIDE || $height > Settings::MAX_SIDE || $width * $height > $settings['max_megapixels'] * 1000000) {
            return $fail(Reason::TOO_LARGE_PIXELS, $width, $height, $bytes);
        }
        // Shape first: a 1200 by 152 banner is the wrong shape, whatever its size.
        $rules = $ctx->sizeRules;
        $ratio = $width / $height;
        if ($ratio < $rules['min_ratio'] || $ratio > $rules['max_ratio']) {
            return $fail(Reason::BAD_ASPECT, $width, $height, $bytes);
        }
        if ($width < $rules['min_width'] || $height < $rules['min_height']) {
            return $fail(Reason::TOO_SMALL, $width, $height, $bytes);
        }
        $sha = (string) hash_file('sha256', $tmp);
        $reuse = 0;
        $existing = self::findPluginImage('_ffs_sha256', $sha, $ctx->placeholderId);
        if ($existing === 0) {
            $existing = self::findPluginImage('_ffs_image_key', md5(Url::bare($candidate->url)), $ctx->placeholderId);
        }
        if ($existing > 0) {
            $parent = (int) wp_get_post_parent_id($existing);
            if ($parent === $ctx->postId && $ctx->postId > 0) {
                $reuse = $existing;
            } elseif ($parent > 0 && get_post_status($parent) === 'publish') {
                $policyName = $settings['reuse_policy'];
                if ($policyName === 'reject') {
                    return $fail(Reason::REUSED, $width, $height, $bytes);
                }
                if ($policyName === 'share') {
                    $reuse = $existing;
                }
            }
        }
        return new Judgement(null, $tmp, $magicType, $width, $height, $bytes, $sha, $reuse);
    }

    /**
     * Sideload (or reuse) and set the featured image (cases 60, 67, 68, 69 to 75).
     */
    private function apply(Candidate $candidate, Judgement $judgement, RunContext $ctx): string
    {
        $post = get_post($ctx->postId);
        if (!$post instanceof \WP_Post || $post->post_status === 'trash') {
            return Reason::POST_GONE;
        }
        $attachmentId = $judgement->reuseAttachment;
        if ($attachmentId === 0) {
            try {
                $attachmentId = Sideloader::sideload(
                    $judgement->file,
                    ['sha256' => $judgement->sha256, 'type' => $judgement->type],
                    $candidate,
                    $post,
                    ['url' => $ctx->sourceUrl, 'credit' => $ctx->credit, 'licence' => $ctx->licence],
                    $ctx->pageUrl,
                    $ctx->siteName,
                    $ctx->settings['credit_template']
                );
            } catch (SideloadException $e) {
                return $e->getMessage();
            }
        }
        $ctx->attachmentId = $attachmentId;
        $post = get_post($ctx->postId);
        if (!$post instanceof \WP_Post || $post->post_status === 'trash') {
            return Reason::POST_GONE;
        }
        $current = (int) get_post_thumbnail_id($ctx->postId);
        if ($current > 0 && $current !== $ctx->startThumb && $current !== $ctx->placeholderId && $current !== $attachmentId) {
            return Reason::EDITOR_IMAGE_KEPT;
        }
        set_post_thumbnail($ctx->postId, $attachmentId);
        return Reason::DONE;
    }

    private function finish(RunContext $ctx, string $reason): RunRecord
    {
        $outcome = 'none';
        if ($reason === Reason::DONE) {
            $outcome = 'done';
        } elseif (Reason::isSkip($reason)) {
            $outcome = 'skipped';
        } elseif (!$ctx->dryRun && $ctx->postId > 0 && Reason::isRetryable($reason) && $ctx->attempt < self::MAX_ATTEMPTS) {
            $outcome = 'retry';
        } elseif (!$ctx->dryRun && $ctx->postId > 0 && $this->placeholderAllowed($reason, $ctx)) {
            $current = (int) get_post_thumbnail_id($ctx->postId);
            $placeholder = $ctx->placeholderId > 0 ? $ctx->placeholderId : self::placeholderFor($ctx->postId);
            if ($placeholder > 0 && ($current === 0 || $current === $placeholder)) {
                if ($current !== $placeholder) {
                    set_post_thumbnail($ctx->postId, $placeholder);
                }
                $ctx->attachmentId = $placeholder;
                $outcome = 'placeholder';
            }
        }
        $record = new RunRecord(
            $ctx->postId,
            $ctx->sourceUrl,
            $ctx->pageUrl,
            $ctx->startedAt,
            Clock::now(),
            $outcome,
            $reason,
            $ctx->attempt,
            $ctx->attachmentId,
            $outcome === 'done' || ($ctx->dryRun && $reason === Reason::DONE) ? $ctx->winner : null,
            $ctx->tried,
            $ctx->truncated,
            $ctx->dryRun
        );

        if (!$ctx->dryRun && $ctx->postId > 0 && $reason !== Reason::DISABLED && $reason !== Reason::LOCKED) {
            if ($outcome === 'retry') {
                Queue::retry($ctx->postId, $ctx->pageHost !== '' ? $ctx->pageHost : HostName::ofUrl($ctx->sourceUrl), $ctx->attempt + 1);
            } elseif ($reason !== Reason::RIGHTS_NOT_ACKNOWLEDGED) {
                Queue::remove($ctx->postId);
            }
            Status::write($record);
            Log::recordRun($record);
        }
        Log::debug($ctx->postId, $reason, $ctx->sourceUrl);

        if (!$ctx->dryRun && $ctx->postId > 0) {
            if ($outcome === 'done') {
                do_action('ffs_image_set', $ctx->postId, $ctx->attachmentId, $record);
            } elseif ($outcome === 'placeholder') {
                do_action('ffs_placeholder_set', $ctx->postId, $ctx->attachmentId, $record);
            }
        }
        do_action('ffs_run_finished', $record);
        return $record;
    }

    private function placeholderAllowed(string $reason, RunContext $ctx): bool
    {
        if (!$ctx->settings['use_placeholder']) {
            return false;
        }
        if ($reason === Reason::NO_SOURCE_URL || $reason === Reason::NO_CREDIT) {
            return $ctx->settings['placeholder_when_missing'];
        }
        return Reason::allowsPlaceholder($reason);
    }

    private function reject(RunContext $ctx, Candidate $candidate, string $reason, ?int $w = null, ?int $h = null, ?int $bytes = null): void
    {
        $ctx->tried($candidate, $reason, $w, $h, $bytes);
        Log::debug($ctx->postId, $reason, $candidate->url);
        do_action('ffs_candidate_rejected', $candidate, $reason, $ctx->filterContext());
    }

    /**
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    private function filterCandidates(array $candidates, RunContext $ctx): array
    {
        $filtered = apply_filters('ffs_candidates', $candidates, $ctx->filterContext());
        if (!is_array($filtered)) {
            return $candidates;
        }
        $out = [];
        $seen = [];
        foreach ($filtered as $item) {
            $candidate = $item instanceof Candidate ? $item : (is_array($item) ? Candidate::fromArray($item) : null);
            if ($candidate === null || isset($seen[$candidate->url])) {
                continue;
            }
            $seen[$candidate->url] = true;
            if ($candidate->url !== '' && !Url::isHttp($candidate->url) && !str_contains($candidate->url, ':')) {
                $resolved = Url::resolve($candidate->url, $ctx->pageUrl);
                if ($resolved !== '') {
                    $candidate = Candidate::fromArray(['url' => $resolved] + $candidate->toArray()) ?? $candidate;
                }
            }
            $out[] = $candidate;
        }
        return $out;
    }

    /**
     * Note the first og and first twitter candidate of this page (case 45).
     *
     * @param list<Candidate> $candidates
     */
    private function recordSocial(DefaultArt $art, string $domain, array $candidates, string $pageUrl): void
    {
        $done = [];
        foreach ($candidates as $candidate) {
            if (($candidate->kind === 'og' || $candidate->kind === 'twitter') && !isset($done[$candidate->kind])) {
                $done[$candidate->kind] = true;
                if (Url::isHttp($candidate->url)) {
                    $art->record($domain, $candidate->url, $pageUrl);
                }
            }
        }
    }

    private function cleanup(RunContext $ctx): void
    {
        foreach ($ctx->tempFiles as $file) {
            self::unlink($file);
        }
        $ctx->tempFiles = [];
        if ($ctx->lockTaken) {
            Lock::release($ctx->postId);
            $ctx->lockTaken = false;
        }
    }

    private static function unlink(string $file): void
    {
        if ($file !== '' && is_file($file)) {
            unlink($file);
        }
    }

    /**
     * @param SettingsArray $settings
     * @return list<string>
     */
    public static function acceptedTypes(array $settings): array
    {
        $out = [];
        foreach ($settings['accepted_types'] as $type) {
            if ($type === 'gif' || $type === 'avif') {
                if (!wp_image_editor_supports(['mime_type' => ImageProbe::MIME[$type]])) {
                    continue;
                }
            }
            $out[] = $type;
        }
        return $out;
    }

    /**
     * The site's own hosts (home and site URL).
     *
     * @return list<string>
     */
    public static function siteHosts(): array
    {
        return array_values(array_unique(array_filter([
            HostName::ofUrl(home_url('/')),
            HostName::ofUrl(site_url('/')),
        ])));
    }

    /**
     * The placeholder for a post after the `ffs_placeholder_id` filter.
     */
    public static function placeholderFor(int $postId): int
    {
        $configured = Settings::get()['placeholder_id'];
        $id = apply_filters('ffs_placeholder_id', $configured, $postId);
        $id = is_numeric($id) ? (int) $id : $configured;
        if ($id > 0 && !wp_attachment_is_image($id)) {
            Log::warn(sprintf('ffs_placeholder_id returned %d for post %d, which is not an image attachment; ignored', $id, $postId));
            $id = $configured > 0 && wp_attachment_is_image($configured) ? $configured : 0;
        }
        return max(0, $id);
    }

    public static function isPluginImage(int $attachmentId): bool
    {
        return $attachmentId > 0 && (string) get_post_meta($attachmentId, '_ffs_sha256', true) !== '';
    }

    /**
     * An attachment this plugin created whose meta matches, or 0.
     */
    private static function findPluginImage(string $key, string $value, int $placeholderId): int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 5,
            'no_found_rows' => true,
            'meta_key' => $key,
            'meta_value' => $value,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($ids as $id) {
            $id = (int) (is_object($id) ? $id->ID : $id);
            if ($id > 0 && $id !== $placeholderId) {
                return $id;
            }
        }
        return 0;
    }
}
