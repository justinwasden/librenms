function endpointManager() {
    return {
        endpoints: [],
        selectedEndpointIndex: null,
        selectedEndpoint: {},
        originalEndpoint: {},
        isDirty: false,
        previewLoading: false,
        previewSuccess: false,
        previewError: false,

        loadEndpoints(endpointsData) {
            console.log('=== Endpoint Manager Init ===');
            console.log('Raw endpoints data:', endpointsData);

            // Set endpoints from passed data
            this.endpoints = Array.isArray(endpointsData) ? endpointsData : [];

            console.log('Endpoints length:', this.endpoints.length);
            console.log('Endpoints is array?', Array.isArray(this.endpoints));

            // Ensure endpoints is an array
            if (!Array.isArray(this.endpoints)) {
                console.error('Endpoints is not an array!', typeof this.endpoints);
                this.endpoints = [];
                return;
            }

            // Process metric_map for each endpoint
            this.endpoints.forEach((ep, idx) => {
                console.log(`Processing endpoint ${idx}:`, ep.name);
                if (!this.endpoints[idx].metric_map_json) {
                    this.endpoints[idx].metric_map_json =
                        typeof ep.metric_map === 'string'
                            ? ep.metric_map
                            : JSON.stringify(ep.metric_map ?? null, null, 4) || '';
                }
            });

            console.log('Processed endpoints:', this.endpoints.length);

            // Auto-select first endpoint if available
            if (this.endpoints.length > 0) {
                console.log('Auto-selecting first endpoint');
                this.selectEndpoint(0);
            } else {
                console.log('No endpoints to select');
            }

            console.log('Init complete. selectedEndpointIndex:', this.selectedEndpointIndex);
        },

        selectEndpoint(index) {
            this.selectedEndpointIndex = index;
            this.selectedEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.originalEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.isDirty = false;
            this.previewLoading = false;
            this.previewSuccess = false;
            this.previewError = false;
        },

        // Check if current endpoint has changes
        checkForChanges() {
            if (this.selectedEndpointIndex === null) {
                this.isDirty = false;
                return;
            }

            // For new endpoints (no _endpoint_index), always mark as dirty
            if (this.selectedEndpoint._endpoint_index === undefined) {
                this.isDirty = true;
                return;
            }

            // Compare current with original
            const current = JSON.stringify({
                name: this.selectedEndpoint.name,
                path: this.selectedEndpoint.path,
                method: this.selectedEndpoint.method,
                resource_type: this.selectedEndpoint.resource_type,
                metric_map_json: this.selectedEndpoint.metric_map_json
            });

            const original = JSON.stringify({
                name: this.originalEndpoint.name,
                path: this.originalEndpoint.path,
                method: this.originalEndpoint.method,
                resource_type: this.originalEndpoint.resource_type,
                metric_map_json: this.originalEndpoint.metric_map_json
            });

            this.isDirty = current !== original;
        },

        addNewEndpoint() {
            const newEp = {
                name: 'New Endpoint',
                path: '/api/2.30/',
                method: 'GET',
                resource_type: '',
                metric_map: null,
                metric_map_json: '',
                _connection_index: 0, // Default to first connection
                _is_template: true
                // Note: no _endpoint_index means it's new
            };
            this.endpoints.push(newEp);
            this.selectEndpoint(this.endpoints.length - 1);
            this.isDirty = true; // New endpoints are always dirty
        },

        // NEW METHOD: Fetch API Preview
        async fetchApiPreview() {
            if (!this.selectedEndpoint.path) {
                alert('Please enter an API path first');
                return;
            }

            this.previewLoading = true;
            this.previewSuccess = false;
            this.previewError = false;

            try {
                const templateId = {{ $template->id }};
                const connIdx = this.selectedEndpoint._connection_index || 0;
                const epIdx = this.selectedEndpoint._endpoint_index !== undefined ? this.selectedEndpoint._endpoint_index : -1;
                
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                const res = await fetch('/api/rest-api/template-preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({
                        template_id: templateId,
                        connection_index: connIdx,
                        endpoint_index: epIdx >= 0 ? epIdx : 0
                    })
                });

                const data = await res.json();
                
                if (data.success) {
                    this.previewLoading = false;
                    this.previewSuccess = true;
                    this.previewError = false;
                    
                    // Show preview and recommendations
                    alert('API Preview successful!\n\nResponse structure preview:\n' + JSON.stringify(data.preview, null, 2).substring(0, 300) + '...');
                } else {
                    this.previewLoading = false;
                    this.previewSuccess = false;
                    this.previewError = true;
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                console.error(err);
                this.previewLoading = false;
                this.previewSuccess = false;
                this.previewError = true;
                alert('Failed to fetch preview: ' + err.message);
            }
        },

        // NEW METHOD: Beautify JSON
        beautifyJson() {
            try {
                const json = JSON.parse(this.selectedEndpoint.metric_map_json);
                this.selectedEndpoint.metric_map_json = JSON.stringify(json, null, 2);
                this.checkForChanges();
            } catch (e) {
                alert('Invalid JSON: ' + e.message);
            }
        },

        async saveEndpointChanges() {
            if (this.selectedEndpointIndex === null || !this.isDirty) return;

            // Validate required fields
            if (!this.selectedEndpoint.name || !this.selectedEndpoint.path) {
                alert('Name and Path are required fields');
                return;
            }

            // Parse metric_map JSON if provided
            if (this.selectedEndpoint.metric_map_json && this.selectedEndpoint.metric_map_json.trim()) {
                try {
                    this.selectedEndpoint.metric_map = JSON.parse(this.selectedEndpoint.metric_map_json);
                } catch (e) {
                    alert('Invalid JSON in Metric Mapping: ' + e.message);
                    return;
                }
            } else {
                this.selectedEndpoint.metric_map = null;
            }

            // Update the endpoint in the array
            this.endpoints[this.selectedEndpointIndex] = { ...this.selectedEndpoint };

            // Determine if this is a new endpoint or an update
            const isNewEndpoint = this.selectedEndpoint._endpoint_index === undefined;

            // Save to template_data JSON via API
            try {
                const templateId = {{ $template->id }};
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                const url = isNewEndpoint
                    ? `/settings/rest-api/templates/${templateId}/add-endpoint`
                    : `/settings/rest-api/templates/${templateId}/update-endpoint`;

                const payload = isNewEndpoint ? {
                    connection_index: this.selectedEndpoint._connection_index || 0,
                    endpoint_data: {
                        name: this.selectedEndpoint.name,
                        path: this.selectedEndpoint.path,
                        method: this.selectedEndpoint.method,
                        resource_type: this.selectedEndpoint.resource_type || '',
                        metric_map: this.selectedEndpoint.metric_map
                    }
                } : {
                    connection_index: this.selectedEndpoint._connection_index,
                    endpoint_index: this.selectedEndpoint._endpoint_index,
                    endpoint_data: {
                        name: this.selectedEndpoint.name,
                        path: this.selectedEndpoint.path,
                        method: this.selectedEndpoint.method,
                        resource_type: this.selectedEndpoint.resource_type || '',
                        metric_map: this.selectedEndpoint.metric_map
                    }
                };

                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();
                if (data.success) {
                    // Update the endpoint with new metadata
                    if (data.endpoint) {
                        this.endpoints[this.selectedEndpointIndex] = {
                            ...this.selectedEndpoint,
                            _connection_index: data.endpoint._connection_index,
                            _endpoint_index: data.endpoint._endpoint_index,
                            _is_template: true
                        };
                        this.selectedEndpoint = JSON.parse(JSON.stringify(this.endpoints[this.selectedEndpointIndex]));
                        this.originalEndpoint = JSON.parse(JSON.stringify(this.endpoints[this.selectedEndpointIndex]));
                    }

                    // Mark as clean and show success
                    this.isDirty = false;

                    // Show success message without alert
                    const saveBtn = document.querySelector('[data-save-endpoint]');
                    if (saveBtn) {
                        const originalText = saveBtn.innerHTML;
                        saveBtn.innerHTML = '<i class="fas fa-check"></i> Saved!';
                        saveBtn.classList.remove('btn-primary');
                        saveBtn.classList.add('btn-success');
                        setTimeout(() => {
                            saveBtn.innerHTML = originalText;
                            saveBtn.classList.remove('btn-success');
                            saveBtn.classList.add('btn-primary');
                        }, 2000);
                    }
                } else {
                    alert('Error saving endpoint: ' + (data.message || 'Unknown error'));
                }
            } catch (err) {
                console.error(err);
                alert('Failed to save endpoint. Check console for details.');
            }
        },

        async deleteEndpoint() {
            if (!confirm('Are you sure you want to delete this endpoint from the template?')) return;

            try {
                const templateId = {{ $template->id }};
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                const res = await fetch(`/settings/rest-api/templates/${templateId}/delete-endpoint`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({
                        connection_index: this.selectedEndpoint._connection_index,
                        endpoint_index: this.selectedEndpoint._endpoint_index
                    })
                });

                const data = await res.json();
                if (data.success) {
                    this.endpoints.splice(this.selectedEndpointIndex, 1);
                    this.selectedEndpointIndex = null;
                    this.selectedEndpoint = {};
                    alert('Endpoint deleted from template successfully!');
                } else {
                    alert('Failed to delete endpoint: ' + (data.message || 'Unknown error'));
                }
            } catch (err) {
                console.error(err);
                alert('Failed to delete endpoint. Check console for details.');
            }
        },
    }
}
