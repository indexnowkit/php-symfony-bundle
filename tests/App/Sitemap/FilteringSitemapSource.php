<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Sitemap;

use DateTimeImmutable;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * What an application does to shape what indexnow:sitemap submits: decorate the source, keep the reader.
 */
#[AsDecorator('indexnowkit.sitemap_reader')]
final class FilteringSitemapSource implements SitemapSourceInterface
{
    public function __construct(#[AutowireDecorated] private readonly SitemapSourceInterface $inner) {}

    public function read(string $sitemap, ?DateTimeImmutable $changedSince = null): iterable
    {
        foreach ($this->inner->read($sitemap, $changedSince) as $entry) {
            if (!str_contains($entry->url, '/private/')) {
                yield $entry;
            }
        }
    }
}
