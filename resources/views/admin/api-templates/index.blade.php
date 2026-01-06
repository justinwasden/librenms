@extends('layouts.librenmsv1')

@section('title', 'API Templates')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-plug"></i> API Templates
                        <div class="pull-right">
                            <a href="{{ route('admin.api-auth-schemas.index') }}" class="btn btn-default btn-sm">
                                <i class="fa fa-key"></i> Auth Schemas
                            </a>
                            <a href="{{ route('admin.api-templates.create') }}" class="btn btn-primary btn-sm">
                                <i class="fa fa-plus"></i> New Template
                            </a>
                        </div>
                    </h3>
                </div>
                <div class="panel-body">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Name</th>
                                    <th>Auth Type</th>
                                    <th>OS Types</th>
                                    <th>Endpoints</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($templates as $template)
                                    <tr>
                                        <td><code>{{ $template->key }}</code></td>
                                        <td>
                                            {{ $template->name }}
                                            @if($template->is_system)
                                                <span class="label label-info">System</span>
                                            @endif
                                        </td>
                                        <td>{{ $template->authSchema->name ?? $template->auth_type }}</td>
                                        <td>
                                            @foreach($template->os_types ?? [] as $os)
                                                <span class="label label-default">{{ $os }}</span>
                                            @endforeach
                                        </td>
                                        <td>
                                            <span class="badge">{{ $template->endpoints->count() }}</span>
                                            ({{ $template->endpoints->where('enabled', true)->count() }} enabled)
                                        </td>
                                        <td>
                                            @if($template->enabled)
                                                <span class="label label-success">Enabled</span>
                                            @else
                                                <span class="label label-danger">Disabled</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-xs">
                                                <a href="{{ route('admin.api-templates.edit', $template) }}"
                                                   class="btn btn-primary" title="Edit">
                                                    <i class="fa fa-edit"></i>
                                                </a>
                                                <a href="{{ route('admin.api-templates.export', $template) }}"
                                                   class="btn btn-info" title="Export">
                                                    <i class="fa fa-download"></i>
                                                </a>
                                                <form action="{{ route('admin.api-templates.clone', $template) }}"
                                                      method="POST" style="display:inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-default" title="Clone">
                                                        <i class="fa fa-copy"></i>
                                                    </button>
                                                </form>
                                                @unless($template->is_system)
                                                    <form action="{{ route('admin.api-templates.destroy', $template) }}"
                                                          method="POST" style="display:inline"
                                                          onsubmit="return confirm('Delete this template?')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-danger" title="Delete">
                                                            <i class="fa fa-trash"></i>
                                                        </button>
                                                    </form>
                                                @endunless
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">
                                            No API templates configured.
                                            <a href="{{ route('admin.api-templates.create') }}">Create one</a> or seed defaults.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <hr>
                    <h4>Import Template</h4>
                    <form action="{{ route('admin.api-templates.import') }}" method="POST" enctype="multipart/form-data" class="form-inline">
                        @csrf
                        <div class="form-group">
                            <input type="file" name="file" accept=".json" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-default">
                            <i class="fa fa-upload"></i> Import
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
