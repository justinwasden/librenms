@extends('layouts.librenmsv1')
@section('content')
    <x-device.page :device="$device">
        <x-device.edit-tabs :device="$device" />
						@if ($errors->any())
						    <div class="alert alert-danger alert-dismissible">
						        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
						        <ul class="mb-0">
						            @foreach ($errors->all() as $error)
						                <li>{{ $error }}</li>
						            @endforeach
						        </ul>
						    </div>
						@endif

						@if(session('success'))
						    <div class="alert alert-success alert-dismissible">
						        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
						        {{ session('success') }}
						    </div>
						@endif

						<div class="row">
						    <div class="col-md-12">
						        <div class="panel panel-default">
						            <div class="panel-heading">
						                <h3 class="panel-title">Apply REST API Template</h3>
						            </div>
						            <div class="panel-body">
						                <form action="{{ route('device.rest-api.apply-template', $device) }}" method="POST" class="form-inline">
						                    @csrf
						                    <div class="form-group">
						                        <label for="template_id" class="sr-only">Template</label>
						                        <select name="template_id" id="template_id" class="form-control" required style="width: 300px;">
						                            <option value="">Select a Template</option>
						                            @foreach($templates as $template)
						                                <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->vendor }})</option>
						                            @endforeach
						                        </select>
						                    </div>
						                    <button type="submit" class="btn btn-primary">Apply Template</button>
						                </form>
						            </div>
						        </div>
						    </div>
						</div>

						<div class="row" style="margin-top: 20px;">
						    <div class="col-md-12">
						        <div class="panel panel-default">
						            <div class="panel-heading">
						                <h3 class="panel-title">API Connections</h3>
						            </div>
						            <div class="panel-body">
						                @if($device->restApiConnections->isEmpty())
						                    <p>No REST API connections configured for this device.</p>
						                @else
						                    @foreach($device->restApiConnections as $connection)
						                        <div class="panel panel-default">
						                            <div class="panel-heading">
						                                <h4 class="panel-title pull-left" style="padding-top: 7.5px;">{{ $connection->name }}</h4>
						                                <div class="pull-right">
						                                    <form action="{{ route('device.rest-api.connections.destroy', [$device, $connection]) }}" method="POST" style="display: inline;">
						                                        @csrf
						                                        @method('DELETE')
						                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this connection and all its endpoints?')">Delete Connection</button>
						                                    </form>
						                                </div>
						                                <div class="clearfix"></div>
						                            </div>
						                            <div class="panel-body">
						                                <p><strong>Base URL:</strong> {{ $connection->base_url }}</p>
						                                <p><strong>Credential:</strong> {{ $connection->credential->name ?? 'None' }}</p>
						                                <p><strong>Rate Limit:</strong> {{ $connection->rate_limit }} reqs/min</p>

						                                <h5 style="margin-top: 20px;">Endpoints:</h5>
						                                <table class="table table-condensed table-striped">
						                                    <thead>
						                                        <tr>
						                                            <th>Name</th>
						                                            <th>Method</th>
						                                            <th>Path</th>
						                                            <th>Last Polled</th>
						                                        </tr>
						                                    </thead>
						                                    <tbody>
						                                        @foreach($connection->endpoints as $endpoint)
						                                            <tr>
						                                                <td>{{ $endpoint->name }}</td>
						                                                <td><span class="label label-info">{{ $endpoint->method }}</span></td>
						                                                <td><code>{{ $endpoint->path }}</code></td>
						                                                <td>{{ $endpoint->last_polled ? $endpoint->last_polled->diffForHumans() : 'Never' }}</td>
						                                            </tr>
						                                        @endforeach
						                                    </tbody>
						                                </table>
						                            </div>
						                        </div>
						                        @if(!$loop->last)
						                            <hr>
						                        @endif
						                    @endforeach
						                @endif
						            </div>
						        </div>
						    </div>
						</div>
    </x-device.page>
@endsection