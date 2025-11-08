<?php

namespace App\ApiClients\NetApp;

use App\ApiClients\GenericDeviceApiClient;
use App\ApiClients\Contracts\DeviceApiClientInterface;

/**
 * NetApp ONTAP API Client
 *
 * This client can be extended with ONTAP-specific methods.
 * For now, it extends the GenericDeviceApiClient to use templates.
 */
class OntapClient extends GenericDeviceApiClient implements DeviceApiClientInterface
{
    /**
     * Vendor identifier for auto-detection
     */
    public const VENDOR = 'netapp_ontap';
}
