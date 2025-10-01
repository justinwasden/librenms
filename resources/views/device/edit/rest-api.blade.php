@extends('device.edit.edit-menu')

@section('edit-content')
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">{{ __('Apply Template') }}</h3>
            </div>
            <div class="panel-body">
                <form action="{{ route('device.rest-api.apply-template', $device) }}" method="POST" class="form-horizontal">
                    @csrf
                    <div class="form-group">
                        <label for="template_id" class="col-sm-3 control-label">{{ __('Template') }}</label>
                        <div class="col-sm-6">
                            <select name="template_id" id="template_id" class="form-control" required>
                                <option value="">{{ __('Select a Template') }}</option>
                                @foreach(\App\Models\RestApiTemplate::all() as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->vendor }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-check"></i> {{ __('Apply Template') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">{{ __('API Connections') }}</h3>
            </div>
            <div class="panel-body">
                @if($device->restApiConnections->isEmpty())
                    <p>{{ __('No REST API connections configured for this device.') }}</p>
                @else
                    @foreach($device->restApiConnections as $connection)
                        <div class="panel panel-info">
                            <div class="panel-heading">
                                <h4 class="panel-title">
                                    {{ $connection->name }}
                                    <div class="pull-right">
                                        <form action="{{ route('device.rest-api.connections.destroy', [$device, $connection]) }}" method="POST" style="display: inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('{{ __('Are you sure?') }}')">
                                                <i class="fa fa-trash"></i> {{ __('Delete') }}
                                            </button>
                                        </form>
                                    </div>
                                </h4>
                            </div>
                            <div class="panel-body">
                                <dl class="dl-horizontal">
                                    <dt>{{ __('Base URL') }}</dt>
                                    <dd>{{ $connection->base_url }}</dd>

                                    <dt>{{ __('Credential') }}</dt>
                                    <dd>{{ $connection->credential->name ?? __('None') }}</dd>

                                    <dt>{{ __('Rate Limit') }}</dt>
                                    <dd>{{ $connection->rate_limit }} {{ __('reqs/min') }}</dd>
                                </dl>

                                <h5>{{ __('Endpoints') }}</h5>
                                <table class="table table-condensed table-striped">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Name') }}</th>
                                            <th>{{ __('Method') }}</th>
                                            <th>{{ __('Path') }}</th>
                                            <th>{{ __('Last Polled') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($connection->endpoints as $endpoint)
                                            <tr>
                                                <td>{{ $endpoint->name }}</td>
                                                <td><span class="label label-info">{{ $endpoint->method }}</span></td>
                                                <td><code>{{ $endpoint->path }}</code></td>
                                                <td>{{ $endpoint->last_polled ? $endpoint->last_polled->diffForHumans() : __('Never') }}</td>
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