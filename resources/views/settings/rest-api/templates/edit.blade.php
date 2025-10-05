{{-- /resources/views/device/rest-api/templates/edit.blade.php --}}
@extends('layouts.librenmsv1')

@section('content')
<div x-data="{ tab: 'connection', openEndpoint: null }">
    <div class="modal fade show" id="editTemplateModal" tabindex="-1" role="dialog" aria-labelledby="editTemplateLabel" aria-modal="true" style="display:block;">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <form action="{{ route('devices.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="modal-header">
                        <h5 class="modal-title">Edit REST API Template: {{ $template->name }}</h5>
                        <button type="button" class="close" onclick="window.history.back();">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                        {{-- Tabs --}}
                        <ul class="nav nav-tabs mb-3">
                            <li class="nav-item">
                                <a class="nav-link" :class="{ 'active': tab === 'connection' }" @click.prevent="tab = 'connection'">Connection</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" :class="{ 'active': tab === 'endpoints' }" @click.prevent="tab = 'endpoints'">Endpoints</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" :class="{ 'active': tab === 'preview' }" @click.prevent="tab = 'preview'">Preview / Test</a>
                            </li>
                        </ul>

                        {{-- Connection Tab --}}
                        <div x-show="tab === 'connection'" x-cloak>
                            @include('device.rest-api.templates.partials.connection', ['template' => $template])
                        </div>

                        {{-- Endpoints Tab --}}
                        <div x-show="tab === 'endpoints'" x-cloak>
                            @php
                                $template_data_array = is_array($template->template_data)
                                    ? $template->template_data
                                    : (json_decode($template->template_data, true) ?? []);
                                $connections = $template_data_array['connections'] ?? [];
                            @endphp

                            @foreach ($connections as $cIndex => $connection)
                                <div class="card mb-3 border-info">
                                    <div class="card-header bg-info text-white">
                                        <strong>{{ $connection['name'] ?? 'Unnamed Connection' }}</strong>
                                        <small class="ml-2 text-light">({{ $connection['base_url'] ?? 'No URL' }})</small>
                                    </div>

                                    <div class="card-body">
                                        @if (!empty($connection['endpoints']))
                                            <ul class="list-group">
                                                @foreach ($connection['endpoints'] as $eIndex => $endpoint)
                                                    <li class="list-group-item">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span>
                                                                <strong>{{ $endpoint['name'] ?? 'Unnamed Endpoint' }}</strong>
                                                                <small class="text-muted">({{ $endpoint['path'] ?? '' }})</small>
                                                            </span>
                                                            <button type="button" class="btn btn-sm btn-secondary"
                                                                    @click="openEndpoint === '{{ $cIndex }}-{{ $eIndex }}' ? openEndpoint = null : openEndpoint = '{{ $cIndex }}-{{ $eIndex }}'">
                                                                <span x-show="openEndpoint !== '{{ $cIndex }}-{{ $eIndex }}'">Edit</span>
                                                                <span x-show="openEndpoint === '{{ $cIndex }}-{{ $eIndex }}'">Close</span>
                                                            </button>
                                                        </div>

                                                        <div class="mt-3" x-show="openEndpoint === '{{ $cIndex }}-{{ $eIndex }}'" x-cloak>
                                                            @include('device.rest-api.templates.partials.endpoint-form', [
                                                                'connectionIndex' => $cIndex,
                                                                'endpointIndex' => $eIndex,
                                                                'endpoint' => $endpoint,
                                                            ])
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="text-muted">No endpoints defined for this connection.</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Preview / Test Tab --}}
                        <div x-show="tab === 'preview'" x-cloak>
                            @include('device.rest-api.templates.partials.preview', ['template' => $template])
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="window.history.back();">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
