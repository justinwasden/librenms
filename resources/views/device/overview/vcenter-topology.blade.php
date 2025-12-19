<div class="row">
    <div class="col-md-12">
        {{-- vCenter Infrastructure Topology --}}
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-sitemap fa-lg icon-theme" aria-hidden="true"></i>
                <strong>vCenter Infrastructure Topology</strong>
            </div>

            <div class="panel-body">
                @if (!empty($topology['datacenters']))
                    {{-- Datacenters Section --}}
                    <h4><i class="fa fa-building"></i> Datacenters ({{ count($topology['datacenters']) }})</h4>
                    <ul class="list-unstyled" style="margin-left: 20px;">
                        @foreach ($topology['datacenters'] as $dc)
                            <li style="margin-bottom: 20px;">
                                <i class="fa fa-folder-open text-info"></i>
                                <strong>{{ $dc['name'] }}</strong>
                                <small class="text-muted">({{ $dc['id'] }})</small>

                                {{-- Clusters under this datacenter --}}
                                @if (!empty($topology['clusters']))
                                    <ul class="list-unstyled" style="margin-left: 30px; margin-top: 10px;">
                                        @foreach ($topology['clusters'] as $cluster)
                                            <li style="margin-bottom: 15px;">
                                                <i class="fa fa-cubes text-primary"></i>
                                                <strong>Cluster: {{ $cluster['name'] }}</strong>
                                                <small class="text-muted">({{ $cluster['id'] }})</small>

                                                {{-- Cluster features --}}
                                                <div style="margin-left: 20px; margin-top: 5px;">
                                                    @if ($cluster['drs_enabled'])
                                                        <span class="label label-success">DRS</span>
                                                    @endif
                                                    @if ($cluster['ha_enabled'])
                                                        <span class="label label-success">HA</span>
                                                    @endif
                                                    @if ($cluster['vsan_enabled'])
                                                        <span class="label label-info">vSAN</span>
                                                    @endif
                                                </div>

                                                {{-- VMs in this cluster (if we can determine cluster membership) --}}
                                                @php
                                                    $clusterVms = array_filter($topology['vms'], function($vm) use ($cluster) {
                                                        return isset($vm['cluster_id']) && $vm['cluster_id'] === $cluster['id'];
                                                    });
                                                @endphp

                                                @if (!empty($clusterVms))
                                                    <ul class="list-unstyled" style="margin-left: 30px; margin-top: 8px;">
                                                        <li><small class="text-muted">Virtual Machines ({{ count($clusterVms) }})</small></li>
                                                    </ul>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Standalone resources (not explicitly tied to a datacenter in the view) --}}
                @if (!empty($topology['clusters']) || !empty($topology['vms']))
                    <hr>
                    <div class="row">
                        {{-- Clusters Summary --}}
                        @if (!empty($topology['clusters']))
                            <div class="col-md-6">
                                <h5><i class="fa fa-cubes"></i> Cluster Summary</h5>
                                <table class="table table-condensed table-striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Features</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($topology['clusters'] as $cluster)
                                            <tr>
                                                <td>{{ $cluster['name'] }}</td>
                                                <td>
                                                    @if ($cluster['drs_enabled'])
                                                        <span class="label label-success">DRS</span>
                                                    @endif
                                                    @if ($cluster['ha_enabled'])
                                                        <span class="label label-success">HA</span>
                                                    @endif
                                                    @if ($cluster['vsan_enabled'])
                                                        <span class="label label-info">vSAN</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        {{-- VM Summary --}}
                        @if (!empty($topology['vms']))
                            <div class="col-md-6">
                                <h5><i class="fa fa-desktop"></i> Virtual Machine Summary</h5>
                                <table class="table table-condensed">
                                    <tbody>
                                        <tr>
                                            <th style="width: 50%;">Total VMs</th>
                                            <td>{{ count($topology['vms']) }}</td>
                                        </tr>
                                        <tr>
                                            <th>Powered On</th>
                                            <td>
                                                @php
                                                    $poweredOn = count(array_filter($topology['vms'], fn($vm) => $vm['power_state'] === 'POWERED_ON'));
                                                @endphp
                                                <span class="label label-success">{{ $poweredOn }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Powered Off</th>
                                            <td>
                                                @php
                                                    $poweredOff = count(array_filter($topology['vms'], fn($vm) => $vm['power_state'] === 'POWERED_OFF'));
                                                @endphp
                                                <span class="label label-default">{{ $poweredOff }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Suspended</th>
                                            <td>
                                                @php
                                                    $suspended = count(array_filter($topology['vms'], fn($vm) => $vm['power_state'] === 'SUSPENDED'));
                                                @endphp
                                                <span class="label label-warning">{{ $suspended }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- vSAN Status Section --}}
                @if (!empty($topology['vsan_status']))
                    <hr>
                    <h5><i class="fa fa-hdd-o"></i> vSAN Status</h5>
                    <table class="table table-condensed table-striped">
                        <thead>
                            <tr>
                                <th>Cluster</th>
                                <th>Health Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topology['vsan_status'] as $vsan)
                                <tr>
                                    <td>{{ $vsan['cluster_name'] }}</td>
                                    <td>
                                        @php
                                            $health = strtolower($vsan['health_status']);
                                            $labelClass = match($health) {
                                                'green' => 'label-success',
                                                'yellow' => 'label-warning',
                                                'red' => 'label-danger',
                                                default => 'label-default',
                                            };
                                        @endphp
                                        <span class="label {{ $labelClass }}">{{ strtoupper($health) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- Virtual Machines List --}}
                @if (!empty($topology['vms']) && count($topology['vms']) <= 50)
                    <hr>
                    <h5><i class="fa fa-list"></i> Virtual Machines (showing {{ count($topology['vms']) }})</h5>
                    <table class="table table-condensed table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Power State</th>
                                <th>CPUs</th>
                                <th>Memory</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topology['vms'] as $vm)
                                <tr>
                                    <td>{{ $vm['name'] }}</td>
                                    <td>
                                        @php
                                            $powerState = $vm['power_state'];
                                            $labelClass = match($powerState) {
                                                'POWERED_ON' => 'label-success',
                                                'POWERED_OFF' => 'label-default',
                                                'SUSPENDED' => 'label-warning',
                                                default => 'label-info',
                                            };
                                        @endphp
                                        <span class="label {{ $labelClass }}">{{ $powerState }}</span>
                                    </td>
                                    <td>{{ $vm['cpu_count'] ?? 'N/A' }}</td>
                                    <td>
                                        @if (isset($vm['memory_size_MiB']))
                                            {{ \LibreNMS\Util\Number::formatBi($vm['memory_size_MiB'] * 1024 * 1024) }}
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @elseif (!empty($topology['vms']) && count($topology['vms']) > 50)
                    <hr>
                    <p class="text-muted">
                        <i class="fa fa-info-circle"></i>
                        {{ count($topology['vms']) }} virtual machines detected. Detailed VM list hidden to improve performance.
                        View individual VM details in the VM inventory section.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
