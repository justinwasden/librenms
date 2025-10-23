<?php
namespace App\Services\RestApi\Auth;

use Illuminate\Support\Facades\Http;
use App\Models\RestApiConnection;
use App\Models\RestApiCredential;

class ProxmoxApiTokenAuthStrategy implements AuthStrategyInterface
{
    public function getName(): string
    {
        return 'proxmox-api-token';
    }

    /**
     * Expected credential params:
     * - user_realm: e.g., root@pam
     * - token_id:   e.g., mytoken
     * - token_secret: token secret string
     * - verify_ssl: '1' or '0'
     */
    public function prepareRequest(RestApiConnection $connection, RestApiCredential $credential, string $httpMethod)
    {
        $userRealm = $credential->getParamValue('user_realm');
        $tokenId = $credential->getParamValue('token_id');
        $tokenSecret = $credential->getParamValue('token_secret');

        if (!$userRealm || !$tokenId || !$tokenSecret) {
            throw new \Exception('Proxmox API Token auth missing user_realm, token_id, or token_secret');
        }

        $authHeaderValue = sprintf('PVEAPIToken=%s!%s=%s', $userRealm, $tokenId, $tokenSecret);

        // With Proxmox API tokens, CSRFPreventionToken is not required for write ops (verify with your version)
        return Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30,
            'headers' => [
                'Authorization' => $authHeaderValue,
                'Accept' => 'application/json',
            ],
        ]);
    }
}