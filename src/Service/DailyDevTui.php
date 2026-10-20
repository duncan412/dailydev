<?php

declare(strict_types=1);

namespace App\Service;

use Revolt\EventLoop;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

final class DailyDevTui
{
    private const PAGE_SIZE = 20;

    private Tui $tui;
    private ContainerWidget $content;
    private TextWidget $status;
    private ?TextWidget $detailText = null;
    private ?DailyDevAuthorizationRequest $authorization = null;
    private ?SelectListWidget $postList = null;

    /** @var list<array<string, mixed>> */
    private array $posts = [];

    /** @var array<string, mixed>|null */
    private ?array $selectedPost = null;

    private ?string $nextCursor = null;
    private bool $hasNextPage = false;
    private bool $showingReader = false;

    public function __construct(
        private readonly DailyDevApiClient $apiClient,
        private readonly DailyDevOAuthClient $oauthClient,
        private readonly DailyDevOAuthFlow $oauthFlow,
        private readonly DailyDevTokenStore $tokenStore,
    ) {
    }

    public function run(): void
    {
        $this->tui = new Tui(styleSheet: $this->createStyleSheet());
        $this->content = (new ContainerWidget())->expandVertically(true);
        $this->status = new TextWidget('Starting…');

        $this->tui
            ->add((new TextWidget('daily.dev reader'))->addStyleClass('app-title'))
            ->add($this->content)
            ->add($this->status);

        $this->tui->addListener($this->handleInput(...));

        try {
            if ($this->tokenStore->load() === null) {
                $this->showLogin();
            } else {
                $this->showReader();
                $this->loadFeed();
            }
        } catch (\RuntimeException $exception) {
            $this->showLogin();
            $this->setStatus($exception->getMessage());
        }

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

    private function showLogin(): void
    {
        $this->showingReader = false;
        $this->content->clear();

        $oauthError = null;
        try {
            $this->authorization = $this->oauthClient->createAuthorizationRequest();
        } catch (DailyDevOAuthException $exception) {
            $this->authorization = null;
            $oauthError = $exception->getMessage();
        }

        $instructions = new ContainerWidget();
        $instructions
            ->add(new TextWidget('Sign in to daily.dev'))
            ->add(new TextWidget('1. Open daily.dev API settings: https://daily.dev/settings/api'))
            ->add(new TextWidget('2. Sign in, create an OAuth app, and copy its client ID and client secret.'))
            ->add(new TextWidget('3. In the project root, add these values to .env.local:'))
            ->add(new TextWidget('DAILY_DEV_CLIENT_ID=your-client-id'))
            ->add(new TextWidget('DAILY_DEV_CLIENT_SECRET=your-client-secret'))
            ->add(new TextWidget('DAILY_DEV_REDIRECT_URI=http://127.0.0.1:8080/auth/callback'))
            ->add(new TextWidget('DAILY_DEV_SCOPES="openid profile offline_access read write"'))
            ->add(new TextWidget('Register that exact redirect URI in your OAuth app. Keep the client secret private.'))
            ->add(new TextWidget('4. Restart this command, open its authorization URL, and approve access.'))
            ->add(new TextWidget('5. Copy the full redirect URL from the browser address bar and paste it below.'));

        if ($oauthError !== null) {
            $instructions->add(new TextWidget('OAuth setup: ' . $this->safeText($oauthError)));
        } elseif ($this->authorization !== null) {
            $instructions
                ->add(new TextWidget('Authorization URL:'))
                ->add(new TextWidget($this->safeText($this->authorization->authorizationUrl)))
                ->add(new TextWidget('The callback URI and OAuth state are validated before tokens are saved.'));
        }

        $this->content->add($instructions);

        if ($this->authorization !== null) {
            $callbackInput = (new InputWidget())->setPrompt('Redirect URL: ');
            $callbackInput->onSubmit(function (SubmitEvent $event): void {
                if ($this->authorization === null || trim($event->getValue()) === '') {
                    $this->setStatus('Paste the full redirect URL to finish signing in.');

                    return;
                }

                try {
                    $this->oauthFlow->complete($this->authorization, trim($event->getValue()));
                    $this->authorization = null;
                    $this->setStatus('Signed in. Loading your feed…');
                    $this->showReader();
                    $this->loadFeed();
                } catch (\RuntimeException $exception) {
                    $this->setStatus($exception->getMessage());
                }
            });

            $this->content->add($callbackInput);
            $this->tui->setFocus($callbackInput);
        } else {
            $this->setStatus('Complete the OAuth setup above, then restart the TUI. Ctrl+C or Ctrl+D to quit.');
        }

        $authorizationUrl = $this->authorization?->authorizationUrl ?? 'https://daily.dev/settings/api';
        $browserOpenedStatus = $this->authorization === null
            ? 'Browser opened. Create an OAuth app in daily.dev, then add its credentials to .env.local.'
            : 'Browser opened. Authorize the app, then paste the full redirect URL below.';

        $this->setStatus($this->authorization === null
            ? 'Opening daily.dev API settings in your browser…'
            : 'Opening daily.dev authorization in your browser…');

        $this->tui->onTick(function () use ($authorizationUrl, $browserOpenedStatus): bool {
            $this->tui->onTick(null);
            $this->openBrowser($authorizationUrl, $browserOpenedStatus);

            return false;
        });
    }

    private function showReader(): void
    {
        $this->showingReader = true;
        $this->content->clear();

        $panes = (new ContainerWidget())->addStyleClass('panes');
        $listPane = (new ContainerWidget())->addStyleClass('list-pane');
        $detailPane = (new ContainerWidget())->addStyleClass('detail-pane');
        $this->detailText = new TextWidget('Choose a post and press Enter for details.');

        $listPane->add(new TextWidget('For You'))->add(
            $this->postList = (new SelectListWidget([], 18))->addStyleClass('post-list'),
        );
        $detailPane
            ->add(new TextWidget('Selected post'))
            ->add($this->detailText);

        $this->postList
            ->onSelectionChange(function (SelectionChangeEvent $event): void {
                $post = $this->findPost($event->getValue());
                if ($post !== null) {
                    $this->selectedPost = $post;
                    $this->renderPostDetails($post);
                }
            })
            ->onSelect(function (SelectEvent $event): void {
                $this->loadPostDetails($event->getValue());
            });

        $panes->add($listPane)->add($detailPane);
        $this->content->add($panes);
        $this->tui->setFocus($this->postList);
    }

    private function loadFeed(bool $append = false): void
    {
        try {
            $response = $this->apiClient->forYou(
                self::PAGE_SIZE,
                $append ? $this->nextCursor : null,
            );
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
                'Loaded %d posts%s. ↑/↓ select · Enter details · b bookmark · u unbookmark · n next page · r refresh · q quit',
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

            $this->selectedPost = array_merge($this->findPost($postId) ?? [], $details);
            $this->renderPostDetails($this->selectedPost);
            $this->setStatus('Post details loaded. b bookmarks · u removes bookmark · n next page · r refresh · q quit');
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

        if (!is_bool($this->selectedPost['bookmarked'] ?? null)) {
            $this->setStatus('Press Enter to load post details before changing its bookmark.');

            return;
        }

        if ($this->selectedPost['bookmarked'] === $bookmark) {
            $this->setStatus($bookmark ? 'This post is already bookmarked.' : 'This post is not bookmarked.');

            return;
        }

        try {
            if ($bookmark) {
                $this->apiClient->bookmark([$postId]);
            } else {
                $this->apiClient->unbookmark($postId);
            }

            $this->selectedPost['bookmarked'] = $bookmark;
            $this->renderPostDetails($this->selectedPost);
            $this->setStatus($bookmark ? 'Post bookmarked.' : 'Bookmark removed.');
        } catch (\RuntimeException $exception) {
            $this->setStatus($exception->getMessage());
        }
    }

    private function handleInput(InputEvent $event): void
    {
        if (in_array($event->getData(), ["\x03", "\x04"], true)) {
            $this->tui->stop();
            $event->stopPropagation();

            return;
        }

        if (!$this->showingReader) {
            return;
        }

        switch ($event->getData()) {
            case 'q':
                $this->tui->stop();
                break;
            case 'r':
                $this->loadFeed();
                break;
            case 'n':
                $this->loadNextPage();
                break;
            case 'b':
                $this->toggleBookmark(true);
                break;
            case 'u':
                $this->toggleBookmark(false);
                break;
        }

        if (in_array($event->getData(), ['q', 'r', 'n', 'b', 'u'], true)) {
            $event->stopPropagation();
        }
    }

    private function openBrowser(string $url, string $successStatus): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Linux' => 'xdg-open',
            default => null,
        };
        $executable = $command === null ? null : (new ExecutableFinder())->find($command);

        if ($executable === null) {
            $this->setStatus('Could not detect a browser launcher. Open the URL shown above.');

            return;
        }

        $process = new Process([$executable, $url]);
        $process->setTimeout(5);

        try {
            $exitCode = $process->run();
        } catch (\RuntimeException $exception) {
            $this->setStatus('Could not open your browser: ' . $exception->getMessage());

            return;
        }

        if ($exitCode !== 0) {
            $this->setStatus('Could not open your browser. Open the URL shown above.');

            return;
        }

        $this->setStatus($successStatus);
    }

