<?php

declare(strict_types=1);

use IndexNowKit\Key\KeyValidator;
use IndexNowKit\SymfonyBundle\Controller\KeyFileController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Path and host come from indexnowkit.key_file.{path,host}; parameters are resolved by the router.
return static function (RoutingConfigurator $routes): void {
    $route = $routes->add('indexnowkit_key_file', '%indexnowkit.key_file.path%')
        ->controller(KeyFileController::class)
        ->requirements(['key' => '[' . KeyValidator::ALPHABET . ']{' . KeyValidator::MIN_LENGTH . ',' . KeyValidator::MAX_LENGTH . '}'])
        ->methods(['GET']);
    $route->host('%indexnowkit.key_file.host%');
};
