@extends('layouts.librenmsv1')

@section('title', 'API Auth Schemas')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-key"></i> Authentication Schemas
                        <div class="pull-right">
                            <a href="{{ route('admin.api-templates.index') }}" class="btn btn-default btn-sm">
                                <i class="fa fa-arrow-left"></i> Back to Templates
                            </a>
                            <a href="{{ route('admin.api-auth-schemas.create') }}" class="btn btn-primary btn-sm">
                                <i class="fa fa-plus"></i> New Schema
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

                    <p class="text-muted">
                        Authentication schemas define the credential fields required for different API authentication methods.
                    </p>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Fields</th>
                                    <th>Used By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($schemas as $schema)
                                    <tr>
                                        <td><code>{{ $schema->key }}</code></td>
                                        <td>
                                            {{ $schema->name }}
                                            @if($schema->is_system)
                                                <span class="label label-info">System</span>
                                            @endif
                                        </td>
                                        <td>{{ Str::limit($schema->description, 50) }}</td>
                                        <td>
                                            @foreach($schema->fields ?? [] as $field)
                                                <span class="label label-default" title="{{ $field['label'] ?? $field['name'] }}">
                                                    {{ $field['name'] }}
                                                    @if($field['required'] ?? false)
                                                        <span class="text-danger">*</span>
                                                    @endif
                                                </span>
                                            @endforeach
                                        </td>
                                        <td>
                                            <span class="badge">{{ $schema->templates_count ?? 0 }} templates</span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-xs">
                                                <a href="{{ route('admin.api-auth-schemas.edit', $schema) }}"
                                                   class="btn btn-primary" title="Edit">
                                                    <i class="fa fa-edit"></i>
                                                </a>
                                                @unless($schema->is_system)
                                                    <form action="{{ route('admin.api-auth-schemas.destroy', $schema) }}"
                                                          method="POST" style="display:inline"
                                                          onsubmit="return confirm('Delete this schema? Templates using it will need to be updated.')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-danger" title="Delete"
                                                                {{ ($schema->templates_count ?? 0) > 0 ? 'disabled' : '' }}>
                                                            <i class="fa fa-trash"></i>
                                                        </button>
                                                    </form>
                                                @endunless
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            No authentication schemas configured.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Field Types Reference -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h4 class="panel-title">
                        <i class="fa fa-info-circle"></i> Field Types Reference
                    </h4>
                </div>
                <div class="panel-body">
                    <dl class="dl-horizontal">
                        <dt>text</dt>
                        <dd>Standard text input (username, API key name, etc.)</dd>
                        <dt>password</dt>
                        <dd>Password input - value will be encrypted in database</dd>
                        <dt>number</dt>
                        <dd>Numeric input (port numbers, timeouts, etc.)</dd>
                        <dt>checkbox</dt>
                        <dd>Boolean toggle (verify SSL, etc.)</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
