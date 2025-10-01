@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">REST API Credentials</h3>
                <div class="card-tools">
                    <a href="{{ route('settings.rest-api.credentials.create') }}" class="btn btn-sm btn-primary">Add Credential</a>
                </div>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Auth Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($credentials as $credential)
                            <tr>
                                <td>{{ $credential->name }}</td>
                                <td>{{ $credential->authenticationType->name }}</td>
                                <td>
                                    <a href="{{ route('settings.rest-api.credentials.edit', $credential) }}" class="btn btn-sm btn-info">Edit</a>
                                    <form action="{{ route('settings.rest-api.credentials.destroy', $credential) }}" method="POST" style="display: inline-block;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection