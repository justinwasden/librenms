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
        $section = isset($section) ? $section : request()->get('section', 'api');
        $device_id = $device->device_id;
    @endphp

    @if (!Auth::user()->hasGlobalAdmin())
        <div class="alert alert-danger">Insufficient Privileges</div>
    @else
        @if ($section === 'api')
            <form method="POST" action="{{ route('device.edit.update', ['device' => $device_id]) }}">
                @csrf
                @method('PUT')

                @include('device.partials.device_api')

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ url("device/{$device_id}") }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        @else
            <div class="alert alert-warning">This section is handled by the legacy UI.</div>
        @endif
    @endif
</div>
@endsection