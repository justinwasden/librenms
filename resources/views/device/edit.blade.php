{{-- resources/views/device/edit.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Edit Device</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Tabs (match legacy panes) --}}
    @php
        $section = request()->get('section', 'device');
        $device_id = $device->device_id;
        $panes = [
            'device' => 'Device Settings',
            'snmp' => 'SNMP',
            // Legacy panes keep working via includes
            'ports' => (!$device->snmp_disable) ? 'Port Settings' : null,
            'routing' => \DB::table('bgpPeers')->where('device_id', $device_id)->exists() ? 'Routing' : null,
            'icon' => count(\App\Facades\LibrenmsConfig::get("os.{$device->os}.icons", [])) ? 'Icon' : null,
            'apps' => (!$device->snmp_disable) ? 'Applications' : null,
            'alert-rules' => 'Alert Rules',
            'modules' => (!$device->snmp_disable) ? 'Modules' : null,
            'services' => \App\Facades\LibrenmsConfig::get('show_services') ? 'Services' : null,
            'ipmi' => 'IPMI',
            'health' => \DB::table('sensors')->where('device_id', $device_id)->where('sensor_deleted', 0)->exists() ? 'Health' : null,
            'wireless-sensors' => \DB::table('wireless_sensors')->where('device_id', $device_id)->where('sensor_deleted', 0)->exists() ? 'Wireless Sensors' : null,
            'storage' => (!$device->snmp_disable) ? 'Storage' : null,
            'processors' => (!$device->snmp_disable) ? 'Processors' : null,
            'mempools' => (!$device->snmp_disable) ? 'Memory' : null,
            'misc' => 'Misc',
            'component' => 'Components',
            'customoid' => 'Custom OID',
        ];
        // Clean nulls
        $panes = array_filter($panes, fn($v) => !is_null($v));
    @endphp

    <ul class="nav nav-tabs mb-3">
        @foreach ($panes as $type => $text)
            <li role="presentation" class="{{ $section === $type ? 'active' : '' }}">
                @if ($type === 'device')
                    <a href="{{ route('device.edit', [$device_id]) }}">{{ $text }}</a>
                @else
                    <a href="{{ url("device/{$device_id}/edit?section={$type}") }}">{{ $text }}</a>
                @endif
            </li>
        @endforeach
    </ul>

    @if (!Auth::user()->hasGlobalAdmin())
        <div class="alert alert-danger">Insufficient Privileges</div>
    @else
        @if ($section === 'device')
            <form method="POST" action="{{ route('device.update', ['device' => $device_id]) }}">
                @csrf
                @method('PUT')

                <div class="card">
                    <div class="card-header">General</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Hostname</label>
                            <input type="text" class="form-control" name="hostname" value="{{ old('hostname', $device->hostname) }}">
                        </div>
                        {{-- Add other general device fields here as you migrate them from legacy --}}
                    </div>
                </div>

                {{-- Device API configuration section --}}
                @include('device.partials.device_api')

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ route('device.show', ['device' => $device_id]) }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        @else
            {{-- For non-device sections, you can temporarily render legacy includes --}}
            <div class="alert alert-warning">
                The “{{ $panes[$section] ?? $section }}” section is still using the legacy view.
            </div>
            @php
                // Bridge to legacy includes for now:
                $legacy = base_path("includes/html/pages/device/edit/{$section}.inc.php");
                if (is_file($legacy)) {
                    require $legacy;
                } else {
                    echo "<div class='alert alert-info'>Legacy section file not found: includes/html/pages/device/edit/{$section}.inc.php</div>";
                }
            @endphp
        @endif
    @endif
</div>
@endsection