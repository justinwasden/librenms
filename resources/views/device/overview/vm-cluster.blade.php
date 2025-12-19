<div class="row">
    <div class="col-md-12">
        @if ($clusters->isEmpty())
            <div class="alert alert-info">No cluster data available for this device.</div>
        @else
            <div class="panel panel-default panel-condensed">
                <div class="panel-heading">
                    <i class="fa fa-object-group fa-lg icon-theme" aria-hidden="true"></i>
                    <strong>VMware vCenter Clusters</strong>
                </div>

                <table class="table table-hover table-condensed table-striped">
                    <thead>
                        <tr>
                            <th>Cluster Name</th>
                            <th>Hosts</th>
                            <th>VMs (Total / Powered On)</th>
                            <th>CPU</th>
                            <th>Memory</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clusters as $cluster)
                            @php
                                $cpuUsage = $cluster->cpu_usage_pct ?? 0;
                                $cpuClass = $cpuUsage > 90 ? 'danger' : ($cpuUsage > 75 ? 'warning' : 'success');
                                $totalCpu = isset($cluster->total_cpu_mhz) ? number_format($cluster->total_cpu_mhz / 1000, 2) . ' GHz' : 'N/A';
                                $effectiveCpu = isset($cluster->effective_cpu_mhz) ? number_format($cluster->effective_cpu_mhz / 1000, 2) . ' GHz' : 'N/A';

                                $memUsage = $cluster->memory_usage_pct ?? 0;
                                $memClass = $memUsage > 90 ? 'danger' : ($memUsage > 75 ? 'warning' : 'success');
                                $totalMem = isset($cluster->total_memory_mb) ? number_format($cluster->total_memory_mb / 1024, 2) . ' GB' : 'N/A';
                                $effectiveMem = isset($cluster->effective_memory_mb) ? number_format($cluster->effective_memory_mb / 1024, 2) . ' GB' : 'N/A';

                                $lastUpdated = isset($cluster->updated_at) ? \Illuminate\Support\Carbon::parse($cluster->updated_at)->format('Y-m-d H:i:s') : 'N/A';
                            @endphp

                            <tr>
                                <td>{{ $cluster->cluster_name }}</td>

                                <td>
                                    {{ $cluster->num_hosts ?? 0 }} total
                                    @if (isset($cluster->num_effective_hosts) && ($cluster->num_effective_hosts != ($cluster->num_hosts ?? 0)))
                                        <br><small class="text-muted">({{ $cluster->num_effective_hosts }} effective)</small>
                                    @endif
                                </td>

                                <td>
                                    {{ $cluster->num_vms_total ?? 0 }} /
                                    <span class="text-success">{{ $cluster->num_vms_powered_on ?? 0 }}</span>
                                </td>

                                <td>
                                    <div class="progress" style="margin-bottom: 5px;">
                                        <div class="progress-bar progress-bar-{{ $cpuClass }}"
                                             role="progressbar"
                                             style="width: {{ $cpuUsage }}%;">
                                            {{ number_format($cpuUsage, 1) }}%
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        Total: {{ $totalCpu }}<br>
                                        Available: {{ $effectiveCpu }}
                                    </small>
                                </td>

                                <td>
                                    <div class="progress" style="margin-bottom: 5px;">
                                        <div class="progress-bar progress-bar-{{ $memClass }}"
                                             role="progressbar"
                                             style="width: {{ $memUsage }}%;">
                                            {{ number_format($memUsage, 1) }}%
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        Total: {{ $totalMem }}<br>
                                        Available: {{ $effectiveMem }}
                                    </small>
                                </td>

                                <td>
                                    <small class="text-muted">{{ $lastUpdated }}</small>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-info-circle fa-lg icon-theme" aria-hidden="true"></i>
                <strong>About Cluster Metrics</strong>
            </div>

            <table class="table table-hover table-condensed table-striped">
                <tbody>
                    <tr>
                        <td>
                            <ul style="margin-bottom: 0;">
                                <li><strong>Total Hosts:</strong> Number of ESXi hosts in the cluster</li>
                                <li><strong>Effective Hosts:</strong> Number of hosts that are connected and available</li>
                                <li><strong>VMs:</strong> Total VMs and number currently powered on</li>
                                <li><strong>CPU Usage:</strong> Percentage of total CPU capacity being used</li>
                                <li><strong>Memory Usage:</strong> Percentage of total memory being used</li>
                                <li><strong>Available Resources:</strong> CPU and memory available for new workloads (accounts for HA/DRS reservations)</li>
                            </ul>
                            <p class="text-muted" style="margin: 5px 0 0 0;">
                                <small>Data is collected from vCenter and updated during device polling.</small>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
