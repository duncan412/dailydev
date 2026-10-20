<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DailyDevTokenStore
{
    public function __construct(
        #[Autowire('%env(DAILY_DEV_TOKEN_FILE)%')]
        private readonly string $filePath,
    ) {
    }

    public function load(): ?DailyDevTokenSet
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read the daily.dev token file.');
        }

        try {
            $tokens = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DailyDevOAuthException('The saved daily.dev token file is not valid JSON.', previous: $exception);
        }

        if (!is_array($tokens)) {
            throw new DailyDevOAuthException('The saved daily.dev token file is invalid.');
        }

        return DailyDevTokenSet::fromArray($tokens);
    }

    public function save(DailyDevTokenSet $tokens): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the daily.dev token directory.');
        }

        $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            $contents = json_encode($tokens->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporaryPath, $contents . PHP_EOL, LOCK_EX) === false) {
                throw new \RuntimeException('Could not write the daily.dev token file.');
            }

            if (!chmod($temporaryPath, 0600)) {
                throw new \RuntimeException('Could not restrict permissions on the daily.dev token file.');
            }

            if (!rename($temporaryPath, $path)) {
                throw new \RuntimeException('Could not save the daily.dev token file.');
            }
        } catch (\Throwable $exception) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw $exception;
        }
    }

    public function clear(): bool
    {
        $path = $this->path();
        if (!file_exists($path)) {
            return false;
        }

        if (!unlink($path)) {
            throw new \RuntimeException('Could not remove the daily.dev token file.');
        }

        return true;
    }

    private function path(): string
    {
        if ($this->filePath !== '') {
            return $this->filePath;
        }

        $configDirectory = getenv('XDG_CONFIG_HOME');
        if (!is_string($configDirectory) || $configDirectory === '') {
            $home = getenv('HOME');
            if (!is_string($home) || $home === '') {
                throw new \RuntimeException('Set DAILY_DEV_TOKEN_FILE or HOME to store daily.dev tokens.');
            }

            $configDirectory = rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.config';
        }

        return rtrim($configDirectory, DIRECTORY_SEPARATOR) . '/dailydev/tokens.json';
    }
}
