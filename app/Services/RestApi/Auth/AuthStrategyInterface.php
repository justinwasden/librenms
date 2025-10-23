<?php
namespace App\\Services\\RestApi\\Auth;

use App\\Models\\RestApiConnection;
use App\\Models\\RestApiCredential;

interface AuthStrategyInterface
{
    public function getName(): string;

    /**
     * Prepare an Illuminate HTTP client configured with headers/cookies/auth
     * for the given connection and method.
     */
    public function prepareRequest(RestApiConnection $connection, RestApiCredential $credential, string $httpMethod);
}