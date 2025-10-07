@extends('layouts.librenmsv1')

@section('title', 'REST API Templates')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-9 col-lg-10">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-layer-group"></i> REST API Templates
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#createTemplateModal">
                            <i class="fas fa-plus"></i> Add Template
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle"></i> {{ session('success') }}
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    @endif

                    @if($templates->isEmpty())
                        <div class="alert alert-info text-center py-4">
                            <i class="fas fa-info-circle fa-2x mb-3"></i>
                            <h5>No REST API Templates Found</h5>
                            <p class="mb-3">Templates allow you to define reusable API configurations for devices.</p>
                            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#createTemplateModal">
                                <i class="fas fa-plus"></i> Create Your First Template
                            </button>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Vendor</th>
                                        <th>Description</th>
                                        <th>Connections</th>
                                        <th>Endpoints</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($templates as $template)
                                        @php
                                            $template_data = is_array($template->template_data)
                                                ? $template->template_data
                                                : json_decode($template->template_data, true);
                                            $connections = $template_data['connections'] ?? [];
                                            $totalEndpoints = collect($connections)->sum(function($conn) {
                                                return count($conn['endpoints'] ?? []);
                                            });
                                        @endphp
                                        <tr>
                                            <td>
                                                <strong>{{ $template->name }}</strong>
                                            </td>
                                            <td>
                                                @if($template->vendor)
                                                    <span class="badge badge-info">{{ $template->vendor }}</span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                <small class="text-muted">{{ $template->description ?? 'No description' }}</small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-secondary">{{ count($connections) }}</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-secondary">{{ $totalEndpoints }}</span>
                                            </td>
                                            <td class="text-right">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="{{ route('settings.rest-api.templates.edit', $template->id) }}"
                                                       class="btn btn-info"
                                                       title="Edit Template">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <button type="button"
                                                            class="btn btn-danger"
                                                            data-toggle="modal"
                                                            data-target="#deleteModal{{ $template->id }}"
                                                            title="Delete Template">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        {{-- Delete Confirmation Modal --}}
                                        <div class="modal fade" id="deleteModal{{ $template->id }}" tabindex="-1" role="dialog">
                                            <div class="modal-dialog" role="document">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-danger text-white">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-exclamation-triangle"></i> Confirm Delete
                                                        </h5>
                                                        <button type="button" class="close text-white" data-dismiss="modal">
                                                            <span>&times;</span>
                                                        </button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p>Are you sure you want to delete the template <strong>{{ $template->name }}</strong>?</p>
                                                        <p class="text-muted mb-0">
                                                            <small>This action cannot be undone. Devices using this template will not be affected.</small>
                                                        </p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                        <form action="{{ route('settings.rest-api.templates.destroy', $template->id) }}" method="POST" class="d-inline">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-danger">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Create Template Modal --}}
<div class="modal fade" id="createTemplateModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="{{ route('devices.rest-api.templates.store') }}" method="POST">
                @csrf
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-plus"></i> Create REST API Template
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    @include('settings.rest-api.templates._form', [
                        'template' => new \App\Models\RestApiTemplate(),
                        'device' => null
                    ])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(document).ready(function() {
    // Auto-hide success messages after 5 seconds
    setTimeout(function() {
        $('.alert-success').fadeOut('slow');
    }, 5000);

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
});
</script>
@endpush
