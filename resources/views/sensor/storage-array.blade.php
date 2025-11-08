@props(['device'])

@php
    $isStorageDevice = ($device->type === 'storage')
        || in_array($device->os, [
            'purestorage','netapp','ontap','intelliflash','unity','powerstore','isilon','nimble','threepar','primera','svc','flashsystem','vsp','oceanstor','ceph',
        ]);

    $array = $device->storageArray()->first();
    $arrayCapacityRow = $device->storage()
        ->where('type', 'array') // convention: create one with type 'array' and descr 'Array Capacity' if desired
        ->orWhere('storage_descr', 'Array Capacity')
        ->first();
@endphp

@if ($isStorageDevice && $array)
<div class="panel panel-default panel-condensed">
    <div class="panel-heading">
        <i class="fa fa-database fa-lg icon-theme" aria-hidden="true"></i>
        <strong>Storage Array Overview</strong>
        <div class="pull-right">
            {{ $array->array_name ?? $device->displayName() }} |
            {{ $array->vendor }} {{ $array->model }} |
            {{ $array->software_version }}
        </div>
    </div>

    <table class="table table-hover table-condensed table-striped">
        <tbody>
        <tr>
            <td style="width:30%;">Capacity</td>
            <td>
                {{ \LibreNMS\Util\Number::formatBi($array->used_bytes) }} /
                {{ \LibreNMS\Util\Number::formatBi($array->total_bytes) }}
                Ñ {{ round($array->used_pct) }}% used
                @if ($arrayCapacityRow)
                    @php
                        $graph = [
                            'height' => 20,
                            'width' => 80,
                            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
                            'id' => $arrayCapacityRow->storage_id,
                            'type' => 'storage_usage',
                            'from' => \App\Facades\LibrenmsConfig::get('time.day'),
                            'legend' => 'no',
                            'bg' => 'ffffff00',
                        ];
                        $mini = \LibreNMS\Util\Url::lazyGraphTag($graph);
                        $link = \LibreNMS\Util\Url::generate([
                            'page' => 'graphs',
                            'id' => $arrayCapacityRow->storage_id,
                            'type' => 'storage_usage',
                            'from' => \App\Facades\LibrenmsConfig::get('time.day'),
                            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
                        ]);
                    @endphp
                    {!! \LibreNMS\Util\Url::overlibLink($link, $mini, \LibreNMS\Util\Url::overlibContent($graph, $device->displayName().' - Array Capacity')) !!}
                @endif
            </td>
        </tr>
        <tr>
            <td>Efficiency</td>
            <td>
                @if (!is_null($array->data_reduction_ratio))
                    DRR: {{ number_format($array->data_reduction_ratio, 2) }}:1
                @else
                    Not reported
                @endif
            </td>
        </tr>
        <tr>
            <td>Inventory</td>
            <td>
                Controllers: {{ $array->controllers_count }} |
                Volumes: {{ $array->volumes_count }} |
                Hosts: {{ $array->hosts_count }} |
                Replication Links: {{ $array->replication_links_count }}
            </td>
        </tr>
        <tr>
            <td>Alerts</td>
            <td>
                Open alerts: <span class="label {{ $array->alerts_open_count ? 'label-danger' : 'label-success' }}">{{ $array->alerts_open_count }}</span>
            </td>
        </tr>
        </tbody>
    </table>
</div>
@endif
