<?php

declare(strict_types=1);

namespace App\Service;

final readonly class DailyDevTokenSet
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?int $expiresAt,
        public ?string $scope,
        public string $tokenType,
    ) {
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function fromResponse(array $response, ?string $previousRefreshToken = null): self
    {
        $accessToken = $response['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new DailyDevOAuthException('The daily.dev token response did not contain an access token.');
        }

        $refreshToken = $response['refresh_token'] ?? $previousRefreshToken;
        if ($refreshToken !== null && !is_string($refreshToken)) {
            throw new DailyDevOAuthException('The daily.dev token response contained an invalid refresh token.');
        }

        $expiresIn = $response['expires_in'] ?? null;
        if ($expiresIn !== null && (!is_numeric($expiresIn) || (int) $expiresIn < 0)) {
            throw new DailyDevOAuthException('The daily.dev token response contained an invalid expiry.');
        }

        $scope = $response['scope'] ?? null;
        if ($scope !== null && !is_string($scope)) {
            throw new DailyDevOAuthException('The daily.dev token response contained an invalid scope.');
        }

        $tokenType = $response['token_type'] ?? 'Bearer';
        if (!is_string($tokenType) || $tokenType === '') {
            throw new DailyDevOAuthException('The daily.dev token response contained an invalid token type.');
        }

        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresIn === null ? null : time() + (int) $expiresIn,
            scope: $scope,
            tokenType: $tokenType,
        );
    }

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: ?string,
     *     expires_at: ?int,
     *     scope: ?string,
     *     token_type: string
     * }
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'scope' => $this->scope,
            'token_type' => $this->tokenType,
        ];
    }

    /**
     * @param array<string, mixed> $tokens
     */
    public static function fromArray(array $tokens): self
    {
        $accessToken = $tokens['access_token'] ?? null;
        $refreshToken = $tokens['refresh_token'] ?? null;
        $expiresAt = $tokens['expires_at'] ?? null;
        $scope = $tokens['scope'] ?? null;
        $tokenType = $tokens['token_type'] ?? null;

        if (
            !is_string($accessToken)
            || $accessToken === ''
            || ($refreshToken !== null && !is_string($refreshToken))
            || ($expiresAt !== null && !is_int($expiresAt))
            || ($scope !== null && !is_string($scope))
            || !is_string($tokenType)
            || $tokenType === ''
        ) {
            throw new DailyDevOAuthException('The saved daily.dev token file is invalid.');
        }

        return new self($accessToken, $refreshToken, $expiresAt, $scope, $tokenType);
    }
}
