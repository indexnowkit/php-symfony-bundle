<?php

declare(strict_types=1);

use IndexNowKit\SymfonyBundle\Controller\KeyFileController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('indexnowkit_key_file', '/{key}.txt')
        ->controller(KeyFileController::class)
        ->requirements(['key' => '[A-Za-z0-9-]{8,128}'])
        ->methods(['GET']);
};
