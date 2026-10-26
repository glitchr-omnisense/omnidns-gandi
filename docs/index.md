# omnidns/gandi

Gandi for [omnisense/omnidns](https://github.com/glitchr-omnisense/omnidns): whether a domain is
free and its price, registering and renewing it, the account's domains and their terms, their name
servers, and LiveDNS records - Gandi's API v5 (`https://api.gandi.net/v5`, or the sandbox
`https://api.sandbox.gandi.net/v5`) on the application's HTTP client. No maintained
framework-free PHP client of Gandi's API v5 was found; this one is a handful of calls.

| Operation | Gandi API v5 |
|---|---|
| `check()` | `GET /domain/check?name=` |
| `register()` | `POST /domain/domains` |
| `renew()` | `POST /domain/domains/{name}/renew` |
| `domains()`, `domain()` | `GET /domain/domains` (100 a page), `GET /domain/domains/{name}` |
| `nameservers()`, `setNameservers()` | `GET`, `PUT /domain/domains/{name}/nameservers` |
| `records()` | `GET /livedns/domains/{zone}/records` |
| `add()`, `update()`, `delete()` | `GET`, `POST`, `PUT`, `DELETE /livedns/domains/{zone}/records[/{name}/{type}]` |

## Installation

```sh
composer require omnidns/gandi symfony/http-client
```

Requires PHP 8.2, `omnisense/omnidns` (1.x) and `symfony/http-client-contracts` (^3.0): the
application gives the HTTP client to `GandiFactory`'s constructor.

## Credentials

A **personal access token** made in Gandi's admin, with the rights it needs: "See and renew
domain names", "Manage domain name technical configurations" (LiveDNS), "Register domain names"
for a registration. A sandbox token for the sandbox endpoint. The organization billed, when it is
not the token's own, is its **sharing id**.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `token` | string | - | yes | sent as `Authorization: Bearer` |
| `sharing_id` | string | none | no | added as `sharing_id` to every `/domain/` call |
| `endpoint` | string | `https://api.gandi.net/v5` | no | the sandbox: `https://api.sandbox.gandi.net/v5` |
| `dry_run` | bool | `false` | no | `Dry-Run: 1` on every `POST /domain/domains...`: Gandi checks a registration or a renewal and makes nothing |

## Capabilities

| Contract | Supported |
|---|---|
| `AvailabilityInterface` | yes, priced (first year, before taxes) |
| `RegistrarInterface` | yes |
| `DomainsInterface` | yes, name servers in the same answer |
| `NameserversInterface` | yes |
| `DnsInterface` | yes (LiveDNS); ALIAS, DNAME and the other types outside omnidns's left out |

Terms: Gandi's terms of service, the API reference (rate limited per token).

## Behaviour

**Availability.** The first product of the answer: free when its status is `available`; the price
the first offer with a minimum duration of 1 year, `price_before_taxes` in the answer's currency
(EUR when none); premium when the status contains `premium` or the process is `premium`; the
status as the reason when not free. No renewal price.

**Registration.** The owner in full is required (an empty e-mail or none is an
`InvalidArgumentException`): `type`, `given`, `family`, `orgname`, `email`, `phone`,
`streetaddr`, `city`, `zip`, `country` (upper case), the empty ones left out; `fqdn` and
`duration`. Gandi bills the account's prepaid balance or means of payment. The `Order` has
Gandi's `message` as its status (else `accepted`), or `checked (dry run)`; no id, no price.

**Renewal.** `duration`; the same statuses.

**Domains.** Pages of 100 until one comes back shorter. Each domain: `dates.registry_ends_at`,
`autorenew` (a bool, or its `enabled`), `status`, `nameservers` (lower case, no trailing dot),
`dates.registry_created_at`.

**Records.** LiveDNS keeps a name and a type's values together (an rrset) under one TTL: a record
here is one value, under the rrset's TTL; Gandi gives no id, so a record is known by its name,
type and content. `records()` filters by `rrset_type` and, locally, by name; an MX's priority is
split from its value. `add()` creates the rrset (`POST`) or extends it (`PUT`, its TTL kept unless
the record gives one); `update()` replaces the value in its rrset - a change of name or type
deletes and adds; `delete()` removes the value (`PUT`) or, with the last one, the rrset
(`DELETE`). The apex is `@` in Gandi's paths. A value not found is a `ProviderException` (`no such
record`).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnidns\Gandi\GandiFactory;
use Omnidns\Model\Contact;
use Omnidns\Model\Record;
use Omnidns\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new GandiFactory(HttpClient::create(['timeout' => 20]))], [
    'gandi' => ['factory' => 'gandi', 'options' => [
        'token' => getenv('GANDI_TOKEN'),
        'endpoint' => 'https://api.sandbox.gandi.net/v5',
        'dry_run' => true,
    ]],
]);

$owner = new Contact('Camille', 'Durand', 'camille@example.org', '+33.612345678', '1 rue de Rivoli', 'Paris', '75001', 'FR');
echo $registry->registrar('gandi')->register('example.fr', 1, $owner)->status, "\n";   // checked (dry run)

$dns = $registry->dns('gandi');
$dns->add('example.fr', Record::fromValue('MX', '', '10 spool.mail.gandi.net.'));
foreach ($dns->records('example.fr', 'MX') as $mx) {
    echo $mx->priority, ' ', $mx->content, "\n";
}
```

Not run live (no account); the error mapping was run on a recorded 404 on 2026-10-26
(`Gandi answered 404 (The resource could not be found.) for the domain example.org.`, code 404).

## Errors

- A status of 300 or more: `ProviderException` `Gandi answered <status> (<Gandi's message>) for
  <what>.`, the status as its code (a 404 on a record lookup is read as "no rrset" instead).
- A network failure: `UnreachableException`.
- A duration outside 1 to 10 years, an owner not in full: `InvalidArgumentException`.

## Verified, and not

`Tests/GandiProviderTest.php`, on recorded answers written from Gandi's API v5 reference (not
captured): a free and a taken domain, a registration with its owner, `Dry-Run` and `sharing_id`, a
renewal, the domains and their terms, the name servers, rrsets as records, an rrset created,
extended, changed, shrunk and deleted, a record Gandi does not hold, an error's message. Green in
the family's harness on 2026-10-26. **Not run live**: no Gandi account at hand; Gandi's sandbox is
the place for the first check.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Gandi SAS. Whoever runs it complies with Gandi's [terms of
service](https://www.gandi.net/en/contracts/terms-of-service) and pays the orders it places; see
the README's Responsibility section.
