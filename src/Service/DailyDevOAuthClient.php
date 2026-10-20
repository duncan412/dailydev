<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DailyDevOAuthClient
{
    private const AUTHORIZE_URL = 'https://api.daily.dev/auth/oauth2/authorize';
    private const TOKEN_URL = 'https://api.daily.dev/auth/oauth2/token';
    private const RESOURCE = 'https://api.daily.dev/public/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(DAILY_DEV_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(DAILY_DEV_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
        #[Autowire('%env(DAILY_DEV_REDIRECT_URI)%')]
        private readonly string $redirectUri,
        #[Autowire('%env(DAILY_DEV_SCOPES)%')]
        private readonly string $scopes,
    ) {
    }

    public function createAuthorizationRequest(): DailyDevAuthorizationRequest
    {
        $this->assertClientCredentials();
        $this->assertRedirectUri();

        $state = $this->randomToken();
        $codeVerifier = $this->randomToken(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $scopes = preg_split('/\s+/', trim($this->scopes), flags: PREG_SPLIT_NO_EMPTY);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $scopes ?: []),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'resource' => self::RESOURCE,
        ], encoding_type: PHP_QUERY_RFC3986);

        return new DailyDevAuthorizationRequest(
            authorizationUrl: self::AUTHORIZE_URL . '?' . $query,
            state: $state,
            codeVerifier: $codeVerifier,
            redirectUri: $this->redirectUri,
        );
    }

    public function exchangeCode(string $code, string $codeVerifier): DailyDevTokenSet
    {
        $this->assertRedirectUri();

        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $codeVerifier,
            'resource' => self::RESOURCE,
        ]);

        return DailyDevTokenSet::fromResponse($response);
    }

    public function refresh(DailyDevTokenSet $tokens): DailyDevTokenSet
    {
        if ($tokens->refreshToken === null) {
            throw new DailyDevOAuthException('No daily.dev refresh token is available.');
        }

        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens->refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'resource' => self::RESOURCE,
        ]);

        return DailyDevTokenSet::fromResponse($response, $tokens->refreshToken);
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(array $body): array
    {
        $this->assertClientCredentials();

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => $body,
            ]);
            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            throw new DailyDevOAuthException('Could not reach the daily.dev token endpoint.', previous: $exception);
        } catch (DecodingExceptionInterface $exception) {
            throw new DailyDevOAuthException('The daily.dev token endpoint returned invalid JSON.', previous: $exception);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new DailyDevOAuthException(sprintf('The daily.dev token endpoint returned HTTP %d.', $statusCode));
        }

        return $data;
    }

    private function assertClientCredentials(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new DailyDevOAuthException(
                'Set DAILY_DEV_CLIENT_ID and DAILY_DEV_CLIENT_SECRET before signing in.',
            );
        }
    }

    private function assertRedirectUri(): void
    {
        $redirect = parse_url($this->redirectUri);

        if (
            $redirect === false
            || !in_array($redirect['scheme'] ?? null, ['http', 'https'], true)
            || !isset($redirect['host'])
            || isset($redirect['user'])
            || isset($redirect['pass'])
            || isset($redirect['query'])
            || isset($redirect['fragment'])
        ) {
            throw new DailyDevOAuthException(
                'DAILY_DEV_REDIRECT_URI must be an HTTP(S) URL without credentials, query parameters or a fragment.',
            );
        }
    }

    private function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
