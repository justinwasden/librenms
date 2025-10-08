{{-- resources/views/settings/rest-api/templates/edit.blade.php --}}
@extends('layouts.librenmsv1')

@section('title', 'Edit REST API Template')

@push('styles')
<style>
    [x-cloak] { display: none !important; }

    .action-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .action-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,.1);
    }

    /* Ensure wide modals */
    #endpointsModal .modal-dialog,
    #previewModal .modal-dialog {
        max-width: 95%;
    }

    .endpoint-form-scroll {
        max-height: 70vh;
        overflow-y: auto;
        padding-right: 15px;
    }
</style>
@endpush

@push('scripts')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
@endpush

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-9 col-xl-8">
            <div x-data="templateEditor()" x-init="init()">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Edit Template: {{ $template->name }}</h3>
                        <a href="{{ route('settings.rest-api.templates.index') }}" class="btn btn-default btn-sm">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>

                    {{-- Basic Template Form --}}
                    <form action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="card-body">
                            <h5 class="text-info mb-3"><i class="fas fa-info-circle"></i> Basic Information</h5>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="name">Template Name <span class="text-danger">*</span></label>
                                    <input type="text" id="name" name="name" class="form-control"
                                        value="{{ old('name', $template->name) }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="vendor">Vendor</label>
                                    <input type="text" id="vendor" name="vendor" class="form-control"
                                        value="{{ old('vendor', $template->vendor) }}">
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="resource_type">Primary Resource Type</label>
                                    <select name="resource_type" id="resource_type" class="form-control">
                                        <option value="">-- None (Generic) --</option>
                                        @foreach(['device','port','storage','sensor','processor','mempool','alert','custom'] as $type)
                                            <option value="{{ $type }}" {{ old('resource_type', $template->resource_type) === $type ? 'selected' : '' }}>
                                                {{ ucfirst($type) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="description">Description</label>
                                    <textarea id="description" name="description" class="form-control" rows="3">{{ old('description', $template->description) }}</textarea>
                                </div>
                            </div>

                            <hr>

                            <h5><i class="fas fa-tools"></i> Configuration Modules</h5>
                            <div class="row mt-3">
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100 text-center">
                                        <div class="card-body">
                                            <i class="fas fa-plug fa-3x text-info mb-3"></i>
                                            <h5>Connection Settings</h5>
                                            <p class="small text-muted">Base URL, Credentials, Login Path, and SSL</p>
                                            <button type="button" class="btn btn-info btn-block mt-3" data-toggle="modal" data-target="#connectionModal">
                                                <i class="fas fa-edit"></i> Configure
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100 text-center">
                                        <div class="card-body">
                                            <i class="fas fa-list fa-3x text-primary mb-3"></i>
                                            <h5>Endpoint Management</h5>
                                            <p class="small text-muted">Paths, Methods, Mapping, Intervals</p>
                                            <button type="button" class="btn btn-primary btn-block mt-3" data-toggle="modal" data-target="#endpointsModal">
                                                <i class="fas fa-tasks"></i> Manage
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100 text-center">
                                        <div class="card-body">
                                            <i class="fas fa-eye fa-3x text-success mb-3"></i>
                                            <h5>Test & Preview</h5>
                                            <p class="small text-muted">Verify API calls against a device</p>
                                            <button type="button" class="btn btn-success btn-block mt-3" data-toggle="modal" data-target="#previewModal">
                                                <i class="fas fa-play"></i> Run Test
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer text-right">
                            <a href="{{ route('settings.rest-api.templates.index') }}" class="btn btn-default"><i class="fas fa-times"></i> Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ============================= --}}
{{-- Connection Modal --}}
{{-- ============================= --}}
<div class="modal fade" id="connectionModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
            @csrf @method('PUT')
            <div class="modal-header bg-info text-white">
                <h5><i class="fas fa-plug"></i> Configure API Connection</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                @include('settings.rest-api.templates.partials.connection', ['template' => $template])
                <input type="hidden" name="action_type" value="update_connection_only">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-info"><i class="fas fa-save"></i> Save Connection</button>
            </div>
        </form>
    </div></div>
</div>

