<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Host rules: allow and deny lists, the image host rule and the CDN map
 * (cases 3 to 6, 23, 46).
 *
 * @phpstan-import-type SettingsArray from Settings
 */
final class HostPolicy
{
    /**
     * @param SettingsArray $settings
     */
    public function __construct(private array $settings)
    {
    }

    public function isDenied(string $host): bool
    {
        return HostName::matchesList($host, $this->settings['deny_list']);
    }

    /**
     * Check the source page host. Returns a reason, or null when allowed.
     */
    public function checkPage(string $host, int $postId): ?string
    {
        if ($host === '') {
            return Reason::HOST_NOT_ALLOWED;
        }
        if ($this->isDenied($host)) {
            return Reason::HOST_DENIED;
        }
        if ($this->settings['host_mode'] === 'allow_list' && !HostName::matchesList($host, $this->settings['allow_list'])) {
            return Reason::HOST_NOT_ALLOWED;
        }
        if (!(bool) apply_filters('ffs_host_allowed', true, $host, 'page', $postId)) {
            return Reason::HOST_NOT_ALLOWED;
        }
        return null;
    }

    /**
     * Check a redirect hop of the page request (case 23). Any failure is
     * `host_not_allowed`.
     */
    public function checkPageHop(string $host, int $postId): ?string
    {
        return $this->checkPage($host, $postId) === null ? null : Reason::HOST_NOT_ALLOWED;
    }

    /**
     * Check an intermediate redirect hop of an image download. Only the deny
     * list applies; the image host rule applies to the final host.
     */
    public function checkImageHop(string $host): ?string
    {
        return $this->isDenied($host) ? Reason::HOST_DENIED : null;
    }

    /**
     * Check a candidate image host against the page host (cases 4, 6, 46).
     */
    public function checkImage(string $imageHost, string $pageHost, int $postId): ?string
    {
        if ($imageHost === '') {
            return Reason::BAD_URL;
        }
        if ($this->isDenied($imageHost)) {
            return Reason::HOST_DENIED;
        }
        if (!$this->imageHostAllowed($imageHost, $pageHost)) {
            return Reason::IMAGE_HOST_NOT_ALLOWED;
        }
        if (!(bool) apply_filters('ffs_host_allowed', true, $imageHost, 'image', $postId)) {
            return Reason::IMAGE_HOST_NOT_ALLOWED;
        }
        return null;
    }

    private function imageHostAllowed(string $imageHost, string $pageHost): bool
    {
        $pageDomain = PublicSuffix::registrable($pageHost);
        if ($pageDomain !== '' && HostName::matchesEntry($imageHost, $pageDomain)) {
            return true;
        }
        foreach ($this->settings['cdn_map'] as $cdn => $domains) {
            if (!HostName::matchesEntry($imageHost, $cdn)) {
                continue;
            }
            foreach ($domains as $domain) {
                if ($domain === $pageDomain || HostName::matchesEntry($pageHost, $domain)) {
                    return true;
                }
            }
        }
        if ($this->settings['other_hosts']) {
            if ($this->settings['host_mode'] === 'allow_list') {
                return HostName::matchesList($imageHost, $this->settings['allow_list']);
            }
            return true;
        }
        return false;
    }
}