    private function loadNextPage(): void
    {
        if (!$this->hasNextPage) {
            $this->setStatus('There are no more posts in this feed page sequence.');

            return;
        }

        $this->loadFeed(append: true);
    }

    private function updatePostList(): void
    {
        if ($this->postList === null) {
            return;
        }

        $items = [];
        foreach ($this->posts as $post) {
            $source = is_array($post['source'] ?? null) ? ($post['source']['name'] ?? '') : '';
            $summary = $post['summary'] ?? '';
            $items[] = [
                'value' => (string) $post['id'],
                'label' => $this->safeText((string) $post['title']),
                'description' => $this->safeText(implode(' · ', array_filter([
                    is_string($source) ? $source : '',
                    is_string($summary) ? $summary : '',
                ]))),
            ];
        }

        $selectedIndex = $this->postList->getSelectedItem() === null
            ? 0
            : $this->selectedPostIndex($this->postList->getSelectedItem()['value']);
        $this->postList->setItems($items);
        if ($items !== []) {
            $this->postList->setSelectedIndex($selectedIndex);
            $selected = $this->postList->getSelectedItem();
            if ($selected !== null && ($post = $this->findPost($selected['value'])) !== null) {
                $this->selectedPost = $post;
                $this->renderPostDetails($post);
            }
        } else {
            $this->selectedPost = null;
            $this->renderPostDetails(null);
        }
    }

