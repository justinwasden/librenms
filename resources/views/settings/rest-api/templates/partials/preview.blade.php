{{-- /resources/views/settings/rest-api/templates/partials/preview.blade.php --}}
<div class="p-3" x-data="templateTester()">
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> 
        <strong>Test Template</strong> - Test this template's API connections without applying it to a device
    </div>

    {{-- Device Selection --}}
    <div class="form-group">
        <label for="test_device_id">
            <i class="fas fa-server"></i> Select Device to Test <span class="text-danger">*</span>
        </label>
        <select class="form-control" id="test_device_id" x-model="selectedDeviceId" @change="updatePreview()">
            <option value="">-- Select a device --</option>
            @foreach(\App\Models\Device::orderBy('hostname')->get() as $device)
                <option value="{{ $device->device_id }}">
                    {{ $device->hostname }} 
                    @if($device->ip)
                        ({{ $device->ip }})
                    @endif
                    @if($device->sysName)
                        - {{ $device->sysName }}
                    @endif
                </option>
            @endforeach
        </select>
        <small class="form-text text-muted">
            Variables in the template will be replaced with this device's values
        </small>
    </div>

    {{-- Endpoint Selection --}}
    <div class="form-group" x-show="selectedDeviceId">
        <label for="test_endpoint">
            <i class="fas fa-plug"></i> Select Endpoint(s) to Test
        </label>
        <select class="form-control" id="test_endpoint" x-model="selectedEndpoint">
            <option value="all">All Endpoints</option>
            <option value="first">First Endpoint Only (Quick Test)</option>
            <template x-for="(endpoint, index) in availableEndpoints" :key="index">
                <option :value="index" x-text="`${endpoint.connection} → ${endpoint.name} (${endpoint.method} ${endpoint.path})`"></option>
            </template>
        </select>
        <small class="form-text text-muted">
            Choose specific endpoints or test all at once
        </small>
    </div>

    {{-- Preview of Variables --}}
    <div class="card mb-3" x-show="selectedDeviceId && previewData" x-cloak>
        <div class="card-header bg-secondary text-white">
            <h6 class="mb-0">
                <i class="fas fa-eye"></i> Variable Preview
                <button type="button" 
                        class="btn btn-sm btn-outline-light float-right"
                        @click="showPreview = !showPreview">
                    <i class="fas" :class="showPreview ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                    <span x-text="showPreview ? 'Hide' : 'Show'"></span>
                </button>
            </h6>
        </div>
        <div class="card-body p-2" x-show="showPreview" x-transition>
            <small>
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Variable</th>
                            <th>Will Be Replaced With</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(value, variable) in previewData" :key="variable">
                            <tr>
                                <td><code x-text="variable"></code></td>
                                <td><strong x-text="value || '(empty)'"></strong></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </small>
        </div>
    </div>

    {{-- Test Options --}}
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">
                <i class="fas fa-cog"></i> Test Options
            </h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="test_verify_ssl" x-model="verifySsl">
                        <label class="custom-control-label" for="test_verify_ssl">
                            Verify SSL certificate
                        </label>
                        <small class="form-text text-muted">Uncheck for self-signed certificates</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="test_show_headers" x-model="showHeaders">
                        <label class="custom-control-label" for="test_show_headers">
                            Show response headers
                        </label>
                        <small class="form-text text-muted">Include HTTP headers in results</small>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-6">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="test_verbose" x-model="verboseOutput">
                        <label class="custom-control-label" for="test_verbose">
                            Verbose output
                        </label>
                        <small class="form-text text-muted">Show detailed request/response info</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-0">
                        <label for="timeout" class="mb-1">
                            <small>Timeout (seconds)</small>
                        </label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="timeout" 
                               x-model="timeout" 
                               min="1" 
                               max="300"
                               placeholder="30">
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Run Test Button --}}
    <div class="form-group">
        <button type="button" 
                class="btn btn-primary btn-lg btn-block" 
                @click="runTest()"
                :disabled="!selectedDeviceId || testing">
            <i class="fas" :class="testing ? 'fa-spinner fa-spin' : 'fa-play'"></i>
            <span x-text="testing ? 'Testing...' : 'Run Test'"></span>
        </button>
    </div>

    {{-- Quick Actions --}}
    <div class="text-center mb-3" x-show="hasResults">
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary" @click="copyResults()">
                <i class="fas fa-copy"></i> Copy Results
            </button>
            <button type="button" class="btn btn-outline-secondary" @click="downloadResults()">
                <i class="fas fa-download"></i> Download JSON
            </button>
            <button type="button" class="btn btn-outline-danger" @click="clearResults()">
                <i class="fas fa-trash"></i> Clear
            </button>
        </div>
    </div>

    {{-- Results Section --}}
    <div x-show="hasResults" x-cloak x-transition>
        <div class="card">
            <div class="card-header" :class="testSuccess ? 'bg-success' : 'bg-danger'">
                <h6 class="mb-0 text-white">
                    <i class="fas" :class="testSuccess ? 'fa-check-circle' : 'fa-exclamation-circle'"></i>
                    <span x-text="testSuccess ? 'Test Successful' : 'Test Failed'"></span>
                    <span class="float-right">
                        <small x-text="'Tested at: ' + new Date().toLocaleString()"></small>
                    </span>
                </h6>
            </div>
            <div class="card-body">
                {{-- Test Summary --}}
                <div class="mb-3" x-show="testSummary">
                    <h6>Summary</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tbody>
                                <tr>
                                    <td><strong>Device:</strong></td>
                                    <td x-text="testSummary?.device"></td>
                                </tr>
                                <tr>
                                    <td><strong>Connection:</strong></td>
                                    <td x-text="testSummary?.connection"></td>
                                </tr>
                                <tr>
                                    <td><strong>Base URL:</strong></td>
                                    <td><code x-text="testSummary?.base_url"></code></td>
                                </tr>
                                <tr>
                                    <td><strong>Endpoints Tested:</strong></td>
                                    <td x-text="testSummary?.endpoints_tested"></td>
                                </tr>
                                <tr>
                                    <td><strong>Total Time:</strong></td>
                                    <td x-text="testSummary?.total_time + 'ms'"></td>
                                </tr>
                                <tr>
                                    <td><strong>Success Rate:</strong></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" 
                                                 :class="testSummary?.success_rate === 100 ? 'bg-success' : 'bg-warning'"
                                                 :style="`width: ${testSummary?.success_rate}%`">
                                                <span x-text="testSummary?.success_rate + '%'"></span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Endpoint Results --}}
                <div x-show="endpointResults.length > 0">
                    <h6>Endpoint Results</h6>
                    <template x-for="(result, index) in endpointResults" :key="index">
                        <div class="card mb-2">
                            <div class="card-header p-2" 
                                 :class="result.success ? 'bg-light' : 'bg-danger text-white'">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="fas" :class="result.success ? 'fa-check text-success' : 'fa-times'"></i>
                                        <strong x-text="result.name"></strong>
                                    </span>
                                    <div>
                                        <span class="badge mr-2" 
                                              :class="result.success ? 'badge-success' : 'badge-danger'"
                                              x-text="result.status_code || 'Error'"></span>
                                        <span class="badge badge-info" x-text="result.response_time + 'ms'"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-2">
                                <div class="row mb-2">
                                    <div class="col-md-8">
                                        <small>
                                            <strong>URL:</strong> 
                                            <code x-text="result.url"></code>
                                        </small>
                                    </div>
                                    <div class="col-md-4 text-right">
                                        <small>
                                            <span class="badge badge-info" x-text="result.method"></span>
                                        </small>
                                    </div>
                                </div>
                                
                                <div x-show="result.error" class="mt-2">
                                    <div class="alert alert-danger mb-2">
                                        <small><strong>Error:</strong> <span x-text="result.error"></span></small>
                                    </div>
                                </div>
                                
                                <div x-show="result.response_preview" class="mt-2">
                                    <div class="btn-group btn-group-sm mb-2">
                                        <button type="button" 
                                                class="btn btn-outline-secondary"
                                                @click="result.showResponse = !result.showResponse">
                                            <i class="fas" :class="result.showResponse ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                            <span x-text="result.showResponse ? 'Hide' : 'Show'"></span> Response
                                        </button>
                                        <button type="button" 
                                                class="btn btn-outline-secondary"
                                                @click="copyToClipboard(result.response_preview)"
                                                title="Copy response to clipboard">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                    
                                    <div x-show="result.showResponse" x-cloak class="mt-2">
                                        <pre class="bg-dark text-light p-2 rounded" style="max-height: 400px; overflow-y: auto; font-size: 11px;"><code x-text="result.response_preview"></code></pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Error Message --}}
                <div x-show="errorMessage" class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error:</strong> <span x-text="errorMessage"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function templateTester() {
    return {
        selectedDeviceId: '',
        selectedEndpoint: 'first',
        verifySsl: false,
        showHeaders: false,
        verboseOutput: false,
        timeout: 30,
        testing: false,
        hasResults: false,
        testSuccess: false,
        testSummary: null,
        endpointResults: [],
        errorMessage: '',
        showPreview: false,
        previewData: null,
        availableEndpoints: [],
        
        init() {
            this.parseEndpoints();
        },
        
        parseEndpoints() {
            const templateData = @json($template->template_data);
            const endpoints = [];
            
            if (templateData.connections) {
                templateData.connections.forEach((conn, cIndex) => {
                    if (conn.endpoints) {
                        conn.endpoints.forEach((ep, eIndex) => {
                            endpoints.push({
                                connection: conn.name,
                                name: ep.name,
                                method: ep.method || 'GET',
                                path: ep.path || '',
                                index: `${cIndex}-${eIndex}`
                            });
                        });
                    }
                });
            }
            
            this.availableEndpoints = endpoints;
        },
        
        async updatePreview() {
            if (!this.selectedDeviceId) {
                this.previewData = null;
                return;
            }
            
            try {
                const response = await fetch(`/ajax/select/device?q=${this.selectedDeviceId}`);
                const data = await response.json();
                const device = data.results ? data.results[0] : null;
                
                if (device) {
                    this.previewData = {
                        '{device_hostname}': device.hostname || device.text,
                        '{device_ip}': device.ip || 'N/A',
                        '{device_sysname}': device.sysName || 'N/A',
                        '{device_display}': device.display || device.hostname || device.text,
                    };
                }
            } catch (error) {
                console.error('Error fetching device info:', error);
            }
        },
        
        async runTest() {
            if (!this.selectedDeviceId) {
                alert('Please select a device to test');
                return;
            }
            
            this.testing = true;
            this.hasResults = false;
            this.errorMessage = '';
            
            try {
                const response = await fetch('{{ route("devices.rest-api.templates.test", $template->id) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        device_id: this.selectedDeviceId,
                        test_all_endpoints: this.selectedEndpoint === 'all',
                        specific_endpoint: this.selectedEndpoint !== 'all' && this.selectedEndpoint !== 'first' ? this.selectedEndpoint : null,
                        verify_ssl: this.verifySsl,
                        show_headers: this.showHeaders,
                        verbose: this.verboseOutput,
                        timeout: this.timeout,
                    })
                });
                
                const data = await response.json();
                
                if (response.ok) {
                    this.testSuccess = data.success;
                    this.testSummary = data.summary;
                    this.endpointResults = (data.endpoint_results || []).map(r => ({
                        ...r,
                        showResponse: false
                    }));
                    this.hasResults = true;
                } else {
                    this.errorMessage = data.message || 'Test failed';
                    this.hasResults = true;
                    this.testSuccess = false;
                }
            } catch (error) {
                console.error('Test error:', error);
                this.errorMessage = 'Network error: ' + error.message;
                this.hasResults = true;
                this.testSuccess = false;
            } finally {
                this.testing = false;
            }
        },
        
        copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        },
        
        copyResults() {
            const results = {
                summary: this.testSummary,
                endpoints: this.endpointResults
            };
            this.copyToClipboard(JSON.stringify(results, null, 2));
        },
        
        downloadResults() {
            const results = {
                template: '{{ $template->name }}',
                tested_at: new Date().toISOString(),
                summary: this.testSummary,
                endpoints: this.endpointResults
            };
            
            const blob = new Blob([JSON.stringify(results, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `template-test-{{ $template->id }}-${Date.now()}.json`;
            a.click();
            URL.revokeObjectURL(url);
        },
        
        clearResults() {
            this.hasResults = false;
            this.testSuccess = false;
            this.testSummary = null;
            this.endpointResults = [];
            this.errorMessage = '';
        }
    }
}
</script>

@push('styles')
<style>
    [x-cloak] { display: none !important; }
</style>
@endpush
