<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DailyDevApiClient;
use App\Service\DailyDevReaderTui;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class DailyDevReaderTuiTest extends TestCase
{
    public function testArrowDownSelectsNextPostAndCtrlCStopsTui(): void
    {
        [$reader, $tui, $terminal] = $this->createReader();
        $terminal->simulateInput("\x1b[B");

        $selectedPost = (new \ReflectionProperty(DailyDevReaderTui::class, 'selectedPost'))->getValue($reader);
        self::assertSame('post-2', $selectedPost['id']);

        $terminal->simulateInput("\x03");
        self::assertFalse($tui->isRunning());
    }

    public function testEnterLoadsPostDetailsIntoTheDetailPane(): void
    {
        [$reader, $tui, $terminal] = $this->createReader(new MockHttpClient(
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('GET', $method);
                self::assertSame('/public/v1/posts/post-1', parse_url($url, PHP_URL_PATH));

                return new MockResponse(
                    '{"data":{"id":"post-1","title":"Post One","summary":"Full detail text","bookmarked":false}}',
                );
            },
        ));

        $terminal->simulateInput("\r");

        $detail = (new \ReflectionProperty(DailyDevReaderTui::class, 'detail'))->getValue($reader);
        self::assertStringContainsString('Full detail text', $detail->getText());
        $tui->stop();
    }

    public function testSlashOpensSearchAndEnterShowsSearchResults(): void
    {
        $apiClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('/public/v1/search/posts', parse_url($url, PHP_URL_PATH));
            self::assertSame(['q' => 'php symfony', 'limit' => 20], $options['query']);

            return new MockResponse(
                '{"data":[{"id":"search-1","title":"PHP Symfony Search","summary":"Search result summary"}],"pagination":{"hasNextPage":false,"cursor":null}}',
            );
        });
        [$reader, $tui, $terminal] = $this->createReader($apiClient);

        $terminal->simulateInput('/');
        $terminal->simulateInput('php symfony');
        $terminal->simulateInput("\r");

        $selectedPost = (new \ReflectionProperty(DailyDevReaderTui::class, 'selectedPost'))->getValue($reader);
        self::assertSame('search-1', $selectedPost['id']);
        self::assertSame('Search: php symfony', (new \ReflectionProperty(DailyDevReaderTui::class, 'feedHeading'))->getValue($reader)->getText());
        self::assertSame('php symfony', (new \ReflectionProperty(DailyDevReaderTui::class, 'searchQuery'))->getValue($reader));
        $tui->stop();
    }

    public function testSelectedPostCanBeBookmarkedAndUnbookmarkedInTheSameSession(): void
    {
        $requests = [];
        [$reader, $tui, $terminal] = $this->createReader(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, parse_url($url, PHP_URL_PATH)];

                return match ($method) {
                    'GET' => new MockResponse(
                        '{"data":{"id":"post-1","title":"Post One","summary":"Details","bookmarked":false}}',
                    ),
                    'POST' => new MockResponse('{"data":[{"postId":"post-1"}]}'),
                    'DELETE' => new MockResponse('', ['http_code' => 204]),
                    default => throw new \LogicException('Unexpected API method.'),
                };
            },
        ));

        $terminal->simulateInput("\r");
        $terminal->simulateInput('b');
        self::assertTrue((new \ReflectionProperty(DailyDevReaderTui::class, 'selectedPost'))->getValue($reader)['bookmarked']);

        $terminal->simulateInput('u');
        self::assertFalse((new \ReflectionProperty(DailyDevReaderTui::class, 'selectedPost'))->getValue($reader)['bookmarked']);
        self::assertSame([
            ['GET', '/public/v1/posts/post-1'],
            ['POST', '/public/v1/bookmarks/'],
            ['DELETE', '/public/v1/bookmarks/post-1'],
        ], $requests);

        $tui->stop();
    }

    /**
     * @return array{DailyDevReaderTui, Tui, VirtualTerminal}
     */
    private function createReader(?MockHttpClient $httpClient = null): array
    {
        $reader = new DailyDevReaderTui(new DailyDevApiClient(
            $httpClient ?? new MockHttpClient(
                static fn (): MockResponse => throw new \LogicException('No API request expected.'),
            ),
            'test-token',
        ));
        $terminal = new VirtualTerminal();
        $tui = new Tui(terminal: $terminal);

        $this->setProperty($reader, 'tui', $tui);
        $this->setProperty($reader, 'content', new ContainerWidget());
        $this->setProperty($reader, 'status', new TextWidget());
        $this->setProperty($reader, 'posts', [
            ['id' => 'post-1', 'title' => 'Post One', 'summary' => 'Summary one'],
            ['id' => 'post-2', 'title' => 'Post Two', 'summary' => 'Summary two'],
        ]);
        $tui->add((new \ReflectionProperty(DailyDevReaderTui::class, 'content'))->getValue($reader));

        $showReader = new \ReflectionMethod(DailyDevReaderTui::class, 'showReader');
        $showReader->invoke($reader);
        (new \ReflectionMethod(DailyDevReaderTui::class, 'updatePostList'))->invoke($reader);
        $handleInput = new \ReflectionMethod(DailyDevReaderTui::class, 'handleInput');
        $tui->addListener($handleInput->getClosure($reader));
        $tui->start();

        return [$reader, $tui, $terminal];
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}
