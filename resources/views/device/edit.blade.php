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
            <form method="POST" action="{{ route('device.edit.update', $device) }}" class="container-fluid">
                @method('PUT')
                @csrf
                @include('device.partials.device_api')
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check"></i> Save</button>
                </div>
            </form>
        @elseif ($section === 'device' || !isset($section))
            @include('device.edit.device')
        @endif
    </x-device.page>
@endsection