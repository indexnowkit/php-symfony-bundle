<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

use IndexNowKit\Retry\WorkerOutcome;
use IndexNowKit\SubmitterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Retryable outcomes (429, 5xx, network) throw RecoverableMessageHandlingException so the transport's
 * retry strategy applies (with the engine's Retry-After as the delay on Symfony >= 7.2); final failures (400, 403,
 * 422) are logged at error and acknowledged, a retry would not help. `Retry\WorkerOutcome` does the sorting.
 */
#[AsMessageHandler]
final class SubmitUrlsHandler
{
    /**
     * @param MessageBusInterface|null $bus the bus the message came from: what was rejected temporarily is re-queued as a new,
     *                                      smaller message, so a retry never resends the URLs an engine already accepted
     */
    public function __construct(private readonly SubmitterInterface $submitter, private readonly LoggerInterface $logger = new NullLogger(), private readonly ?MessageBusInterface $bus = null) {}

    private static ?bool $retryDelaySupported = null;

    /** Symfony >= 7.2 accepts a retry delay (ms) as 4th constructor argument. */
    private static function supportsRetryDelay(): bool
    {
        return self::$retryDelaySupported ??= ((new ReflectionClass(RecoverableMessageHandlingException::class))->getConstructor()?->getNumberOfParameters() ?? 0) >= 4;
    }

    public function __invoke(SubmitUrlsMessage $message): void
    {
        $outcome = WorkerOutcome::of($this->submitter->submit($message->urls));
        if ($outcome->hasFinalFailures()) {
            $this->logger->error(...$outcome->finalLog($message->id, 'bin/console indexnow:check'));
        }
        if (!$outcome->hasRetryable()) {
            return;
        }
        $this->logger->info(...$outcome->retryLog($message->id));
        if ($this->bus !== null && \count($outcome->retryUrls) < \count($message->urls)) {
            // Part of the batch went through: only the rest comes back, as its own message (the transport would replay the whole one).
            $stamps = $outcome->retryAfter !== null && $outcome->retryAfter > 0 ? [new DelayStamp($outcome->retryAfter * 1000)] : [];
            $this->bus->dispatch(new SubmitUrlsMessage($outcome->retryUrls, $message->id), $stamps);

            return;
        }
        $text = \sprintf('IndexNow: %d URL(s) temporarily rejected (job %s)', \count($outcome->retryUrls), $message->id);
        if ($outcome->retryAfter !== null && $outcome->retryAfter > 0 && self::supportsRetryDelay()) {
            // @phpstan-ignore arguments.count (Symfony < 7.2 has no $retryDelay; supportsRetryDelay() guards the call)
            throw new RecoverableMessageHandlingException($text, 0, null, $outcome->retryAfter * 1000);
        }

        throw new RecoverableMessageHandlingException($text);
    }
}
