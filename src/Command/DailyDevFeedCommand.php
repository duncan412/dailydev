<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DailyDevReaderTui;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:daily-dev:feed', description: 'Browse and bookmark your daily.dev feed')]
final class DailyDevFeedCommand extends Command
{
    public function __construct(private readonly DailyDevReaderTui $reader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of posts to fetch per page (1-50)', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 50) {
            (new SymfonyStyle($input, $output))->error('The --limit option must be a number between 1 and 50.');

            return Command::INVALID;
        }

        try {
            $this->reader->run($limit);
        } catch (\RuntimeException $exception) {
            (new SymfonyStyle($input, $output))->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
