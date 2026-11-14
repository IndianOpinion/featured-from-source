<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * The outcome of one followed request.
 */
final class HttpResult
{
    /**
     * @param array<string, string> $headers Lowercased header names.
     */
    public function __construct(
        public readonly string $url,
        public readonly int $code,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $error,
    ) {
    }

    public static function failure(string $url, string $reason): self
    {
        return new self($url, 0, [], '', $reason);
    }

    public function ok(): bool
    {
        return $this->error === null && $this->code === 200;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * The media type without parameters, lowercased.
     */
    public function mediaType(): string
    {
        $type = $this->header('content-type');
        $semi = strpos($type, ';');
        if ($semi !== false) {
            $type = substr($type, 0, $semi);
        }
        return strtolower(trim($type));
    }

    /**
     * The `charset` parameter of the content type, lowercased, or ''.
     */
    public function charset(): string
    {
        if (preg_match('/charset\s*=\s*["\']?([a-z0-9_\-:.]+)/i', $this->header('content-type'), $m) === 1) {
            return strtolower($m[1]);
        }
        return '';
    }
}
