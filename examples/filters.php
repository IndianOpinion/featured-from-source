<?php

/**
 * Filter recipes. Put what you need in a small plugin of your own.
 */

declare(strict_types=1);

use IndianOpinion\FeaturedFromSource\Candidate;

// Portrait pictures for one category.
add_filter('ffs_size_rules', static function (array $rules, int $postId): array {
    if (has_category('portraits', $postId)) {
        $rules['min_ratio'] = 0.6;
        $rules['max_ratio'] = 1.0;
        $rules['min_height'] = 800;
    }
    return $rules;
}, 10, 2);

// A different placeholder per category.
add_filter('ffs_placeholder_id', static function (int $id, int $postId): int {
    return has_category('sport', $postId) ? 1234 : $id;
}, 10, 2);

// Add the structured-data image after the social tags.
add_filter('ffs_candidates', static function (array $candidates, array $context): array {
    $dom = $context['dom'];
    if (!$dom instanceof DOMDocument) {
        return $candidates;
    }
    foreach ($dom->getElementsByTagName('script') as $script) {
        if (stripos($script->getAttribute('type'), 'ld+json') === false) {
            continue;
        }
        $data = json_decode($script->textContent, true, 32);
        $image = is_array($data) ? ($data['image']['url'] ?? $data['image'] ?? null) : null;
        if (is_string($image)) {
            $candidates[] = new Candidate($image, 'body', 999);
        }
    }
    return $candidates;
}, 10, 2);

// Your own rejection rule.
add_filter('ffs_candidate_reject', static function (?string $reason, Candidate $candidate): ?string {
    return str_contains($candidate->url, '/stock/') ? 'stock_library' : $reason;
}, 10, 2);

// Credit line wording.
add_filter('ffs_credit_html', static function (string $html): string {
    return $html . ' (reproduced with permission)';
});

// Never fetch for posts in the "opinion" category.
add_filter('ffs_should_process', static function (bool $process, int $postId): bool {
    return $process && !has_category('opinion', $postId);
}, 10, 2);

// Forward every run record to your own monitoring.
add_action('ffs_run_finished', static function ($record): void {
    error_log('featured-from-source ' . wp_json_encode($record->toArray()));
});
