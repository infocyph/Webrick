<?php

declare(strict_types=1);

namespace Infocyph\Webrick;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;

/**
 * Webrick composition-root contribution helpers.
 *
 * The host owns the ContainerBuilder and environment. Webrick only contributes
 * explicitly requested definitions/providers to that existing graph.
 */
final class Webrick
{
    private function __construct() {}

    /**
     * @param array<int,class-string<ServiceProviderInterface>|ServiceProviderInterface> $providers
     */
    public static function contributeTo(ContainerBuilder $builder, array $providers = []): ContainerBuilder
    {
        if ($providers === []) {
            return $builder;
        }

        $registration = $builder->registration();
        foreach ($providers as $provider) {
            $registration->import($provider);
        }

        return $builder;
    }

    /**
     * Explicit standalone development convenience. Production never uses this
     * path and must receive a host-selected compiled InterMix runtime.
     *
     * @param array<int,class-string<ServiceProviderInterface>|ServiceProviderInterface> $providers
     */
    public static function standaloneDevelopment(string $environment = 'dev', array $providers = []): ContainerBuilder
    {
        $builder = ContainerBuilder::create('webrick.standalone')->setEnvironment($environment);

        return self::contributeTo($builder, $providers);
    }
}
