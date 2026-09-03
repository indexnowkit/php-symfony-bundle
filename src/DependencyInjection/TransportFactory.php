<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportInterface;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the transport from `http.client`: a PSR-18 service is used as is, a symfony/http-client service
 * (including scoped clients) is wrapped in Psr18Client, null means discovery.
 */
final class TransportFactory
{
    private function __construct() {}

    /**
     * @throws ConfigurationException when the service is neither, or when nothing can be discovered
     */
    public static function create(?object $client, float $timeout): TransportInterface
    {
        if ($client !== null && !$client instanceof ClientInterface) {
            if ($client instanceof HttpClientInterface && class_exists(Psr18Client::class)) {
                $client = new Psr18Client($client);
            } else {
                throw new ConfigurationException(\sprintf('indexnowkit.http.client must be a PSR-18 client or a symfony/http-client service, got %s.', $client::class));
            }
        }

        return Psr18Transport::discover($client, $timeout);
    }
}
