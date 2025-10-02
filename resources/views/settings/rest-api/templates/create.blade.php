@extends('layouts.librenmsv1')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Create REST API Template</h3>
            </div>
            <div class="card-body">
                {{-- FIX: Use the new store route --}}
                <form action="{{ route('devices.rest-api.templates.store') }}" method="POST">
                    @csrf
                    @include('settings.rest-api.templates._form')
                    <button type="submit" class="btn btn-primary">Create</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection