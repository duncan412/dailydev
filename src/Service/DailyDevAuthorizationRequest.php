<?php

declare(strict_types=1);

namespace App\Service;

final readonly class DailyDevAuthorizationRequest
{
    public function __construct(
        public string $authorizationUrl,
        public string $state,
        public string $codeVerifier,
        public string $redirectUri,
    ) {
    }
}
