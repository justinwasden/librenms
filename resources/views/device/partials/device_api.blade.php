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

    @php
        // Determine the requested section (default to 'device')
        $section = request()->get('section', 'device');
        $device_id = $device->device_id;
    @endphp

    @if (!Auth::user()->hasGlobalAdmin())
        <div class="alert alert-danger">Insufficient Privileges</div>
    @else
        @if ($section === 'device' || $section === 'api')
            <form method="POST" action="{{ route('device.update', ['device' => $device_id]) }}">
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

                    {{-- Device API section can be displayed within Device Settings if you still want it here --}}
                    @include('device.partials.device_api')
                @endif

                @if ($section === 'api')
                    {{-- Dedicated Device API tab --}}
                    @include('device.partials.device_api')
                @endif

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ route('device.show', ['device' => $device_id]) }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        @else
            {{-- This Blade view only handles "device" and "api" sections.
                 Other sections (snmp, ports, etc.) remain on legacy includes and are routed by the legacy dispatcher. --}}
            <div class="alert alert-warning">This section is handled by the legacy UI.</div>
        @endif
    @endif
</div>
@endsection