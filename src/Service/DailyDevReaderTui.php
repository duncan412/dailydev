<?php

declare(strict_types=1);

namespace App\Service;

use Revolt\EventLoop;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

final class DailyDevReaderTui
{
    private Tui $tui;
    private ContainerWidget $content;
    private TextWidget $status;
    private TextWidget $detail;
    private SelectListWidget $postList;

    /** @var list<array<string, mixed>> */
    private array $posts = [];

    /** @var array<string, mixed>|null */
    private ?array $selectedPost = null;

    private ?string $nextCursor = null;
    private bool $hasNextPage = false;
    private int $pageSize = 20;

    public function __construct(private readonly DailyDevApiClient $apiClient)
    {
    }

    public function run(int $pageSize = 20): void
    {
        $this->pageSize = $pageSize;
        $this->tui = new Tui(styleSheet: $this->createStyleSheet());
        $this->content = (new ContainerWidget())->expandVertically(true);
        $this->status = new TextWidget('Loading your feed…');

        $this->tui
            ->add((new TextWidget('daily.dev reader'))->addStyleClass('app-title'))
            ->add($this->content)
            ->add($this->status);

        $this->tui->addListener($this->handleInput(...));
        $this->showReader();
        $this->loadFeed($pageSize);

        $signalCallbackId = null;
        if (defined('SIGINT') && function_exists('pcntl_signal')) {
            $signalCallbackId = EventLoop::onSignal(SIGINT, function (): void {
                $this->tui->stop();
            });
        }

        try {
            $this->tui->run();
        } finally {
            if ($signalCallbackId !== null) {
                EventLoop::cancel($signalCallbackId);
            }
        }
    }

    private function showReader(): void
    {
        $panes = (new ContainerWidget())->addStyleClass('panes');
        $listPane = (new ContainerWidget())->addStyleClass('list-pane');
        $detailPane = (new ContainerWidget())->addStyleClass('detail-pane');
        $this->detail = new TextWidget('Select a post and press Enter to open its details.');
        $this->postList = new SelectListWidget([], 18);

        $listPane->add(new TextWidget('For You'))->add($this->postList);
        $detailPane->add(new TextWidget('Selected post'))->add($this->detail);
        $panes->add($listPane)->add($detailPane);
        $this->content->add($panes);
        $this->tui->setFocus($this->postList);

        $this->postList
            ->onSelectionChange(function (SelectionChangeEvent $event): void {
                $post = $this->findPost($event->getValue());
                if ($post !== null) {
                    $this->selectedPost = $post;
                    $this->renderPost($post, detailed: false);
                }
            })
            ->onSelect(function (SelectEvent $event): void {
                $this->loadPostDetails($event->getValue());
            });
    }

    private function loadFeed(int $pageSize, bool $append = false): void
    {
        try {
            $response = $this->apiClient->forYou($pageSize, $append ? $this->nextCursor : null);
            $pagePosts = $response['data'] ?? null;
            $pagination = $response['pagination'] ?? null;
            if (!is_array($pagePosts) || !is_array($pagination)) {
                throw new DailyDevApiException('The daily.dev feed response was missing posts or pagination.', 200);
            }

            if (!$append) {
                $this->posts = [];
            }
            foreach ($pagePosts as $post) {
                if (is_array($post) && is_string($post['id'] ?? null) && is_string($post['title'] ?? null)) {
                    $this->posts[] = $post;
                }
            }

            $cursor = $pagination['cursor'] ?? null;
            $this->nextCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
            $this->hasNextPage = ($pagination['hasNextPage'] ?? false) === true && $this->nextCursor !== null;
            $this->updatePostList();
            $this->setStatus(sprintf(
                '%d posts%s · ↑/↓ scroll · Enter open · b bookmark · u unbookmark · n next page · q/Ctrl+C quit',
                count($this->posts),
                $this->hasNextPage ? ' (more available)' : '',
            ));
        } catch (\RuntimeException $exception) {
            $this->setStatus($exception->getMessage());
        }
    }

    private function loadPostDetails(string $postId): void
    {
        try {
            $response = $this->apiClient->post($postId);
            $details = $response['data'] ?? null;
            if (!is_array($details)) {
                throw new DailyDevApiException('The daily.dev post response was missing post details.', 200);
            }

            $post = array_merge($this->findPost($postId) ?? [], $details);
            $this->selectedPost = $post;
            $this->replacePost($post);
            $this->renderPost($post, detailed: true);
            $this->setStatus('Post opened · b bookmark · u unbookmark · ↑/↓ scroll the feed · q/Ctrl+C quit');
        } catch (\RuntimeException $exception) {
            $this->setStatus($exception->getMessage());
        }
    }

    private function toggleBookmark(bool $bookmark): void
    {
        $postId = $this->selectedPost['id'] ?? null;
        if (!is_string($postId) || $postId === '') {
            $this->setStatus('Select a post before changing its bookmark.');

            return;
        }

        $post = $this->selectedPost;
        if (!is_bool($post['bookmarked'] ?? null)) {
            $this->loadPostDetails($postId);
            $post = $this->selectedPost;
            if (!is_array($post) || !is_bool($post['bookmarked'] ?? null)) {
                $this->setStatus('Could not determine whether this post is bookmarked. Press Enter to retry loading it.');

                return;
            }
        }

        if ($post['bookmarked'] === $bookmark) {
            $this->setStatus($bookmark ? 'This post is already bookmarked.' : 'This post is not bookmarked.');

            return;
        }

        try {
            if ($bookmark) {
                $this->apiClient->bookmark([$postId]);
            } else {
                $this->apiClient->unbookmark($postId);
            }

            $post['bookmarked'] = $bookmark;
            $this->selectedPost = $post;
            $this->replacePost($post);
            $this->renderPost($post, detailed: true);
            $this->setStatus($bookmark ? 'Post bookmarked.' : 'Bookmark removed.');
        } catch (\RuntimeException $exception) {
            $this->setStatus($exception->getMessage());
        }
    }

