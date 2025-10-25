{{-- resources/views/device/edit.blade.php --}}
@extends('layouts.librenmsv1')

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

    @php
        $section = request()->get('section', 'device');
        $device_id = $device->device_id;

        // Build tabs similar to legacy, inserting "Device API" after SNMP
        $panes = [];
        $panes['device'] = 'Device Settings';
        $panes['snmp'] = 'SNMP';
        $panes['api'] = 'Device API'; // new tab

        if (! $device->snmp_disable) {
            $panes['ports'] = 'Port Settings';
        }
        if (\DB::table('bgpPeers')->where('device_id', $device_id)->exists()) {
            $panes['routing'] = 'Routing';
        }
        if (count(\App\Facades\LibrenmsConfig::get("os.{$device->os}.icons", []))) {
            $panes['icon'] = 'Icon';
        }
        if (! $device->snmp_disable) {
            $panes['apps'] = 'Applications';
        }
        $panes['alert-rules'] = 'Alert Rules';
        if (! $device->snmp_disable) {
            $panes['modules'] = 'Modules';
        }
        if (\App\Facades\LibrenmsConfig::get('show_services')) {
            $panes['services'] = 'Services';
        }
        $panes['ipmi'] = 'IPMI';
        if (\DB::table('sensors')->where('device_id', $device_id)->where('sensor_deleted', 0)->exists()) {
            $panes['health'] = 'Health';
        }
        if (\DB::table('wireless_sensors')->where('device_id', $device_id)->where('sensor_deleted', 0)->exists()) {
            $panes['wireless-sensors'] = 'Wireless Sensors';
        }
        if (! $device->snmp_disable) {
            $panes['storage'] = 'Storage';
            $panes['processors'] = 'Processors';
            $panes['mempools'] = 'Memory';
        }
        $panes['misc'] = 'Misc';
        $panes['component'] = 'Components';
        $panes['customoid'] = 'Custom OID';
    @endphp

    {{-- Tabs --}}
    <ul class="nav nav-tabs mb-3">
        @foreach ($panes as $type => $text)
            <li role="presentation" class="{{ $section === $type ? 'active' : '' }}">
                @if ($type === 'device')
                    <a href="{{ route('device.edit', [$device_id]) }}">{{ $text }}</a>
                @elseif ($type === 'api')
                    <a href="{{ route('device.edit', [$device_id]) }}?section=api">{{ $text }}</a>
                @else
                    {{-- Link to legacy sections directly --}}
                    <a href="{{ url("device/device=$device_id/tab=edit/section=$type") }}">{{ $text }}</a>
                @endif
            </li>
        @endforeach
    </ul>

    @if (!Auth::user()->hasGlobalAdmin())
        <div class="alert alert-danger">Insufficient Privileges</div>
    @else
        @if ($section === 'device' || $section === 'api')
            <form method="POST" action="{{ route('device.edit.update', ['device' => $device_id]) }}">
                @csrf
                @method('PUT')

                @if ($section === 'device')
                    <div class="card">
                        <div class="card-header">General</div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Hostname</label>
                                <input type="text" class="form-control" name="hostname" value="{{ old('hostname', $device->hostname) }}">
                            </div>
                            {{-- Add other general device fields here if desired --}}
                        </div>
                    </div>

                @endif

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    {{-- Cancel goes back to the legacy device page --}}
                    <a href="{{ url("device/{$device_id}") }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        @else
            <div class="alert alert-warning">This section is handled by the legacy UI.</div>
        @endif
    @endif
</div>
@endsection