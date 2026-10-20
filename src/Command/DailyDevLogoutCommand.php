<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DailyDevTokenStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:daily-dev:logout', description: 'Remove saved daily.dev tokens')]
final class DailyDevLogoutCommand extends Command
{
    public function __construct(private readonly DailyDevTokenStore $tokenStore)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $removed = $this->tokenStore->clear();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success($removed ? 'Saved daily.dev tokens were removed.' : 'No saved daily.dev tokens were found.');

        return Command::SUCCESS;
    }
}