    private function handleInput(InputEvent $event): void
    {
        $data = $event->getData();
        if (in_array($data, ["\x03", "\x04"], true)) {
            $this->tui->stop();
            $event->stopPropagation();

            return;
        }

        switch ($data) {
            case 'q':
                $this->tui->stop();
                break;
            case 'b':
                $this->toggleBookmark(true);
                break;
            case 'u':
                $this->toggleBookmark(false);
                break;
            case 'n':
                $this->loadNextPage();
                break;
            case 'r':
                $this->loadFeed($this->pageSize, append: false);
                break;
            case "\x1b":
                $this->setStatus('↑/↓ scroll · Enter open · b bookmark · u unbookmark · n next page · q/Ctrl+C quit');
                break;
        }

        if (in_array($data, ['q', 'b', 'u', 'n', 'r', "\x1b"], true)) {
            $event->stopPropagation();
        }
    }

    private function loadNextPage(): void
    {
        if (!$this->hasNextPage) {
            $this->setStatus('No more posts are available in this feed.');

            return;
        }

        $this->loadFeed($this->pageSize, append: true);
    }

    private function updatePostList(): void
    {
        $items = [];
        foreach ($this->posts as $post) {
            if (!is_string($post['id'] ?? null) || !is_string($post['title'] ?? null)) {
                continue;
            }

            $source = is_array($post['source'] ?? null) ? ($post['source']['name'] ?? '') : '';
            $items[] = [
                'value' => $post['id'],
                'label' => $this->safeText($post['title']),
                'description' => $this->safeText(is_string($source) ? $source : ''),
            ];
        }

        $selectedIndex = 0;
        if (($selected = $this->postList->getSelectedItem()) !== null) {
            $selectedIndex = $this->postIndex($selected['value']);
        }

        $this->postList->setItems($items);
        if ($items !== []) {
            $this->postList->setSelectedIndex($selectedIndex);
            $selected = $this->postList->getSelectedItem();
            if ($selected !== null && ($post = $this->findPost($selected['value'])) !== null) {
                $this->selectedPost = $post;
                $this->renderPost($post, detailed: false);
            }
        } else {
            $this->selectedPost = null;
            $this->detail->setText('No posts found in this feed.');
        }
    }

    /**
     * @param array<string, mixed> $updatedPost
     */
    private function replacePost(array $updatedPost): void
    {
        foreach ($this->posts as $index => $post) {
            if (($post['id'] ?? null) === ($updatedPost['id'] ?? null)) {
                $this->posts[$index] = $updatedPost;
                break;
            }
        }

        $this->updatePostList();
    }

    /**
     * @param array<string, mixed> $post
     */
    private function renderPost(array $post, bool $detailed): void
    {
        $source = is_array($post['source'] ?? null) ? ($post['source']['name'] ?? '') : '';
        $author = is_array($post['author'] ?? null) ? ($post['author']['name'] ?? '') : '';
        $body = $detailed
            ? ($post['content'] ?? $post['summary'] ?? 'No summary is available for this post.')
            : ($post['summary'] ?? 'Press Enter to load post details.');
        $bookmarkStatus = match ($post['bookmarked'] ?? null) {
            true => 'Bookmark: saved (u to remove)',
            false => 'Bookmark: not saved (b to save)',
            default => 'Bookmark status unavailable; press Enter to reload details.',
        };
        $tags = is_array($post['tags'] ?? null) ? implode(', ', array_filter($post['tags'], 'is_string')) : '';
        $url = is_string($post['url'] ?? null) ? $post['url'] : '';
        $title = is_string($post['title'] ?? null) ? $post['title'] : 'Untitled post';

        $this->detail->setText($this->safeText(implode("\n\n", array_filter([
            $title,
            implode(' · ', array_filter([
                is_string($source) ? $source : '',
                is_string($author) ? $author : '',
                is_numeric($post['readTime'] ?? null) ? $post['readTime'] . ' min read' : '',
            ])),
            is_string($body) ? $body : '',
            $tags !== '' ? 'Tags: ' . $tags : '',
            $bookmarkStatus,
            $url !== '' ? 'URL: ' . $url : '',
        ], static fn (string $line): bool => $line !== ''))));
    }

    private function createStyleSheet(): StyleSheet
    {
        return (new StyleSheet())
            ->addRule('.app-title', (new Style())->withBold()->withColor('cyan'))
            ->addRule('.panes', (new Style())->withDirection(Direction::Vertical)->withGap(1))
            ->addRule('.list-pane', (new Style())->withFlex(1)->withPadding([1])->withBorder(Border::all(1, color: 'blue')))
            ->addRule('.detail-pane', (new Style())->withFlex(3)->withPadding([1])->withBorder(Border::all(1, color: 'green')))
            ->addRule('.post-list::selected', (new Style())->withBold()->withColor('cyan'))
            ->addBreakpoint(100, '.panes', (new Style())->withDirection(Direction::Horizontal));
    }

    private function postIndex(string $postId): int
    {
        foreach ($this->posts as $index => $post) {
            if (($post['id'] ?? null) === $postId) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPost(string $postId): ?array
    {
        foreach ($this->posts as $post) {
            if (($post['id'] ?? null) === $postId) {
                return $post;
            }
        }

        return null;
    }

    private function setStatus(string $message): void
    {
        $this->status->setText($this->safeText($message));
    }

    private function safeText(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($text));
    }
}