{{-- ============================= --}}
{{-- Endpoints Modal --}}
{{-- ============================= --}}
<div class="modal fade" id="endpointsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content" x-data="endpointManager()" x-init="init()">
        <form id="endpoint-management-form" method="POST" action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}">
            @csrf @method('PUT')
            <div class="modal-header bg-primary text-white">
                <h5><i class="fas fa-tasks"></i> Manage Endpoints</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>

            <div class="modal-body">
                <div class="row">
                    {{-- LEFT PANE --}}
                    <div class="col-md-3 border-right">
                        <h6 class="mb-3 text-primary"><i class="fas fa-list-ul"></i> Existing Endpoints</h6>
                        @php
                            $template_data = json_decode($template->template_data ?? '[]', true);
                            $connections = $template_data['connections'] ?? [];
                            $connection = $connections[0] ?? [];
                        @endphp
                        @if (!empty($connection))
                            <div class="alert alert-info py-2">Connection: {{ $connection['name'] ?? 'Unnamed' }}</div>
                            <div class="list-group" style="max-height:600px;overflow-y:auto;">
                                @forelse($connection['endpoints'] ?? [] as $i => $endpoint)
                                    <a href="#" class="list-group-item list-group-item-action"
                                        :class="{ 'active': activeEndpointIndex === '{{ $i }}' }"
                                        @click.prevent="openEndpoint('{{ $i }}', '{{ $endpoint['name'] ?? 'Unnamed' }}', {{ json_encode($endpoint) }})">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0"><span class="badge badge-secondary">{{ strtoupper($endpoint['method'] ?? 'GET') }}</span> {{ $endpoint['name'] ?? 'Unnamed' }}</h6>
                                            <small class="text-{{ ($endpoint['enabled'] ?? true) ? 'success' : 'danger' }}">{{ ($endpoint['enabled'] ?? true) ? 'Enabled' : 'Disabled' }}</small>
                                        </div>
                                        <small>{{ $endpoint['path'] ?? '' }}</small>
                                    </a>
                                @empty
                                    <div class="text-muted text-center py-3">No endpoints defined.</div>
                                @endforelse
                            </div>
                        @else
                            <div class="alert alert-danger">No connection configured.</div>
                        @endif
                        <button type="button" class="btn btn-success btn-block mt-3" @click="openNewEndpoint()">
                            <i class="fas fa-plus-circle"></i> Add New
                        </button>
                    </div>

                    {{-- RIGHT PANE --}}
                    <div class="col-md-9">
                        <div x-show="activeEndpointIndex || isAddingNew" x-cloak>
                            <h6 class="mb-3" x-text="isAddingNew ? 'New Endpoint' : 'Edit: ' + activeEndpointName"></h6>
                            <div class="endpoint-form-scroll">
                                <div id="endpoint-detail-container" x-html="currentEndpointFormHtml" @input="isFormDirty = true"></div>
                            </div>
                        </div>
                        <div x-show="!activeEndpointIndex && !isAddingNew">
                            <div class="alert alert-warning text-center mt-5"><i class="fas fa-hand-point-left"></i> Select or Add an endpoint.</div>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="action_type" value="update_endpoints_only">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary" :disabled="!isFormDirty"><i class="fas fa-save"></i> Save Endpoints</button>
            </div>

            <template id="full-endpoint-template">
                @include('settings.rest-api.templates.partials.endpoint-form', [
                    'connectionIndex' => 0,
                    'endpointIndex' => '__ACTIVE_INDEX__',
                    'endpoint' => [],
                ])
            </template>
        </form>
    </div></div>
</div>

{{-- ============================= --}}
{{-- Preview Modal --}}
{{-- ============================= --}}
<div class="modal fade" id="previewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content">
        <div class="modal-header bg-success text-white">
            <h5><i class="fas fa-eye"></i> Test Template Configuration</h5>
            <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
        </div>
        <div class="modal-body">@include('settings.rest-api.templates.partials.preview', ['template' => $template])</div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div>
    </div></div>
</div>

<script>
function templateEditor() { return { init() {} }; }

function endpointManager() {
    const escapeHtml = str => str ? str.replace(/[&<>"']/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[t])) : '';

    const hydrateForm = (html, data, index) => {
        html = html.replace(/__ACTIVE_INDEX__/g, index);
        for (const [k,v] of Object.entries(data)) {
            const val = escapeHtml(String(v ?? ''));
            html = html.replace(new RegExp(`name=".*\\[${k}\\]".*value="[^"]*"`), `name="template_data[connections][0][endpoints][${index}][${k}]" value="${val}"`);
        }
        return html;
    };

    return {
        activeEndpointIndex:null, activeEndpointName:'', activeEndpointData:{},
        isAddingNew:false, isFormDirty:false, currentEndpointFormHtml:'',
        init() {
            $('#endpointsModal').on('hide.bs.modal', e=>{
                if(this.isFormDirty && !confirm('Unsaved changes will be lost. Close anyway?')) e.preventDefault();
            });
        },
        openEndpoint(i,n,d){ if(this.isFormDirty&&!confirm('Discard unsaved changes?'))return;
            this.activeEndpointIndex=i;this.activeEndpointName=n;this.isAddingNew=false;this.isFormDirty=false;
            this.currentEndpointFormHtml=hydrateForm(document.getElementById('full-endpoint-template').innerHTML,d,i);
            this.$nextTick(()=>document.querySelector('.endpoint-form-scroll').scrollTop=0);
        },
        openNewEndpoint(){ if(this.isFormDirty&&!confirm('Discard unsaved changes?'))return;
            const idx='new_'+Date.now();this.activeEndpointIndex=idx;this.activeEndpointName='New';this.isAddingNew=true;this.isFormDirty=false;
            const d={name:'',path:'',method:'GET',poll_interval:300,enabled:true,metric_map:{}};
            this.currentEndpointFormHtml=hydrateForm(document.getElementById('full-endpoint-template').innerHTML,d,idx);
        }
    };
}
</script>
@endsection
