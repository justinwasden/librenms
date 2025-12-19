<?php

namespace App\Http\ViewComposers;

use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

class DeviceOverviewComposer
{
    public function compose(View $view): void
    {
        // Expect $device already bound to the overview view
        $data = $view->getData();
        $device = $data['device'] ?? null;

        if (! $device || ! in_array($device->os, ['vmware_vcenter', 'vcenter', 'vmware'], true)) {
            return;
        }

        $clusters = DB::table('hypervisor_clusters')
            ->where('device_id', $device->device_id)
            ->where('cluster_type', 'vmware')
            ->orderBy('cluster_name')
            ->get();

        // Share $clusters so your Blade partial can render
        $view->with('clusters', $clusters);
    }
}
