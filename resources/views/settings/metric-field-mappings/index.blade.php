@extends('layouts.librenmsv1')

@section('title', 'API Metric Mappings')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h3>
                <i class="fa fa-exchange"></i> REST API Metric Field Mappings
            </h3>
            <p class="text-muted">Configure how REST API metrics are mapped to LibreNMS database fields</p>

            {{-- Success/Error Messages --}}
            @if(session('success'))
                <div class="alert alert-success alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    {{ session('error') }}
                </div>
            @endif

            {{-- Action Buttons --}}
						<div class="row mb-3">
						    <div class="col-md-12">
						        {{-- Corrected 'Create New Mapping' link --}}
						        <a href="{{ route('admin.metric-field-mappings.create') }}" class="btn btn-primary">
						            <i class="fa fa-plus"></i> Create New Mapping
						        </a>

						        <button type="button" class="btn btn-success" data-toggle="modal" data-target="#runMatchingModal">
						            <i class="fa fa-refresh"></i> Run Matching
						        </button>

						        <button type="button" class="btn btn-warning" onclick="confirmBulkDelete()">
						            <i class="fa fa-trash"></i> Delete All Unmatched
						        </button>

						        {{-- Corrected 'Import from JSON' link to SHOW THE FORM --}}
										<a href="{{ route('admin.metric-field-mappings.import.show') }}" class="btn btn-sm btn-primary" style="display: inline-block; vertical-align: middle;">
										    <i class="fa fa-upload"></i> Import from JSON
										</a>

						        {{-- Corrected 'Export to JSON' link --}}
						        <a href="{{ route('admin.metric-field-mappings.export') }}" class="btn btn-info">
						            <i class="fa fa-download"></i> Export to JSON
						        </a>
						    </div>
						</div>

            {{-- Filters --}}
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Filters</h3>
                </div>
                <div class="panel-body">
                    <form method="GET" action="{{ route('admin.metric-field-mappings.index') }}" class="form-inline">
                        <div class="form-group mr-2">
                            <input type="text" name="search" class="form-control" placeholder="Search..." value="{{ request('search') }}">
                        </div>

                        <div class="form-group mr-2">
                            <select name="vendor" class="form-control">
                                <option value="">All Vendors</option>
                                @foreach($vendors as $vendor)
                                    <option value="{{ $vendor }}" {{ request('vendor') == $vendor ? 'selected' : '' }}>
                                        {{ $vendor }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group mr-2">
                            <select name="os" class="form-control">
                                <option value="">All OS</option>
                                @foreach($operatingSystems as $os)
                                    <option value="{{ $os }}" {{ request('os') == $os ? 'selected' : '' }}>
                                        {{ $os }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group mr-2">
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="matched" {{ request('status') == 'matched' ? 'selected' : '' }}>Matched</option>
                                <option value="unmatched" {{ request('status') == 'unmatched' ? 'selected' : '' }}>Unmatched</option>
                            </select>
                        </div>

                        <div class="form-group mr-2">
                            <select name="auto_learned" class="form-control">
                                <option value="">All Types</option>
                                <option value="1" {{ request('auto_learned') == '1' ? 'selected' : '' }}>Auto-learned</option>
                                <option value="0" {{ request('auto_learned') == '0' ? 'selected' : '' }}>Manual</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="{{ route('admin.metric-field-mappings.index') }}" class="btn btn-default">Reset</a>
                    </form>
                </div>
            </div>

            {{-- Mappings Table --}}
            <div class="table-responsive">
                <table class="table table-striped table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>Metric Name</th>
                            <th>Resource Type</th>
                            <th>Vendor/OS</th>
                            <th>Maps To</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Last Seen</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($mappings as $mapping)
                            <tr class="{{ $mapping->isUnmatched() ? 'warning' : '' }}">
                                <td>
                                    <strong>{{ $mapping->metric_name }}</strong>
                                    @if($mapping->auto_learned)
                                        <span class="label label-info">Auto</span>
                                    @else
                                        <span class="label label-success">Manual</span>
                                    @endif
                                </td>
                                <td>{{ $mapping->resource_type ?? 'N/A' }}</td>
                                <td>
                                    <small>
                                        {{ $mapping->vendor ?? 'generic' }} / {{ $mapping->os ?? 'generic' }}
                                    </small>
                                </td>
                                <td>
                                    @if($mapping->isUnmatched())
                                        <span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Unmatched</span>
                                    @else
                                        <code>{{ $mapping->librenms_table }}.{{ $mapping->librenms_field }}</code>
                                    @endif
                                </td>
                                <td><span class="label label-default">{{ $mapping->data_type }}</span></td>
                                <td>
                                    @if($mapping->enabled)
                                        <span class="label label-success"><i class="fa fa-check"></i> Enabled</span>
                                    @else
                                        <span class="label label-default"><i class="fa fa-pause"></i> Disabled</span>
                                    @endif
                                </td>
                                <td><small>{{ $mapping->last_seen_at?->diffForHumans() ?? 'Never' }}</small></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('admin.metric-field-mappings.edit', $mapping) }}" class="btn btn-primary" title="Edit">
                                            <i class="fa fa-edit"></i>
                                        </a>

                                        <form action="{{ route('admin.metric-field-mappings.toggle', $mapping) }}" method="POST" style="display: inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-{{ $mapping->enabled ? 'warning' : 'success' }}" title="{{ $mapping->enabled ? 'Disable' : 'Enable' }}">
                                                <i class="fa fa-{{ $mapping->enabled ? 'pause' : 'play' }}"></i>
                                            </button>
                                        </form>

                                        <form action="{{ route('admin.metric-field-mappings.destroy', $mapping) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this mapping?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger" title="Delete">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center">No metric mappings found</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="text-center">
                {{ $mappings->links() }}
            </div>
        </div>
    </div>
</div>

{{-- Run Matching Modal --}}
<div class="modal fade" id="runMatchingModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="{{ route('admin.metric-field-mappings.run-matching') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Run Metric Matching</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Vendor (optional)</label>
                        <select name="vendor" class="form-control">
                            <option value="">All Vendors</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor }}">{{ $vendor }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label>OS (optional)</label>
                        <select name="os" class="form-control">
                            <option value="">All OS</option>
                            @foreach($operatingSystems as $os)
                                <option value="{{ $os }}">{{ $os }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="reset" value="1">
                            Reset and re-match all metrics
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Run Matching</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
function confirmBulkDelete() {
    if (confirm('Are you sure you want to delete ALL unmatched mappings? This cannot be undone.')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '{{ route("admin.metric-field-mappings.bulk-delete-unmatched") }}';

        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = '{{ csrf_token() }}';
        form.appendChild(csrfInput);

        var methodInput = document.createElement('input');
        methodInput.type = 'hidden';
        methodInput.name = '_method';
        methodInput.value = 'DELETE';
        form.appendChild(methodInput);

        document.body.appendChild(form);
        form.submit();
    }
}
</script>
@endsection
