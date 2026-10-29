<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Breach;

use GlpiPlugin\Grcmanager\Services\Breach\HibpClient;
use PHPUnit\Framework\TestCase;

/**
 * Issue #183 : client Have I Been Pwned sur des réponses rejouées (aucun appel extérieur).
 */
final class HibpClientTest extends TestCase
{
    /** @var list<array{url: string, headers: array<string, string>}> */
    private array $calls = [];

    /**
     * @param array<string, array{status: int, body?: string, retry_after?: int}> $responses chemin => réponse
     */
    private function client(array $responses): HibpClient
    {
        return new HibpClient('secret-key', function (string $url, array $headers) use ($responses): array {
            $this->calls[] = ['url' => $url, 'headers' => $headers];
            $path = substr($url, strlen(HibpClient::BASE_URL));
            $response = $responses[$path] ?? ['status' => 500];

            return [
                'status'      => $response['status'],
                'body'        => $response['body'] ?? '',
                'retry_after' => $response['retry_after'] ?? 0,
            ];
        });
    }

    public function testAccountWithBreachesSendsTheKeyAndAUserAgent(): void
    {
        $client = $this->client([
            '/breachedaccount/jane%40example.com?truncateResponse=false' => [
                'status' => 200,
                'body'   => json_encode([['Name' => 'Adobe'], ['Name' => 'Gawker']]),
            ],
        ]);
        $response = $client->breachedAccount('jane@example.com');

        self::assertTrue($response->isOk());
        self::assertCount(2, (array) $response->data);
        self::assertSame('secret-key', $this->calls[0]['headers']['hibp-api-key']);
        self::assertNotSame('', $this->calls[0]['headers']['User-Agent']);
    }

    public function testNotFoundMeansNoBreach(): void
    {
        $response = $this->client(['/breachedaccount/x%40example.com?truncateResponse=false' => ['status' => 404]])
            ->breachedAccount('x@example.com');

        self::assertTrue($response->isOk());
        self::assertSame([], $response->data);
    }

    public function testRateLimitAndUnauthorizedAreReported(): void
    {
        $limited = $this->client([
            '/breachedaccount/x%40example.com?truncateResponse=false' => ['status' => 429, 'retry_after' => 7],
        ])->breachedAccount('x@example.com');
        self::assertTrue($limited->isRateLimited());
        self::assertSame(7, $limited->retryAfter);

        $refused = $this->client(['/subscribeddomains' => ['status' => 401]])->subscribedDomains();
        self::assertTrue($refused->isUnauthorized());
    }

    public function testDomainSearchMapsAliasesToBreachNames(): void
    {
        $client = $this->client([
            '/breacheddomain/example.com' => [
                'status' => 200,
                'body'   => json_encode(['Jane' => ['Adobe'], 'bob' => ['Adobe', 'Gawker']]),
            ],
            '/subscribeddomains'          => [
                'status' => 200,
                'body'   => json_encode([['DomainName' => 'Example.com']]),
            ],
        ]);

        self::assertSame(
            ['jane' => ['Adobe'], 'bob' => ['Adobe', 'Gawker']],
            $client->breachedDomain('example.com')->data
        );
        self::assertSame(['example.com'], $client->subscribedDomains()->data);
    }

    public function testCatalogIsFetchedWithoutTheKey(): void
    {
        $client = $this->client([
            '/breaches' => ['status' => 200, 'body' => json_encode([['Name' => 'Adobe', 'Title' => 'Adobe']])],
        ]);

        self::assertArrayHasKey('Adobe', (array) $client->breaches()->data);
        self::assertArrayNotHasKey(
            'hibp-api-key',
            $this->calls[0]['headers'],
            'Le catalogue public ne reçoit pas la clé.'
        );
    }

    public function testInvalidJsonIsAnError(): void
    {
        $response = $this->client(['/breaches' => ['status' => 200, 'body' => 'not json']])->breaches();

        self::assertFalse($response->isOk());
    }
}