    private function selectedPostIndex(string $postId): int
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

    private function renderPostDetails(?array $post): void
    {
        if ($this->detailText === null) {
            return;
        }

        if ($post === null) {
            $this->detailText->setText('No posts found.');

            return;
        }

        $source = is_array($post['source'] ?? null) ? ($post['source']['name'] ?? '') : '';
        $author = is_array($post['author'] ?? null) ? ($post['author']['name'] ?? '') : '';
        $tags = is_array($post['tags'] ?? null) ? implode(', ', array_filter($post['tags'], 'is_string')) : '';
        $body = $post['content'] ?? $post['summary'] ?? 'No summary is available for this post.';
        $bookmarked = ($post['bookmarked'] ?? false) === true;
        $url = is_string($post['url'] ?? null) ? $post['url'] : '';
        $title = is_string($post['title'] ?? null) ? $post['title'] : 'Untitled post';
        $engagement = is_numeric($post['numUpvotes'] ?? null) && is_numeric($post['numComments'] ?? null)
            ? sprintf('▲ %s · %s comments', $post['numUpvotes'], $post['numComments'])
            : '';

        $this->detailText->setText($this->safeText(implode("\n\n", array_filter([
            $title,
            implode(' · ', array_filter([
                is_string($source) ? $source : '',
                is_string($author) ? $author : '',
                is_numeric($post['readTime'] ?? null) ? $post['readTime'] . ' min read' : '',
            ])),
            is_string($body) ? $this->truncate($body, 1800) : '',
            $tags !== '' ? 'Tags: ' . $tags : '',
            $engagement,
            'Bookmark: ' . ($bookmarked ? 'saved (u to remove)' : 'not saved (b to save)'),
            $url !== '' ? 'Open in browser: ' . $url : '',
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

    private function setStatus(string $message): void
    {
        $this->status->setText($this->safeText($message));
    }

    private function safeText(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($text));
    }

    private function truncate(string $text, int $maxLength): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . '…';
    }
}
