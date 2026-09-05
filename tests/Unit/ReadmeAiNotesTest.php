<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Unit;

use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Testing\Conformance\ReadmeAssertions;
use PHPUnit\Framework\TestCase;

/**
 * The "Notes for AI assistants" section of the README (EN and RU): present, with a complete snippet, naming only
 * commands and configuration keys that exist (spec 17 §3.1).
 */
final class ReadmeAiNotesTest extends TestCase
{
    public function testTheNotesForAiAssistantsAreConsistentWithTheCode(): void
    {
        ReadmeAssertions::assertAiNotes(\dirname(__DIR__, 2), ['indexnow:check', 'indexnow:key:generate', 'indexnow:submit', 'indexnow:submit-entity', 'indexnow:explain', 'indexnow:sitemap'], [...SitemapConfig::OPTIONS, 'messenger.transport', 'messenger.delay', 'messenger.stamps', 'messenger.bus', 'doctrine.enabled', 'doctrine.listener_priority', 'doctrine.connections', 'key_file.path', 'key_file.host', 'key_file.route_name', 'logging.channel', 'profiler.enabled', 'flush.priority', 'flush.console_priority']);
    }
}
