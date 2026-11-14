<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * The immutable record of one run (section 5.3).
 *
 * @phpstan-type Tried array{candidate: array<string, mixed>, reason: string, width: ?int, height: ?int, bytes: ?int}
 */
final class RunRecord
{
    public const OUTCOMES = ['done', 'placeholder', 'none', 'skipped', 'retry'];

    /**
     * @param list<Tried> $tried
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $sourceUrl,
        public readonly string $pageUrl,
        public readonly int $startedAt,
        public readonly int $finishedAt,
        public readonly string $outcome,
        public readonly string $reason,
        public readonly int $attempt,
        public readonly int $attachmentId,
        public readonly ?Candidate $winner,
        public readonly array $tried,
        public readonly bool $truncated,
        public readonly bool $dryRun,
    ) {
    }

    public function isFinal(): bool
    {
        return $this->outcome !== 'retry';
    }

    /**
     * @return array{
     *     post_id: int, source_url: string, page_url: string, started_at: int, finished_at: int,
     *     outcome: string, reason: string, attempt: int, attachment_id: int,
     *     winner: ?array<string, mixed>, tried: list<Tried>, truncated: bool, dry_run: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'post_id' => $this->postId,
            'source_url' => $this->sourceUrl,
            'page_url' => $this->pageUrl,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'attempt' => $this->attempt,
            'attachment_id' => $this->attachmentId,
            'winner' => $this->winner?->toArray(),
            'tried' => $this->tried,
            'truncated' => $this->truncated,
            'dry_run' => $this->dryRun,
        ];
    }
}
