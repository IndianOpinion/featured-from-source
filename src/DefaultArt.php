<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Remembers which social images an outlet uses on many pages (case 45).
 * Shape: domain => image hash => {p: list of page hashes, t: last seen}.
 * Bounded at 5000 image entries; the least recently seen go first.
 */
final class DefaultArt
{
    public const OPTION = 'ffs_default_art';
    public const MAX_ENTRIES = 5000;
    public const PAGES_KEPT = 5;

    /** @var array<string, array<string, array{p: list<string>, t: int}>>|null */
    private ?array $store = null;
    private bool $dirty = false;

    public function __construct(private int $threshold)
    {
    }

    private static function hash(string $value): string
    {
        return substr(md5($value), 0, 16);
    }

    /**
     * @return array<string, array<string, array{p: list<string>, t: int}>>
     */
    private function &load(): array
    {
        if ($this->store === null) {
            $stored = get_option(self::OPTION, []);
            $this->store = is_array($stored) ? $stored : [];
        }
        return $this->store;
    }

    /**
     * Note that an image was a social image of a page.
     */
    public function record(string $domain, string $imageUrl, string $pageUrl): void
    {
        $store = &$this->load();
        $image = self::hash(Url::bare($imageUrl));
        $page = self::hash(Url::bare($pageUrl));
        $entry = $store[$domain][$image] ?? ['p' => [], 't' => 0];
        if (!in_array($page, $entry['p'], true)) {
            $entry['p'][] = $page;
            $keep = max(self::PAGES_KEPT, $this->threshold);
            if (count($entry['p']) > $keep) {
                $entry['p'] = array_slice($entry['p'], -$keep);
            }
        }
        $entry['t'] = Clock::now();
        $store[$domain][$image] = $entry;
        $this->dirty = true;
    }

    /**
     * Has the image been the social image of enough distinct pages?
     */
    public function isDefaultArt(string $domain, string $imageUrl): bool
    {
        $store = &$this->load();
        $entry = $store[$domain][self::hash(Url::bare($imageUrl))] ?? null;
        return $entry !== null && count($entry['p']) >= $this->threshold;
    }

    /**
     * Write the store back, trimmed to its cap.
     */
    public function save(): void
    {
        if (!$this->dirty || $this->store === null) {
            return;
        }
        $flat = [];
        $total = 0;
        foreach ($this->store as $domain => $images) {
            foreach ($images as $hash => $entry) {
                $flat[] = [$entry['t'], $domain, $hash];
                $total++;
            }
        }
        if ($total > self::MAX_ENTRIES) {
            usort($flat, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            foreach (array_slice($flat, 0, $total - self::MAX_ENTRIES) as [, $domain, $hash]) {
                unset($this->store[$domain][$hash]);
                if ($this->store[$domain] === []) {
                    unset($this->store[$domain]);
                }
            }
        }
        update_option(self::OPTION, $this->store, false);
        $this->dirty = false;
    }

    public function count(): int
    {
        $total = 0;
        foreach ($this->load() as $images) {
            $total += count($images);
        }
        return $total;
    }
}
