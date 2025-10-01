@extends('layouts.librenmsv1')

@section('title', __('REST API Credentials'))

@section('content')
<div class="container-fluid">
    <x-panel>
        <x-slot name="title">
            <i class="fa fa-key fa-fw fa-lg" aria-hidden="true"></i> {{ __('REST API Credentials') }}
        </x-slot>

        <x-slot name="heading">
            <a href="{{ route('settings.rest-api.credentials.create') }}" class="btn btn-primary btn-sm">
                <i class="fa fa-plus"></i> {{ __('Add Credential') }}
            </a>
        </x-slot>

        <div class="table-responsive">
            <table class="table table-striped table-bordered table-condensed">
                <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Auth Type') }}</th>
                        <th data-sortable="false">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($credentials as $credential)
                        <tr>
                            <td>{{ $credential->name }}</td>
                            <td>{{ $credential->authenticationType->name }}</td>
                            <td>
                                <a href="{{ route('settings.rest-api.credentials.edit', $credential) }}" class="btn btn-primary btn-sm">
                                    <i class="fa fa-pencil"></i> {{ __('Edit') }}
                                </a>
                                <form action="{{ route('settings.rest-api.credentials.destroy', $credential) }}" method="POST" style="display: inline-block;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('{{ __('Are you sure?') }}')">
                                        <i class="fa fa-trash"></i> {{ __('Delete') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center">{{ __('No credentials found') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>
</div>
@endsection