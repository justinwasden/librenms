@extends('layouts.librenmsv1')

@section('content')
    <x-device.page :device="$device">
        <x-device.edit-tabs :device="$device" :tab="$section ?? 'device'" />

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        @if ($section === 'api')
            <form id="edit-api" name="edit-api" method="POST" action="{{ route('device.edit.update', [$device->device_id]) }}" role="form" class="form-horizontal" novalidate>
                @method('PUT')
                @csrf
                @include('device.partials.device_api', [
                    'templates' => $templates ?? [],
                    'authTypes' => $authTypes ?? [],
                    'apiConfig' => $apiConfig ?? null,
                    'selectedTemplate' => $selectedTemplate ?? null,
                    'templateData' => $templateData ?? null,
                    'autoSelectTemplate' => $autoSelectTemplate ?? false,
                ])
            </form>
            <br><br>
            <div class="alert alert-info" role="alert">
                <p>To disable REST API polling, uncheck "Enable REST API discovery/polling" and click <b>Save Settings</b>.</p>
            </div>
        @elseif ($section === 'device' || !isset($section))
				    @include('device.edit.device')
				@elseif (isset($legacyContent))
				    {!! $legacyContent !!}
				@endif
    </x-device.page>
@endsection