<?php

namespace Omnidns\Gandi;

use Omnidns\Config;
use Omnidns\ProviderFactory;
use Omnidns\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gandi, from its options:
 *
 *   token       a personal access token (required), made in Gandi's admin with the rights it needs:
 *               "See and renew domain names", "Manage domain name technical configurations" (LiveDNS),
 *               "Register domain names" for a registration
 *   sharing_id  the organization billed and owning, when it is not the token's own
 *   endpoint    https://api.gandi.net/v5 (Gandi's sandbox: https://api.sandbox.gandi.net/v5)
 *   dry_run     true: a registration or a renewal is checked by Gandi and not made (Dry-Run: 1)
 */
final class GandiFactory extends ProviderFactory
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnidns.factory_name' => 'gandi',
            'omnidns.factory_title' => 'Gandi',
            'omnidns.required_options' => ['token'],
            'sharing_id' => '',
            'endpoint' => GandiProvider::ENDPOINT,
            'dry_run' => false,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new GandiProvider($this->http, (string) $c->string('token'), $c->string('sharing_id'), $c->string('endpoint') ?? GandiProvider::ENDPOINT, $c->bool('dry_run'));
    }
}
