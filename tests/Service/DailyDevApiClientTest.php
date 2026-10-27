<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DailyDevApiClient;
use App\Service\DailyDevApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DailyDevApiClientTest extends TestCase
{
    public function testForYouReturnsPaginatedFeedAndSendsPersonalAccessToken(): void
    {
        $apiClient = new DailyDevApiClient(
            new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('GET', $method);
                self::assertSame('/public/v1/feeds/foryou', parse_url($url, PHP_URL_PATH));
                self::assertStringStartsWith('Authorization: Bearer ', $options['normalized_headers']['authorization'][0]);
                self::assertSame(['limit' => 15, 'cursor' => 'next-page'], $options['query']);

                return new MockResponse(
                    '{"data":[{"id":"post-1","title":"A post"}],"pagination":{"hasNextPage":true,"cursor":"next"}}',
                    ['http_code' => 200],
                );
            }),
            'personal-access-token',
        );

        $response = $apiClient->forYou(15, 'next-page');

        self::assertSame('post-1', $response['data'][0]['id']);
        self::assertSame('next', $response['pagination']['cursor']);
    }

    public function testBookmarkAndUnbookmarkUseWriteEndpoints(): void
    {
        $requests = [];
        $apiClient = new DailyDevApiClient(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $url, $options];

                return $method === 'POST'
                    ? new MockResponse('{"data":[{"postId":"post-1"}]}', ['http_code' => 200])
                    : new MockResponse('', ['http_code' => 204]);
            },
        ), 'personal-access-token');

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

    public function testEmptyPersonalAccessTokenIsRejectedWithoutRequest(): void
    {
        $apiClient = new DailyDevApiClient(
            new MockHttpClient(static fn (): MockResponse => throw new \LogicException('No API request expected.')),
            '',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Set DD_TOKEN in .env.local');

        $apiClient->forYou();
    }

    public function testRateLimitErrorPreservesRetryAfter(): void
    {
        $apiClient = new DailyDevApiClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse(
                '{"error":"rate_limit","message":"Try again later","retryAfter":30}',
                ['http_code' => 429],
            )),
            'personal-access-token',
        );

        try {
            $apiClient->popular();
            self::fail('Expected a rate-limit exception.');
        } catch (DailyDevApiException $exception) {
            self::assertSame(429, $exception->statusCode);
            self::assertSame(30, $exception->retryAfter);
            self::assertSame('Try again later', $exception->getMessage());
        }
    }
}
