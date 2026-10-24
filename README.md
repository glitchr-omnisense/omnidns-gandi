# omnidns/gandi

Gandi for [omniscient/omnidns](https://github.com/glitchr-studio/omnidns), under any PHP host:
whether a domain is free and its price, registering and renewing it, the account's domains and
their terms, their name servers, and LiveDNS records - Gandi's API v5, on the application's HTTP
client (any `symfony/http-client-contracts` implementation).

```php
use Omnidns\Gandi\GandiFactory;
use Omnidns\Registry;

$registry = new Registry([new GandiFactory($httpClient)], [
    'gandi' => ['factory' => 'gandi', 'options' => ['token' => $personalAccessToken]],
]);
$registry->dns('gandi')->records('example.fr', 'MX');
```

Options: `token` (required: a personal access token with the rights it needs - see and renew
domains, manage technical configurations, register), `sharing_id` (the organization, when not the
token's own), `endpoint` (`https://api.gandi.net/v5`; Gandi's sandbox
`https://api.sandbox.gandi.net/v5`), `dry_run` (true: Gandi checks a registration or a renewal
and makes nothing - `Dry-Run: 1`).

**Reuse**: no maintained framework-free PHP client of Gandi's API v5 was found; this one is a
handful of calls on the application's client.

- **Availability**: `/domain/check`, the price for one year before taxes in the account's
  currency.
- **Registration**: `/domain/domains` with the owner in full (`Contact`: names, e-mail, phone,
  address, country, type); Gandi bills the account's prepaid balance or means of payment.
- **Renewal**: `/domain/domains/{name}/renew`.
- **Domains**: `/domain/domains` (100 a page), each one's term, auto-renewal, statuses and name
  servers.
- **Records**: LiveDNS keeps a name and a type's values together (an rrset) under one TTL: a
  record here is one value; adding one extends its rrset (its TTL kept), deleting the last one
  removes the rrset. Gandi gives no record id: a record is found by name, type and content. Types
  outside omnidns's (ALIAS, DNAME...) are left out.

## Terms

Gandi's [terms of service](https://www.gandi.net/en/contracts/terms-of-service) and its
[API reference](https://api.gandi.net/docs/reference/) (rate limited per token: cache what you
read). A registration or a renewal is a purchase from Gandi.

## Verified, and not

- Verified on recorded answers, written from Gandi's API v5 reference (not captured): a free and
  a taken domain, a registration with its owner, `Dry-Run` and `sharing_id`, a renewal, the
  domains and their terms, the name servers, rrsets as records, an rrset created, extended,
  changed, shrunk and deleted, a record Gandi does not hold, an error's message; `BareTest`;
  PHPStan at the highest level.
- **Not verified live**: no Gandi account at hand. Gandi's sandbox (`endpoint`, with a sandbox
  token) is the place for the first check.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Gandi SAS. It reaches their service on behalf of whoever runs it; its MIT licence covers this
package's code only and grants no right to the service or its data. Whoever installs and runs it
is responsible for complying with Gandi's terms, for paying the orders it places, for obtaining what
they require (an account, a key, a contract), and for any use made of what it returns. The authors
publish it free of charge and accept no liability for how it is used (see [LICENSE](LICENSE)).

## Licence

MIT.
