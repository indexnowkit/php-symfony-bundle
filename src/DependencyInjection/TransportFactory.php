<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportFactory as CoreTransportFactory;
use IndexNowKit\Http\TransportInterface;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the transport from `http.client`: a PSR-18 service is used as is, a symfony/http-client service
 * (including scoped clients) is wrapped in Psr18Client, null means discovery. The service id is a compile-time fact
 * of the container, so the core's `TransportFactory::lazy()` (which resolves the id at runtime) is not used; its
 * `psr18()` check is.
 */
final class TransportFactory
{
    private function __construct() {}

    /**
     * @throws ConfigurationException when the service is neither, or when nothing can be discovered
     */
    public static function create(?object $client, float $timeout, string $id = 'indexnowkit.http.client'): TransportInterface
    {
        if ($client instanceof HttpClientInterface && !$client instanceof ClientInterface && class_exists(Psr18Client::class)) {
            $client = new Psr18Client($client);
        }

        return Psr18Transport::discover($client === null ? null : CoreTransportFactory::psr18($client, $id), $timeout);
    }
}
