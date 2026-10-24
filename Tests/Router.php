<?php

namespace Omnidns\Gandi\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Recorded answers by "METHOD /path?query", each request kept. */
final class Router
{
    /** @var list<array{call: string, headers: list<string>, body: string}> */
    public array $requests = [];

    /** @param array<string, mixed> $routes an answer, or [status, answer] */
    public function __construct(private readonly array $routes, private readonly string $base)
    {
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $call = $method.' '.substr($url, \strlen($this->base));
            $headers = \is_array($options['headers'] ?? null) ? array_values(array_filter($options['headers'], 'is_string')) : [];
            $this->requests[] = ['call' => $call, 'headers' => $headers, 'body' => \is_string($options['body'] ?? null) ? $options['body'] : ''];
            if (!\array_key_exists($call, $this->routes)) {
                return new MockResponse('{"message":"not recorded: '.$call.'"}', ['http_code' => 404]);
            }
            $answer = $this->routes[$call];
            $status = 200;
            if (\is_array($answer) && [0, 1] === array_keys($answer) && \is_int($answer[0]) && $answer[0] >= 200 && (null === $answer[1] || \is_array($answer[1]))) {
                [$status, $answer] = $answer;
            }

            return new MockResponse(null === $answer ? '' : (string) json_encode($answer), ['http_code' => $status]);
        });
    }

    /** @return list<string> */
    public function calls(): array
    {
        return array_map(static fn (array $r) => $r['call'], $this->requests);
    }

    public function body(string $call): string
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request['call'] === $call) {
                return $request['body'];
            }
        }

        return '';
    }
}
