<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Import;

/**
 * One item from an importer: a URL, a title, a body and a source name.
 * The source name becomes the credit line.
 */
final class Item
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $body,
        public readonly string $sourceName,
    ) {
    }

    /**
     * @param array<string, mixed> $data Keys: url, title, body, source_name.
     */
    public static function fromArray(array $data): self
    {
        $get = static fn (string $key): string => isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
        return new self($get('url'), $get('title'), $get('body'), $get('source_name'));
    }

    /**
     * @return array{url: string, title: string, body: string, source_name: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'body' => $this->body,
            'source_name' => $this->sourceName,
        ];
    }
}
