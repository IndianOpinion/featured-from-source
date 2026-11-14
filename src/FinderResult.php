<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * What the finder learned about a page.
 */
final class FinderResult
{
    /**
     * @param list<Candidate> $candidates In candidate order.
     * @param list<array{0: Candidate, 1: string}> $rejected Body tag URLs rejected while choosing a tag's URL.
     * @param array<string, true> $logos Bare logo URLs (case 44).
     */
    public function __construct(
        public readonly array $candidates,
        public readonly array $rejected,
        public readonly array $logos,
        public readonly string $siteName,
        public readonly string $baseUrl,
    ) {
    }
}
