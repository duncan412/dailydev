<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DailyDevOAuthFlow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:daily-dev:login', description: 'Authenticate this TUI with daily.dev')]
final class DailyDevLoginCommand extends Command
{
    public function __construct(private readonly DailyDevOAuthFlow $oauthFlow)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $authorization = $this->oauthFlow->begin();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Sign in to daily.dev');
        $io->writeln('Open this URL in your browser, authorize the app, and copy the full redirect URL:');
        $io->writeln($authorization->authorizationUrl);
        $io->newLine();

        $callbackUrl = $io->ask('Paste the redirect URL');
        if (!is_string($callbackUrl) || trim($callbackUrl) === '') {
            $io->error('A redirect URL is required.');

            return Command::FAILURE;
        }

        try {
            $this->oauthFlow->complete($authorization, trim($callbackUrl));
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('daily.dev authentication saved.');

        return Command::SUCCESS;
    }
}
