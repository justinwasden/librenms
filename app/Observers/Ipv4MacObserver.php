<?php

namespace App\Observers;

use App\Models\Eventlog;
use App\Models\Ipv4Mac;
use Illuminate\Support\Facades\Log;
use LibreNMS\Enum\Severity;
use LibreNMS\Util\Mac;

class Ipv4MacObserver
{
    public function updated(Ipv4Mac $arp): void
    {
        // log mac changes
        if ($arp->wasChanged('mac_address')) {
            $old_mac = $arp->getOriginal('mac_address');
            $new_mac = $arp->mac_address;

            Log::debug("Changed mac address for {$arp->ipv4_address} from {$old_mac} to {$new_mac}");

            $old_readable = $old_mac;
            $new_readable = $new_mac;

            try {
                $old_readable = Mac::parse($old_mac)->readable();
            } catch (\Throwable $e) {
                // leave as raw if parsing fails
            }

            try {
                $new_readable = Mac::parse($new_mac)->readable();
            } catch (\Throwable $e) {
            }

            Eventlog::log(
                "MAC change: {$arp->ipv4_address} : {$old_readable} -> {$new_readable}",
                $arp->device_id,
                'interface',
                Severity::Warning,
                $arp->port_id
            );
        }
    }
}
