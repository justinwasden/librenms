@extends('layouts.librenmsv1')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Edit REST API Template</h3>
            </div>
            <div class="card-body">
                <form action="{{ route('devices.rest-api.templates.update', $template) }}" method="POST">
                    @csrf
                    @method('PUT')
                    @include('settings.rest-api.templates._form')
                    <button type="submit" class="btn btn-primary">Update</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection