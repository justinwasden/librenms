<?php

namespace App\ApiClients\AuthStrategies;

use App\ApiClients\Contracts\AuthStrategyInterface;

class AuthStrategyFactory
{
    public static function make(string $strategyKey): ?AuthStrategyInterface
    {
        return match ($strategyKey) {
            'pure_token_login', 'purestorage_api_token_login' => new PureTokenLoginStrategy(),
            'basic' => new BasicAuthStrategy(),
            'bearer' => new BearerAuthStrategy(),
            // Fallbacks if optional strategies are not implemented in your codebase
            'api_key_header', 'apikey', 'apikey_custom_header' =>
                class_exists(ApiKeyHeaderStrategy::class) ? new ApiKeyHeaderStrategy() : new BearerAuthStrategy(),
            'api_key_query', 'apikey_query' =>
                class_exists(ApiKeyQueryStrategy::class) ? new ApiKeyQueryStrategy() : new BearerAuthStrategy(),
            'oauth2_client_credentials' =>
                class_exists(OAuth2ClientCredentialsStrategy::class) ? new OAuth2ClientCredentialsStrategy() : new BearerAuthStrategy(),
            'cookie_session', 'cookie' =>
                class_exists(CookieSessionStrategy::class) ? new CookieSessionStrategy() : new BearerAuthStrategy(),
            default => new BearerAuthStrategy(),
        };
    }
}