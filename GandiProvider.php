<?php

namespace Omnidns\Gandi;

use Omnidns\AvailabilityInterface;
use Omnidns\Capabilities;
use Omnidns\DnsInterface;
use Omnidns\DomainsInterface;
use Omnidns\Exception\InvalidArgumentException;
use Omnidns\Exception\ProviderException;
use Omnidns\Http\Json;
use Omnidns\Model\Availability;
use Omnidns\Model\Contact;
use Omnidns\Model\DomainInfo;
use Omnidns\Model\DomainName;
use Omnidns\Model\Money;
use Omnidns\Model\Order;
use Omnidns\Model\Record;
use Omnidns\NameserversInterface;
use Omnidns\RegistrarInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gandi's API v5: /domain for availability, orders, the domains and their
 * name servers; /livedns for the records. LiveDNS keeps a name and a type's
 * values together (an rrset) under one TTL: a record here is one value of
 * it, known by its name, type and content (Gandi gives no id); adding one
 * extends its rrset, deleting the last one removes it.
 */
final class GandiProvider implements AvailabilityInterface, RegistrarInterface, DomainsInterface, NameserversInterface, DnsInterface
{
    public const ENDPOINT = 'https://api.gandi.net/v5';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $token,
        private readonly ?string $sharingId = null,
        private readonly string $endpoint = self::ENDPOINT,
        private readonly bool $dryRun = false,
    ) {
    }

    public function getName(): string
    {
        return 'gandi';
    }

    public function getTitle(): string
    {
        return 'Gandi';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(true, true, true, true, true, Record::TYPES, [
            'https://www.gandi.net/en/contracts/terms-of-service',
            'https://api.gandi.net/docs/reference/',
        ]);
    }

    public function check(string $domain): Availability
    {
        $domain = DomainName::normalize($domain);
        $answer = $this->call('GET', '/domain/check', ['query' => ['name' => $domain]], "the availability of $domain");
        $product = Json::get($answer, 'products', 0);
        $currency = Json::string($answer, 'currency') ?? 'EUR';
        $status = Json::string($product, 'status') ?? 'unknown';
        $price = null;
        foreach (Json::list($product, 'prices') as $offer) {
            if (1 === (Json::int($offer, 'min_duration') ?? 1) && null !== $value = Json::string($offer, 'price_before_taxes')) {
                $price = Money::fromDecimal($value, $currency);
                break;
            }
        }
        $available = 'available' === $status;

        return new Availability($domain, $available, $available ? $price : null, null, str_contains($status, 'premium') || 'premium' === Json::string($product, 'process'), $available ? null : $status);
    }

    public function register(string $domain, int $years = 1, ?Contact $owner = null): Order
    {
        $domain = DomainName::normalize($domain);
        self::years($years);
        if (null === $owner || '' === $owner->email) {
            throw new InvalidArgumentException('Gandi needs the owner in full to register a domain (Contact: names, e-mail, phone, address, country, type).');
        }
        $answer = $this->call('POST', '/domain/domains', ['json' => ['fqdn' => $domain, 'duration' => $years, 'owner' => array_filter([
            'type' => $owner->type,
            'given' => $owner->firstName,
            'family' => $owner->lastName,
            'orgname' => $owner->organization,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'streetaddr' => $owner->street,
            'city' => $owner->city,
            'zip' => $owner->zip,
            'country' => strtoupper($owner->country),
        ], static fn ($v) => null !== $v && '' !== $v)]], "the registration of $domain");

        return new Order($domain, Order::REGISTER, $this->dryRun ? 'checked (dry run)' : (Json::string($answer, 'message') ?? 'accepted'), years: $years);
    }

    public function renew(string $domain, int $years = 1): Order
    {
        $domain = DomainName::normalize($domain);
        self::years($years);
        $answer = $this->call('POST', "/domain/domains/$domain/renew", ['json' => ['duration' => $years]], "the renewal of $domain");

        return new Order($domain, Order::RENEW, $this->dryRun ? 'checked (dry run)' : (Json::string($answer, 'message') ?? 'accepted'), years: $years);
    }

    public function domains(): array
    {
        $domains = [];
        for ($page = 1; ; ++$page) {
            $list = Json::list($this->call('GET', '/domain/domains', ['query' => ['page' => $page, 'per_page' => 100]], 'the domains'));
            foreach ($list as $item) {
                if (null !== $fqdn = Json::string($item, 'fqdn')) {
                    $domains[] = $fqdn;
                }
            }
            if (\count($list) < 100) {
                return $domains;
            }
        }
    }

    public function domain(string $domain): DomainInfo
    {
        $domain = DomainName::normalize($domain);
        $data = $this->call('GET', "/domain/domains/$domain", [], "the domain $domain");
        $auto = Json::get($data, 'autorenew');

        return new DomainInfo(
            $domain,
            Json::date($data, 'dates', 'registry_ends_at'),
            \is_bool($auto) ? $auto : (\is_array($auto) && \is_bool($auto['enabled'] ?? null) ? $auto['enabled'] : null),
            array_values(array_filter(Json::list($data, 'status'), 'is_string')),
            array_values(array_map(static fn (string $ns) => rtrim(strtolower($ns), '.'), array_filter(Json::list($data, 'nameservers'), 'is_string'))),
            Json::date($data, 'dates', 'registry_created_at'),
        );
    }

    public function nameservers(string $domain): array
    {
        $domain = DomainName::normalize($domain);

        return array_values(array_map(static fn (string $ns) => rtrim(strtolower($ns), '.'), array_filter(Json::list($this->call('GET', "/domain/domains/$domain/nameservers", [], "the name servers of $domain")), 'is_string')));
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $domain = DomainName::normalize($domain);
        $this->call('PUT', "/domain/domains/$domain/nameservers", ['json' => ['nameservers' => array_map(static fn (string $ns) => rtrim(strtolower($ns), '.'), $nameservers)]], "the name servers of $domain");
    }

    public function records(string $zone, ?string $type = null, ?string $name = null): array
    {
        $zone = DomainName::normalize($zone);
        $records = [];
        foreach (Json::list($this->call('GET', "/livedns/domains/$zone/records", null === $type ? [] : ['query' => ['rrset_type' => strtoupper($type)]], "the records of $zone")) as $rrset) {
            $rrsetType = (string) Json::string($rrset, 'rrset_type');
            $rrsetName = (string) Json::string($rrset, 'rrset_name');
            if (!\in_array($rrsetType, Record::TYPES, true) || (null !== $type && strtoupper($type) !== $rrsetType)) {
                continue;
            }
            foreach (Json::list($rrset, 'rrset_values') as $value) {
                $record = Record::fromValue($rrsetType, $rrsetName, \is_scalar($value) ? (string) $value : '', Json::int($rrset, 'rrset_ttl'));
                if (null === $name || $record->name === Record::normalizeName($name)) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    public function add(string $zone, Record $record): Record
    {
        $zone = DomainName::normalize($zone);
        $values = $this->values($zone, $record->type, $record->name);
        if (null === $values) {
            $this->call('POST', "/livedns/domains/$zone/records", ['json' => array_filter(['rrset_name' => self::name($record), 'rrset_type' => $record->type, 'rrset_values' => [$record->value()], 'rrset_ttl' => $record->ttl], static fn ($v) => null !== $v)], "a record of $zone");
        } else {
            $this->put($zone, $record->type, $record->name, [...$values['values'], $record->value()], $record->ttl ?? $values['ttl']);
        }

        return $record;
    }

    public function update(string $zone, Record $current, Record $new): Record
    {
        $zone = DomainName::normalize($zone);
        if ($current->type !== $new->type || $current->name !== $new->name) {
            $this->delete($zone, $current);

            return $this->add($zone, $new);
        }
        $values = $this->values($zone, $current->type, $current->name);
        $found = false;
        $list = [];
        foreach ($values['values'] ?? [] as $value) {
            if (!$found && $current->sameAs(Record::fromValue($current->type, $current->name, $value))) {
                $found = true;
                $value = $new->value();
            }
            $list[] = $value;
        }
        if (!$found) {
            throw ProviderException::answered('Gandi', "a record of $zone", 'no such record');
        }
        $this->put($zone, $new->type, $new->name, $list, $new->ttl ?? $values['ttl'] ?? null);

        return $new;
    }

    public function delete(string $zone, Record $record): void
    {
        $zone = DomainName::normalize($zone);
        $values = $this->values($zone, $record->type, $record->name);
        $left = array_values(array_filter($values['values'] ?? [], static fn (string $value) => !$record->sameAs(Record::fromValue($record->type, $record->name, $value))));
        if (null === $values || \count($left) === \count($values['values'])) {
            throw ProviderException::answered('Gandi', "a record of $zone", 'no such record');
        }
        $path = "/livedns/domains/$zone/records/".rawurlencode(self::name($record)).'/'.$record->type;
        if ([] === $left) {
            $this->call('DELETE', $path, [], "a record of $zone");
        } else {
            $this->put($zone, $record->type, $record->name, $left, $values['ttl']);
        }
    }

    /** @return array{values: list<string>, ttl: ?int}|null the rrset's values and TTL; null when there is none */
    private function values(string $zone, string $type, string $name): ?array
    {
        $rrset = $this->call('GET', "/livedns/domains/$zone/records/".rawurlencode('' === $name ? '@' : $name)."/$type", [], "a record of $zone", allowMissing: true);
        if (null === $rrset) {
            return null;
        }

        return ['values' => array_map(static fn ($v) => \is_scalar($v) ? (string) $v : '', Json::list($rrset, 'rrset_values')), 'ttl' => Json::int($rrset, 'rrset_ttl')];
    }

    /** @param list<string> $values */
    private function put(string $zone, string $type, string $name, array $values, ?int $ttl): void
    {
        $this->call('PUT', "/livedns/domains/$zone/records/".rawurlencode('' === $name ? '@' : $name)."/$type", ['json' => array_filter(['rrset_values' => $values, 'rrset_ttl' => $ttl], static fn ($v) => null !== $v)], "a record of $zone");
    }

    private static function name(Record $record): string
    {
        return '' === $record->name ? '@' : $record->name;
    }

    private static function years(int $years): void
    {
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException(\sprintf('A domain is registered or renewed for 1 to 10 years, not %d.', $years));
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return ($allowMissing is true ? array<mixed>|null : array<mixed>)
     */
    private function call(string $method, string $path, array $options, string $what, bool $allowMissing = false): ?array
    {
        $headers = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
        if ($this->dryRun && 'POST' === $method && str_starts_with($path, '/domain/domains')) {
            $headers['Dry-Run'] = '1';
        }
        if (null !== $this->sharingId && str_starts_with($path, '/domain/')) {
            $options['query'] = (\is_array($options['query'] ?? null) ? $options['query'] : []) + ['sharing_id' => $this->sharingId];
        }
        try {
            return Json::request($this->http, $method, rtrim($this->endpoint, '/').$path, ['headers' => $headers] + $options, 'Gandi', $what);
        } catch (ProviderException $e) {
            if ($allowMissing && 404 === $e->getCode()) {
                return null;
            }
            throw $e;
        }
    }
}
