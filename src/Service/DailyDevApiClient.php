<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DailyDevApiClient
{
    private const API_BASE_URL = 'https://api.daily.dev/public/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(DD_TOKEN)%')]
        private readonly string $personalAccessToken,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forYou(int $limit = 20, ?string $cursor = null): array
    {
        return $this->get('/feeds/foryou', $this->pageQuery($limit, $cursor));
    }

    /**
     * @param list<string> $tags
     *
     * @return array<string, mixed>
     */
    public function popular(int $limit = 20, ?string $cursor = null, array $tags = []): array
    {
        if (count($tags) > 20 || array_filter($tags, static fn (string $tag): bool => trim($tag) === '') !== []) {
            throw new \InvalidArgumentException('Popular feed accepts up to 20 non-empty tags.');
        }

        $query = $this->pageQuery($limit, $cursor);
        if ($tags !== []) {
            $query['tags'] = implode(',', $tags);
        }

        return $this->get('/feeds/popular', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function discussed(
        int $limit = 20,
        ?string $cursor = null,
        int $period = 7,
        ?string $tag = null,
        ?string $source = null,
    ): array {
        if (!in_array($period, [7, 30, 365], true)) {
            throw new \InvalidArgumentException('Discussed feed period must be 7, 30 or 365 days.');
        }

        return $this->get('/feeds/discussed', array_filter(
            $this->pageQuery($limit, $cursor) + [
                'period' => $period,
                'tag' => $tag,
                'source' => $source,
            ],
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function byTag(string $tag, int $limit = 20, ?string $cursor = null): array
    {
        $this->assertPathSegment($tag, 'tag');

        return $this->get('/feeds/tag/' . rawurlencode($tag), $this->pageQuery($limit, $cursor));
    }

    /**
     * @return array<string, mixed>
     */
    public function bySource(string $source, int $limit = 20, ?string $cursor = null): array
    {
        $this->assertPathSegment($source, 'source');

        return $this->get('/feeds/source/' . rawurlencode($source), $this->pageQuery($limit, $cursor));
    }

    /**
     * @return array<string, mixed>
     */
    public function post(string $id): array
    {
        $this->assertPathSegment($id, 'post ID');

        return $this->get('/posts/' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function bookmarks(
        int $limit = 20,
        ?string $cursor = null,
        bool $unreadOnly = false,
        string $status = 'ALL',
        ?string $listId = null,
    ): array {
        if (!in_array($status, ['ALL', 'UNREAD', 'READ', 'ARCHIVED'], true)) {
            throw new \InvalidArgumentException('Bookmark status must be ALL, UNREAD, READ or ARCHIVED.');
        }

        return $this->get('/bookmarks/', array_filter(
            $this->pageQuery($limit, $cursor) + [
                'unreadOnly' => $unreadOnly ? 'true' : null,
                'status' => $status === 'ALL' ? null : $status,
                'listId' => $listId,
            ],
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    /**
     * @param list<string> $postIds
     *
     * @return array<string, mixed>
     */
    public function bookmark(array $postIds, ?string $listId = null): array
    {
        if ($postIds === [] || count($postIds) > 100) {
            throw new \InvalidArgumentException('Bookmarking requires between 1 and 100 post IDs.');
        }

        foreach ($postIds as $postId) {
            $this->assertPathSegment($postId, 'post ID');
        }

        $body = ['postIds' => $postIds];
        if ($listId !== null) {
            $this->assertPathSegment($listId, 'bookmark list ID');
            $body['listId'] = $listId;
        }

        return $this->request('POST', '/bookmarks/', body: $body);
    }

    public function unbookmark(string $postId): void
    {
        $this->assertPathSegment($postId, 'post ID');
        $this->request('DELETE', '/bookmarks/' . rawurlencode($postId));
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param array<string, int|string> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
    ): array {
        if (trim($this->personalAccessToken) === '') {
            throw new \RuntimeException('Set DD_TOKEN in .env.local to use the daily.dev API.');
        }

        $options = [
            'auth_bearer' => $this->personalAccessToken,
            'headers' => ['Accept' => 'application/json'],
        ];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, self::API_BASE_URL . $path, $options);
            $statusCode = $response->getStatusCode();
            if ($statusCode === 204) {
                return [];
            }

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            throw new DailyDevApiException('Could not reach the daily.dev API.', 0, previous: $exception);
        } catch (DecodingExceptionInterface $exception) {
            throw new DailyDevApiException('The daily.dev API returned invalid JSON.', 0, previous: $exception);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_string($data['message'] ?? null)
                ? $data['message']
                : sprintf('The daily.dev API returned HTTP %d.', $statusCode);
            $retryAfter = is_numeric($data['retryAfter'] ?? null) ? (int) $data['retryAfter'] : null;

            throw new DailyDevApiException($message, $statusCode, $retryAfter);
        }

        $responseData = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new DailyDevApiException('The daily.dev API returned an unexpected response.', $statusCode);
            }

            $responseData[$key] = $value;
        }

        return $responseData;
    }

    /**
     * @return array<string, int|string>
     */
    private function pageQuery(int $limit, ?string $cursor): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('Page limit must be between 1 and 50.');
        }

        $query = ['limit' => $limit];
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        return $query;
    }

    private function assertPathSegment(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s must not be empty.', ucfirst($name)));
        }
    }
}
