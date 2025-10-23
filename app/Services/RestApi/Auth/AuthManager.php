<?php
namespace App\Services\RestApi\Auth;

use App\Models\RestApiConnection;
use App\Models\RestApiCredential;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class AuthManager
{
    protected array $strategies = [];

    public function __construct()
    {
        // Keep existing registrations
        $this->strategies['basic'] = new BasicAuthStrategy();
        $this->strategies['bearer'] = new BearerAuthStrategy();
        $this->strategies['api_key'] = new ApiKeyAuthStrategy();
        $this->strategies['session token'] = new SessionTokenAuthStrategy(); // Pure Storage
        $this->strategies['proxmox'] = new ProxmoxAuthStrategy();
        $this->strategies['oauth2'] = new BearerAuthStrategy(); // or a dedicated OAuth2 strategy
        $this->strategies['custom'] = new CustomHeaderAuthStrategy();

        // NEW: register Proxmox API Token without disrupting others
        $this->strategies['proxmox api token'] = new ProxmoxApiTokenAuthStrategy();
    }

    public function getRequest(RestApiConnection $connection, ?RestApiCredential $credential, string $httpMethod)
    {
        $default = Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30,
        ]);

        if (!$credential || !$credential->authenticationType) {
            return $default;
        }

        $authType = strtolower($credential->authenticationType->name);
        $strategy = $this->strategies[$authType] ?? null;

        if (!$strategy) {
            Log::warning("AuthManager: no strategy for auth type {$authType}, using default.");
            return $default;
        }

        return $strategy->prepareRequest($connection, $credential, $httpMethod);
    }
}