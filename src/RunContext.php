<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Mutable state of one run, turned into a RunRecord at the end.
 *
 * @phpstan-import-type SettingsArray from Settings
 * @phpstan-import-type Tried from RunRecord
 */
final class RunContext
{
    public string $sourceUrl = '';
    public string $pageUrl = '';
    public string $pageHost = '';
    public string $credit = '';
    public string $licence = '';
    public string $siteName = '';
    public bool $truncated = false;
    public ?Candidate $winner = null;
    public int $attachmentId = 0;
    public int $startThumb = 0;
    public int $placeholderId = 0;
    public int $downloads = 0;
    public bool $lockTaken = false;
    /** @var list<Tried> */
    public array $tried = [];
    /** @var list<string> */
    public array $tempFiles = [];
    /** @var array<string, true> */
    public array $logos = [];
    /** @var array{min_width: int, min_height: int, min_ratio: float, max_ratio: float} */
    public array $sizeRules;
    public ?\DOMDocument $dom = null;

    /**
     * @param SettingsArray $settings
     */
    public function __construct(
        public readonly int $postId,
        public readonly int $attempt,
        public readonly bool $force,
        public readonly bool $dryRun,
        public readonly int $startedAt,
        public readonly array $settings,
    ) {
        $this->sizeRules = [
            'min_width' => $settings['min_width'],
            'min_height' => $settings['min_height'],
            'min_ratio' => $settings['min_ratio'],
            'max_ratio' => $settings['max_ratio'],
        ];
    }

    public function tried(Candidate $candidate, string $reason, ?int $width = null, ?int $height = null, ?int $bytes = null): void
    {
        $this->tried[] = [
            'candidate' => $candidate->toArray(),
            'reason' => $reason,
            'width' => $width,
            'height' => $height,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return array<string, mixed> Context passed to filters and actions.
     */
    public function filterContext(): array
    {
        return [
            'post_id' => $this->postId,
            'page_url' => $this->pageUrl,
            'source_url' => $this->sourceUrl,
            'dom' => $this->dom,
            'dry_run' => $this->dryRun,
        ];
    }
}
