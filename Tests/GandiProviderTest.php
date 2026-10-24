<?php

namespace Omnidns\Gandi\Tests;

use Omnidns\Exception\InvalidArgumentException;
use Omnidns\Exception\ProviderException;
use Omnidns\Gandi\GandiFactory;
use Omnidns\Gandi\GandiProvider;
use Omnidns\Model\Contact;
use Omnidns\Model\Record;
use Omnidns\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Gandi's answers recorded, written from its API v5 reference (no account
 * at hand: not captured), each request checked: the path, the bearer token,
 * the body, Dry-Run and sharing_id when asked.
 */
final class GandiProviderTest extends TestCase
{
    private Router $router;

    public function testAFreeDomainAndItsPrice(): void
    {
        $availability = $this->provider(['GET /domain/check?name=omnidns-test.fr' => ['currency' => 'EUR', 'grid' => 'A', 'products' => [['name' => 'omnidns-test.fr', 'status' => 'available', 'process' => 'create', 'taxes' => [['type' => 'service', 'rate' => 20, 'name' => 'vat']], 'prices' => [['max_duration' => 10, 'min_duration' => 1, 'duration_unit' => 'y', 'price_after_taxes' => 14.4, 'price_before_taxes' => 12, 'discount' => false, 'normal_price_after_taxes' => 14.4, 'normal_price_before_taxes' => 12]]]], 'taxes' => [['type' => 'service', 'rate' => 20, 'name' => 'vat']]]])->check('omnidns-test.fr');

        self::assertTrue($availability->available);
        self::assertSame(['12.00', 'EUR'], [$availability->price?->decimal(), $availability->price?->currency], 'before taxes');
        self::assertContains('Authorization: Bearer pat', $this->router->requests[0]['headers']);
    }

    public function testATakenDomain(): void
    {
        $availability = $this->provider(['GET /domain/check?name=gandi.net' => ['currency' => 'EUR', 'products' => [['name' => 'gandi.net', 'status' => 'unavailable', 'process' => 'create']]]])->check('gandi.net');

        self::assertSame([false, 'unavailable', null], [$availability->available, $availability->reason, $availability->price]);
    }

    public function testARegistrationWithItsOwnerAndADryRun(): void
    {
        $provider = $this->provider(['POST /domain/domains?sharing_id=org-1' => [202, ['message' => 'Confirmation']]], dryRun: true, sharingId: 'org-1');
        $order = $provider->register('omnidns-test.fr', 2, new Contact('Ada', 'Lovelace', 'ada@example.org', '+33.123456789', '1 rue de la Paix', 'Paris', '75002', 'fr'));

        self::assertSame(['register', 'checked (dry run)', 2], [$order->action, $order->status, $order->years]);
        self::assertContains('Dry-Run: 1', $this->router->requests[0]['headers']);
        self::assertSame('{"fqdn":"omnidns-test.fr","duration":2,"owner":{"type":"individual","given":"Ada","family":"Lovelace","email":"ada@example.org","phone":"+33.123456789","streetaddr":"1 rue de la Paix","city":"Paris","zip":"75002","country":"FR"}}', $this->router->body('POST /domain/domains?sharing_id=org-1'));
    }

    public function testARegistrationNeedsTheOwner(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provider([])->register('omnidns-test.fr');
    }

    public function testARenewal(): void
    {
        $order = $this->provider(['POST /domain/domains/example.fr/renew' => [202, ['message' => 'Confirmation']]])->renew('example.fr', 3);

        self::assertSame(['renew', 'Confirmation', 3], [$order->action, $order->status, $order->years]);
        self::assertSame('{"duration":3}', $this->router->body('POST /domain/domains/example.fr/renew'));
    }

