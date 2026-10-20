<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DailyDevApiClient;
use App\Service\DailyDevApiException;
use App\Service\DailyDevOAuthClient;
use App\Service\DailyDevOAuthException;
use App\Service\DailyDevOAuthFlow;
use App\Service\DailyDevTokenSet;
use App\Service\DailyDevTokenStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class DailyDevApiClientTest extends TestCase
{
    private string $directory;
    private string $tokenFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dailydev-api-test-' . bin2hex(random_bytes(8));
        $this->tokenFile = $this->directory . '/tokens.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tokenFile)) {
            unlink($this->tokenFile);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testForYouReturnsPaginatedFeedAndSendsBearerToken(): void
    {
        $apiClient = $this->createApiClient(
            new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('GET', $method);
                self::assertSame('/public/v1/feeds/foryou', parse_url($url, PHP_URL_PATH));
                self::assertSame('Authorization: Bearer access-token', $options['normalized_headers']['authorization'][0]);
                self::assertSame(['limit' => 15, 'cursor' => 'next-page'], $options['query']);

                return new MockResponse(
                    '{"data":[{"id":"post-1","title":"A post"}],"pagination":{"hasNextPage":true,"cursor":"next"}}',
                    ['http_code' => 200],
                );
            }),
        );

        $response = $apiClient->forYou(15, 'next-page');

        self::assertSame('post-1', $response['data'][0]['id']);
        self::assertSame('next', $response['pagination']['cursor']);
    }

    public function testBookmarkAndUnbookmarkUseWriteEndpoints(): void
    {
        $requests = [];
        $apiClient = $this->createApiClient(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $url, $options];

                return $method === 'POST'
                    ? new MockResponse('{"data":[{"postId":"post-1"}]}', ['http_code' => 200])
                    : new MockResponse('', ['http_code' => 204]);
            },
        ));

        $response = $apiClient->bookmark(['post-1'], 'reading-list');
        $apiClient->unbookmark('post-1');

        self::assertSame('post-1', $response['data'][0]['postId']);
        self::assertSame('POST', $requests[0][0]);
        self::assertSame('/public/v1/bookmarks/', parse_url($requests[0][1], PHP_URL_PATH));
        self::assertSame(
            ['postIds' => ['post-1'], 'listId' => 'reading-list'],
            json_decode($requests[0][2]['body'], true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame('DELETE', $requests[1][0]);
        self::assertSame('/public/v1/bookmarks/post-1', parse_url($requests[1][1], PHP_URL_PATH));
    }

    public function testRefreshesExpiringTokenBeforeApiRequest(): void
    {
        $tokenStore = $this->createTokenStore();
        $tokenStore->save(new DailyDevTokenSet('expired-access', 'old-refresh', time() - 1, 'read write', 'Bearer'));
        $oauthClient = new DailyDevOAuthClient(
            new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://api.daily.dev/auth/oauth2/token', $url);

                return new MockResponse(
                    '{"access_token":"fresh-access","refresh_token":"fresh-refresh","expires_in":3600,"scope":"read write"}',
                    ['http_code' => 200],
                );
            }),
            'client-id',
            'client-secret',
            'http://127.0.0.1:8080/auth/callback',
            'openid profile offline_access read write',
        );
        $flow = $this->createFlow($tokenStore, $oauthClient);
        $apiClient = new DailyDevApiClient(
            new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('Authorization: Bearer fresh-access', $options['normalized_headers']['authorization'][0]);

                return new MockResponse('{"data":[],"pagination":{"hasNextPage":false,"cursor":null}}');
            }),
            $flow,
        );

        $apiClient->forYou();

        self::assertSame('fresh-refresh', $tokenStore->load()?->refreshToken);
    }

    public function testBookmarkRejectsTokenWithoutWriteScope(): void
    {
        $tokenStore = $this->createTokenStore();
        $tokenStore->save(new DailyDevTokenSet('access-token', 'refresh-token', null, 'read', 'Bearer'));
        $apiClient = $this->createApiClient(
            new MockHttpClient(static fn (): MockResponse => throw new \LogicException('No API request expected.')),
            $tokenStore,
        );

        $this->expectException(DailyDevOAuthException::class);
        $this->expectExceptionMessage('missing the "write" scope');

        $apiClient->bookmark(['post-1']);
    }

    public function testRateLimitErrorPreservesRetryAfter(): void
    {
        $apiClient = $this->createApiClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse(
                '{"error":"rate_limit","message":"Try again later","retryAfter":30}',
                ['http_code' => 429],
            ),
        ));

        try {
            $apiClient->popular();
            self::fail('Expected a rate-limit exception.');
        } catch (DailyDevApiException $exception) {
            self::assertSame(429, $exception->statusCode);
            self::assertSame(30, $exception->retryAfter);
            self::assertSame('Try again later', $exception->getMessage());
        }
    }

    private function createApiClient(MockHttpClient $httpClient, ?DailyDevTokenStore $tokenStore = null): DailyDevApiClient
    {
        $tokenStore ??= $this->createTokenStore();
        if ($tokenStore->load() === null) {
            $tokenStore->save(new DailyDevTokenSet('access-token', 'refresh-token', time() + 3600, 'read write', 'Bearer'));
        }

        $oauthClient = new DailyDevOAuthClient(
            new MockHttpClient(),
            'client-id',
            'client-secret',
            'http://127.0.0.1:8080/auth/callback',
            'openid profile offline_access read write',
        );

        return new DailyDevApiClient($httpClient, $this->createFlow($tokenStore, $oauthClient));
    }

    private function createTokenStore(): DailyDevTokenStore
    {
        return new DailyDevTokenStore($this->tokenFile);
    }

    private function createFlow(DailyDevTokenStore $tokenStore, DailyDevOAuthClient $oauthClient): DailyDevOAuthFlow
    {
        return new DailyDevOAuthFlow(
            $oauthClient,
            $tokenStore,
            new LockFactory(new InMemoryStore()),
        );
    }
}
