<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DailyDevApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:daily-dev:feed', description: 'Read posts from the daily.dev feed')]
final class DailyDevFeedCommand extends Command
{
    public function __construct(private readonly DailyDevApiClient $apiClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of posts to fetch (1-50)', '20')
            ->addOption('post', null, InputOption::VALUE_REQUIRED, 'Show one post by ID')
            ->addOption('bookmark', null, InputOption::VALUE_REQUIRED, 'Bookmark a post by ID')
            ->addOption('unbookmark', null, InputOption::VALUE_REQUIRED, 'Remove a bookmark by post ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if (($postId = $input->getOption('bookmark')) !== null) {
                $this->apiClient->bookmark([(string) $postId]);
                $io->success('Post bookmarked.');

                return Command::SUCCESS;
            }

            if (($postId = $input->getOption('unbookmark')) !== null) {
                $this->apiClient->unbookmark((string) $postId);
                $io->success('Bookmark removed.');

                return Command::SUCCESS;
            }

            if (($postId = $input->getOption('post')) !== null) {
                $response = $this->apiClient->post((string) $postId);
                $post = $response['data'] ?? null;
                if (!is_array($post)) {
                    $io->error('The daily.dev API returned no post data.');

                    return Command::FAILURE;
                }

                $io->title($this->safeText((string) ($post['title'] ?? 'Untitled post')));
                $io->definitionList(
                    ['ID' => $this->safeText((string) ($post['id'] ?? $postId))],
                    ['URL' => $this->safeText((string) ($post['url'] ?? ''))],
                    ['Summary' => $this->safeText((string) ($post['summary'] ?? 'No summary available.'))],
                );

                return Command::SUCCESS;
            }

            $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 50) {
                $io->error('The --limit option must be a number between 1 and 50.');

                return Command::INVALID;
            }

            $response = $this->apiClient->forYou($limit);
            $posts = $response['data'] ?? null;
            if (!is_array($posts)) {
                $io->error('The daily.dev API returned no feed data.');

                return Command::FAILURE;
            }

            $rows = [];
            foreach ($posts as $post) {
                if (!is_array($post)) {
                    continue;
                }

                $rows[] = [
                    $this->safeText((string) ($post['id'] ?? '')),
                    $this->safeText((string) ($post['title'] ?? '')) ?: 'Untitled post',
                    $this->safeText((string) ($post['url'] ?? '')),
                ];
            }

            (new Table($output))
                ->setHeaders(['ID', 'Title', 'URL'])
                ->setRows($rows)
                ->render();

            $io->text('Show details: --post=<ID> | Bookmark: --bookmark=<ID> | Remove bookmark: --unbookmark=<ID>');

            return Command::SUCCESS;
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function safeText(string $text): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
    }
}