    public function testTheDomainsAndTheirTerms(): void
    {
        $provider = $this->provider([
            'GET /domain/domains?page=1&per_page=100' => [['fqdn' => 'example.fr', 'tld' => 'fr', 'dates' => ['registry_ends_at' => '2027-03-01T10:00:00Z']], ['fqdn' => 'example.com', 'tld' => 'com']],
            'GET /domain/domains/example.fr' => ['fqdn' => 'example.fr', 'status' => ['clientTransferProhibited'], 'dates' => ['registry_created_at' => '2020-03-01T10:00:00Z', 'registry_ends_at' => '2027-03-01T10:00:00Z'], 'autorenew' => ['enabled' => true, 'duration' => 1], 'nameservers' => ['ns-1-a.gandi.net', 'ns-2-b.gandi.net']],
        ]);

        self::assertSame(['example.fr', 'example.com'], $provider->domains());
        $info = $provider->domain('example.fr');
        self::assertSame(['2027-03-01', true, ['clientTransferProhibited'], ['ns-1-a.gandi.net', 'ns-2-b.gandi.net']], [$info->expiresAt?->format('Y-m-d'), $info->autoRenew, $info->status, $info->nameservers]);
    }

    public function testTheNameServers(): void
    {
        $provider = $this->provider([
            'GET /domain/domains/example.fr/nameservers' => ['ns-1-a.gandi.net.', 'ns-2-b.gandi.net'],
            'PUT /domain/domains/example.fr/nameservers' => [202, ['message' => 'Nameservers updated']],
        ]);

        self::assertSame(['ns-1-a.gandi.net', 'ns-2-b.gandi.net'], $provider->nameservers('example.fr'));
        $provider->setNameservers('example.fr', ['NS1.example.net.']);
        self::assertSame('{"nameservers":["ns1.example.net"]}', $this->router->body('PUT /domain/domains/example.fr/nameservers'));
    }

    public function testTheRrsetsAsRecords(): void
    {
        $records = $this->provider(['GET /livedns/domains/example.fr/records' => self::zone()])->records('example.fr');

        self::assertSame([['A', '', '192.0.2.1', 300], ['MX', '', 'spool.mail.gandi.net.', 10800], ['MX', '', 'fb.mail.gandi.net.', 10800], ['TXT', '', '"v=spf1 include:_mailcust.gandi.net ?all"', 10800]], array_map(static fn (Record $r) => [$r->type, $r->name, $r->content, $r->ttl], $records));
        self::assertSame([10, 50], [$records[1]->priority, $records[2]->priority]);
        self::assertNull($records[0]->id, 'Gandi gives no id');
    }

    public function testTheRecordsOfOneNameAndType(): void
    {
        $records = $this->provider(['GET /livedns/domains/example.fr/records?rrset_type=MX' => [self::zone()[1]]])->records('example.fr', 'MX', '@');

        self::assertCount(2, $records);
    }

    public function testARecordAddedToANewRrsetAndToAnExistingOne(): void
    {
        $provider = $this->provider([
            'GET /livedns/domains/example.fr/records/_dmarc/TXT' => [404, ['code' => 404, 'message' => 'Unable to find the record', 'object' => 'HTTPNotFound', 'cause' => 'Not Found']],
            'POST /livedns/domains/example.fr/records' => [201, ['message' => 'DNS Record Created']],
            'GET /livedns/domains/example.fr/records/%40/MX' => self::zone()[1],
            'PUT /livedns/domains/example.fr/records/%40/MX' => [201, ['message' => 'DNS Record Created']],
        ]);

        $provider->add('example.fr', new Record('TXT', '_dmarc', '"v=DMARC1; p=none"', 300));
        self::assertSame('{"rrset_name":"_dmarc","rrset_type":"TXT","rrset_values":["\u0022v=DMARC1; p=none\u0022"],"rrset_ttl":300}', $this->router->body('POST /livedns/domains/example.fr/records'));

        $provider->add('example.fr', new Record('MX', '', 'backup.example.net.', priority: 90));
        self::assertSame('{"rrset_values":["10 spool.mail.gandi.net.","50 fb.mail.gandi.net.","90 backup.example.net."],"rrset_ttl":10800}', $this->router->body('PUT /livedns/domains/example.fr/records/%40/MX'), 'the rrset extended, its TTL kept');
    }

