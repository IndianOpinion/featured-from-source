<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * One image URL found on the source page (section 5.3).
 */
final class Candidate
{
    public const KINDS = ['og', 'twitter', 'body', 'direct'];

    public function __construct(
        public readonly string $url,
        public readonly string $kind,
        public readonly int $position,
        public readonly string $alt = '',
        public readonly ?int $hintWidth = null,
        public readonly ?int $hintHeight = null,
        public readonly string $hintType = '',
    ) {
    }

    /**
     * Build from an array, as returned by the `ffs_candidates` filter.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $url = $data['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return null;
        }
        $kind = is_string($data['kind'] ?? null) && in_array($data['kind'], self::KINDS, true) ? $data['kind'] : 'body';
        $int = static fn (mixed $v): ?int => is_numeric($v) && (int) $v > 0 ? (int) $v : null;
        return new self(
            $url,
            $kind,
            is_numeric($data['position'] ?? null) ? (int) $data['position'] : 0,
            is_string($data['alt'] ?? null) ? $data['alt'] : '',
            $int($data['hint_width'] ?? null),
            $int($data['hint_height'] ?? null),
            is_string($data['hint_type'] ?? null) ? $data['hint_type'] : '',
        );
    }

    /**
     * @return array{url: string, kind: string, position: int, alt: string, hint_width: ?int, hint_height: ?int, hint_type: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'kind' => $this->kind,
            'position' => $this->position,
            'alt' => $this->alt,
            'hint_width' => $this->hintWidth,
            'hint_height' => $this->hintHeight,
            'hint_type' => $this->hintType,
        ];
    }
}
