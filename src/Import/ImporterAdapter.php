<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Import;

/**
 * An importer: anything that produces items with a URL, a title, a body and
 * a source name. The plugin ships one reference adapter, for WordPress
 * core's own feed fetching (CoreFeedAdapter).
 */
interface ImporterAdapter
{
    /**
     * A short name for logs and the run record.
     */
    public function name(): string;

    /**
     * Up to $limit items, newest first.
     *
     * @return list<Item>
     */
    public function items(int $limit): array;
}
