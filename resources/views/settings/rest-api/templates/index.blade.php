@extends('layouts.librenmsv1')

@section('title', __('REST API Templates'))

@section('content')
<div class="container-fluid">
    <x-panel>
        <x-slot name="title">
            <i class="fa fa-file-code-o fa-fw fa-lg" aria-hidden="true"></i> {{ __('REST API Templates') }}
        </x-slot>

        <x-slot name="heading">
            <a href="{{ route('settings.rest-api.templates.create') }}" class="btn btn-primary btn-sm">
                <i class="fa fa-plus"></i> {{ __('Add Template') }}
            </a>
        </x-slot>

        <div class="table-responsive">
            <table class="table table-striped table-bordered table-condensed">
                <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Vendor') }}</th>
                        <th data-sortable="false">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($templates as $template)
                        <tr>
                            <td>{{ $template->name }}</td>
                            <td>{{ $template->vendor ?? __('N/A') }}</td>
                            <td>
                                <a href="{{ route('settings.rest-api.templates.edit', $template) }}" class="btn btn-primary btn-sm">
                                    <i class="fa fa-pencil"></i> {{ __('Edit') }}
                                </a>
                                <form action="{{ route('settings.rest-api.templates.destroy', $template) }}" method="POST" style="display: inline-block;">
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
                            <td colspan="3" class="text-center">{{ __('No templates found') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>
</div>
@endsection