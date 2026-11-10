<?php

namespace Omnidns\Gandi\Tests;

use Omnidns\Gandi\GandiFactory;
use Omnidns\Gandi\GandiProvider;
use Omnidns\Exception\OmnidnsException;
use Omnidns\Exception\UsageLimitReachedException;
use Omnidns\Registry;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * GandiProvider's usage policy (policy.json) through omnidns's registry:
 * as declared, a site's limit counted with the call over it never made, a
 * 429 and its Retry-After holding every next call, a looser limit refused.
 * No network: recorded answers only.
 */
final class PolicyTest extends TestCase
{
    private const OPTIONS = ['token' => 'not-a-real-gandi-token'];

    private int $made = 0;

    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = $this->registry()->policy('dns');

        self::assertSame('gandi', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertFileExists(GandiProvider::POLICY);
        self::assertSame(['1000/minute/application/*'], null === $policy->rateLimits ? null : array_map(static fn ($l) => $l->id(), $policy->rateLimits));
        self::assertTrue($policy->requirements->key);
        self::assertSame($policy->provenance->terms, $this->registry()->get('dns')->capabilities()->terms[0] ?? null, 'the older field read from the policy');
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $registry = $this->registry(200, ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['records']]]]);

        $registry->dns('dns')->records('example.org');
        $made = $this->made;
        try {
            $registry->dns('dns')->records('example.org');
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnidnsException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/minute/application/records', $e->limit?->id());
        }
        self::assertSame($made, $this->made, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $registry = $this->registry(429);

        try {
            $registry->dns('dns')->records('example.org');
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('gandi answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
        }
        try {
            $registry->availability('dns')->check('example.org');
            self::fail('A call went out while the provider asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(119, $e->retryAfter);
        }
        self::assertSame(1, $this->made, 'the wait held the next request back');
    }

    public function testASiteCannotLoosenThePolicy(): void
    {
        $registry = $this->registry(200, ['rateLimits' => [['max' => 5000, 'window' => 'minute']]]);

        $this->expectException(InvalidPolicyException::class);
        $registry->dns('dns');
    }

    /** @param array<string, mixed> $policy */
    private function registry(int $status = 200, array $policy = []): Registry
    {
        $http = new MockHttpClient(function () use ($status): MockResponse {
            ++$this->made;

            return 429 === $status
                ? new MockResponse('{"message":"too many requests"}', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']])
                : new MockResponse('[]', ['http_code' => 200]);
        });

        return new Registry([new GandiFactory($http)], ['dns' => ['factory' => 'gandi', 'options' => self::OPTIONS] + ([] === $policy ? [] : ['policy' => $policy])]);
    }
}
