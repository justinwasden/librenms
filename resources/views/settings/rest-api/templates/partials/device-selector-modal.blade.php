{{-- Device Selector Modal for API Preview Testing --}}
<div class="modal fade" id="deviceSelectorModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div x-data="deviceSelectorData()" x-init="init()">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-flask"></i> Select Test Device & Credentials</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>

                <div class="modal-body">
                    <!-- Device Selection -->
                    <div class="form-group">
                        <label for="deviceSearch" class="font-weight-bold">Test Device <span class="text-danger">*</span></label>
                        <input type="text" 
                               id="deviceSearch"
                               class="form-control" 
                               x-model="searchText"  
                               @input="filterDevices()"
                               placeholder="Search by hostname or IP...">
                        
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; background: white;">
                            <template x-for="device in filteredDevices" :key="device.device_id">
                                <div style="padding: 10px; border-bottom: 1px solid #eee; cursor: pointer;"
                                     @click="selectDevice(device)"
                                     :style="{ 'background': selectedDevice?.device_id === device.device_id ? '#e8f4fd' : 'white' }">
                                    <strong x-text="device.hostname"></strong>
                                    <span style="color: #666; font-size: 12px;"> (<span x-text="device.ip"></span>)</span>
                                </div>
                            </template>

                            <div x-show="filteredDevices.length === 0 && searchText" style="padding: 10px; color: #999; text-align: center;">
                                No devices found matching "<span x-text="searchText"></span>"
                            </div>

                            <div x-show="filteredDevices.length === 0 && !searchText && allDevices.length === 0" style="padding: 10px; color: #999; text-align: center;">
                                Loading devices...
                            </div>
                        </div>

                        <template x-if="selectedDevice">
                            <div style="background: #e8f4fd; border-left: 4px solid #0066cc; padding: 12px; border-radius: 0 0 4px 4px; font-size: 13px; margin-top: 0; border-top: 1px solid #ddd;">
                                <strong>Selected:</strong> <span x-text="selectedDevice.hostname"></span> 
                                <span style="color: #666;">(<span x-text="selectedDevice.ip"></span>)</span>
                            </div>
                        </template>
                    </div>

                    <!-- Credentials Selection -->
                    <div class="form-group mt-4">
                        <label for="credentialSelect" class="font-weight-bold">API Credentials</label>
                        <select id="credentialSelect" 
                                class="form-control" 
                                x-model="selectedCredentialId"
                                @change="onCredentialChange()">
                            <option value="">-- Use Device Default Credentials --</option>
                            <template x-for="cred in availableCredentials" :key="cred.id">
                                <option :value="cred.id" x-text="cred.name"></option>
                            </template>
                        </select>

                        <template x-if="selectedCredentialInfo.id">
                            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; border-radius: 4px; margin-top: 10px; font-size: 13px;">
                                <strong>Auth Type:</strong> <span x-text="selectedCredentialInfo.auth_type"></span>
                                <template x-if="selectedCredentialInfo.description">
                                    <div><strong>Description:</strong> <span x-text="selectedCredentialInfo.description"></span></div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <!-- Error Messages -->
                    <template x-if="deviceSelectorError">
                        <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin-top: 10px;">
                            <i class="fas fa-exclamation-circle"></i> <span x-text="deviceSelectorError"></span>
                        </div>
                    </template>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-info" @click="confirmSelection()" :disabled="!selectedDevice || previewLoading">
                        <span x-show="previewLoading" style="display: inline-block; width: 12px; height: 12px; border: 2px solid #f3f3f3; border-top: 2px solid #17a2b8; border-radius: 50%; animation: spin 1s linear infinite; margin-right: 8px;"></span>
                        <span x-text="previewLoading ? 'Testing Connection...' : 'Test & Preview'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>

<script>
function deviceSelectorData() {
    return {
        allDevices: [],
        filteredDevices: [],
        searchText: '',
        selectedDevice: null,
        selectedCredentialId: '',
        availableCredentials: [],
        selectedCredentialInfo: {},
        deviceSelectorError: '',
        previewLoading: false,

        async init() {
            console.log('Device Selector initialized');
            console.log('Current location origin:', window.location.origin);
            await this.loadDevices();
            await this.loadCredentials();
        },

        async loadDevices() {
            this.deviceSelectorError = '';
            try {
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                // Use window.location.origin to ensure we're hitting the correct LibreNMS host
                const url = window.location.origin + '/api/rest-api/devices';
                console.log('Fetching devices from:', url);
                const res = await fetch(url, {
                    method: 'GET',
                    headers: { 
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    this.allDevices = data.devices || [];
                    this.filteredDevices = this.allDevices;
                    console.log(`Loaded ${this.allDevices.length} devices`);
                } else {
                    this.deviceSelectorError = 'Failed to load devices: ' + (data.error || 'Unknown error');
                }
            } catch (err) {
                console.error('Failed to load devices:', err);
                this.deviceSelectorError = 'Failed to load devices: ' + err.message;
            }
        },

        async loadCredentials() {
            this.deviceSelectorError = '';
            try {
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                // Use window.location.origin to ensure we're hitting the correct LibreNMS host
                const url = window.location.origin + '/api/rest-api/credentials';
                console.log('Fetching credentials from:', url);
                const res = await fetch(url, {
                    method: 'GET',
                    headers: { 
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    this.availableCredentials = data.credentials || [];
                    console.log(`Loaded ${this.availableCredentials.length} credentials`);
                } else {
                    console.warn('Failed to load credentials:', data.error);
                }
            } catch (err) {
                console.error('Failed to load credentials:', err);
            }
        },

        filterDevices() {
            const search = this.searchText.toLowerCase();
            this.filteredDevices = this.allDevices.filter(d => 
                d.hostname.toLowerCase().includes(search) || 
                d.ip.toLowerCase().includes(search)
            );
        },

        selectDevice(device) {
            this.selectedDevice = device;
            this.searchText = device.hostname;
        },

        onCredentialChange() {
            const cred = this.availableCredentials.find(c => c.id == this.selectedCredentialId);
            if (cred) {
                this.selectedCredentialInfo = cred;
            } else {
                this.selectedCredentialInfo = {};
            }
        },

        async confirmSelection() {
            if (!this.selectedDevice) {
                alert('Please select a device first');
                return;
            }

            this.previewLoading = true;
            this.deviceSelectorError = '';

            try {
                // Get endpointManager instance from parent modal
                const endpointModalEl = document.querySelector('#endpointsModal [x-data*=endpointManager]');
                if (endpointModalEl && endpointModalEl.__x) {
                    const em = endpointModalEl.__x.$data;
                    
                    // Call performPreview on the endpoint manager
                    await em.performPreview(this.selectedDevice.device_id, this.selectedCredentialId || null);
                    
                    this.previewLoading = false;
                    
                    // Close modal if successful
                    if (em.previewSuccess) {
                        $('#deviceSelectorModal').modal('hide');
                    } else {
                        this.deviceSelectorError = em.deviceSelectorError;
                    }
                } else {
                    throw new Error('Could not find endpoint manager');
                }
            } catch (err) {
                console.error(err);
                this.previewLoading = false;
                this.deviceSelectorError = 'Failed to test preview: ' + err.message;
            }
        },
    }
}
</script>
