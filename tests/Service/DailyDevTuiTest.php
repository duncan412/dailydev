<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DailyDevAuthorizationRequest;
use App\Service\DailyDevTui;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class DailyDevTuiTest extends TestCase
{
    public function testCtrlCAndCtrlDQuitDuringOAuth(): void
    {
        foreach (["\x03", "\x04"] as $input) {
            $terminal = new VirtualTerminal();
            $tui = new Tui(terminal: $terminal);

            $dailyDevTui = (new \ReflectionClass(DailyDevTui::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(DailyDevTui::class, 'tui'))->setValue($dailyDevTui, $tui);
            (new \ReflectionProperty(DailyDevTui::class, 'showingReader'))->setValue($dailyDevTui, false);
            (new \ReflectionProperty(DailyDevTui::class, 'authorization'))->setValue(
                $dailyDevTui,
                new DailyDevAuthorizationRequest('https://example.com', 'state', 'verifier', 'http://localhost/callback'),
            );

            $method = new \ReflectionMethod(DailyDevTui::class, 'handleInput');
            $tui->addListener($method->getClosure($dailyDevTui));
            $tui->start();
            $terminal->simulateInput($input);

            self::assertFalse($tui->isRunning());
        }
    }
}
