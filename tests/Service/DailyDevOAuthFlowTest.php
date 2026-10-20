<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DailyDevOAuthClient;
use App\Service\DailyDevOAuthException;
use App\Service\DailyDevOAuthFlow;
use App\Service\DailyDevTokenSet;
use App\Service\DailyDevTokenStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DailyDevOAuthFlowTest extends TestCase
{
    private string $directory;
    private string $tokenFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dailydev-test-' . bin2hex(random_bytes(8));
        $this->tokenFile = $this->directory . '/tokens.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tokenFile)) {
            unlink($this->tokenFile);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAuthorizationRequestUsesPkceS256(): void
    {
        $client = $this->createClient();

        $authorization = $client->createAuthorizationRequest();
        parse_str((string) parse_url($authorization->authorizationUrl, PHP_URL_QUERY), $query);

        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $authorization->codeVerifier, true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
        self::assertSame($authorization->state, $query['state']);
    }

    public function testCallbackValidatesStateAndPersistsTokensWithPrivatePermissions(): void
    {
        $client = $this->createClient(new MockHttpClient(
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://api.daily.dev/auth/oauth2/token', $url);

                return new MockResponse(
                    '{"access_token":"access","refresh_token":"refresh","expires_in":3600,"scope":"read","token_type":"Bearer"}',
                    ['http_code' => 200],
                );
            },
        ));
        $tokenStore = new DailyDevTokenStore($this->tokenFile);
        $flow = new DailyDevOAuthFlow($client, $tokenStore, $this->createLockFactory());
        $authorization = $flow->begin();
        $callbackUrl = $authorization->redirectUri . '?' . http_build_query([
            'code' => 'authorization-code',
            'state' => $authorization->state,
        ]);

        $tokens = $flow->complete($authorization, $callbackUrl);

        self::assertSame('access', $tokens->accessToken);
        self::assertSame('refresh', $tokenStore->load()?->refreshToken);
        self::assertSame(0600, fileperms($this->tokenFile) & 0777);
    }

    public function testCallbackRejectsInvalidStateBeforeExchangingCode(): void
    {
        $client = $this->createClient(new MockHttpClient(
            static fn (): MockResponse => throw new \LogicException('The token endpoint must not be called.'),
        ));
        $flow = new DailyDevOAuthFlow($client, new DailyDevTokenStore($this->tokenFile), $this->createLockFactory());
        $authorization = $flow->begin();
        $callbackUrl = $authorization->redirectUri . '?' . http_build_query([
            'code' => 'authorization-code',
            'state' => 'incorrect-state',
        ]);

        $this->expectException(DailyDevOAuthException::class);
        $this->expectExceptionMessage('The OAuth state did not match.');

        $flow->complete($authorization, $callbackUrl);
    }

    public function testRefreshPersistsTheRotatedRefreshToken(): void
    {
        $tokenStore = new DailyDevTokenStore($this->tokenFile);
        $tokenStore->save(new DailyDevTokenSet('old-access', 'old-refresh', null, 'read', 'Bearer'));
        $client = $this->createClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse(
                '{"access_token":"new-access","refresh_token":"new-refresh","expires_in":3600}',
                ['http_code' => 200],
            ),
        ));
        $flow = new DailyDevOAuthFlow($client, $tokenStore, $this->createLockFactory());

        $tokens = $flow->refreshStoredTokens();

        self::assertSame('new-access', $tokens->accessToken);
        self::assertSame('new-refresh', $tokenStore->load()?->refreshToken);
    }

    private function createClient(?MockHttpClient $httpClient = null): DailyDevOAuthClient
    {
        return new DailyDevOAuthClient(
            $httpClient ?? new MockHttpClient(),
            'client-id',
            'client-secret',
            'http://127.0.0.1:8080/auth/callback',
            'openid profile offline_access read',
        );
    }

    private function createLockFactory(): LockFactory
    {
        return new LockFactory(new InMemoryStore());
    }
}
