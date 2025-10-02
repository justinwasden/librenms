@extends('layouts.device-tab')

@section('tab-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Apply Template</h3>
            </div>
            <div class="card-body">
                <form action="{{ route('device.rest-api.apply-template', $device) }}" method="POST" class="form-inline">
                    @csrf
                    <div class="form-group mb-2 mr-sm-2">
                        <label for="template_id" class="sr-only">Template</label>
                        <select name="template_id" id="template_id" class="form-control" required>
                            <option value="">Select a Template</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->vendor }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary mb-2">Apply Template</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row" style="margin-top: 20px;">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">API Connections</h3>
            </div>
            <div class="card-body">
                @if($device->restApiConnections->isEmpty())
                    <p>No REST API connections configured for this device.</p>
                @else
                    @foreach($device->restApiConnections as $connection)
                        <div class="card mb-3">
                            <div class="card-header">
                                <h4 class="card-title">{{ $connection->name }}</h4>
                                <div class="card-tools">
                                    <form action="{{ route('device.rest-api.connections.destroy', [$device, $connection]) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this connection and all its endpoints?')">Delete Connection</button>
                                    </form>
                                </div>
                            </div>
                            <div class="card-body">
                                <p><strong>Base URL:</strong> {{ $connection->base_url }}</p>
                                <p><strong>Credential:</strong> {{ $connection->credential->name ?? 'None' }}</p>
                                <p><strong>Rate Limit:</strong> {{ $connection->rate_limit }} reqs/min</p>

                                <h5 class="mt-3">Endpoints:</h5>
                                <table class="table table-sm table-striped">
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
                                                <td><span class="badge badge-info">{{ $endpoint->method }}</span></td>
                                                <td>{{ $endpoint->path }}</td>
                                                <td>{{ $endpoint->last_polled ? $endpoint->last_polled->diffForHumans() : 'Never' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
@endsection