<div class="row">
    <div class="col-md-12">
        {{-- Array Storage Metrics --}}
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-database fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Array Storage Metrics</strong>
            </div>

            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Array Name</th>
                        <th>Software Version</th>
                        <th>Raw Capacity</th>
                        <th>Used Storage</th>
                        <th>Total Reduction</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $array->array_name ?? 'N/A' }}</td>
                        <td>{{ $array->software_version ?? 'N/A' }}</td>
                        <td>{{ \LibreNMS\Util\Number::formatBi($array->total_bytes) }}</td>
                        <td>
                            {{ \LibreNMS\Util\Number::formatBi($array->used_bytes) }}
                            ({{ round($array->used_pct) }}% used)
                            @if ($arrayCapacityRow)
                                @php
                                    $graph = [
                                        'height' => 20,
                                        'width' => 80,
                                        'to' => \App\Facades\LibrenmsConfig::get('time.now'),
                                        'id' => $arrayCapacityRow->storage_id,
                                        'type' => 'storage_usage',
                                        'from' => \App\Facades\LibrenmsConfig::get('time.day'),
                                        'legend' => 'no',
                                        'bg' => 'ffffff00',
                                    ];
                                    $mini = \LibreNMS\Util\Url::lazyGraphTag($graph);
                                    $link = \LibreNMS\Util\Url::generate([
                                        'page' => 'graphs',
                                        'id' => $arrayCapacityRow->storage_id,
                                        'type' => 'storage_usage',
                                        'from' => \App\Facades\LibrenmsConfig::get('time.day'),
                                        'to' => \App\Facades\LibrenmsConfig::get('time.now'),
                                    ]);
                                @endphp
                                {!! \LibreNMS\Util\Url::overlibLink($link, $mini, \LibreNMS\Util\Url::overlibContent($graph, $device->displayName().' - Array Capacity')) !!}
                            @endif
                        </td>
                        <td>
                            @if (!is_null($array->data_reduction_ratio))
                                {{ number_format($array->data_reduction_ratio, 2) }}:1
                            @else
                                N/A
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Controllers --}}
        @if ($controllers->isNotEmpty())
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Controllers</strong>
            </div>

            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Controller Name</th>
                        <th>Serial Number</th>
                        <th>Model</th>
                        <th>Status</th>
                        <th>Mode</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($controllers as $controller)
                    <tr>
                        <td>{{ $controller->controller_name }}</td>
                        <td>{{ $controller->serial ?? 'N/A' }}</td>
                        <td>{{ $controller->model ?? 'N/A' }}</td>
                        <td>
                            <span class="label {{ $controller->status === 'ok' || $controller->status === 'healthy' || $controller->status === 'up'|| $controller->status === 'ready' ? 'label-success' : 'label-danger' }}">
                                {{ $controller->status ?? 'Unknown' }}
                            </span>
                        </td>
                        <td>{{ $controller->mode ?? 'N/A' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Volumes --}}
        @if ($volumes->isNotEmpty())
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-hdd-o fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Volumes</strong>
            </div>

            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Volume Name</th>
                        <th>Read BW</th>
                        <th>Write BW</th>
                        <th>Read IOPS</th>
                        <th>Write IOPS</th>
                        <th>Read Latency</th>
                        <th>Write Latency</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($volumes as $volume)
                    <tr>
                        <td>{{ $volume->volume_name }}</td>
                        <td>{{ \LibreNMS\Util\Number::formatBi($volume->read_bandwidth) }}/s</td>
                        <td>{{ \LibreNMS\Util\Number::formatBi($volume->write_bandwidth) }}/s</td>
                        <td>{{ number_format($volume->read_iops) }}</td>
                        <td>{{ number_format($volume->write_iops) }}</td>
                        <td>{{ !is_null($volume->read_latency) ? number_format($volume->read_latency, 2) . ' μs' : 'N/A' }}</td>
                        <td>{{ !is_null($volume->write_latency) ? number_format($volume->write_latency, 2) . ' μs' : 'N/A' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Connected Hosts --}}
        @if ($hosts->isNotEmpty())
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-plug fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Connected Hosts</strong>
            </div>

            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Host Name</th>
                        <th>Personality</th>
                        <th>Port Connectivity Status</th>
                        <th>Port Connectivity Details</th>
                        <th>Host Group</th>
                        <th>Is Local</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($hosts as $host)
                    <tr>
                        <td>{{ $host->host_name }}</td>
                        <td>{{ $host->personality ?? 'N/A' }}</td>
                        <td>
                            <span class="label {{ $host->port_connectivity_status === 'connected' ? 'label-success' : ($host->port_connectivity_status === 'offline' ? 'label-danger' : 'label-warning') }}">
                                {{ $host->port_connectivity_status ?? 'Unknown' }}
                            </span>
                        </td>
                        <td>{{ $host->port_connectivity_details ?? 'N/A' }}</td>
                        <td>{{ $host->host_group ?? 'N/A' }}</td>
                        <td>
                            @if ($host->is_local)
                                <span class="label label-success">Yes</span>
                            @else
                                <span class="label label-default">No</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
