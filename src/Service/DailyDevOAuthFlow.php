<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Lock\LockFactory;

final class DailyDevOAuthFlow
{
    public function __construct(
        private readonly DailyDevOAuthClient $client,
        private readonly DailyDevTokenStore $tokenStore,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function begin(): DailyDevAuthorizationRequest
    {
        return $this->client->createAuthorizationRequest();
    }

    public function complete(DailyDevAuthorizationRequest $authorization, string $callbackUrl): DailyDevTokenSet
    {
        $response = $this->parseCallback($authorization->redirectUri, $callbackUrl);
        $state = $response['state'] ?? null;

        if (!is_string($state) || !hash_equals($authorization->state, $state)) {
            throw new DailyDevOAuthException('The OAuth state did not match. Please start sign-in again.');
        }

        if (isset($response['error'])) {
            throw new DailyDevOAuthException('daily.dev authorization was denied. Please start sign-in again.');
        }

        $code = $response['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new DailyDevOAuthException('The callback URL did not contain an authorization code.');
        }

        $tokens = $this->client->exchangeCode($code, $authorization->codeVerifier);
        $this->tokenStore->save($tokens);

        return $tokens;
    }

    public function refreshStoredTokens(): DailyDevTokenSet
    {
        $lock = $this->lockFactory->createLock('dailydev.oauth.refresh');
        $lock->acquire(true);

        try {
            $tokens = $this->tokenStore->load();
            if ($tokens === null) {
                throw new DailyDevOAuthException('No saved daily.dev tokens were found. Sign in first.');
            }

            return $this->refreshTokens($tokens);
        } finally {
            $lock->release();
        }
    }

    public function getValidStoredTokens(int $expirationBufferSeconds = 60): DailyDevTokenSet
    {
        $lock = $this->lockFactory->createLock('dailydev.oauth.refresh');
        $lock->acquire(true);

        try {
            $tokens = $this->tokenStore->load();
            if ($tokens === null) {
                throw new DailyDevOAuthException('No saved daily.dev tokens were found. Sign in first.');
            }

            if ($tokens->expiresAt !== null && $tokens->expiresAt <= time() + $expirationBufferSeconds) {
                return $this->refreshTokens($tokens);
            }

            return $tokens;
        } finally {
            $lock->release();
        }
    }

    private function refreshTokens(DailyDevTokenSet $tokens): DailyDevTokenSet
    {
        $refreshedTokens = $this->client->refresh($tokens);
        $this->tokenStore->save($refreshedTokens);

        return $refreshedTokens;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCallback(string $redirectUri, string $callbackUrl): array
    {
        $expected = parse_url($redirectUri);
        $actual = parse_url($callbackUrl);

        if (
            $expected === false
            || $actual === false
            || isset($actual['fragment'])
            || isset($actual['user'])
            || isset($actual['pass'])
            || ($expected['scheme'] ?? null) !== ($actual['scheme'] ?? null)
            || ($expected['host'] ?? null) !== ($actual['host'] ?? null)
            || ($expected['port'] ?? null) !== ($actual['port'] ?? null)
            || ($expected['path'] ?? '') !== ($actual['path'] ?? '')
        ) {
            throw new DailyDevOAuthException('The pasted URL does not match the configured OAuth redirect URI.');
        }

        if (!isset($actual['query'])) {
            throw new DailyDevOAuthException('The pasted URL did not contain an OAuth response.');
        }

        parse_str($actual['query'], $response);

        return $response;
    }
}
