<?php

namespace App\ApiClients\AuthStrategies;

use App\ApiClients\Contracts\AuthStrategyInterface;

class AuthStrategyFactory
{
    public static function make(string $strategyKey): ?AuthStrategyInterface
    {
        return match ($key) {
            'pure_token_login' => new PureTokenLoginStrategy(),
            'basic'            => new BasicAuthStrategy(),
            'bearer'           => new BearerAuthStrategy(),
            'api_key_header'   => new ApiKeyHeaderStrategy(),
            'api_key_query'    => new ApiKeyQueryStrategy(),
            'oauth2_client_credentials' => new OAuth2ClientCredentialsStrategy(),
            'cookie_session'   => new CookieSessionStrategy(),
            default            => new BearerAuthStrategy(), // sane default
        };
    }
}