    public function testAValueReplacedThenRemovedThenTheLastOneDeleted(): void
    {
        $provider = $this->provider([
            'GET /livedns/domains/example.fr/records/%40/MX' => self::zone()[1],
            'PUT /livedns/domains/example.fr/records/%40/MX' => [201, ['message' => 'DNS Record Created']],
            'GET /livedns/domains/example.fr/records/%40/A' => self::zone()[0],
            'DELETE /livedns/domains/example.fr/records/%40/A' => [204, null],
        ]);

        $provider->update('example.fr', new Record('MX', '', 'fb.mail.gandi.net', priority: 50), new Record('MX', '', 'fb2.mail.gandi.net.', priority: 50));
        self::assertSame('{"rrset_values":["10 spool.mail.gandi.net.","50 fb2.mail.gandi.net."],"rrset_ttl":10800}', $this->router->body('PUT /livedns/domains/example.fr/records/%40/MX'));

        $provider->delete('example.fr', new Record('MX', '', 'spool.mail.gandi.net.', priority: 10));
        self::assertSame('{"rrset_values":["50 fb.mail.gandi.net."],"rrset_ttl":10800}', $this->router->body('PUT /livedns/domains/example.fr/records/%40/MX'));

        $provider->delete('example.fr', new Record('A', '', '192.0.2.1'));
        self::assertContains('DELETE /livedns/domains/example.fr/records/%40/A', $this->router->calls(), 'the last value: the rrset deleted');
    }

    public function testARecordGandiDoesNotHold(): void
    {
        $provider = $this->provider(['GET /livedns/domains/example.fr/records/%40/A' => self::zone()[0]]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Gandi answered no such record for a record of example.fr.');
        $provider->delete('example.fr', new Record('A', '', '192.0.2.99'));
    }

    public function testAnErrorGandiAnswers(): void
    {
        $provider = $this->provider(['GET /domain/domains/nowhere.fr' => [403, ['code' => 403, 'message' => 'Access was denied to this resource.', 'object' => 'HTTPForbidden', 'cause' => 'Forbidden']]]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Gandi answered 403 (Access was denied to this resource.) for the domain nowhere.fr.');
        $provider->domain('nowhere.fr');
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new GandiFactory((new Router([], ''))->client())], ['gandi' => ['factory' => 'gandi', 'options' => ['token' => 'pat']]]);

        self::assertInstanceOf(GandiProvider::class, $registry->get('gandi'));
    }

    /** @return list<array<string, mixed>> */
    private static function zone(): array
    {
        return [
            ['rrset_name' => '@', 'rrset_type' => 'A', 'rrset_ttl' => 300, 'rrset_values' => ['192.0.2.1'], 'rrset_href' => 'https://api.gandi.net/v5/livedns/domains/example.fr/records/%40/A'],
            ['rrset_name' => '@', 'rrset_type' => 'MX', 'rrset_ttl' => 10800, 'rrset_values' => ['10 spool.mail.gandi.net.', '50 fb.mail.gandi.net.'], 'rrset_href' => 'https://api.gandi.net/v5/livedns/domains/example.fr/records/%40/MX'],
            ['rrset_name' => '@', 'rrset_type' => 'TXT', 'rrset_ttl' => 10800, 'rrset_values' => ['"v=spf1 include:_mailcust.gandi.net ?all"'], 'rrset_href' => 'https://api.gandi.net/v5/livedns/domains/example.fr/records/%40/TXT'],
            ['rrset_name' => 'webmail', 'rrset_type' => 'ALIAS', 'rrset_ttl' => 10800, 'rrset_values' => ['webmail.gandi.net.'], 'rrset_href' => 'https://api.gandi.net/v5/livedns/domains/example.fr/records/webmail/ALIAS'],
        ];
    }

    /** @param array<string, mixed> $routes */
    private function provider(array $routes, bool $dryRun = false, ?string $sharingId = null): GandiProvider
    {
        $this->router = new Router($routes, GandiProvider::ENDPOINT);

        return new GandiProvider($this->router->client(), 'pat', $sharingId, dryRun: $dryRun);
    }
}
