<div class="row">
    <div class="col-md-12">
        {{-- UCS Manager Cluster Information --}}
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-sitemap fa-lg icon-theme" aria-hidden="true"></i>
                <strong>UCS Manager Cluster</strong>
            </div>

            <table class="table table-hover table-condensed table-striped">
                <tbody>
                    @if (!empty($clusterInfo['domain_name']))
                    <tr>
                        <th style="width: 30%;">Domain Name</th>
                        <td>{{ $clusterInfo['domain_name'] }}</td>
                    </tr>
                    @endif

                    @if (!empty($clusterInfo['leadership']))
                    <tr>
                        <th>Leadership</th>
                        <td>
                            <span class="label {{ $clusterInfo['leadership'] === 'elected' ? 'label-success' : 'label-warning' }}">
                                {{ ucfirst($clusterInfo['leadership']) }}
                            </span>
                        </td>
                    </tr>
                    @endif

                    @if (!empty($clusterInfo['ha_configuration']))
                    <tr>
                        <th>HA Configuration</th>
                        <td>{{ $clusterInfo['ha_configuration'] }}</td>
                    </tr>
                    @endif

                    <tr>
                        <th>HA Ready</th>
                        <td>
                            @if ($clusterInfo['ha_ready'])
                                <span class="label label-success">Yes</span>
                            @else
                                <span class="label label-danger">No</span>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <th>Fabric Interconnects</th>
                        <td>{{ count($clusterInfo['fabric_interconnects']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Fabric Interconnect Details --}}
        @if (!empty($clusterInfo['fabric_interconnects']))
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-server fa-lg icon-theme" aria-hidden="true"></i>
                <strong>Fabric Interconnects</strong>
            </div>

            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Role</th>
                        <th>Model</th>
                        <th>Serial Number</th>
                        <th>Operability</th>
                        <th>Thermal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($clusterInfo['fabric_interconnects'] as $fi)
                    <tr>
                        <td>
                            <strong>{{ $fi['id'] }}</strong>
                        </td>
                        <td>
                            @php $role = $fi['role'] ?? null; @endphp
                            @if (!empty($role))
                                <span class="label label-default">{{ ucfirst($role) }}</span>
                            @elseif ($fi['id'] === 'A')
                                <span class="label label-primary">Primary</span>
                            @elseif ($fi['id'] === 'B')
                                <span class="label label-info">Subordinate</span>
                            @else
                                <span class="text-muted">Unknown</span>
                            @endif
                        </td>
                        <td>{{ $fi['model'] }}</td>
                        <td><small>{{ $fi['serial'] }}</small></td>
                        <td>
                            <span class="label {{ $fi['operability'] === 'operable' ? 'label-success' : ($fi['operability'] === 'degraded' ? 'label-warning' : 'label-danger') }}">
                                {{ ucfirst($fi['operability']) }}
                            </span>
                        </td>
                        <td>
                            <span class="label {{ $fi['thermal'] === 'ok' ? 'label-success' : 'label-danger' }}">
                                {{ strtoupper($fi['thermal']) }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
