<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * The result of downloading and measuring one candidate.
 */
final class Judgement
{
    public function __construct(
        public readonly ?string $reason,
        public readonly string $file = '',
        public readonly string $type = '',
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $bytes = null,
        public readonly string $sha256 = '',
        public readonly int $reuseAttachment = 0,
    ) {
    }
}
