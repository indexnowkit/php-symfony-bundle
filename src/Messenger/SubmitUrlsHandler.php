<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

use IndexNowKit\Result;
use IndexNowKit\SubmitterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Retryable outcomes (429, 5xx, network) throw RecoverableMessageHandlingException so the transport's
 * retry strategy applies; everything else is final and only logged.
 */
#[AsMessageHandler]
final class SubmitUrlsHandler
{
    public function __construct(private readonly SubmitterInterface $submitter, private readonly LoggerInterface $logger = new NullLogger()) {}

    private static ?bool $retryDelaySupported = null;

    /** Symfony >= 7.2 accepts a retry delay (ms) as 4th constructor argument. */
    private static function supportsRetryDelay(): bool
    {
        return self::$retryDelaySupported ??= ((new ReflectionClass(RecoverableMessageHandlingException::class))->getConstructor()?->getNumberOfParameters() ?? 0) >= 4;
    }

    public function __invoke(SubmitUrlsMessage $message): void
    {
        $results = $this->submitter->submit($message->urls);
        $retryUrls = Result::retryableUrls($results);
        $retryAfter = null;
        foreach ($results as $result) {
            if ($result->retryable && $result->retryAfter !== null) {
                $retryAfter = max($retryAfter ?? 0, $result->retryAfter);
            }
        }
        if ($retryUrls !== []) {
            $this->logger->info('indexnow: {count} URL(s) will be retried', ['count' => \count($retryUrls)]);
            $message = \sprintf('IndexNow: %d URL(s) temporarily rejected', \count($retryUrls));
            if ($retryAfter !== null && $retryAfter > 0 && self::supportsRetryDelay()) {
                throw new RecoverableMessageHandlingException($message, 0, null, $retryAfter * 1000);
            }

            throw new RecoverableMessageHandlingException($message);
        }
    }
}
