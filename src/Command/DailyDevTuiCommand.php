<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DailyDevTui;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:daily-dev:tui', description: 'Read your daily.dev feed in the terminal')]
final class DailyDevTuiCommand extends Command
{
    public function __construct(private readonly DailyDevTui $dailyDevTui)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dailyDevTui->run();

        return Command::SUCCESS;
    }
}
