# Project Code Changes Summary (Last 24 Hours)
File Path: `app/ApiClients/DeviceHttpClient.php`
```php
<?php
namespace App\ApiClients;

use App\Models\Device;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LibreNMS\HTTP\RateLimiter;
use LibreNMS\Util\DeviceApiSettings;

/**
 * Generic HTTP client for device APIs.
 * - Base URL handling
 * - Common headers (auth, custom)
 * - TLS verification, timeout, proxy
 * - Retries with exponential backoff
 * - JSON parsing and error normalization
 *
 * Compose this inside vendor-specific clients (e.g., PureStorage, Proxmox).
 */
class DeviceHttpClient
{
    protected string $baseUrl;
    protected array $headers;
    protected bool $verifyTls;
    protected int $timeoutMs;
    protected ?string $proxy;
    protected int $maxRetries;
    protected int $retryInitialDelayMs;
    protected ?Device $device;
    protected ?RateLimiter $rateLimiter;
    protected int $rateLimitQps;
    protected bool $enableCircuitBreaker;
    protected int $circuitBreakerThreshold;

    public function __construct(array $options, ?Device $device = null)
    {
        $this->baseUrl = rtrim((string)($options['base_url'] ?? ''), '/');
        $this->headers = (array)($options['headers'] ?? []);
        $this->verifyTls = (bool)($options['verify_tls'] ?? true);
        $this->timeoutMs = (int)($options['timeout_ms'] ?? 5000);
        $this->proxy = $options['proxy'] ?? null;
        $this->maxRetries = (int)($options['max_retries'] ?? 2);
        $this->retryInitialDelayMs = (int)($options['retry_initial_delay_ms'] ?? 250);
        $this->device = $device;
        $this->rateLimiter = $options['rate_limiter'] ?? app(RateLimiter::class);
        $this->rateLimitQps = (int)($options['rate_limit_qps'] ?? 10);
        $this->enableCircuitBreaker = (bool)($options['enable_circuit_breaker'] ?? true);
        $this->circuitBreakerThreshold = (int)($options['circuit_breaker_threshold'] ?? 5);

        if ($this->baseUrl === '') {
            throw new \InvalidArgumentException('DeviceHttpClient requires base_url');
        }
    }

    /**
     * HTTP GET returning decoded JSON array.
     */
    public function get(string $path, array $query = []): array
    {
        $this->checkCircuitBreaker();
        $this->applyRateLimit();

        $start = microtime(true);
        try {
            $resp = $this->send('GET', $path, ['query' => $query]);
            $data = $this->parseJson($resp, $path);
            $this->recordSuccess($start);
            return $data;
        } catch (\Throwable $e) {
            $this->recordError($e->getMessage());
            throw $e;
        }
    }

    /**
     * HTTP POST returning decoded JSON array.
     * $body is sent as JSON by default.
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        $this->checkCircuitBreaker();
        $this->applyRateLimit();

        $start = microtime(true);
        try {
            $resp = $this->send('POST', $path, ['json' => $body, 'query' => $query]);
            $data = $this->parseJson($resp, $path);
            $this->recordSuccess($start);
            return $data;
        } catch (\Throwable $e) {
            $this->recordError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Core request sender with retries/backoff.
     */
    protected function send(string $method, string $path, array $opts = []): Response
    {
        $url = $this->buildUrl($path);

        $req = Http::withHeaders($this->headers)
            ->timeout($this->timeoutMs / 1000)
            ->withOptions(['verify' => $this->verifyTls]);

        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        // Attach cookies if provided in headers via special key
        // Example: $options['cookies'] = ['Name' => 'Value'] set in headers via setCookies()
        if (!empty($this->headers['_cookies']) && is_array($this->headers['_cookies'])) {
            $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '';
            $req = $req->withCookies($this->headers['_cookies'], $host);
        }

        $attempt = 0;
        $delay = $this->retryInitialDelayMs;

        while (true) {
            $attempt++;

            try {
                $resp = $this->dispatch($req, $method, $url, $opts);

                // Retry on transient errors (HTTP 429/5xx)
                if ($this->shouldRetry($resp) && $attempt <= $this->maxRetries + 1) {
                    usleep($delay * 1000);
                    $delay = min($delay * 2, 2000);
                    continue;
                }

                return $resp;
            } catch (\Throwable $e) {
                // Network/timeout/transport exceptions should retry
                if ($attempt <= $this->maxRetries + 1) {
                    usleep($delay * 1000);
                    $delay = min($delay * 2, 2000);
                    continue;
                }
                throw new \RuntimeException(sprintf('HTTP %s %s failed: %s', $method, $url, $e->getMessage()), 0, $e);
            }
        }
    }

    protected function dispatch($req, string $method, string $url, array $opts): Response
    {
        // Query params
        $query = Arr::get($opts, 'query', []);
        // Body options
        $json = Arr::get($opts, 'json', null);
        $form = Arr::get($opts, 'form_params', null);

        if (strtoupper($method) === 'GET') {
            return $req->get($url, $query);
        }

        if ($json !== null) {
            return $req->withHeaders(['Content-Type' => 'application/json'])->post($url . $this->querySuffix($query), $json);
        }

        if ($form !== null) {
            return $req->asForm()->post($url . $this->querySuffix($query), $form);
        }

        // Default POST without body
        return $req->post($url . $this->querySuffix($query));
    }

    protected function parseJson(Response $resp, string $path): array
    {
        if ($resp->failed()) {
            $status = $resp->status();
            $body = $this->safeBodyPreview($resp);
            throw new \RuntimeException(sprintf('HTTP %s returned %d: %s', $path, $status, $body));
        }

        $data = $resp->json();

        if (is_null($data)) {
            // Non-JSON or empty body; treat as empty array
            return [];
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response for ' . $path);
        }

        return $data;
    }

    protected function buildUrl(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    protected function querySuffix(array $query): string
    {
        if (empty($query)) {
            return '';
        }
        return '?' . http_build_query($query);
    }

    protected function shouldRetry(Response $resp): bool
    {
        $code = $resp->status();
        if ($code === 429) {
            return true;
        }
        // Retry on 5xx server errors
        return $code >= 500 && $code <= 599;
    }

    protected function safeBodyPreview(Response $resp, int $maxLen = 256): string
    {
        $raw = (string) $resp->body();
        // Avoid logging secrets; truncate and strip newlines
        $raw = preg_replace('/\s+/', ' ', $raw);
        return mb_substr($raw, 0, $maxLen);
    }

    /**
     * Helper to add or override headers (e.g., auth) at runtime.
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);
        return $clone;
    }

    /**
     * Helper to set cookies, e.g., Proxmox ticket auth.
     * Usage: $client->withCookies(['PVEAuthCookie' => $ticket])
     */
    public function withCookies(array $cookies): self
    {
        $clone = clone $this;
        $clone->headers['_cookies'] = $cookies;
        return $clone;
    }

    /**
     * Factory convenience to build client from a generic options array.
     * Expected keys:
     *  - base_url (string)
     *  - headers (array)
     *  - verify_tls (bool)
     *  - timeout_ms (int)
     *  - proxy (string|null)
     *  - max_retries (int)
     *  - retry_initial_delay_ms (int)
     */
    public static function fromOptions(array $options, ?Device $device = null): self
    {
        return new self($options, $device);
    }

    /**
     * Check circuit breaker state before making requests
     *
     * @throws \RuntimeException If circuit breaker is tripped
     */
    protected function checkCircuitBreaker(): void
    {
        if (!$this->enableCircuitBreaker || !$this->device) {
            return;
        }

        if (DeviceApiSettings::shouldTripCircuitBreaker($this->device, $this->circuitBreakerThreshold)) {
            throw new \RuntimeException('Circuit breaker open: too many consecutive API failures. Reset via device settings.');
        }
    }

    /**
     * Apply rate limiting before making requests
     */
    protected function applyRateLimit(): void
    {
        if (!$this->rateLimiter) {
            return;
        }

        $key = $this->baseUrl;
        if (!$this->rateLimiter->waitForAllow($key, $this->rateLimitQps)) {
            throw new \RuntimeException('Rate limit timeout exceeded');
        }
    }

    /**
     * Record successful API call with latency tracking
     */
    protected function recordSuccess(float $start): void
    {
        if (!$this->device) {
            return;
        }

        $latencyMs = (int) ((microtime(true) - $start) * 1000);
        DeviceApiSettings::recordSuccess($this->device, $latencyMs);
    }

    /**
     * Record failed API call
     */
    protected function recordError(string $error): void
    {
        if (!$this->device) {
            return;
        }

        DeviceApiSettings::recordError($this->device, $error);
    }

    /**
     * Test if the API is reachable with a simple request
     *
     * @return bool True if API is reachable and returns valid response
     */
    public function isReachable(): bool
    {
        try {
            // Try a simple GET to the base URL or a known lightweight endpoint
            $this->get('/');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get API information (override in vendor-specific clients)
     *
     * @return array Basic info about the API
     */
    public function getApiInfo(): array
    {
        return [
            'vendor' => 'generic',
            'base_url' => $this->baseUrl,
            'version' => 'unknown',
        ];
    }

    /**
     * Factory method to create client from Device model
     *
     * @param Device $device
     * @return static
     */
    public static function fromDevice(Device $device): self
    {
        $options = DeviceApiSettings::httpOptions($device);
        $options['rate_limit_qps'] = DeviceApiSettings::rateLimitQps($device);

        return new self($options, $device);
    }
}

```File Path: `app/ApiClients/Contracts/DeviceApiClientInterface.php`
```php
<?php
namespace App\ApiClients\Contracts;

use App\Models\Device;

interface DeviceApiClientInterface
{
    // Short vendor identifier (e.g., 'purestorage', 'proxmox')
    public const VENDOR = 'generic';

    // Fast eligibility check (attribute or quick probe)
    public function supports(Device $device): bool;

    // Advertise which data types the client can provide
    // e.g., ['sensors','ports','mempools','processors','inventory','ipv4']
    public function capabilities(): array;

    // Fetch normalized data structures Modules expect
    public function fetchSensors(Device $device): array;
    public function fetchPorts(Device $device): array;
    public function fetchMempools(Device $device): array;
    public function fetchProcessors(Device $device): array;
    public function fetchInventory(Device $device): array;

    // IPv4 addresses (optional capability)
    // Return an array of entries with keys: ifIndex, ipv4_address, ipv4_prefixlen, context_name
    public function fetchIpv4Addresses(Device $device): array;

    /**
     * Low-level HTTP transport methods
     */

    /**
     * Perform a GET request to the API
     *
     * @param string $path The endpoint path
     * @param array $query Query parameters
     * @return array Decoded JSON response
     * @throws \RuntimeException On HTTP errors or invalid responses
     */
    public function get(string $path, array $query = []): array;

    /**
     * Perform a POST request to the API
     *
     * @param string $path The endpoint path
     * @param array $body Request body
     * @return array Decoded JSON response
     * @throws \RuntimeException On HTTP errors or invalid responses
     */
    public function post(string $path, array $body = []): array;

    /**
     * Test if the API is reachable and credentials are valid
     *
     * @return bool
     */
    public function isReachable(): bool;

    /**
     * Get API version and metadata information
     *
     * @return array Array with keys: version, vendor, api_version, etc.
     */
    public function getApiInfo(): array;
}
```

File Path: `app/Http/Controllers/DeviceController.php`
Modification Time: Sat Oct 25 21:00:09 2025

```php
<?php

namespace App\Http\Controllers;

use App\Facades\DeviceCache;
use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\View\Components\Device\PageTabs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Util\Debug;
use LibreNMS\Util\Url;

class DeviceController extends Controller
{
    public function index(Request $request, $device, $current_tab = 'overview', $vars = '')
    {
        $device = str_replace('device=', '', $device);
        $device = is_numeric($device) ? DeviceCache::get((int) $device) : DeviceCache::getByHostname($device);
        $device_id = $device->device_id;

        if (! $device->exists) {
            abort(404);
        }

        DeviceCache::setPrimary($device_id);

        $current_tab = str_replace('tab=', '', $current_tab) ?: 'overview';

        if ($current_tab == 'port') {
            $vars = Url::parseLegacyPath($request->path());
            $port = $device->ports()->findOrFail($vars->get('port'));
            Gate::authorize('view', $port);
        } else {
            Gate::authorize('view', $device);
        }

        $tab_controller = PageTabs::getTab($current_tab);
        $title = $tab_controller->name();
        $data = $tab_controller->data($device, $request);

        $data_array = [
            'title' => $title,
            'device' => $device,
            'device_id' => $device_id,
            'data' => $data,
            'vars' => $vars,
            'current_tab' => $current_tab,
            'request' => $request,
        ];

        if (view()->exists('device.tabs.' . $current_tab)) {
            return view('device.tabs.' . $current_tab, $data_array);
        }

        $data_array['tab_content'] = $this->renderLegacyTab($current_tab, $device, $data);

        return view('device.tabs.legacy', $data_array);
    }

    private function renderLegacyTab($tab, Device $device, $data)
    {
        ob_start();
        $device = $device->toArray();
        $device['os_group'] = LibrenmsConfig::get("os.{$device['os']}.group");
        Debug::set(false);
        chdir(base_path());
        $init_modules = ['web', 'auth'];
        require base_path('/includes/init.php');

        $vars['device'] = $device['device_id'];
        $vars['tab'] = $tab;

        extract($data); // set preloaded data into variables
        include "includes/html/pages/device/$tab.inc.php";
        $output = ob_get_clean();
        ob_end_clean();

        return $output;
    }

    public function rediscover(Device $device): JsonResponse
    {
        $device->last_discovered = null;
        $saved = $device->save();

        return response()->json([
            'message' => $saved ? 'Device scheduled for discovery' : 'Failed to schedule device for discovery',
            'status' => $saved ? 'ok' : 'error',
        ]);
    }

}
```
File Path: `app/Http/Controllers/Device/EditDeviceController.php`
Modification Time: Sun Oct 26 14:07:37 2025

```php
<?php

/**
 * EditDeviceController.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2025 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Http\Controllers\Device;

use App\ApiClients\DeviceHttpClient;
use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\PollerGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\MaintenanceBehavior;
use LibreNMS\Exceptions\HostRenameException;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\DeviceApiSettings;
use LibreNMS\Util\File;
use LibreNMS\Util\Number;

class EditDeviceController
{
    public function index(Device $device): View
    {
        // Eager load attribs to ensure they're available in the view
        $device->load('attribs');

        $section = request()->get('section', 'device');

        // Handle API section
        if ($section === 'api') {
            // Load templates filtered by device OS
            $templates = ApiTemplateManager::getTemplatesForOs($device->os);
            $authTypes = ApiTemplateManager::getAuthTypes();

            // Get currently configured endpoints (or empty array if none)
            $configuredEndpoints = json_decode($device->getAttrib('rest_endpoints', '[]'), true);

            // If a template is selected, load it; otherwise auto-select if only one template matches
            $selectedTemplate = $device->getAttrib('rest_template');
            if (!$selectedTemplate && count($templates) === 1) {
                $selectedTemplate = array_key_first($templates);
            }
            $templateData = $selectedTemplate ? ApiTemplateManager::loadTemplate($selectedTemplate) : null;

            return view('device.edit', [
                'device' => $device,
                'section' => 'api',
                'templates' => $templates,
                'authTypes' => $authTypes,
                'configuredEndpoints' => $configuredEndpoints,
                'selectedTemplate' => $selectedTemplate,
                'templateData' => $templateData,
                'autoSelectTemplate' => !$device->getAttrib('rest_template') && count($templates) === 1,
            ]);
        }

        // Handle device settings section (default)
        $types = collect(LibrenmsConfig::get('device_types'))->keyBy('type');
        if (! $types->has($device->type)) {
            $types->put($device->type, [
                'icon' => null,
                'text' => ucfirst($device->type),
                'type' => $device->type,
            ]);
        }

        [$rrd_size, $rrd_num] = File::getFolderSize(Rrd::dirFromHost($device->hostname));

        $alertSchedules = $device->alertSchedules()->isActive()->get();
        $isUnderMaintenance = $alertSchedules->isNotEmpty();
        $exclusiveSchedules = $alertSchedules->filter(function ($schedule) {
            $totalMappings = DB::table('alert_schedulables')
                ->where('schedule_id', $schedule->schedule_id)
                ->count();

            return $totalMappings === 1; // only exclusive schedules
        });
        $exclusive_schedule_id = $exclusiveSchedules->count() === 1 ? $exclusiveSchedules->first()->schedule_id : 0;

        [$static_show, $static_groups] = DeviceGroup::where('type', 'static')->exists()
            ? [true, $device->groups()->where('type', 'static')->pluck('name', 'id')]
            : [false, []];

        return view('device.edit', [
            'device' => $device,
            'section' => $section,
            'show_static_groups' => $static_show,
            'static_groups' => $static_groups,
            'types' => $types,
            'default_type' => LibrenmsConfig::getOsSetting($device->os, 'type'),
            'parents' => $device->parents()->pluck('hostname', 'device_id'),
            'poller_groups' => PollerGroup::orderBy('group_name')->pluck('group_name', 'id'),
            'default_poller_group' => LibrenmsConfig::get('distributed_poller_group'),
            'override_sysContact_bool' => $device->getAttrib('override_sysContact_bool'),
            'override_sysContact_string' => $device->getAttrib('override_sysContact_string'),
            'maintenance' => $isUnderMaintenance,
            'default_maintenance_behavior' => MaintenanceBehavior::from((int) LibrenmsConfig::get('alert.scheduled_maintenance_default_behavior'))->value,
            'exclusive_maintenance_id' => $exclusive_schedule_id,
            'rrd_size' => Number::formatBi($rrd_size),
            'rrd_num' => $rrd_num,
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device): RedirectResponse
    {
        // Check if this is an API settings update
        if ($request->has('rest_enabled')) {
            $this->updateApiSettings($request, $device);

            // Reload the device to get fresh attributes from database
            $device->refresh();
            $device->load('attribs');

            toast()->success(__('Device API settings updated'));

            return redirect()->route('device.edit', ['device' => $device->device_id, 'section' => 'api']);
        }

        // Handle device settings update
        $device->fill($request->validated());

        $device->parents()->sync($request->get('parent_id', [])); // TODO avoid loops!

        // sync groups without removing dynamic groups
        $dynamic_groups = $device->groups()->where('type', 'dynamic')->pluck('id')->toArray();
        $device->groups()->sync(array_merge($dynamic_groups, $request->get('static_groups', [])));

        // handle sysLocation update
        if ($device->override_sysLocation) {
            $device->setLocation($request->get('sysLocation'), true, true);
            $device->location?->save();
        } elseif ($device->isDirty('override_sysLocation')) {
            // no longer overridden, clear location
            $device->location()->dissociate();
        }

        // check if sysContact is overridden
        if ($request->get('override_sysContact')) {
            $device->setAttrib('override_sysContact_bool', true);
            $device->setAttrib('override_sysContact_string', (string) $request->get('override_sysContact_string'));
        } else {
            $device->forgetAttrib('override_sysContact_bool');
        }

        // check if type was overridden
        if ($device->isDirty('type')) {
            $device->type = strtolower($device->type);
            $device->setAttrib('override_device_type', true);
        }

        // save it, no message if no changes
        try {
            if ($device->isDirty()) {
                if ($device->save()) {
                    toast()->success(__('Device record updated'));
                } else {
                    toast()->error(__('Device record update error'));
                }
            }
        } catch (HostRenameException $e) {
            toast()->error($e->getMessage());
        }

        return response()->redirectToRoute('device', ['device' => $device->device_id, 'edit']);
    }

    private function updateApiSettings($request, Device $device): void
    {
        // Device API attributes
        $device->setAttrib('rest_enabled', $request->boolean('rest_enabled') ? 1 : 0);
        $device->setAttrib('rest_template', $request->input('rest_template') ?? '');
        $device->setAttrib('rest_vendor', $request->input('rest_vendor') ?? '');
        $device->setAttrib('rest_base_url', $request->input('rest_base_url') ?? '');
        $device->setAttrib('rest_auth_type', $request->input('rest_auth_type') ?? '');

        $device->setAttrib('rest_headers', $request->input('rest_headers') ?? '');
        $device->setAttrib('rest_verify_tls', $request->boolean('rest_verify_tls') ? 1 : 0);
        $device->setAttrib('rest_timeout_ms', (int) ($request->input('rest_timeout_ms') ?? 5000));
        $device->setAttrib('rest_proxy', $request->input('rest_proxy') ?? '');
        $device->setAttrib('rest_rate_limit_qps', (int) ($request->input('rest_rate_limit_qps') ?? 10));

        // Save endpoints configuration
        if ($request->has('rest_endpoints')) {
            $endpoints = $request->input('rest_endpoints');
            $device->setAttrib('rest_endpoints', is_array($endpoints) ? json_encode($endpoints) : ($endpoints ?? '[]'));
        }

        if ($request->filled('rest_token')) {
            $device->setAttrib('rest_token_enc', Crypt::encryptString($request->input('rest_token')));
        }
        if ($request->filled('rest_username')) {
            $device->setAttrib('rest_username', $request->input('rest_username'));
        }
        if ($request->filled('rest_password')) {
            $device->setAttrib('rest_password_enc', Crypt::encryptString($request->input('rest_password')));
        }

        // Proxmox token
        if ($request->filled('proxmox_token_user')) {
            $device->setAttrib('proxmox_token_user', $request->input('proxmox_token_user'));
        }
        if ($request->filled('proxmox_token_id')) {
            $device->setAttrib('proxmox_token_id', $request->input('proxmox_token_id'));
        }
        if ($request->filled('proxmox_token')) {
            $device->setAttrib('proxmox_token_enc', Crypt::encryptString($request->input('proxmox_token')));
        }

        // Proxmox ticket
        if ($request->filled('proxmox_username')) {
            $device->setAttrib('proxmox_username', $request->input('proxmox_username'));
        }
        if ($request->filled('proxmox_password')) {
            $device->setAttrib('proxmox_password_enc', Crypt::encryptString($request->input('proxmox_password')));
        }

        $device->save();
    }

    /**
     * Test API connection with provided credentials
     */
    public function testApiConnection(Request $request, Device $device): JsonResponse
    {
        try {
            $baseUrl = $request->input('rest_base_url');

            // Validate base URL
            if (empty($baseUrl)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Base URL is required',
                ]);
            }

            // Build temporary options from request
            $options = [
                'base_url' => $baseUrl,
                'verify_tls' => $request->boolean('rest_verify_tls', true),
                'timeout_ms' => (int) $request->input('rest_timeout_ms', 5000),
                'headers' => [],
                'enable_circuit_breaker' => false, // Disable circuit breaker for testing
                'max_retries' => 0, // Don't retry during testing for faster feedback
            ];

            // Add auth headers based on type
            $authType = $request->input('rest_auth_type', 'bearer');
            $token = $request->input('rest_token');
            $username = $request->input('rest_username');
            $password = $request->input('rest_password');

            if ($authType === 'bearer' && $token) {
                $options['headers']['Authorization'] = 'Bearer ' . $token;
            } elseif ($authType === 'apikey' && $token) {
                $options['headers']['X-API-Key'] = $token;
            } elseif ($authType === 'basic' && $username) {
                $options['headers']['Authorization'] = 'Basic ' . base64_encode($username . ':' . ($password ?? ''));
            }

            // Create client and test with better error handling
            $client = new DeviceHttpClient($options);

            // Determine which endpoint to test based on template
            $testPaths = ['/'];
            $templateName = $request->input('rest_template');

            if ($templateName) {
                $template = ApiTemplateManager::loadTemplate($templateName);
                if ($template && !empty($template['endpoints'])) {
                    // Use the first enabled endpoint from the template for testing
                    foreach ($template['endpoints'] as $endpoint) {
                        if ($endpoint['enabled'] ?? true) {
                            $testPaths = [$endpoint['path']];
                            break;
                        }
                    }
                }
            }

            // Try to make a simple request
            $lastError = null;
            foreach ($testPaths as $path) {
                try {
                    $data = $client->get($path);

                    return response()->json([
                        'success' => true,
                        'vendor' => $templateName ?? 'generic',
                        'version' => 'connected',
                        'base_url' => $baseUrl,
                        'message' => 'Connection successful',
                        'test_path' => $path,
                    ]);
                } catch (\Throwable $e) {
                    $lastError = $e;

                    // If we got HTTP 4xx, that's actually OK - it means we connected
                    // 401 = auth required (connected but need creds)
                    // 403 = forbidden (connected, auth sent, but insufficient permissions)
                    // 404 = not found (connected, endpoint doesn't exist)
                    if (preg_match('/returned (\d+)/', $e->getMessage(), $matches)) {
                        $code = (int)$matches[1];
                        if ($code >= 400 && $code < 500) {
                            $messages = [
                                401 => 'Connection successful - Authentication required (check credentials)',
                                403 => 'Connection successful - Authenticated but insufficient permissions (check API token permissions)',
                                404 => 'Connection successful - Endpoint not found (this is normal for some APIs)',
                            ];

                            return response()->json([
                                'success' => true,
                                'vendor' => $templateName ?? 'generic',
                                'version' => 'connected',
                                'base_url' => $baseUrl,
                                'message' => $messages[$code] ?? "Connection successful (HTTP $code)",
                                'test_path' => $path,
                                'http_code' => $code,
                            ]);
                        }
                    }

                    // For other errors, continue trying next path
                    continue;
                }
            }

            // All paths failed - provide detailed error
            if ($lastError) {
                $errorMessage = $lastError->getMessage();

                // Extract useful error information
                if (str_contains($errorMessage, 'Could not resolve host')) {
                    $errorMessage = 'Could not resolve hostname - check the URL';
                } elseif (str_contains($errorMessage, 'Connection refused')) {
                    $errorMessage = 'Connection refused - check if the service is running';
                } elseif (str_contains($errorMessage, 'timed out')) {
                    $errorMessage = 'Connection timed out - check firewall/network settings';
                } elseif (str_contains($errorMessage, 'SSL')) {
                    $errorMessage = 'SSL/TLS error - try disabling certificate verification for testing';
                } elseif (preg_match('/returned (\d+)/', $errorMessage, $matches)) {
                    $code = $matches[1];
                    // Any 2xx or 4xx response means we connected successfully
                    if ($code >= 200 && $code < 500) {
                        return response()->json([
                            'success' => true,
                            'vendor' => $templateName ?? 'generic',
                            'version' => 'connected',
                            'base_url' => $baseUrl,
                            'message' => "Connection successful (HTTP $code received)",
                        ]);
                    }
                    $errorMessage = 'API returned HTTP ' . $code . ' - server error';
                }

                return response()->json([
                    'success' => false,
                    'error' => $errorMessage,
                    'raw_error' => $lastError->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset circuit breaker for a device
     */
    public function resetCircuitBreaker(Request $request, Device $device): JsonResponse
    {
        try {
            DeviceApiSettings::resetCircuitBreaker($device);

            return response()->json([
                'success' => true,
                'message' => 'Circuit breaker reset successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

```
File Path: `app/View/Components/Device/EditTabs.php`
Modification Time: Sat Oct 25 21:57:18 2025

```php
<?php

/**
 * EditTabs.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2025 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\View\Components\Device;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use Illuminate\Support\Facades\Request;
use Illuminate\View\Component;

class EditTabs extends Component
{
    public array $tabs;
    public string $tab;

    public function __construct(
        public Device $device,
        ?string $tab = null,
    ) {
        $this->tab = $tab ?? Request::segment(4, 'device');

        $this->tabs = [
            'device' => [
                'text' => __('Device Settings'),
                'link' => route('device.edit', ['device' => $this->device->device_id, 'section' => 'device']),
            ],
            'api' => [
                'text' => __('API'),
                'link' => route('device.edit', ['device' => $this->device->device_id, 'section' => 'api']),
            ],
            'snmp' => [
                'text' => 'SNMP',
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=snmp/'),
            ],
        ];

        if (! $device->snmp_disable) {
            $this->tabs['ports'] = [
                'text' => __('Port Settings'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=ports/'),
            ];
        }

        if ($device->bgppeers()->exists()) {
            $this->tabs['routing'] = [
                'text' => __('Routing'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=routing/'),
            ];
        }

        if (count(LibrenmsConfig::get("os.{$device->os}.icons", []))) {
            $this->tabs['icon'] = [
                'text' => __('Icon'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=icon/'),
            ];
        }

        if (! $device->snmp_disable) {
            $this->tabs['apps'] = [
                'text' => __('Applications'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=apps/'),
            ];
        }

        $this->tabs['alert-rules'] = [
            'text' => __('Alert Rules'),
            'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=alert-rules/'),
        ];

        if (! $device->snmp_disable) {
            $this->tabs['modules'] = [
                'text' => __('Modules'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=modules/'),
            ];
        }

        if (LibrenmsConfig::get('show_services')) {
            $this->tabs['services'] = [
                'text' => __('Services'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=services/'),
            ];
        }

        $this->tabs['ipmi'] = [
            'text' => __('IPMI'),
            'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=ipmi/'),
        ];

        if ($this->device->sensors()->exists()) {
            $this->tabs['health'] = [
                'text' => __('Health'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=health/'),
            ];
        }

        if ($this->device->wirelessSensors()->exists()) {
            $this->tabs['wireless-sensors'] = [
                'text' => __('Wireless Sensors'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=wireless-sensors/'),
            ];
        }

        if (! $device->snmp_disable) {
            $this->tabs['storage'] = [
                'text' => __('Storage'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=storage/'),
            ];
            $this->tabs['processors'] = [
                'text' => __('Processors'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=processors/'),
            ];
            $this->tabs['mempools'] = [
                'text' => __('Memory'),
                'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=mempools/'),
            ];
        }

        $this->tabs['misc'] = [
            'text' => __('Misc'),
            'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=misc/'),
        ];

        $this->tabs['component'] = [
            'text' => __('Components'),
            'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=component/'),
        ];

        $this->tabs['customoid'] = [
            'text' => __('Custom OID'),
            'link' => url('/device/device=' . $this->device->device_id . '/tab=edit/section=customoid/'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function render()
    {
        return view('components.device.edit-tabs');
    }
}

```
File Path: `config/api-templates/proxmox.json`
Modification Time: Sun Oct 26 13:12:21 2025

```php
{
    "name": "Proxmox VE",
    "vendor": "proxmox",
    "description": "Template for Proxmox Virtual Environment API",
    "os": ["proxmox"],
    "base_url_pattern": "https://{hostname}:8006",
    "base_url_example": "https://pve-host:8006",
    "auth_type": "token",
    "auth_config": {
        "method": "header",
        "header_name": "Authorization",
        "header_format": "PVEAPIToken={token_user}!{token_id}={token_secret}",
        "fields": ["token_user", "token_id", "token_secret"]
    },
    "default_settings": {
        "verify_tls": false,
        "timeout_ms": 3000,
        "rate_limit_qps": 5
    },
    "endpoints": [
        {
            "id": "version",
            "name": "Version Information",
            "path": "/api2/json/version",
            "method": "GET",
            "enabled": true,
            "description": "PVE version and release information",
            "poll_interval": 3600,
            "category": "inventory"
        },
        {
            "id": "cluster_status",
            "name": "Cluster Status",
            "path": "/api2/json/cluster/status",
            "method": "GET",
            "enabled": true,
            "description": "Cluster nodes and quorum status",
            "poll_interval": 60,
            "category": "cluster"
        },
        {
            "id": "cluster_resources",
            "name": "Cluster Resources",
            "path": "/api2/json/cluster/resources",
            "method": "GET",
            "enabled": true,
            "description": "VMs, containers, storage, and node resources",
            "poll_interval": 300,
            "category": "cluster"
        },
        {
            "id": "nodes",
            "name": "Nodes List",
            "path": "/api2/json/nodes",
            "method": "GET",
            "enabled": true,
            "description": "List of cluster nodes",
            "poll_interval": 300,
            "category": "inventory"
        },
        {
            "id": "node_status",
            "name": "Node Status",
            "path": "/api2/json/nodes/{node}/status",
            "method": "GET",
            "enabled": true,
            "description": "CPU, memory, uptime, load for specific node",
            "poll_interval": 60,
            "category": "performance",
            "requires_param": "node"
        },
        {
            "id": "node_storage",
            "name": "Node Storage",
            "path": "/api2/json/nodes/{node}/storage",
            "method": "GET",
            "enabled": true,
            "description": "Storage pools and usage per node",
            "poll_interval": 300,
            "category": "storage",
            "requires_param": "node"
        },
        {
            "id": "node_network",
            "name": "Node Network",
            "path": "/api2/json/nodes/{node}/network",
            "method": "GET",
            "enabled": true,
            "description": "Network interfaces per node",
            "poll_interval": 300,
            "category": "ports",
            "requires_param": "node"
        },
        {
            "id": "node_tasks",
            "name": "Node Tasks",
            "path": "/api2/json/nodes/{node}/tasks",
            "method": "GET",
            "enabled": false,
            "description": "Recent tasks on node (optional)",
            "poll_interval": 300,
            "category": "inventory",
            "requires_param": "node"
        }
    ]
}

```File Path: `config/api-templates/generic.json`
Modification Time: Sun Oct 26 13:12:26 2025

```php
{
    "name": "Generic REST API",
    "vendor": "generic",
    "description": "Template for generic REST APIs with customizable endpoints",
    "os": [],
    "base_url_pattern": "https://{hostname}/api",
    "base_url_example": "https://device.example/api/v1",
    "auth_type": "bearer",
    "auth_config": {
        "method": "header",
        "header_name": "Authorization",
        "header_format": "Bearer {token}"
    },
    "default_settings": {
        "verify_tls": true,
        "timeout_ms": 5000,
        "rate_limit_qps": 10
    },
    "endpoints": [
        {
            "id": "status",
            "name": "Status/Health",
            "path": "/status",
            "method": "GET",
            "enabled": true,
            "description": "Device status and health check",
            "poll_interval": 60,
            "category": "inventory"
        },
        {
            "id": "metrics",
            "name": "Metrics",
            "path": "/metrics",
            "method": "GET",
            "enabled": true,
            "description": "Device performance metrics",
            "poll_interval": 60,
            "category": "performance"
        }
    ]
}

```File Path: `config/api-templates/purestorage.json`
Modification Time: Sun Oct 26 13:12:16 2025

```php
{
    "name": "Pure Storage FlashArray",
    "vendor": "purestorage",
    "description": "Template for Pure Storage FlashArray REST API v2.x",
    "os": ["purestorage"],
    "base_url_pattern": "https://{hostname}/api/2.26",
    "base_url_example": "https://array-name/api/2.26",
    "auth_type": "apikey",
    "auth_config": {
        "method": "header",
        "header_name": "X-API-Key",
        "header_format": "{token}"
    },
    "default_settings": {
        "verify_tls": true,
        "timeout_ms": 5000,
        "rate_limit_qps": 10
    },
    "endpoints": [
        {
            "id": "arrays",
            "name": "Array Information",
            "path": "/arrays",
            "method": "GET",
            "enabled": true,
            "description": "Get array metadata (name, version, ID)",
            "poll_interval": 300,
            "category": "inventory"
        },
        {
            "id": "array_performance",
            "name": "Array Performance",
            "path": "/arrays/performance",
            "method": "GET",
            "enabled": true,
            "description": "Array-level performance metrics (IOPS, bandwidth, latency)",
            "poll_interval": 60,
            "category": "performance"
        },
        {
            "id": "controllers",
            "name": "Controllers",
            "path": "/controllers",
            "method": "GET",
            "enabled": true,
            "description": "Controller status and health",
            "poll_interval": 60,
            "category": "hardware"
        },
        {
            "id": "drives",
            "name": "Drives",
            "path": "/drives",
            "method": "GET",
            "enabled": true,
            "description": "Drive inventory and status",
            "poll_interval": 300,
            "category": "hardware"
        },
        {
            "id": "hardware",
            "name": "Hardware Components",
            "path": "/hardware",
            "method": "GET",
            "enabled": true,
            "description": "Power supplies, fans, temperature sensors",
            "poll_interval": 60,
            "category": "hardware"
        },
        {
            "id": "ports",
            "name": "Network Ports",
            "path": "/ports",
            "method": "GET",
            "enabled": true,
            "description": "FC/iSCSI port status and configuration",
            "poll_interval": 300,
            "category": "ports"
        },
        {
            "id": "port_performance",
            "name": "Port Performance",
            "path": "/ports/performance",
            "method": "GET",
            "enabled": true,
            "description": "Per-port bandwidth and error counters",
            "poll_interval": 60,
            "category": "performance"
        },
        {
            "id": "volumes",
            "name": "Volumes",
            "path": "/volumes",
            "method": "GET",
            "enabled": true,
            "description": "Volume inventory and space usage",
            "poll_interval": 300,
            "category": "storage"
        },
        {
            "id": "volume_performance",
            "name": "Volume Performance",
            "path": "/volumes/performance",
            "method": "GET",
            "enabled": true,
            "description": "Per-volume IOPS, bandwidth, latency",
            "poll_interval": 60,
            "category": "performance"
        },
        {
            "id": "hosts",
            "name": "Attached Hosts",
            "path": "/hosts",
            "method": "GET",
            "enabled": true,
            "description": "Host connectivity and space metrics",
            "poll_interval": 300,
            "category": "storage"
        }
    ]
}

```
File Path: `resources/views/device/edit.blade.php`
Modification Time: Sat Oct 25 21:31:08 2025

```php
@extends('layouts.librenmsv1')

@section('content')
    <x-device.page :device="$device">
        <x-device.edit-tabs :device="$device" :tab="$section ?? 'device'" />

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        @if ($section === 'api')
            <form id="edit-api" name="edit-api" method="POST" action="{{ route('device.edit.update', $device) }}" role="form" class="form-horizontal">
                @method('PUT')
                @csrf
                @include('device.partials.device_api')
                <div class="row">
                    <div class="col-md-1 col-md-offset-2">
                        <button type="submit" name="Submit" class="btn btn-default"><i class="fa fa-check"></i> Save</button>
                    </div>
                </div>
            </form>
            <br><br>
            <div class="alert alert-info" role="alert">
                <p>To disable REST API polling, uncheck "Enable REST API discovery/polling" and click <b>Save</b>.</p>
            </div>
        @elseif ($section === 'device' || !isset($section))
            @include('device.edit.device')
        @endif
    </x-device.page>
@endsection
```File Path: `resources/views/device/edit/device.blade.php`
Modification Time: Sat Oct 25 21:26:31 2025

```php
{{-- Device Settings Form (partial included in edit.blade.php) --}}
<div class="row">
    <div class="col-sm-6 col-sm-offset-2 tw:justify-between tw:flex tw:flex-wrap">
        <form id="delete_host" name="delete_host" method="post" action="delhost/" role="form" class="tw:inline-block">
            @csrf
            <input type="hidden" name="id" value="{{ $device->device_id }}">
            <button type="submit" class="btn btn-danger" name="Submit"><i class="fa fa-trash"></i> {{ __('device.edit.delete_device') }}</button>
        </form>

        @if(LibrenmsConfig::get('enable_clear_discovery') && ! $device->snmp_disable)
            <button type="submit" id="rediscover" data-device_id="{{ $device->device_id }}"
                    class="btn btn-primary" name="rediscover" title="{{ __('device.edit.rediscover_title') }}">
                <i class="fa fa-retweet"></i> {{ __('device.edit.rediscover') }}
            </button>
        @endif
    </div>
</div>
<br>

<form id="edit" name="edit" method="post" action="{{ route('device.edit.update', [$device->device_id]) }}" role="form" class="form-horizontal">
    @method('PUT')
    @csrf
            <div class="form-group" data-toggle="tooltip" data-container="body" data-placement="bottom" title="{{ __('device.edit.hostname_title') }}" >
                <label for="edit-hostname-input" class="col-sm-2 control-label" >{{ __('device.edit.hostname_ip') }}</label>
                <div class="col-sm-6">
                    <input type="text" id="edit-hostname-input" name="hostname" class="form-control" disabled value="{{ old('hostname', $device->hostname) }}" />
                </div>
                <div class="col-sm-2">
                    <button type="button" name="hostname-edit-button" id="hostname-edit-button" class="btn btn-danger" onclick="toggleHostnameEdit()"> <i class="fa fa-pencil"></i> </button>
                </div>
            </div>

            <div class="form-group" data-toggle="tooltip" data-container="body" data-placement="bottom" title="{{ __('device.edit.display_title', ['sysName' => $device->sysName]) }}" >
                <label for="edit-display-input" class="col-sm-2 control-label" >{{ __('device.edit.display_name') }}</label>
                <div class="col-sm-6">
                    <input type="text" id="edit-display-input" name="display" class="form-control" placeholder="{{ __('device.edit.system_default') }}" value="{{ old('display', $device->display) }}">
                </div>
            </div>

            <div class="form-group" data-toggle="tooltip" data-container="body" data-placement="bottom" title="{{ __('device.edit.overwrite_ip_title') }}" >
                <label for="edit-overwrite_ip-input" class="col-sm-2 control-label text-danger" >{{ __('device.edit.overwrite_ip') }}</label>
                <div class="col-sm-6">
                    <input type="text" id="edit-overwrite_ip-input" name="overwrite_ip" class="form-control" value="{{ old('overwrite_ip', $device->overwrite_ip) }}">
                </div>
            </div>

            <div class="form-group">
                <label for="descr" class="col-sm-2 control-label">{{ __('device.edit.description') }}</label>
                <div class="col-sm-6">
                    <textarea id="descr" name="purpose" class="form-control">{{ old('purpose', $device->purpose) }}</textarea>
                </div>
            </div>

            <div class="form-group">
                <label for="type" class="col-sm-2 control-label">{{ __('device.edit.type') }}</label>
                <div class="col-sm-6">
                    <select id="type" name="type" class="form-control">
                        @foreach($types as $type => $type_data)
                            <option value="{{ $type }}" {{ old('type', $device->type) == $type ? 'selected' : '' }} data-icon="{{ $type_data['icon'] }}">
                                {{ $type_data['text'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if($show_static_groups)
            <div class="form-group">
                <label for="static_groups" class="col-sm-2 control-label">{{ __('device.edit.static_groups') }}</label>
                <div class="col-sm-6">
                    <select id="static_groups" name="static_groups[]" class="form-control" multiple style="width: 100%">
                        @foreach($static_groups as $group_id => $group_name)
                            <option value="{{ $group_id }}" selected>{{ $group_name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @endif

            <div class="form-group">
                <label for="sysLocation" class="col-sm-2 control-label">{{ __('device.edit.override_sysLocation') }}</label>
                <div class="col-sm-6">
                    <input onChange="edit.sysLocation.disabled=!edit.override_sysLocation.checked; edit.sysLocation.select()"
                           type="checkbox" name="override_sysLocation" data-size="small"
                            {{ old('override_sysLocation', $device->override_sysLocation) ? 'checked' : '' }}
                    />
                </div>
            </div>
            <div class="form-group" title="{{ __('device.edit.coordinates_title') }}">
                <div class="col-sm-2"></div>
                <div class="col-sm-6">
                    <input id="sysLocation" name="sysLocation" class="form-control"
                           {{ old('override_sysLocation', $device->override_sysLocation) ? '' : 'disabled' }}
                             value="{{ old('sysLocation', $device->location?->location) }}" />
                </div>
            </div>

            <div class="form-group">
                <label for="override_sysContact" class="col-sm-2 control-label">{{ __('device.edit.override_sysContact') }}</label>
                <div class="col-sm-6">
                    <input onChange="edit.override_sysContact_string.disabled=!edit.override_sysContact.checked"
                           type="checkbox" id="override_sysContact" name="override_sysContact" data-size="small"
                            {{ old('override_sysContact', $override_sysContact_bool) ? 'checked' : '' }}
                    />
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-2">
                </div>
                <div class="col-sm-6">
                    <input id="override_sysContact_string" class="form-control" name="override_sysContact_string" size="32"
                           {{ old('override_sysContact', $override_sysContact_bool) ? '' : 'disabled' }}
                           data-override="{{ $override_sysContact_string }}"
                           data-default="{{ $device->sysContact }}"
                           value="{{ old('override_sysContact_string', $override_sysContact_bool ? $override_sysContact_string : $device->sysContact) }}"
                    />
                </div>
            </div>

            <div class="form-group">
                <label for="parent_id" class="col-sm-2 control-label">{{ __('device.edit.depends_on') }}</label>
                <div class="col-sm-6">
                    <select multiple name="parent_id[]" id="parent_id" class="form-control" style="width: 100%">
                        @foreach ($parents as $parent_id => $parent_hostname)
                            <option value="{{  $parent_id }}" selected>{{ $parent_hostname }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @config('distributed_poller')
            <div class="form-group">
                <label for="poller_group" class="col-sm-2 control-label">{{ __('device.edit.poller_group') }}</label>
                <div class="col-sm-6">
                    <select name="poller_group" id="poller_group" class="form-control input-sm">
                        <option value="0">{{ __('device.edit.poller_group_general') }}{{$default_poller_group == 0 ? ' ' . __('device.edit.default_poller') : ''}}</option>
                        @foreach($poller_groups as $group_id => $group_name)
                            <option value="{{ $group_id }}" {{ old('poller_group', $device->poller_group) == $group_id ? 'selected' : '' }}>
                                {{ $group_name }}{{ $default_poller_group == $group_id ? ' ' . __('device.edit.default_poller') : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            @endconfig

            <div class="form-group">
                <label for="disabled" class="col-sm-2 control-label">{{ __('device.edit.disable_polling_alerting') }}</label>
                <div class="col-sm-6">
                    <input name="disabled" type="checkbox" id="disabled" value="1" data-size="small"
                       {{ old('disabled', $device->disabled) ? 'checked' : '' }}
                    />
                </div>
            </div>

            <div class="form-group">
                <label for="maintenance" class="col-sm-2 control-label"></label>
                <div class="col-sm-6">
                    <div id="app">
                        <maintenance-mode
                            :device-id="{{ $device->device_id }}"
                            device-name="{{ $device->displayName() }}"
                            :maintenance-id="{{ $exclusive_maintenance_id }}"
                            :default-maintenance-behavior="{{ $default_maintenance_behavior }}"
                            :maintenance="{{ $maintenance ? 'true' : 'false' }}"
                        ></maintenance-mode>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="disable_notify" class="col-sm-2 control-label">{{ __('device.edit.disable_alerting') }}</label>
                <div class="col-sm-6">
                    <input id="disable_notify" type="checkbox" name="disable_notify" data-size="small"
                       {{ old('disable_notify', $device->disable_notify) ? 'checked' : '' }}
                    />
                </div>
            </div>
            <div class="form-group">
                <label for="ignore" class="col-sm-2 control-label" title="{{ __('device.edit.ignore_alert_tag_title') }}">{{ __('device.edit.ignore_alert_tag') }}</label>
                <div class="col-sm-6">
                    <input name="ignore" type="checkbox" id="ignore" value="1" data-size="small"
                       {{ old('ignore', $device->ignore) ? 'checked' : '' }}
                    />
                </div>
            </div>
            <div class="form-group">
                <label for="ignore_status" class="col-sm-2 control-label" title="{{ __('device.edit.ignore_device_status_title') }}">{{ __('device.edit.ignore_device_status') }}</label>
                <div class="col-sm-6">
                    <input name="ignore_status" type="checkbox" id="ignore_status" value="1" data-size="small"
                       {{ old('ignore_status', $device->ignore_status) ? 'checked' : '' }}
                    />
                </div>
            </div>
    <div class="row">
        <div class="col-md-1 col-md-offset-2">
            <button type="submit" name="Submit"  class="btn btn-default"><i class="fa fa-check"></i> {{ __('device.edit.save') }}</button>
        </div>
    </div>
</form>
<br />
<div class="panel panel-default">
    <div class="panel-heading">
        @if($rrd_num)
        {{ __('device.edit.size_on_disk') }}: <b>{{ $rrd_size }}</b> in <b>{{ $rrd_num }}</b> {{ __('device.edit.rrd_files') }} |
        @endif
        {{ __('device.edit.last_polled') }}: <b>{{ $device->last_polled }}</b>
        @if($device->last_discovered)
            | {{ __('device.edit.last_discovered') }}: <b>{{ $device->last_discovered }}</b>
        @endif
    </div>
</div>

@push('scripts')
    <script>
        init_select2('#parent_id', 'device', {exclude: {{ $device->device_id }}}, null, '{{ __('device.edit.none') }}');
        init_select2('#static_groups', 'device-group', {type: 'static'}, null, '{{ __('device.edit.none') }}');
        const defaultType = '{{ $default_type }}';
        function templateTypeSelection(option) {
            if (!option.id) { // placeholder
                return option.text;
            }
            const iconClass = $(option.element).data('icon');
            if (!iconClass) {
                return option.text;
            }

            return $('<span>').append(
                $('<i>', {
                    class: `fa-solid fa-${iconClass} fa-fw fa-lg`
                }),
                $('<span>', {
                    text: option.text
                })
            );
        }
        $('#type').select2({
            placeholder: 'Select or enter a device type',
            templateResult: templateTypeSelection,
            templateSelection: templateTypeSelection,
            tags: true,
            allowClear: true,
        }).on('select2:clearing', function(e) {
            // reset to the default value when clearing
            e.preventDefault();
            setTimeout(function() {
                $('#type').val(defaultType).trigger('change');
            }, 10);
        }).on('change select2:select initialized', function() {
            // hide the clear button when default is selected
            const currentValue = $(this).val();
            $(this).parent().find('.select2-selection__clear').toggle(currentValue !== defaultType);
        }).trigger('initialized');

        $('[type="checkbox"]').bootstrapSwitch('offColor', 'danger');
        $('#override_sysContact').on('switchChange.bootstrapSwitch', function(event, state) {
            var $input = $('#override_sysContact_string');
            var newValue = state ? $input.data('override') : $input.data('default');

            if (!state || newValue) {
                $input.val(newValue);
            }
        });
        $("#rediscover").on("click", function() {
                fetch('{{ route('device.rediscover', [$device->device_id]) }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']').content
                    }
                })
                    .then(r => r.json())
                    .then(d => toastr[d.status === 'ok' ? 'success' : 'error'](d.message))
                    .catch(() => toastr.error('An error occurred setting this device to be rediscovered'));
        });

        function toggleHostnameEdit() {
            document.getElementById('edit-hostname-input').disabled = ! document.getElementById('edit-hostname-input').disabled;
        }
    </script>
    @vuei18n
@endpush

```File Path: `resources/views/device/partials/device_api.blade.php`
Modification Time: Sun Oct 26 14:01:56 2025

```php
{{-- resources/views/device/partials/device_api.blade.php --}}
@if(!empty($device->getAttrib('rest_last_error_message')))
    <div class="alert alert-warning">
        <strong>Last Error:</strong> {{ $device->getAttrib('rest_last_error_message') }}
        @if(!empty($device->getAttrib('rest_last_error')))
            <br><small>{{ \Carbon\Carbon::createFromTimestamp($device->getAttrib('rest_last_error'))->diffForHumans() }}</small>
        @endif
    </div>
@endif

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                <input type="checkbox" id="rest_enabled" name="rest_enabled" value="1"
                       {{ old('rest_enabled', $device->getAttrib('rest_enabled', 0)) ? 'checked' : '' }}>
                <strong>Enable REST API discovery/polling</strong>
            </label>
        </div>
    </div>
</div>

{{-- Template Selector --}}
<div class="form-group">
    <label for="rest_template" class="col-sm-2 control-label">Template</label>
    <div class="col-sm-6">
        @php $selectedTemplate = old('rest_template', $device->getAttrib('rest_template', '')); @endphp
        <select class="form-control" id="rest_template" name="rest_template">
            <option value="">Custom (no template)</option>
            @foreach($templates as $vendor => $template)
                <option value="{{ $vendor }}" {{ $selectedTemplate === $vendor ? 'selected' : '' }}>
                    {{ $template['name'] }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">
            @if(count($templates) === 0)
                No templates available for {{ $device->os }}. Use custom configuration.
            @elseif(count($templates) === 1)
                Template auto-selected for {{ $device->os }} devices.
            @else
                Select a pre-configured template or configure manually.
            @endif
        </small>
    </div>
</div>

{{-- Hidden vendor field (auto-populated from template) --}}
<input type="hidden" id="rest_vendor" name="rest_vendor" value="{{ old('rest_vendor', $device->getAttrib('rest_vendor', '')) }}">

{{-- Authentication Type Selector --}}
<div class="form-group">
    <label for="rest_auth_type" class="col-sm-2 control-label">Authentication Type <span class="text-danger">*</span></label>
    <div class="col-sm-6">
        @php $authType = old('rest_auth_type', $device->getAttrib('rest_auth_type', '')); @endphp
        <select class="form-control" id="rest_auth_type" name="rest_auth_type">
            <option value="">Select authentication type...</option>
            @foreach($authTypes as $type => $config)
                <option value="{{ $type }}" {{ $authType === $type ? 'selected' : '' }}>
                    {{ $config['name'] }}
                </option>
            @endforeach
        </select>
        <small class="text-muted auth-description"></small>
    </div>
</div>

{{-- Base URL --}}
<div class="form-group">
    <label for="rest_base_url" class="col-sm-2 control-label">Base URL <span class="text-danger">*</span></label>
    <div class="col-sm-6">
        <input type="url" id="rest_base_url" class="form-control" name="rest_base_url"
               value="{{ old('rest_base_url', $device->getAttrib('rest_base_url', '')) }}"
               placeholder="https://device.example/api">
        <small class="text-muted base-url-hint"></small>
    </div>
</div>

{{-- Auth Fields (dynamically shown based on auth type) --}}

{{-- Bearer Token --}}
<div class="form-group auth-field auth-bearer auth-apikey" style="display: none;">
    <label for="rest_token" class="col-sm-2 control-label">Token / API Key</label>
    <div class="col-sm-6">
        <input type="password" id="rest_token" class="form-control" name="rest_token"
               placeholder="Enter to set or replace" value="">
        @if(!empty($device->getAttrib('rest_token_enc')))
            <small class="text-muted">A token is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

{{-- Basic Auth --}}
<div class="form-group auth-field auth-basic" style="display: none;">
    <label for="rest_username" class="col-sm-2 control-label">Username</label>
    <div class="col-sm-6">
        <input type="text" id="rest_username" class="form-control" name="rest_username"
               value="{{ old('rest_username', $device->getAttrib('rest_username', '')) }}">
    </div>
</div>

<div class="form-group auth-field auth-basic" style="display: none;">
    <label for="rest_password" class="col-sm-2 control-label">Password</label>
    <div class="col-sm-6">
        <input type="password" id="rest_password" class="form-control" name="rest_password" value="">
        @if(!empty($device->getAttrib('rest_password_enc')))
            <small class="text-muted">A password is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

{{-- Proxmox Token Auth --}}
<div class="form-group auth-field auth-token" style="display: none;">
    <label for="proxmox_token_user" class="col-sm-2 control-label">Token User@Realm</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_token_user" class="form-control" name="proxmox_token_user"
               value="{{ old('proxmox_token_user', $device->getAttrib('proxmox_token_user', '')) }}"
               placeholder="user@pve">
    </div>
</div>

<div class="form-group auth-field auth-token" style="display: none;">
    <label for="proxmox_token_id" class="col-sm-2 control-label">Token ID</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_token_id" class="form-control" name="proxmox_token_id"
               value="{{ old('proxmox_token_id', $device->getAttrib('proxmox_token_id', '')) }}"
               placeholder="tokenid">
    </div>
</div>

<div class="form-group auth-field auth-token" style="display: none;">
    <label for="proxmox_token" class="col-sm-2 control-label">Token Secret</label>
    <div class="col-sm-6">
        <input type="password" id="proxmox_token" class="form-control" name="proxmox_token"
               placeholder="Enter to set or replace" value="">
        @if(!empty($device->getAttrib('proxmox_token_enc')))
            <small class="text-muted">A token secret is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

{{-- Proxmox Ticket Auth --}}
<div class="form-group auth-field auth-ticket" style="display: none;">
    <label for="proxmox_username" class="col-sm-2 control-label">Username@Realm</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_username" class="form-control" name="proxmox_username"
               value="{{ old('proxmox_username', $device->getAttrib('proxmox_username', '')) }}"
               placeholder="root@pam">
    </div>
</div>

<div class="form-group auth-field auth-ticket" style="display: none;">
    <label for="proxmox_password" class="col-sm-2 control-label">Password</label>
    <div class="col-sm-6">
        <input type="password" id="proxmox_password" class="form-control" name="proxmox_password" value="">
        @if(!empty($device->getAttrib('proxmox_password_enc')))
            <small class="text-muted">A password is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

{{-- Other Settings --}}
<div class="form-group">
    <label for="rest_headers" class="col-sm-2 control-label">Extra Headers (JSON)</label>
    <div class="col-sm-6">
        <textarea id="rest_headers" class="form-control" name="rest_headers" rows="2"
                  placeholder='{"X-Custom-Header":"value"}'>{{ old('rest_headers', $device->getAttrib('rest_headers', '')) }}</textarea>
    </div>
</div>

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                @php $verify = old('rest_verify_tls', $device->getAttrib('rest_verify_tls', 1)); @endphp
                <input type="checkbox" id="rest_verify_tls" name="rest_verify_tls" value="1"
                       {{ $verify ? 'checked' : '' }}>
                Verify TLS/SSL certificates
            </label>
            <small class="text-muted d-block">Uncheck to disable SSL certificate verification (not recommended for production).</small>
        </div>
    </div>
</div>

<div class="form-group">
    <label for="rest_timeout_ms" class="col-sm-2 control-label">Timeout (ms)</label>
    <div class="col-sm-6">
        <input type="number" id="rest_timeout_ms" class="form-control" name="rest_timeout_ms"
               value="{{ old('rest_timeout_ms', $device->getAttrib('rest_timeout_ms', 5000)) }}">
    </div>
</div>

<div class="form-group">
    <label for="rest_proxy" class="col-sm-2 control-label">Proxy (optional)</label>
    <div class="col-sm-6">
        <input type="text" id="rest_proxy" class="form-control" name="rest_proxy"
               value="{{ old('rest_proxy', $device->getAttrib('rest_proxy', '')) }}"
               placeholder="http://user:pass@proxy:3128">
    </div>
</div>

<div class="form-group">
    <label for="rest_rate_limit_qps" class="col-sm-2 control-label">Rate Limit (queries/second)</label>
    <div class="col-sm-6">
        <input type="number" id="rest_rate_limit_qps" class="form-control" name="rest_rate_limit_qps" min="1" max="100"
               value="{{ old('rest_rate_limit_qps', $device->getAttrib('rest_rate_limit_qps', 10)) }}">
        <small class="text-muted">Maximum API requests per second (default: 10).</small>
    </div>
</div>

<hr>

{{-- Endpoints Management Section --}}
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-10">
        <h4>API Endpoints <small class="text-muted">Configure which endpoints to poll</small></h4>
    </div>
</div>

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-10">
        <div class="panel panel-default">
            <div class="panel-heading">
                <button type="button" id="add-endpoint-btn" class="btn btn-xs btn-success pull-right">
                    <i class="fa fa-plus"></i> Add Endpoint
                </button>
                Configured Endpoints
            </div>
            <div class="panel-body" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-condensed table-hover" id="endpoints-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;"><input type="checkbox" id="toggle-all-endpoints"></th>
                            <th style="width: 20%;">Name</th>
                            <th style="width: 25%;">Path</th>
                            <th style="width: 10%;">Method</th>
                            <th style="width: 15%;">Category</th>
                            <th style="width: 15%;">Poll Interval (s)</th>
                            <th style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="endpoints-tbody">
                        {{-- Endpoints will be populated via JavaScript --}}
                    </tbody>
                </table>
                <p class="text-muted text-center" id="no-endpoints-msg" style="display: none;">
                    No endpoints configured. Select a template or add endpoints manually.
                </p>
            </div>
        </div>
    </div>
</div>

<input type="hidden" name="rest_endpoints" id="rest_endpoints" value="">

<hr>

{{-- Test Connection and Health Status --}}
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <button type="button" id="test-api-connection" class="btn btn-info">
            <i class="fa fa-plug"></i> Test Connection
        </button>

        @if(!empty($device->getAttrib('rest_error_count')) && $device->getAttrib('rest_error_count') > 0)
            <button type="button" id="reset-circuit-breaker" class="btn btn-warning">
                <i class="fa fa-refresh"></i> Reset Error Counter
            </button>
        @endif
    </div>
</div>

@if(!empty($device->getAttrib('rest_last_success')))
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <small class="text-muted">
            <i class="fa fa-check-circle text-success"></i>
            Last success: {{ \Carbon\Carbon::createFromTimestamp($device->getAttrib('rest_last_success'))->diffForHumans() }}
            @if(!empty($device->getAttrib('rest_avg_latency_ms')))
                (avg {{ $device->getAttrib('rest_avg_latency_ms') }}ms)
            @endif
        </small>
    </div>
</div>
@endif

@push('scripts')
<script>
// Device information
const deviceHostname = '{{ $device->hostname }}';
const deviceSysName = '{{ $device->sysName ?? $device->hostname }}';
const autoSelectTemplate = {{ isset($autoSelectTemplate) && $autoSelectTemplate ? 'true' : 'false' }};

// Template metadata and auth config
const templates = @json($templates);
const authTypes = @json($authTypes);
const configuredEndpoints = @json($configuredEndpoints);

// Pre-load all template data for instant switching
const allTemplateData = {
@foreach($templates as $vendor => $template)
    @php
        $fullTemplate = \LibreNMS\Util\ApiTemplateManager::loadTemplate($vendor);
    @endphp
    '{{ $vendor }}': @json($fullTemplate),
@endforeach
};

// Endpoints storage
let endpoints = configuredEndpoints && Array.isArray(configuredEndpoints) ? configuredEndpoints : [];

// Initialize on page load
$(document).ready(function() {
    // Show appropriate auth fields for saved auth type
    updateAuthFieldVisibility();

    // Render saved endpoints
    renderEndpointsTable();
    updateEndpointsHiddenField();

    // Update auth description if auth type is already set
    const authType = $('#rest_auth_type').val();
    if (authType && authTypes[authType]) {
        $('.auth-description').text(authTypes[authType].description);
    }

    // Auto-select and apply template if only one matches device OS and nothing is configured yet
    if (autoSelectTemplate && endpoints.length === 0) {
        const templateName = $('#rest_template').val();
        if (templateName) {
            loadTemplateData(templateName);
        }
    }
});

// Template selection handler
$('#rest_template').on('change', function() {
    const templateName = $(this).val();
    if (templateName) {
        // User selected a template - load and apply it
        loadTemplateData(templateName);
    } else {
        // User selected "Custom" - clear vendor but keep existing endpoints
        $('#rest_vendor').val('');
        toastr.info('Switched to custom configuration');
    }
});

// Load template data (from pre-loaded data)
function loadTemplateData(templateName) {
    if (allTemplateData[templateName]) {
        applyTemplate(allTemplateData[templateName]);
    } else {
        toastr.error('Template not found: ' + templateName);
        console.error('Template not found:', templateName);
    }
}

// Apply template to form
function applyTemplate(template) {
    // Set vendor name
    if (template.vendor) {
        $('#rest_vendor').val(template.vendor);
    }

    // Build and set base URL from pattern using device hostname
    if (template.base_url_pattern) {
        const baseUrl = template.base_url_pattern.replace('{hostname}', deviceHostname);
        $('#rest_base_url').val(baseUrl);
        $('.base-url-hint').text('Auto-populated from device hostname');
    } else if (template.base_url_example) {
        $('#rest_base_url').attr('placeholder', template.base_url_example);
        $('.base-url-hint').text('Example: ' + template.base_url_example);
    }

    // Set auth type
    if (template.auth_type) {
        $('#rest_auth_type').val(template.auth_type).trigger('change');
    }

    // Set default settings
    if (template.default_settings) {
        $('#rest_verify_tls').prop('checked', template.default_settings.verify_tls ?? true);
        $('#rest_timeout_ms').val(template.default_settings.timeout_ms ?? 5000);
        $('#rest_rate_limit_qps').val(template.default_settings.rate_limit_qps ?? 10);
    }

    // Load endpoints from template (always replace when template is selected)
    if (template.endpoints && template.endpoints.length > 0) {
        endpoints = template.endpoints.map(ep => ({...ep})); // Deep copy
        renderEndpointsTable();
        updateEndpointsHiddenField();
        toastr.success('Template applied with ' + endpoints.length + ' endpoint(s)');
    }
}

// Auth type change handler
$('#rest_auth_type').on('change', function() {
    updateAuthFieldVisibility();

    // Update description
    const authType = $(this).val();
    if (authType && authTypes[authType]) {
        $('.auth-description').text(authTypes[authType].description);
    } else {
        $('.auth-description').text('');
    }
});

// Show/hide auth fields based on selected type
function updateAuthFieldVisibility() {
    const authType = $('#rest_auth_type').val();

    // Hide all auth fields
    $('.auth-field').hide();

    // Show fields for selected auth type
    if (authType) {
        $('.auth-' + authType).show();
    }
}

// Render endpoints table
function renderEndpointsTable() {
    const tbody = $('#endpoints-tbody');
    tbody.empty();

    if (endpoints.length === 0) {
        $('#no-endpoints-msg').show();
        return;
    }

    $('#no-endpoints-msg').hide();

    endpoints.forEach((endpoint, index) => {
        const row = `
            <tr data-index="${index}">
                <td>
                    <input type="checkbox" class="endpoint-enabled" data-index="${index}"
                           ${endpoint.enabled ? 'checked' : ''}>
                </td>
                <td>${escapeHtml(endpoint.name)}</td>
                <td><code>${escapeHtml(endpoint.path)}</code></td>
                <td><span class="label label-info">${endpoint.method || 'GET'}</span></td>
                <td>${endpoint.category || 'general'}</td>
                <td>${endpoint.poll_interval || 60}</td>
                <td>
                    <button type="button" class="btn btn-xs btn-primary edit-endpoint" data-index="${index}">
                        <i class="fa fa-edit"></i>
                    </button>
                    <button type="button" class="btn btn-xs btn-danger delete-endpoint" data-index="${index}">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        tbody.append(row);
    });
}

// Update hidden field with endpoints JSON
function updateEndpointsHiddenField() {
    $('#rest_endpoints').val(JSON.stringify(endpoints));
}

// Toggle endpoint enabled status
$(document).on('change', '.endpoint-enabled', function() {
    const index = $(this).data('index');
    endpoints[index].enabled = $(this).is(':checked');
    updateEndpointsHiddenField();
});

// Toggle all endpoints
$('#toggle-all-endpoints').on('change', function() {
    const checked = $(this).is(':checked');
    $('.endpoint-enabled').prop('checked', checked).each(function() {
        const index = $(this).data('index');
        endpoints[index].enabled = checked;
    });
    updateEndpointsHiddenField();
});

// Add endpoint
$('#add-endpoint-btn').on('click', function() {
    showEndpointModal();
});

// Edit endpoint
$(document).on('click', '.edit-endpoint', function() {
    const index = $(this).data('index');
    showEndpointModal(endpoints[index], index);
});

// Delete endpoint
$(document).on('click', '.delete-endpoint', function() {
    if (!confirm('Are you sure you want to delete this endpoint?')) {
        return;
    }

    const index = $(this).data('index');
    endpoints.splice(index, 1);
    renderEndpointsTable();
    updateEndpointsHiddenField();
});

// Show endpoint modal (simplified - using prompt for now)
function showEndpointModal(endpoint = null, index = null) {
    const isEdit = endpoint !== null;

    const name = prompt('Endpoint Name:', endpoint?.name || '');
    if (!name) return;

    const path = prompt('Endpoint Path (e.g., /api/status):', endpoint?.path || '/');
    if (!path) return;

    const method = prompt('HTTP Method (GET/POST):', endpoint?.method || 'GET');
    const category = prompt('Category:', endpoint?.category || 'general');
    const pollInterval = parseInt(prompt('Poll Interval (seconds):', endpoint?.poll_interval || 60));
    const description = prompt('Description:', endpoint?.description || '');

    const newEndpoint = {
        id: endpoint?.id || 'custom_' + Date.now(),
        name: name,
        path: path,
        method: method.toUpperCase(),
        category: category,
        poll_interval: pollInterval,
        description: description,
        enabled: endpoint?.enabled ?? true
    };

    if (isEdit && index !== null) {
        endpoints[index] = newEndpoint;
    } else {
        endpoints.push(newEndpoint);
    }

    renderEndpointsTable();
    updateEndpointsHiddenField();
    toastr.success(isEdit ? 'Endpoint updated' : 'Endpoint added');
}

// Helper function
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Test API connection
$('#test-api-connection').on('click', function() {
    const btn = $(this);
    const originalHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');

    fetch('{{ route("device.test-api-connection", $device->device_id) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            rest_enabled: $('#rest_enabled').is(':checked'),
            rest_template: $('#rest_template').val(),
            rest_vendor: $('#rest_vendor').val(),
            rest_base_url: $('#rest_base_url').val(),
            rest_auth_type: $('#rest_auth_type').val(),
            rest_token: $('#rest_token').val(),
            rest_username: $('#rest_username').val(),
            rest_password: $('#rest_password').val(),
            rest_verify_tls: $('#rest_verify_tls').is(':checked'),
            rest_timeout_ms: $('#rest_timeout_ms').val()
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            let message = d.message || 'Connection successful!';
            if (d.test_path) {
                message += ' (tested: ' + d.test_path + ')';
            }
            toastr.success(message);
        } else {
            toastr.error('Connection failed: ' + (d.error || 'Unknown error'));
        }
    })
    .catch(e => {
        toastr.error('Connection test failed: ' + e.message);
    })
    .finally(() => {
        btn.prop('disabled', false).html(originalHtml);
    });
});

// Reset circuit breaker
$('#reset-circuit-breaker').on('click', function() {
    const btn = $(this);
    if (!confirm('Reset the error counter and circuit breaker for this device?')) {
        return;
    }

    btn.prop('disabled', true);

    fetch('{{ route("device.reset-circuit-breaker", $device->device_id) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            toastr.success('Circuit breaker reset successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            toastr.error('Failed to reset circuit breaker');
        }
    })
    .catch(() => {
        toastr.error('Failed to reset circuit breaker');
    })
    .finally(() => {
        btn.prop('disabled', false);
    });
});
</script>
@endpush

```
File Path: `includes/polling/functions.inc.php`
Modification Time: Sun Oct 26 14:17:10 2025

```php
<?php

use App\Facades\LibrenmsConfig;
use App\Models\Eventlog;
use Illuminate\Support\Str;
use LibreNMS\Enum\Sensor;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\JsonAppBase64DecodeException;
use LibreNMS\Exceptions\JsonAppBlankJsonException;
use LibreNMS\Exceptions\JsonAppExtendErroredException;
use LibreNMS\Exceptions\JsonAppGzipDecodeException;
use LibreNMS\Exceptions\JsonAppMissingKeysException;
use LibreNMS\Exceptions\JsonAppParsingFailedException;
use LibreNMS\Exceptions\JsonAppPollingFailedException;
use LibreNMS\Exceptions\JsonAppWrongVersionException;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\Util\Debug;
use LibreNMS\Util\Number;
use LibreNMS\Util\Oid;
use LibreNMS\Util\UserFuncHelper;

function bulk_sensor_snmpget($device, $sensors)
{
    $oid_per_pdu = get_device_oid_limit($device);
    $sensors = array_chunk($sensors, $oid_per_pdu);
    $cache = [];
    foreach ($sensors as $chunk) {
        $oids = array_map(function ($data) {
            return $data['sensor_oid'];
        }, $chunk);
        $oids = implode(' ', $oids);
        $multi_response = snmp_get_multi_oid($device, $oids, '-OUQntea');
        $cache = array_merge($cache, $multi_response);
    }

    return $cache;
}

/**
 * @param  $device
 * @param  string  $type  type/class of sensor
 * @return array
 */
function sensor_precache($device, $type)
{
    $sensor_cache = [];
    if (file_exists(LibrenmsConfig::get('install_dir') . '/includes/polling/sensors/pre-cache/' . $device['os'] . '.inc.php')) {
        include LibrenmsConfig::get('install_dir') . '/includes/polling/sensors/pre-cache/' . $device['os'] . '.inc.php';
    }

    return $sensor_cache;
}

function poll_sensor($device, $class)
{
    $sensors = [];
    $misc_sensors = [];
    $rest_api_sensors = [];
    $all_sensors = [];

    foreach (dbFetchRows('SELECT * FROM `sensors` WHERE `sensor_class` = ? AND `device_id` = ?', [$class, $device['device_id']]) as $sensor) {
        if ($sensor['poller_type'] == 'agent') {
            // Agent sensors are polled in the unix-agent
        } elseif ($sensor['poller_type'] == 'ipmi') {
            $misc_sensors[] = $sensor;
        } elseif ($sensor['poller_type'] == 'rest-api') {
            $rest_api_sensors[] = $sensor;
        } else {
            $sensors[] = $sensor;
        }
    }

    // Poll REST API sensors (fetch all at once)
    $rest_api_data = [];
    if (!empty($rest_api_sensors)) {
        d_echo("Polling " . count($rest_api_sensors) . " REST API sensors\n");
        $rest_api_data = require LibrenmsConfig::get('install_dir') . '/includes/polling/sensors/rest-api.inc.php';
    }

    $snmp_data = bulk_sensor_snmpget($device, $sensors);

    $sensor_cache = sensor_precache($device, $class);

    foreach ($sensors as $sensor) {
        Log::info('Checking (' . $sensor['poller_type'] . ") $class " . $sensor['sensor_descr'] . '... ');

        if ($sensor['poller_type'] == 'snmp') {
            $mibdir = null;

            $sensor_value = trim(str_replace('"', '', $snmp_data[$sensor['sensor_oid']] ?? ''));
            if (file_exists(LibrenmsConfig::get('install_dir') . '/includes/polling/sensors/' . $class . '/' . $device['os'] . '.inc.php')) {
                require LibrenmsConfig::get('install_dir') . '/includes/polling/sensors/' . $class . '/' . $device['os'] . '.inc.php';
            } elseif (isset($device['os_group']) && file_exists(LibrenmsConfig::get('install_dir') . '/includes/polling/sensors/' . $class . '/' . $device['os_group'] . '.inc.php')) {
                require LibrenmsConfig::get('install_dir') . '/includes/polling/sensors/' . $class . '/' . $device['os_group'] . '.inc.php';
            }

            if ($class == 'state') {
                if (! is_numeric($sensor_value)) {
                    $state_value = dbFetchCell(
                        'SELECT `state_value`
                        FROM `state_translations` LEFT JOIN `sensors_to_state_indexes`
                        ON `state_translations`.`state_index_id` = `sensors_to_state_indexes`.`state_index_id`
                        WHERE `sensors_to_state_indexes`.`sensor_id` = ?
                        AND `state_translations`.`state_descr` LIKE ?',
                        [$sensor['sensor_id'], $sensor_value]
                    );
                    d_echo('State value of ' . $sensor_value . ' is ' . $state_value . "\n");
                    if (is_numeric($state_value)) {
                        $sensor_value = $state_value;
                    }
                }
            }//end if
            if (isset($mib)) {
                // @phpstan-ignore unset.variable
                unset($mib);
            }
            unset($mibdir);
            $sensor['new_value'] = $sensor_value;
            $all_sensors[] = $sensor;
        }
    }

    foreach ($misc_sensors as $sensor) {
        if ($sensor['poller_type'] == 'agent') {
            if (isset($agent_sensors)) {
                // @phpstan-ignore variable.undefined
                $sensor_value = $agent_sensors[$class][$sensor['sensor_type']][$sensor['sensor_index']]['current'];
                $sensor['new_value'] = $sensor_value;
                $all_sensors[] = $sensor;
            } else {
                Log::info('no agent data!');
                continue;
            }
        } elseif ($sensor['poller_type'] == 'ipmi') {
            Log::info(' already polled.');
            // ipmi should probably move here from the ipmi poller file (FIXME)
            continue;
        } else {
            Log::info('unknown poller type!');
            continue;
        }//end if
    }

    // Process REST API sensors
    foreach ($rest_api_sensors as $sensor) {
        Log::info('Checking (rest-api) ' . $class . ' ' . $sensor['sensor_descr'] . '... ');

        $sensor_index = $sensor['sensor_index'];
        if (isset($rest_api_data[$sensor_index])) {
            $api_sensor = $rest_api_data[$sensor_index];
            $sensor_value = $api_sensor['sensor_current'] ?? null;

            if ($sensor_value !== null) {
                $sensor['new_value'] = $sensor_value;
                $all_sensors[] = $sensor;
                Log::info("$sensor_value\n");
            } else {
                Log::info("no value!\n");
            }
        } else {
            Log::info("not found in REST API data!\n");
        }
    }

    record_sensor_data($device, $all_sensors);
}//end poll_sensor()

/**
 * @param  $device
 * @param  $all_sensors
 */
function record_sensor_data($device, $all_sensors)
{
    foreach ($all_sensors as $sensor) {
        $class = trans('sensors.' . $sensor['sensor_class'] . '.short');
        $unit = Sensor::from($sensor['sensor_class'])->unit();
        $sensor_value = Number::extract($sensor['new_value']);
        $prev_sensor_value = $sensor['sensor_current'];

        if ($sensor_value == -32768 || is_nan($sensor_value)) {
            echo 'Invalid (-32768 or NaN)';
            $sensor_value = 0;
        }

        if ($sensor['sensor_divisor'] && $sensor_value !== 0) {
            $sensor_value /= $sensor['sensor_divisor'];
        }

        if ($sensor['sensor_multiplier']) {
            $sensor_value *= $sensor['sensor_multiplier'];
        }

        if (isset($sensor['user_func'])) {
            if (is_callable($sensor['user_func'])) {
                $sensor_value = $sensor['user_func']($sensor_value);
            } else {
                $sensor_value = (new UserFuncHelper($sensor_value, $sensor['new_value'], $sensor))->{$sensor['user_func']}();
            }
        }

        $rrd_name = get_sensor_rrd_name($device, $sensor);

        $rrd_def = RrdDefinition::make()->addDataset('sensor', $sensor['rrd_type']);

        Log::info("$sensor_value $unit");

        $fields = [
            'sensor' => $sensor_value,
        ];

        $tags = [
            'sensor_class' => $sensor['sensor_class'],
            'sensor_type' => $sensor['sensor_type'],
            'sensor_descr' => $sensor['sensor_descr'],
            'sensor_index' => $sensor['sensor_index'],
            'rrd_name' => $rrd_name,
            'rrd_def' => $rrd_def,
        ];
        app('Datastore')->put($device, 'sensor', $tags, $fields);

        // FIXME also warn when crossing WARN level!
        if ($sensor['sensor_limit_low'] != '' && $prev_sensor_value > $sensor['sensor_limit_low'] && $sensor_value < $sensor['sensor_limit_low'] && $sensor['sensor_alert'] == 1) {
            echo 'Alerting for ' . $device['hostname'] . ' ' . $sensor['sensor_descr'] . "\n";
            Eventlog::log("$class under threshold: $sensor_value $unit (< {$sensor['sensor_limit_low']} $unit)", $device['device_id'], $sensor['sensor_class'], Severity::Warning, $sensor['sensor_id']);
        } elseif ($sensor['sensor_limit'] != '' && $prev_sensor_value < $sensor['sensor_limit'] && $sensor_value > $sensor['sensor_limit'] && $sensor['sensor_alert'] == 1) {
            echo 'Alerting for ' . $device['hostname'] . ' ' . $sensor['sensor_descr'] . "\n";
            Eventlog::log("$class above threshold: $sensor_value $unit (> {$sensor['sensor_limit']} $unit)", $device['device_id'], $sensor['sensor_class'], Severity::Warning, $sensor['sensor_id']);
        }
        if ($sensor['sensor_class'] == 'state' && $prev_sensor_value != $sensor_value) {
            $trans = array_column(
                dbFetchRows(
                    'SELECT `state_translations`.`state_value`, `state_translations`.`state_descr` FROM `sensors_to_state_indexes` LEFT JOIN `state_translations` USING (`state_index_id`) WHERE `sensors_to_state_indexes`.`sensor_id`=? AND `state_translations`.`state_value` IN (?,?)',
                    [$sensor['sensor_id'], $sensor_value, $prev_sensor_value]
                ),
                'state_descr',
                'state_value'
            );

            Eventlog::log($class . ' sensor ' . ($sensor['sensor_descr'] ?? '') . ' has changed from ' . ($trans[$prev_sensor_value] ?? '#unamed state#') . "($prev_sensor_value) to " . ($trans[$sensor_value] ?? '#unamed state#') . " ($sensor_value)", $device['device_id'], $class, Severity::Notice, $sensor['sensor_id']);
        }
        if ($sensor_value != $prev_sensor_value) {
            dbUpdate(['sensor_current' => $sensor_value, 'sensor_prev' => $prev_sensor_value, 'lastupdate' => ['NOW()']], 'sensors', '`sensor_class` = ? AND `sensor_id` = ?', [$sensor['sensor_class'], $sensor['sensor_id']]);
        }
    }
}

/**
 * Update the application status and output in the database.
 *
 * Metric values should have key for of the matching name.
 * If you have multiple groups of metrics, you can group them with multiple sub arrays
 * The group name (key) will be prepended to each metric in that group, separated by an underscore
 * The special group "none" will not be prefixed.
 *
 * @param  \App\Models\Application  $app  app from the db, including app_id
 * @param  string  $response  This should be the return state of Application polling
 * @param  array  $metrics  an array of additional metrics to store in the database for alerting
 * @param  string  $status  This is the current value for alerting
 */
function update_application($app, $response, $metrics = [], $status = '')
{
    if (! $app) {
        d_echo('$app does not exist, could not update');

        return;
    }

    $app->app_state = 'UNKNOWN';
    $app->app_status = $status;
    $app->timestamp = DB::raw('NOW()');

    if ($response != '' && $response !== false) {
        // if the response indicates an error, set it and set app_status to the raw response
        if (Str::contains($response, [
            'Traceback (most recent call last):',
        ])) {
            $app->app_state = 'ERROR';
            $app->app_status = $response;
        } elseif (preg_match('/^(ERROR|LEGACY|UNSUPPORTED)/', $response, $matches)) {
            $app->app_state = $matches[1];
            $app->app_status = $response;
        } else {
            // should maybe be 'unknown' as state
            $app->app_state = 'OK';
        }
    }

    if ($app->isDirty('app_state')) {
        $app->app_state_prev = $app->getOriginal('app_state');

        switch ($app->app_state) {
            case 'OK':
                $severity = Severity::Ok;
                $event_msg = 'changed to OK';
                break;
            case 'ERROR':
                $severity = Severity::Error;
                $event_msg = 'ends with ERROR';
                break;
            case 'LEGACY':
                $severity = Severity::Warning;
                $event_msg = 'Client Agent is deprecated';
                break;
            case 'UNSUPPORTED':
                $severity = Severity::Error;
                $event_msg = 'Client Agent Version is not supported';
                break;
            default:
                $severity = Severity::Unknown;
                $event_msg = 'has UNKNOWN state';
                break;
        }
        \App\Models\Eventlog::log('Application ' . $app->displayName() . ' ' . $event_msg, DeviceCache::getPrimary(), 'application', $severity);
    }

    $app->save();

    // update metrics
    if (! empty($metrics)) {
        $db_metrics = dbFetchRows('SELECT * FROM `application_metrics` WHERE app_id=?', [$app['app_id']]);
        $db_metrics = array_by_column($db_metrics, 'metric');

        // allow two level metrics arrays, flatten them and prepend the group name
        if (is_array(current($metrics))) {
            $metrics = array_reduce(
                array_keys($metrics),
                function ($carry, $metric_group) use ($metrics) {
                    if ($metric_group == 'none') {
                        $prefix = '';
                    } else {
                        $prefix = $metric_group . '_';
                    }

                    foreach ($metrics[$metric_group] as $metric_name => $value) {
                        $carry[$prefix . $metric_name] = $value;
                    }

                    return $carry;
                },
                []
            );
        }

        echo ': ';
        foreach ($metrics as $metric_name => $value) {
            $value = (float) $value; // cast
            if (! isset($db_metrics[$metric_name])) {
                // insert new metric
                dbInsert(
                    [
                        'app_id' => $app['app_id'],
                        'metric' => $metric_name,
                        'value' => $value,
                    ],
                    'application_metrics'
                );
                echo '+';
            } elseif ($value != $db_metrics[$metric_name]['value']) {
                dbUpdate(
                    [
                        'value' => $value,
                        'value_prev' => $db_metrics[$metric_name]['value'],
                    ],
                    'application_metrics',
                    'app_id=? && metric=?',
                    [$app['app_id'], $metric_name]
                );
                echo 'U';
            } else {
                echo '.';
            }

            unset($db_metrics[$metric_name]);
        }

        // remove no longer existing metrics (generally should not happen
        foreach ($db_metrics as $db_metric) {
            dbDelete(
                'application_metrics',
                'app_id=? && metric=?',
                [$app['app_id'], $db_metric['metric']]
            );
            echo '-';
        }

        echo PHP_EOL;
    }
}

/**
 * This is to make it easier polling apps. Also to help standardize around JSON.
 *
 * If the data has is in base64, it will be converted and then gunzipped.
 * https://github.com/librenms/librenms-agent/blob/master/utils/lnms_return_optimizer
 * May be used to convert output from extends to that via piping it through it.
 *
 * The required keys for the returned JSON are as below.
 *  version     - The version of the snmp extend script. Should be numeric and at least 1.
 *  error       - Error code from the snmp extend script. Should be > 0 (0 will be ignored and negatives are reserved)
 *  errorString - Text to describe the error.
 *  data        - An key with an array with the data to be used.
 *
 * If the app returns an error, an exception will be raised.
 * Positive numbers will be errors returned by the extend script.
 *
 * Possible parsing related errors:
 * -2 : Failed to fetch data from the device
 * -3 : Could not decode the JSON.
 * -4 : Empty JSON parsed, meaning blank JSON was returned.
 * -5 : Valid json, but missing required keys
 * -6 : Returned version is less than the min version.
 * -7 : Base64 decode failure.
 * -8 : Gzip decode failure.
 *
 * Error checking may also be done via checking the exceptions listed below.
 *   JsonAppPollingFailedException, -2        : Empty return from SNMP.
 *   JsonAppParsingFailedException, -3        : Could not parse the JSON.
 *   JsonAppBlankJsonException, -4            : Blank JSON.
 *   JsonAppMissingKeysException, -5          : Missing required keys.
 *   JsonAppWrongVersionException , -6        : Older version than supported.
 *   JsonAppExtendErroredException            : Polling and parsing was good, but the returned data has an error set.
 *                                              This may be checked via $e->getParsedJson() and then checking the
 *                                              keys error and errorString.
 *   JsonAppPollingBase64DecodeException , -7 : Base64 decoding failed.
 *   JsonAppPollingGzipDecodeException , -8   : Gzip decoding failed.
 * The error value can be accessed via $e->getCode()
 * The output can be accessed via $->getOutput() Only returned for code -3 or lower.
 * The parsed JSON can be access via $e->getParsedJson()
 *
 * All of the exceptions extend JsonAppException.
 *
 * If the error is less than -1, you can assume it is a legacy snmp extend script.
 *
 * @param  array  $device
 * @param  string  $extend  the extend name. For example, if 'zfs' is passed it will be converted to 'nsExtendOutputFull.3.122.102.115'.
 * @param  int  $min_version  the minimum version to accept for the returned JSON. default: 1
 * @return array The json output data parsed into an array
 *
 * @throws JsonAppBlankJsonException
 * @throws JsonAppExtendErroredException
 * @throws JsonAppMissingKeysException
 * @throws JsonAppParsingFailedException
 * @throws JsonAppPollingFailedException
 * @throws JsonAppWrongVersionException
 */
function json_app_get($device, $extend, $min_version = 1)
{
    $output = snmp_get($device, 'nsExtendOutputFull.' . Oid::encodeString($extend), '-Oqv', 'NET-SNMP-EXTEND-MIB');

    // save for returning if not JSON
    $orig_output = $output;

    // make sure we actually get something back
    if (empty($output)) {
        throw new JsonAppPollingFailedException('Empty return from snmp_get.', -2);
    }

    // checks for base64 decoding and converts it to non-base64 so it can gunzip
    if (preg_match('/^[A-Za-z0-9\/\+\n]+\=*\n*$/', $output) && ! preg_match('/^[0-9]+\n/', $output)) {
        $output = base64_decode($output);
        if (! $output) {
            if (Debug::isEnabled()) {
                echo "Decoding Base64 Failed...\n\n";
            }
            throw new JsonAppBase64DecodeException('Base64 decode failed.', $orig_output, -7);
        }
        $output = gzdecode($output);
        if (! $output) {
            if (Debug::isEnabled()) {
                echo "Decoding GZip failed...\n\n";
            }
            throw new JsonAppGzipDecodeException('Gzip decode failed.', $orig_output, -8);
        }
        if (Debug::isVerbose()) {
            echo 'Decoded Base64+GZip Output: ' . $output . "\n\n";
        }
    } else {
        $output = stripslashes($output);
        if (Debug::isVerbose()) {
            echo 'Output post stripslashes: ' . $output . "\n\n";
        }
    }

    //  turn the JSON into a array
    $parsed_json = json_decode($output, true);

    // improper JSON or something else was returned. Populate the variable with an error.
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new JsonAppParsingFailedException('Invalid JSON', $orig_output, -3);
    }

    // There no keys in the array, meaning '{}' was was returned
    if (empty($parsed_json)) {
        throw new JsonAppBlankJsonException('Blank JSON returned.', $output, -4);
    }

    // It is a legacy JSON app extend, meaning these are not set
    if (! isset($parsed_json['error'], $parsed_json['data'], $parsed_json['errorString'], $parsed_json['version'])) {
        throw new JsonAppMissingKeysException('Legacy script or extend error, missing one or more required keys.', $output, $parsed_json, -5);
    }

    if ($parsed_json['version'] < $min_version) {
        throw new JsonAppWrongVersionException("Script,'" . $parsed_json['version'] . "', older than required version of '$min_version'", $output, $parsed_json, -6);
    }

    if ($parsed_json['error'] != 0) {
        throw new JsonAppExtendErroredException("Script returned exception: {$parsed_json['errorString']}", $output, $parsed_json, $parsed_json['error']);
    }

    return $parsed_json;
}

/**
 * Some data arrays returned with json_app_get are deeper than
 * update_application likes. This recurses through the array
 * and flattens it out so it can nicely be inserted into the
 * database.
 *
 * One argument is taken and that is the array to flatten.
 *
 * @param  array  $array
 * @param  string  $prefix  What to prefix to the name. Defaults to '', nothing.
 * @param  string  $joiner  The string to join the prefix, if set to something other
 *                          than '', and array keys with.
 * @return array The flattened array.
 */
function data_flatten($array, $prefix = '', $joiner = '_')
{
    $return = [];
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            if (strcmp($prefix, '')) {
                $key = $prefix . $joiner . $key;
            }
            $return = array_merge($return, data_flatten($value, $key, $joiner));
        } else {
            if (strcmp($prefix, '')) {
                $key = $prefix . $joiner . $key;
            }
            $return[$key] = $value;
        }
    }

    return $return;
}

```File Path: `includes/polling/ports.inc.php`
Modification Time: Sun Oct 26 14:18:03 2025

```php
<?php

use App\Facades\LibrenmsConfig;
use App\Models\Eventlog;
use LibreNMS\Enum\PortAssociationMode;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\Util\Debug;
use LibreNMS\Util\Mac;
use LibreNMS\Util\Number;
use LibreNMS\Util\StringHelpers;

// Build SNMP Cache Array
$data_oids = [
    'ifName',
    'ifDescr',
    'ifAlias',
    'ifAdminStatus',
    'ifOperStatus',
    'ifMtu',
    'ifSpeed',
    'ifType',
    'ifPhysAddress',
    'ifConnectorPresent',
    'ifDuplex',
    'ifTrunk',
    'ifVlan',
];

$stat_oids = [
    'ifInErrors',
    'ifOutErrors',
    'ifInUcastPkts',
    'ifOutUcastPkts',
    'ifInNUcastPkts',
    'ifOutNUcastPkts',
    'ifHCInMulticastPkts',
    'ifHCInBroadcastPkts',
    'ifHCOutMulticastPkts',
    'ifHCOutBroadcastPkts',
    'ifInOctets',
    'ifOutOctets',
    'ifHCInOctets',
    'ifHCOutOctets',
    'ifInDiscards',
    'ifOutDiscards',
    'ifInUnknownProtos',
    'ifInBroadcastPkts',
    'ifOutBroadcastPkts',
    'ifInMulticastPkts',
    'ifOutMulticastPkts',
];

$stat_oids_db = [
    'ifInOctets',
    'ifOutOctets',
    'ifInErrors',
    'ifOutErrors',
    'ifInUcastPkts',
    'ifOutUcastPkts',
];

$stat_oids_db_extended = [
    'ifInNUcastPkts',
    'ifOutNUcastPkts',
    'ifInDiscards',
    'ifOutDiscards',
    'ifInUnknownProtos',
    'ifInBroadcastPkts',
    'ifOutBroadcastPkts',
    'ifInMulticastPkts',
    'ifOutMulticastPkts',
];

$cisco_oids = [
    'locIfHardType',
    'locIfInRunts',
    'locIfInGiants',
    'locIfInCRC',
    'locIfInFrame',
    'locIfInOverrun',
    'locIfInIgnored',
    'locIfInAbort',
    'locIfCollisions',
    'locIfInputQueueDrops',
    'locIfOutputQueueDrops',
];

/*
 * CISCO-IF-EXTENSION MIB
 */
$cisco_if_extension_oids = [
    'cieIfInRuntsErrs',
    'cieIfInGiantsErrs',
    'cieIfInFramingErrs',
    'cieIfInOverrunErrs',
    'cieIfInIgnored',
    'cieIfInAbortErrs',
    'cieIfInputQueueDrops',
    'cieIfOutputQueueDrops',
];

$pagp_oids = [
    'pagpOperationMode',
];

$pagp_extended_oids = [
    'pagpPortState',
    'pagpPartnerDeviceId',
    'pagpPartnerLearnMethod',
    'pagpPartnerIfIndex',
    'pagpPartnerGroupIfIndex',
    'pagpPartnerDeviceName',
    'pagpEthcOperationMode',
    'pagpDeviceId',
    'pagpGroupIfIndex',
];

$ifmib_oids = [
    'ifDescr',
    'ifAdminStatus',
    'ifOperStatus',
    'ifLastChange',
    'ifType',
    'ifPhysAddress',
    'ifMtu',
    'ifInErrors',
    'ifOutErrors',
    'ifInDiscards',
    'ifOutDiscards',
];

$table_base_oids = [
    'ifName',
    'ifAlias',
    'ifDescr',
    'ifHighSpeed',
    'ifOperStatus',
    'ifAdminStatus',
];

$hc_mappings = [
    'ifHCInOctets' => 'ifInOctets',
    'ifHCOutOctets' => 'ifOutOctets',
    'ifHCInUcastPkts' => 'ifInUcastPkts',
    'ifHCOutUcastPkts' => 'ifOutUcastPkts',
    'ifHCInBroadcastPkts' => 'ifInBroadcastPkts',
    'ifHCOutBroadcastPkts' => 'ifOutBroadcastPkts',
    'ifHCInMulticastPkts' => 'ifInMulticastPkts',
    'ifHCOutMulticastPkts' => 'ifOutMulticastPkts',
];

$hc_oids = [
    'ifInMulticastPkts',
    'ifInBroadcastPkts',
    'ifOutMulticastPkts',
    'ifOutBroadcastPkts',
    'ifHCInOctets',
    'ifHCInUcastPkts',
    'ifHCInMulticastPkts',
    'ifHCInBroadcastPkts',
    'ifHCOutOctets',
    'ifHCOutUcastPkts',
    'ifHCOutMulticastPkts',
    'ifHCOutBroadcastPkts',
    'ifConnectorPresent',
];

$nonhc_oids = [
    'ifSpeed',
    'ifInOctets',
    'ifInUcastPkts',
    'ifInUnknownProtos',
    'ifOutOctets',
    'ifOutUcastPkts',
];

$shared_oids = [
    'ifInErrors',
    'ifOutErrors',
    'ifInNUcastPkts',
    'ifOutNUcastPkts',
    'ifInDiscards',
    'ifOutDiscards',
    'ifPhysAddress',
    'ifLastChange',
    'ifType',
    'ifMtu',
];

$dot3_oids = [
    'dot3StatsIndex',
    'dot3StatsDuplexStatus',
];

// Query known ports and mapping table in order of discovery to make sure
// the latest discoverd/polled port is in the mapping tables.
$ports_mapped = get_ports_mapped($device['device_id'], true);
// If we are not running tests, and no ports are found, we need to run discovery first.
if (! defined('PHPUNIT_RUNNING') && empty($ports_mapped['ports'])) {
    Log::info("No ports found for device {$device['hostname']}, discovery needs to be run first.");

    return;
}

$ports = $ports_mapped['ports'];

$fetched_data_string = 'Fetched data ';
$port_stats = [];

if ($device['os'] === 'f5' && (version_compare($device['version'], '11.2.0', '>=') && version_compare($device['version'], '11.7', '<'))) {
    require 'ports/f5.inc.php';
} elseif ($device['os'] === 'exalink-fusion') {
    require 'ports/exalink-fusion.inc.php';
} else {
    $selected_attrib = DeviceCache::get($device['device_id'] ?? null)->getAttrib('selected_ports');
    if ($selected_attrib !== null ? $selected_attrib == 'true' : LibrenmsConfig::getOsSetting($device['os'], 'polling.selected_ports')) {
        $fetched_data_string .= '(Selected ports polling): ';

        // remove the deleted and disabled ports and mark them skipped
        $polled_ports = array_filter($ports, function ($port) use ($ports) {
            $ports[$port['ifIndex']]['skipped'] = true;

            return ! ($port['deleted'] || $port['disabled']);
        });

        // only try to guess if we should walk base oids if selected_ports is set only globally
        $walk_base = false;
        if (! LibrenmsConfig::has("os.{$device['os']}.polling.selected_ports") && $selected_attrib === null) {
            // if less than 5 ports or less than 10% of the total ports are skipped, walk the base oids instead of get
            $polled_port_count = count($polled_ports);
            $total_port_count = count($ports);
            $walk_base = $total_port_count - $polled_port_count < 5 || $polled_port_count / $total_port_count > 0.9;

            if ($walk_base) {
                Log::info('Not enough ports for selected port polling, walking base OIDs instead');
                foreach ($table_base_oids as $oid) {
                    $port_stats = snmpwalk_cache_oid($device, $oid, $port_stats, 'IF-MIB');
                }
            }
        }

        foreach ($polled_ports as $port_id => $port) {
            $ifIndex = $port['ifIndex'];
            $port_stats[$ifIndex]['ifType'] = $port['ifType']; // we keep it as it is not included in $base_oids

            if (is_port_valid($port, $device)) {
                if (! $walk_base) {
                    // we didn't walk,so snmpget the base oids
                    $base_oids = implode(".$ifIndex ", $table_base_oids) . ".$ifIndex";
                    $port_stats = snmp_get_multi($device, $base_oids, '-OQUst', 'IF-MIB', null, $port_stats);
                }

                // if admin down or operator down since the last poll, skip polling this port
                $admin_down = $port['ifAdminStatus_prev'] === 'down' && $port_stats[$ifIndex]['ifAdminStatus'] === 'down';
                $oper_down = $port['ifOperStatus_prev'] === 'down' && $port_stats[$ifIndex]['ifOperStatus'] === 'down';
                $ll_down = $port['ifOperStatus_prev'] === 'lowerLayerDown' && $port_stats[$ifIndex]['ifOperStatus'] === 'lowerLayerDown';

                if ($admin_down || $oper_down || $ll_down) {
                    if ($admin_down) {
                        d_echo(" port $ifIndex is still admin down\n");
                    } else {
                        d_echo(" port $ifIndex is still down\n");
                    }
                    $ports[$port_id]['skipped'] = true;
                } else {
                    d_echo(" $ifIndex: valid\n");
                    if (is_numeric($port_stats[$ifIndex]['ifHighSpeed']) && $port_stats[$ifIndex]['ifHighSpeed'] > 0) {
                        $full_oids = array_merge($hc_oids, $shared_oids);
                    } else {
                        $full_oids = array_merge($nonhc_oids, $shared_oids);
                    }
                    $oids = implode(".$ifIndex ", $full_oids) . ".$ifIndex";
                    $extra_oids = implode(".$ifIndex ", $dot3_oids) . ".$ifIndex";
                    unset($full_oids);

                    $port_stats = snmp_get_multi($device, $oids, '-OQUst', 'IF-MIB', null, $port_stats);
                    $port_stats = snmp_get_multi($device, $extra_oids, '-OQUst', 'EtherLike-MIB', null, $port_stats);

                    if ($device['os'] != 'asa') {
                        $port_stats = snmp_get_multi($device, "dot1qPvid.$ifIndex", '-OQUst', 'Q-BRIDGE-MIB', null, $port_stats);
                    }
                }
            }
        }
    } else {
        $fetched_data_string .= '(Full ports polling): ';
        // For devices that are on the bad_ifXentry list, try fetching ifAlias to have nice interface descriptions.

        if (! in_array(strtolower($device['hardware'] ?? ''), array_map('strtolower', (array) LibrenmsConfig::getOsSetting($device['os'], 'bad_ifXEntry', [])))) {
            $port_stats = snmpwalk_cache_oid($device, 'ifXEntry', $port_stats, 'IF-MIB');
        } else {
            $port_stats = snmpwalk_cache_oid($device, 'ifAlias', $port_stats, 'IF-MIB', null, '-OQUst');
            $port_stats = snmpwalk_cache_oid($device, 'ifName', $port_stats, 'IF-MIB', null, '-OQUst');
        }
        $hc_test = array_slice($port_stats, 0, 1);
        // If the device doesn't have ifXentry data, fetch ifEntry instead.
        if (! is_numeric($hc_test[0]['ifHCInOctets'] ?? null) || ! is_numeric($hc_test[0]['ifHighSpeed'] ?? null)) {
            $ifEntrySnmpFlags = ['-OQUst'];
            if ($device['os'] == 'bintec-beip-plus') {
                $ifEntrySnmpFlags = ['-OQUst', '-Cc'];
            }
            $port_stats = snmpwalk_cache_oid($device, 'ifEntry', $port_stats, 'IF-MIB', null, $ifEntrySnmpFlags);
        } else {
            // For devices with ifXentry data, only specific ifEntry keys are fetched to reduce SNMP load
            foreach ($ifmib_oids as $oid) {
                $fetched_data_string .= "$oid ";
                $port_stats = snmpwalk_cache_oid($device, $oid, $port_stats, 'IF-MIB', null, '-OQUst');
            }
        }
        if ($device['os'] != 'asa') {
            $fetched_data_string .= 'dot3StatsDuplexStatus ';
            if (LibrenmsConfig::get('enable_ports_poe') || LibrenmsConfig::get('enable_ports_etherlike')) {
                $port_stats = snmpwalk_cache_oid($device, 'dot3StatsIndex', $port_stats, 'EtherLike-MIB');
            }
            $dot3StatsDuplexStatusSnmpFlags = '-OQUs';
            if ($device['os'] == 'bintec-beip-plus') {
                $dot3StatsDuplexStatusSnmpFlags = '-Cc';
            }
            $port_stats = snmpwalk_cache_oid($device, 'dot3StatsDuplexStatus', $port_stats, 'EtherLike-MIB', null, $dot3StatsDuplexStatusSnmpFlags);
            $port_stats = snmpwalk_cache_oid($device, 'dot1qPvid', $port_stats, 'Q-BRIDGE-MIB');
        }
    }
}

$os_file = base_path("includes/polling/ports/os/{$device['os']}.inc.php");
if (file_exists($os_file)) {
    require $os_file;
}

if (LibrenmsConfig::get('enable_ports_poe')) {
    // Code by OS device

    if ($device['os'] == 'ios' || $device['os'] == 'iosxe') {
        $fetched_data_string .= 'cpeExtPsePortTable ';
        $port_stats_poe = snmpwalk_cache_oid($device, 'cpeExtPsePortTable', [], 'CISCO-POWER-ETHERNET-EXT-MIB');
        $port_ent_to_if = snmpwalk_cache_oid($device, 'portIfIndex', [], 'CISCO-STACK-MIB');

        if (! $port_ent_to_if) {
            $ifTable_ifDescr = snmpwalk_cache_oid($device, 'ifDescr', [], 'IF-MIB');
            $port_ent_to_if = [];
            foreach ($ifTable_ifDescr as $if_index => $if_descr) {
                /*
                The ...EthernetX/Y/Z SNMP entries on Catalyst 9x00 iosxe
                are cpeExtStuff.X.Z instead of cpeExtStuff.X.Y.Z
                We need to ignore the middle subslot number so this is slot.port
                */
                if (preg_match('/^[a-z]+ethernet(\d+)\/(\d+)(?:\/(\d+))?$/i', $if_descr['ifDescr'], $matches)) {
                    $port_ent_to_if[$matches[1] . '.' . ($matches[3] ?? $matches[2])] = ['portIfIndex' => $if_index];
                }
            }
        }

        foreach ($port_stats_poe as $p_index => $p_stats) {
            //We replace the ENTITY EntIndex by the IfIndex using the portIfIndex table (stored in $port_ent_to_if).
            //Result is merged into $port_stats
            if ($port_ent_to_if[$p_index] && $port_ent_to_if[$p_index]['portIfIndex'] && $port_stats[$port_ent_to_if[$p_index]['portIfIndex']]) {
                $port_stats[$port_ent_to_if[$p_index]['portIfIndex']] += $p_stats;
            }
        }
    } elseif ($device['os'] == 'vrp') {
        $fetched_data_string .= 'HwPoePortTable ';

        $vrp_poe_oids = [
            'hwPoePortReferencePower',
            'hwPoePortMaximumPower',
            'hwPoePortConsumingPower',
            'hwPoePortPeakPower',
            'hwPoePortEnable',
        ];

        foreach ($vrp_poe_oids as $oid) {
            $port_stats = snmpwalk_cache_oid($device, $oid, $port_stats, 'HUAWEI-POE-MIB');
        }
    } elseif ($device['os'] == 'linksys-ss') {
        $fetched_data_string .= 'rlPethPsePort ';

        $linksys_poe_oids = [
            'pethPsePortAdminEnable',
            'rlPethPsePortPowerLimit',
            'rlPethPsePortOutputPower',
        ];

        $port_stats_temp = [];
        foreach ($linksys_poe_oids as $oid) {
            $port_stats_temp = snmpwalk_cache_oid($device, $oid, $port_stats_temp, 'LINKSYS-POE-MIB:POWER-ETHERNET-MIB');
        }
        foreach ($port_stats_temp as $key => $value) {
            //remove the group index and only keep the ifIndex
            [$group_id, $if_id] = explode('.', $key);
            $port_stats[$if_id] = array_merge($port_stats[$if_id], $value);
        }
    } elseif ($device['os'] == 'jetstream') {
        $fetched_data_string .= 'tpPoePortConfigTable ';
        $port_stats_poe = snmpwalk_cache_oid($device, 'tpPoePortConfigTable', [], 'TPLINK-POWER-OVER-ETHERNET-MIB');
        $ifTable_ifDescr = snmpwalk_cache_oid($device, 'ifDescr', [], 'IF-MIB');

        $port_ent_to_if = [];
        foreach ($ifTable_ifDescr as $if_index => $if_descr) {
            if (preg_match('/^[a-z]+ethernet \d+\/\d+\/(\d+)$/i', $if_descr['ifDescr'], $matches)) {
                $port_ent_to_if[$matches[1]] = $if_index;
            }
        }

        foreach ($port_stats_poe as $p_index => $p_stats) {
            $if_id = $port_ent_to_if[$p_index];
            if (is_array($port_stats[$if_id])) {
                $port_stats[$if_id] = array_merge($port_stats[$if_id], $p_stats);
            }
        }
    }
}

if (isset($device['os_group']) && $device['os_group'] == 'cisco' && $device['os'] != 'asa') {
    foreach ($pagp_oids as $oid) {
        $pagp_port_stats = snmpwalk_cache_oid($device, $oid, [], 'CISCO-PAGP-MIB');
    }
    if (count($pagp_port_stats) > 0) {
        foreach ($pagp_port_stats as $p_index => $p_stats) {
            $port_stats[$p_index]['pagpOperationMode'] = $p_stats['pagpOperationMode'];
        }
        foreach ($pagp_extended_oids as $oid) {
            $port_stats = snmpwalk_cache_oid($device, $oid, $port_stats, 'CISCO-PAGP-MIB');
        }
    }

    // Grab data to put ports into vlans or make them trunks
    // FIXME we probably shouldn't be doing this from the VTP MIB, right?
    $port_stats = snmpwalk_cache_oid($device, 'vmVlan', $port_stats, 'CISCO-VLAN-MEMBERSHIP-MIB');
    $port_stats = snmpwalk_cache_oid($device, 'vlanTrunkPortEncapsulationOperType', $port_stats, 'CISCO-VTP-MIB');
    $port_stats = snmpwalk_cache_oid($device, 'vlanTrunkPortNativeVlan', $port_stats, 'CISCO-VTP-MIB');
}//end if

/*
 *  Most (all) of the IOS/IOS-XE devices support CISCO-IF-EXTENSION MIB that provides
 *  additional informationa bout input and output errors as seen in `show interface` output.
 */
if ($device['os'] == 'ios' || $device['os'] == 'iosxe') {
    foreach ($cisco_if_extension_oids as $oid) {
        $port_stats = snmpwalk_cache_oid($device, $oid, $port_stats, 'CISCO-IF-EXTENSION-MIB');
    }
}

Log::info($fetched_data_string);

// REST API port polling (if enabled)
require base_path('includes/polling/ports/rest-api.inc.php');

$polled = time();

// End Building SNMP Cache Array
d_echo($port_stats);

// By default libreNMS uses the ifIndex to associate ports on devices with ports discoverd/polled
// before and stored in the database. On Linux boxes this is a problem as ifIndexes may be
// unstable between reboots or (re)configuration of tunnel interfaces (think: GRE/OpenVPN/Tinc/...)
// The port association configuration allows to choose between association via ifIndex, ifName,
// or maybe other means in the future. The default port association mode still is ifIndex for
// compatibility reasons.
$port_association_mode = LibrenmsConfig::get('default_port_association_mode');
if ($device['port_association_mode']) {
    $port_association_mode = PortAssociationMode::getName($device['port_association_mode']);
}

$ports_found = [];
// New interface detection
foreach ($port_stats as $ifIndex => $port) {
    // Store ifIndex in port entry and prefetch ifName as we'll need it multiple times
    $port['ifIndex'] = $ifIndex;
    $ifName = $port['ifName'] ?? null;

    // Get port_id according to port_association_mode used for this device
    $port_id = get_port_id($ports_mapped, $port, $port_association_mode);

    if (is_port_valid($port, $device)) {
        d_echo(' valid');

        // Port newly discovered?
        if (! $port_id || empty($ports[$port_id])) {
            /**
             * When using the ifName or ifDescr as means to map discovered ports to
             * known ports in the DB (think of port association mode) it's possible
             * that we're facing the problem that the ifName or ifDescr polled from
             * the device is unset or an empty string (like when querying some ubnt
             * devices...). If this happends we have no way to map this port to any
             * port found in the database. As reported this situation may occur for
             * the time of one poll and might resolve automagically before the next
             * poller run happens. Without this special case this would lead to new
             * ports added to the database each time this situation occurs. To give
             * the user the choice between »a lot of new ports« and »some poll runs
             * are missed but ports stay stable« the 'ignore_unmapable_port' option
             * has been added to configure this behaviour. To skip the port in this
             * loop is sufficient as the next loop is looping only over ports found
             * in the database and "maps back". As we did not add a new port to the
             * DB here, there's no port to be mapped to.
             *
             * I'm using the in_array() check here, as I'm not sure if an "ifIndex"
             * can be legally set to 0, which would yield True when checking if the
             * value is empty().
             */
            if (LibrenmsConfig::get('ignore_unmapable_port') === true && in_array($port[$port_association_mode], ['', null])) {
                continue;
            }

            $port_id = dbInsert(['device_id' => $device['device_id'], 'ifIndex' => $ifIndex, 'ifName' => $ifName], 'ports');
            dbInsert(['port_id' => $port_id], 'ports_statistics');
            $ports[$port_id] = dbFetchRow('SELECT * FROM `ports` WHERE `port_id` = ?', [$port_id]);
            Log::info('Adding: ' . $ifName . '(' . $ifIndex . ')(' . $port_id . ')');
        } elseif ($ports[$port_id]['deleted'] == 1) {
            // Port re-discovered after previous deletion?
            dbUpdate(['deleted' => '0'], 'ports', '`port_id` = ?', [$port_id]);
            $ports[$port_id]['deleted'] = '0';
        }
        if (! isset($ports[$port_id]['ports_statistics_port_id'])) {
            // in case the port was created before we created the table
            dbInsert(['port_id' => $port_id], 'ports_statistics');
        }

        /** Assure stable bidirectional port mapping between DB and polled data
         *
         * Store the *current* ifIndex in the port info array containing all port information
         * fetched from the database, as this is the only means we have to map ports_stats we
         * just polled from the device to a port in $ports. All code below an includeed below
         * will and has to map a port using it's ifIndex.
         */
        $ports[$port_id]['ifIndex'] = $ifIndex;
        $port_stats[$ifIndex]['port_id'] = $port_id;

        /* Build a list of all ports, identified by their port_id, found within this poller run. */
        $ports_found[] = $port_id;
    } elseif ($port_id && empty($ports[$port_id]['skipped'])) {
        // Port vanished (mark as deleted) (except when skipped by selective port polling)
        if ($ports[$port_id]['deleted'] != '1') {
            dbUpdate(['deleted' => '1'], 'ports', '`port_id` = ?', [$port_id]);
            $ports[$port_id]['deleted'] = '1';
        }
    }
} // End new interface detection

// get last poll time to optimize poll_time, poll_prev and poll_period in table db
$prev_poll_times = array_filter(array_column($ports, 'poll_time'));
$max_poll_time_prev = empty($prev_poll_times) ? null : max($prev_poll_times);
$device_global_ports = [
    'poll_time' => $polled,
    'poll_prev' => $max_poll_time_prev,
    'poll_period' => $max_poll_time_prev ? null : ($polled - $max_poll_time_prev),
];

$globally_updated_port_ids = [];

// Loop ports in the DB and update where necessary
foreach ($ports as $port) {
    $port_id = $port['port_id'];
    $ifIndex = $port['ifIndex'];

    $port_info_string = 'Port ' . $port['ifName'] . ': ' . $port['ifDescr'] . " ($ifIndex / #$port_id) ";

    /* We don't care for disabled ports, go on */
    if ($port['disabled'] == 1) {
        Log::info("{$port_info_string}disabled.");
        continue;
    }

    /**
     * If this port did not show up in $port_stats before it has been deleted
     * since the last poller run. Mark it deleted in the database and go on.
     */
    if (! in_array($port_id, $ports_found)) {
        if ($port['deleted'] != '1') {
            dbUpdate(['deleted' => '1'], 'ports', '`device_id` = ? AND `port_id` = ?', [$device['device_id'], $port_id]);
            Log::info("{$port_info_string}deleted.");
        }
        continue;
    }

    if ($port_stats[$ifIndex]) {
        // Check to make sure Port data is cached.
        $this_port = &$port_stats[$ifIndex];

        if ($device['os'] == 'vmware-vcsa' && preg_match('/Device ([a-z0-9]+) at .*/', $this_port['ifDescr'], $matches)) {
            $this_port['ifName'] = $matches[1];
        }

        $polled_period = max($polled - $port['poll_time'], 1);

        $port['update'] = [];
        $port['update_extended'] = [];
        $port['state'] = [];

        if ($port_association_mode != 'ifIndex') {
            $port['update']['ifIndex'] = $ifIndex;
        }

        // rewrite the ifPhysAddress
        if (isset($this_port['ifPhysAddress'])) {
            $this_port['ifPhysAddress'] = Mac::parse($this_port['ifPhysAddress'])->hex();
        }

        // use HC values if they are available
        foreach ($hc_mappings as $hc_oid => $if_oid) {
            if (isset($this_port[$hc_oid]) && $this_port[$hc_oid]) {
                d_echo("$hc_oid ");
                $this_port[$if_oid] = $this_port[$hc_oid];
            } else {
                d_echo("$if_oid ");
            }
        }

        // ifHighSpeed is signed integer, but should be unsigned (Gauge32 in RFC2233). Workaround for some fortinet devices.
        if ($device['os'] == 'fortigate' || $device['os'] == 'fortisandbox') {
            if (isset($this_port['ifHighSpeed']) && $this_port['ifHighSpeed'] > 2147483647) {
                $this_port['ifHighSpeed'] = null;
            }
        }

        if (isset($this_port['ifHighSpeed']) && is_numeric($this_port['ifHighSpeed'])) {
            d_echo("ifHighSpeed ({$this_port['ifHighSpeed']}) ");
            $this_port['ifSpeed'] = $this_port['ifHighSpeed'] == '0' ? 0
                : $this_port['ifHighSpeed'] . '000000'; // * 1000000, but handle in sql
        } elseif (isset($this_port['ifSpeed']) && is_numeric($this_port['ifSpeed'])) {
            d_echo("ifSpeed ({$this_port['ifSpeed']}) ");
        } else {
            d_echo('No ifSpeed ');
            $this_port['ifSpeed'] = 0;
        }

        // Overwrite ifDuplex with dot3StatsDuplexStatus if it exists
        if (isset($this_port['dot3StatsDuplexStatus'])) {
            $port_info_string .= 'dot3Duplex ';
            $this_port['ifDuplex'] = $this_port['dot3StatsDuplexStatus'];
        }

        // update ifLastChange. only in the db, not rrd
        if (isset($this_port['ifLastChange']) && is_numeric($this_port['ifLastChange'])) {
            if ((int) $this_port['ifLastChange'] != (int) $port['ifLastChange']) {
                $port['update']['ifLastChange'] = $this_port['ifLastChange'];
            }
        } elseif ($port['ifLastChange'] != 0) {
            $port['update']['ifLastChange'] = 0;  // no data, so use the same as device uptime
        }

        // Set VLAN and Trunk from Cisco
        if (isset($this_port['vlanTrunkPortEncapsulationOperType']) && $this_port['vlanTrunkPortEncapsulationOperType'] != 'notApplicable') {
            $this_port['ifTrunk'] = $this_port['vlanTrunkPortEncapsulationOperType'];
            if (isset($this_port['vlanTrunkPortNativeVlan'])) {
                $this_port['ifVlan'] = $this_port['vlanTrunkPortNativeVlan'];
            }
        }

        if (isset($this_port['vmVlan'])) {
            $this_port['ifVlan'] = $this_port['vmVlan'];
        }

        // Set VLAN and Trunk from Q-BRIDGE-MIB
        if (! isset($this_port['ifVlan']) && isset($this_port['dot1qPvid'])) {
            $this_port['ifVlan'] = $this_port['dot1qPvid'];
        }

        // Set ifConnectorPresent to null when the device does not support IF-MIB truth values.
        if (isset($this_port['ifConnectorPresent']) && ! in_array($this_port['ifConnectorPresent'], ['true', 'false'])) {
            $this_port['ifConnectorPresent'] = null;
        }

        // FIXME use $q_bridge_mib[$this_port['ifIndex']] to see if it is a trunk (>1 array count)
        $port_info_string .= '  VLAN = ' . ($this_port['ifVlan'] ?? '?') . ' ';

        // attempt to fill missing fields
        port_fill_missing_and_trim($this_port, $device);

        // Update IF-MIB data
        $tune_port = false;
        foreach ($data_oids as $oid) {
            $current_oid = $this_port[$oid] ?? null;

            if ($oid == 'ifAlias') {
                $ifAlias_override = DeviceCache::getPrimary()->getAttrib('ifName:' . $port['ifName']);
                if ($ifAlias_override !== null) {
                    // handle legacy '1' setting, otherwise use value set by override
                    $current_oid = $ifAlias_override === '1' ? $port['ifAlias'] : $ifAlias_override;
                } else {
                    $current_oid = $this_port['ifAlias'];
                }
                $current_oid = StringHelpers::inferEncoding($current_oid); // prevent invalid non-utf8 characters
            }
            if ($oid == 'ifSpeed') {
                $ifSpeed_override = DeviceCache::getPrimary()->getAttrib('ifSpeed:' . $port['ifName']);
                $current_oid = $ifSpeed_override ?? $current_oid;
            }

            if ($port[$oid] != $current_oid && ! isset($current_oid)) {
                $port['update'][$oid] = null;
                Eventlog::log($oid . ': ' . $port[$oid] . ' -> NULL', $device['device_id'], 'interface', Severity::Warning, $port['port_id']);
                d_echo($oid . ': ' . $port[$oid] . ' -> NULL ', $oid . ' ');
            } elseif ($port[$oid] != $current_oid) {
                // if the value is different, update it

                // rrdtune if needed
                if ($oid == 'ifSpeed') {
                    $port_tune = DeviceCache::getPrimary()->getAttrib('ifName_tune:' . $port['ifName']);
                    $device_tune = DeviceCache::getPrimary()->getAttrib('override_rrdtool_tune');
                    if ($port_tune == 'true' ||
                        ($device_tune == 'true' && $port_tune != 'false') ||
                        (LibrenmsConfig::get('rrdtool_tune') == 'true' && $port_tune != 'false' && $device_tune != 'false')) {
                        $tune_port = $port[$oid] < $current_oid; // only tune when speed goes up
                    }
                }

                // set the update data
                $port['update'][$oid] = $current_oid;
                $this_port[$oid] = $current_oid;

                // store the previous values for alerting
                if (in_array($oid, ['ifOperStatus', 'ifAdminStatus', 'ifSpeed'])) {
                    $port['update'][$oid . '_prev'] = $port[$oid];
                }

                if ($oid == 'ifSpeed') {
                    $old = Number::formatSi($port[$oid], 2, 0, 'bps');
                    $new = Number::formatSi($current_oid, 2, 0, 'bps');
                } else {
                    $old = $port[$oid];
                    $new = $current_oid;
                }

                Eventlog::log($oid . ': ' . $old . ' -> ' . $new, $device['device_id'], 'interface', Severity::Notice, $port['port_id']);
                if (Debug::isEnabled()) {
                    d_echo($oid . ': ' . $old . ' -> ' . $new . ' ');
                } else {
                    $port_info_string .= $oid . ' ';
                }
            } else {
                if (in_array($oid, ['ifOperStatus', 'ifAdminStatus', 'ifSpeed'])) {
                    if ($port[$oid . '_prev'] == null) {
                        $port['update'][$oid . '_prev'] = $current_oid;
                    }
                }
            }
        }//end foreach

        // Parse description (usually ifAlias) if config option set
        if (LibrenmsConfig::has('port_descr_parser') && is_file(LibrenmsConfig::get('install_dir') . '/' . LibrenmsConfig::get('port_descr_parser'))) {
            $port_attribs = [
                'type',
                'descr',
                'circuit',
                'speed',
                'notes',
            ];

            $port_ifAlias = []; // for port descr parser mappings
            include LibrenmsConfig::get('install_dir') . '/' . LibrenmsConfig::get('port_descr_parser');

            foreach ($port_attribs as $attrib) {
                $attrib_key = 'port_descr_' . $attrib;
                if (($port_ifAlias[$attrib] ?? null) != $port[$attrib_key]) {
                    if (! isset($port_ifAlias[$attrib])) {
                        $port_ifAlias[$attrib] = null;
                        $log_port = 'NULL';
                    } else {
                        $log_port = $port_ifAlias[$attrib];
                    }

                    $port['update'][$attrib_key] = $port_ifAlias[$attrib];
                    Eventlog::log($attrib . ': ' . $port[$attrib_key] . ' -> ' . $log_port, $device['device_id'], 'interface', Severity::Notice, $port['port_id']);
                    unset($log_port);
                }
            }
        }//end if

        if (! empty($port['skipped'])) {
            // We don't care about statistics for skipped selective polling ports
            d_echo("$port_id skipped because selective polling ports is set.");
        } elseif ($port['ifType'] != 'vdsl' && $port['ifType'] != 'adsl' && $port['ifOperStatus'] == 'down' && $port['ifOperStatus_prev'] == 'down' && $this_port['ifOperStatus'] == 'down' && ($this_port['ifLastChange'] ?? null) == $port['ifLastChange']) {
            // We don't care about statistics for down ports on which states did not change since last polling
            // We still take into account 'adsl' & 'vdsl' ports that may update speed/noise even if the interface is status down
            d_echo("$port_id skipped because port is still down since last polling.");
        } else {
            // End parse ifAlias
            // Update IF-MIB metrics
            $_stat_oids = array_merge($stat_oids_db, $stat_oids_db_extended);
            $current_port_stats = ['ifInOctets_rate' => 0, 'ifOutOctets_rate' => 0];
            foreach ($_stat_oids as $oid) {
                $port_update = 'update';
                $current_oid = $this_port[$oid] ?? null;
                $extended_metric = ! in_array($oid, $stat_oids_db, true);
                if ($extended_metric) {
                    $port_update = 'update_extended';
                }

                $port[$port_update][$oid] = set_numeric($current_oid ?? 0);
                $port[$port_update][$oid . '_prev'] = set_numeric($port[$oid] ?? null);

                $oid_prev = $oid . '_prev';
                if (isset($port[$oid])) {
                    $oid_diff = (intval($current_oid ?? 0) - intval($port[$oid]));
                    $oid_rate = ($oid_diff / $polled_period);
                    if ($oid_rate < 0) {
                        $oid_rate = '0';
                        $oid_diff = '0';
                        $port_info_string .= "negative $oid ";
                    }

                    $current_port_stats[$oid . '_rate'] = $oid_rate;
                    $current_port_stats[$oid . '_diff'] = $oid_diff;
                    $port[$port_update][$oid . '_rate'] = $oid_rate;
                    $port[$port_update][$oid . '_delta'] = $oid_diff;

                    d_echo("\n $oid ($oid_diff B) $oid_rate Bps $polled_period secs\n");
                }//end if
            }//end foreach

            if (LibrenmsConfig::get('debug_port.' . $port['port_id'])) {
                $port_debug = $port['port_id'] . '|' . $polled . '|' . $polled_period . '|' . $this_port['ifHCInOctets'] . '|' . $this_port['ifHCOutOctets'];
                $port_debug .= '|' . $current_port_stats['ifInOctets_rate'] . '|' . $current_port_stats['ifOutOctets_rate'] . "\n";
                file_put_contents('/tmp/port_debug.txt', $port_debug, FILE_APPEND);
                Log::info('debug_port enabled, wrote port debugging data to /tmp/port_debug.txt');
            }

            $current_port_stats['ifInBits_rate'] = round($current_port_stats['ifInOctets_rate'] * 8);
            $current_port_stats['ifOutBits_rate'] = round($current_port_stats['ifOutOctets_rate'] * 8);

            // If we have a valid ifSpeed we should populate the stats for checking
            if (is_numeric($this_port['ifSpeed']) && $this_port['ifSpeed'] > 0) {
                $current_port_stats['ifInBits_perc'] = Number::calculatePercent($current_port_stats['ifInBits_rate'], $this_port['ifSpeed'], 0);
                $current_port_stats['ifOutBits_perc'] = Number::calculatePercent($current_port_stats['ifOutBits_rate'], $this_port['ifSpeed'], 0);
            }

            $port_info_string .= 'bps(' . Number::formatSi($current_port_stats['ifInBits_rate'], 2, 3, 'bps') . '/' . Number::formatSi($current_port_stats['ifOutBits_rate'], 2, 0, 'bps') . ') ';
            $port_info_string .= 'bytes(' . Number::formatBi($current_port_stats['ifInOctets_diff'] ?? 0) . '/' . Number::formatBi($current_port_stats['ifOutOctets_diff'] ?? 0) . ') ';
            $port_info_string .= 'pkts(' . Number::formatSi($current_port_stats['ifInUcastPkts_rate'] ?? 0, 2, 3, 'pps') . '/' . Number::formatSi($current_port_stats['ifOutUcastPkts_rate'] ?? 0, 2, 0, 'pps') . ') ';

            // Update data stores
            $rrd_name = Rrd::portName($port_id, '');
            $rrdfile = Rrd::name($device['hostname'], $rrd_name);
            $rrd_def = RrdDefinition::make()
                ->addDataset('INOCTETS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTOCTETS', 'DERIVE', 0, 12500000000)
                ->addDataset('INERRORS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTERRORS', 'DERIVE', 0, 12500000000)
                ->addDataset('INUCASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTUCASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('INNUCASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTNUCASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('INDISCARDS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTDISCARDS', 'DERIVE', 0, 12500000000)
                ->addDataset('INUNKNOWNPROTOS', 'DERIVE', 0, 12500000000)
                ->addDataset('INBROADCASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTBROADCASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('INMULTICASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTMULTICASTPKTS', 'DERIVE', 0, 12500000000);

            $fields = [
                'INOCTETS' => $this_port['ifInOctets'] ?? null,
                'OUTOCTETS' => $this_port['ifOutOctets'] ?? null,
                'INERRORS' => $this_port['ifInErrors'] ?? null,
                'OUTERRORS' => $this_port['ifOutErrors'] ?? null,
                'INUCASTPKTS' => $this_port['ifInUcastPkts'] ?? null,
                'OUTUCASTPKTS' => $this_port['ifOutUcastPkts'] ?? null,
                'INNUCASTPKTS' => $this_port['ifInNUcastPkts'] ?? null,
                'OUTNUCASTPKTS' => $this_port['ifOutNUcastPkts'] ?? null,
                'INDISCARDS' => $this_port['ifInDiscards'] ?? null,
                'OUTDISCARDS' => $this_port['ifOutDiscards'] ?? null,
                'INUNKNOWNPROTOS' => $this_port['ifInUnknownProtos'] ?? null,
                'INBROADCASTPKTS' => $this_port['ifInBroadcastPkts'] ?? null,
                'OUTBROADCASTPKTS' => $this_port['ifOutBroadcastPkts'] ?? null,
                'INMULTICASTPKTS' => $this_port['ifInMulticastPkts'] ?? null,
                'OUTMULTICASTPKTS' => $this_port['ifOutMulticastPkts'] ?? null,
            ];

            // non rrd stats (will be filtered)
            $fields['ifInUcastPkts_rate'] = $port['ifInUcastPkts_rate'];
            $fields['ifOutUcastPkts_rate'] = $port['ifOutUcastPkts_rate'];
            $fields['ifInErrors_rate'] = $port['ifInErrors_rate'];
            $fields['ifOutErrors_rate'] = $port['ifOutErrors_rate'];
            $fields['ifInOctets_rate'] = $port['ifInOctets_rate'];
            $fields['ifOutOctets_rate'] = $port['ifOutOctets_rate'];

            // Add delta rate between current poll and last poll.
            $fields['ifInBits_rate'] = $current_port_stats['ifInBits_rate'];
            $fields['ifOutBits_rate'] = $current_port_stats['ifOutBits_rate'];

            if ($tune_port === true) {
                Rrd::tune('port', $rrdfile, $this_port['ifSpeed']);
            }

            $tags = [
                'ifName' => $port['ifName'],
                'ifAlias' => $port['ifAlias'],
                'ifIndex' => $port['ifIndex'],
                'port_descr_type' => $port['port_descr_type'],
                'rrd_name' => $rrd_name,
                'rrd_def' => $rrd_def,
            ];
            app('Datastore')->put($device, 'ports', $tags, $fields);

            // End Update IF-MIB
            // Update PAgP
            if (! empty($this_port['pagpOperationMode']) || ! empty($port['pagpOperationMode'])) {
                foreach ($pagp_oids as $oid) {
                    // Loop the OIDs
                    $current_oid = $this_port[$oid] ?? null;
                    if ($current_oid != $port[$oid]) {
                        // If data has changed, build a query
                        $port['update'][$oid] = $current_oid;
                        $port_info_string .= 'PAgP ';
                        Eventlog::log("$oid -> " . $current_oid, $device['device_id'], 'interface', Severity::Notice, $port['port_id']);
                    }
                }
            }

            // End Update PAgP
            // Do EtherLike-MIB
            if (LibrenmsConfig::get('enable_ports_etherlike')) {
                include 'ports/port-etherlike.inc.php';
            }

            // Do PoE MIBs
            if (LibrenmsConfig::get('enable_ports_poe')) {
                include 'ports/port-poe.inc.php';
            }

            if ($device['os'] == 'ios' || $device['os'] == 'iosxe') {
                include 'ports/cisco-if-extension.inc.php';
            }
        }

        // Update Database if $port['update'] is not empty
        // or if previous poll time $port['poll_time'] is different from device globally previous port time $device_global_ports["poll_prev"]
        // This could happen if disabled port was enabled since last polling
        if (! empty($port['update']) || $device_global_ports['poll_prev'] != $port['poll_time']) {
            $port['update']['poll_time'] = $polled;
            $port['update']['poll_prev'] = $port['poll_time'];
            $port['update']['poll_period'] = $polled_period;
            $updated = dbUpdate($port['update'], 'ports', '`port_id` = ?', [$port_id]);

            if (! empty($port['update_extended'])) {
                $updated += dbUpdate($port['update_extended'], 'ports_statistics', '`port_id` = ?', [$port_id]);
            }
            d_echo("$updated updated");
        } else {
            $globally_updated_port_ids[] = $port_id;
        }
        // End Update Database
    }

    Log::info($port_info_string);

    // Clear Per-Port Variables Here
    unset($this_port, $port);
} //end port update

// Update the poll_time, poll_prev and poll_period of all ports in an unique request
$updated = DB::table('ports')->whereIntegerInRaw('port_id', $globally_updated_port_ids)->update($device_global_ports);

d_echo("$updated updated\n");

// Clear Variables Here
unset($port_stats, $ports_found, $data_oids, $stat_oids, $stat_oids_db, $stat_oids_db_extended, $cisco_oids, $pagp_oids, $ifmib_oids, $hc_test, $ports_mapped, $ports, $_stat_oids, $rrd_def);

```File Path: `includes/polling/sensors/rest-api.inc.php`
Modification Time: Sun Oct 26 14:24:34 2025

```php
<?php

/**
 * REST API Sensor Polling
 *
 * Polls sensors that were discovered via REST API
 * Uses vendor-specific API clients to fetch current sensor values
 */

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

d_echo("\n");
d_echo("REST API Sensor Polling\n");

// Convert device array to Model object
$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    d_echo("Device {$device['device_id']} not found in database, skipping REST sensor polling\n");
    return [];
}

// Check if REST API is enabled for this device
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    d_echo("REST API disabled, skipping REST sensor polling\n");
    return [];
}

try {
    // Create vendor-specific API client
    $apiClient = DeviceApiClientFactory::make($deviceModel);

    if (!$apiClient) {
        d_echo("No REST API client available for sensor polling\n");
        return [];
    }

    // Check if client supports sensors
    if (!in_array('sensors', $apiClient->capabilities())) {
        d_echo("API client does not support sensors\n");
        return [];
    }

    // Fetch fresh sensor data from API
    d_echo("Fetching sensors from REST API for polling...\n");
    $api_sensors = $apiClient->fetchSensors($deviceModel);

    if (empty($api_sensors)) {
        d_echo("No sensors returned from REST API\n");
        return [];
    }

    // Build index of API sensors by index for quick lookup
    $api_sensor_index = [];
    foreach ($api_sensors as $sensor_data) {
        if (isset($sensor_data['sensor_index'])) {
            $api_sensor_index[$sensor_data['sensor_index']] = $sensor_data;
        }
    }

    d_echo("Indexed " . count($api_sensor_index) . " REST API sensors\n");

    return $api_sensor_index;

} catch (\Throwable $e) {
    // Log error but don't fail polling
    Log::error("REST API sensor polling failed for device {$device['device_id']}: " . $e->getMessage());
    d_echo("REST API Sensor Polling Error: " . $e->getMessage() . "\n");
    if (getenv('APP_DEBUG')) {
        d_echo($e->getTraceAsString() . "\n");
    }
    return [];
}

```File Path: `includes/polling/ports/rest-api.inc.php`
Modification Time: Sun Oct 26 14:24:47 2025

```php
<?php

/**
 * REST API Port Polling
 *
 * Polls ports that were discovered via REST API or supplements SNMP port data
 * Uses vendor-specific API clients to fetch current port statistics
 */

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

d_echo("\n");
d_echo("REST API Port Polling\n");

// Convert device array to Model object
$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    d_echo("Device {$device['device_id']} not found in database, skipping REST port polling\n");
    return;
}

// Check if REST API is enabled for this device
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    d_echo("REST API disabled, skipping REST port polling\n");
    return;
}

try {
    // Create vendor-specific API client
    $apiClient = DeviceApiClientFactory::make($deviceModel);

    if (!$apiClient) {
        d_echo("No REST API client available for port polling\n");
        return;
    }

    // Check if client supports ports
    if (!in_array('ports', $apiClient->capabilities())) {
        d_echo("API client does not support ports\n");
        return;
    }

    // Fetch ports from API
    d_echo("Fetching ports from REST API for polling...\n");
    $api_ports = $apiClient->fetchPorts($deviceModel);

    if (empty($api_ports)) {
        d_echo("No ports returned from REST API\n");
        return;
    }

    d_echo("Received " . count($api_ports) . " ports from REST API\n");

    // Build index of API ports by ifIndex for quick lookup
    foreach ($api_ports as $port_data) {
        $ifIndex = $port_data['ifIndex'] ?? null;
        if ($ifIndex === null) {
            continue;
        }

        // Check if this port exists in our database
        $db_port = dbFetchRow('SELECT * FROM `ports` WHERE `device_id` = ? AND `ifIndex` = ?', [$device['device_id'], $ifIndex]);

        if (!$db_port) {
            d_echo("Port ifIndex=$ifIndex not found in database, skipping\n");
            continue;
        }

        // Update port operational data from API
        $update_data = [];

        if (isset($port_data['ifOperStatus'])) {
            $update_data['ifOperStatus'] = $port_data['ifOperStatus'];
        }

        if (isset($port_data['ifAdminStatus'])) {
            $update_data['ifAdminStatus'] = $port_data['ifAdminStatus'];
        }

        if (isset($port_data['ifSpeed'])) {
            $update_data['ifSpeed'] = $port_data['ifSpeed'];
            $update_data['ifHighSpeed'] = $port_data['ifSpeed'] / 1000000; // Convert to Mbps
        }

        if (!empty($update_data)) {
            dbUpdate($update_data, 'ports', '`port_id` = ?', [$db_port['port_id']]);
            d_echo("Updated port ifIndex=$ifIndex with REST API data\n");
        }

        // Store port statistics if provided
        // Note: Some APIs provide rates, some provide counters
        // We'll assume the API provides counters unless specified otherwise
        $stats = [];

        foreach (['ifInOctets', 'ifOutOctets', 'ifInUcastPkts', 'ifOutUcastPkts',
                  'ifInErrors', 'ifOutErrors', 'ifInDiscards', 'ifOutDiscards'] as $stat) {
            if (isset($port_data[$stat])) {
                $stats[$stat] = $port_data[$stat];
            }
        }

        if (!empty($stats)) {
            // Update port statistics in the ports table
            dbUpdate($stats, 'ports', '`port_id` = ?', [$db_port['port_id']]);
            d_echo("Updated port stats for ifIndex=$ifIndex\n");
        }
    }

    echo "REST API Port Polling: Polled " . count($api_ports) . " ports\n";

} catch (\Throwable $e) {
    // Log error but don't fail polling
    Log::error("REST API port polling failed for device {$device['device_id']}: " . $e->getMessage());
    d_echo("REST API Port Polling Error: " . $e->getMessage() . "\n");
    if (getenv('APP_DEBUG')) {
        d_echo($e->getTraceAsString() . "\n");
    }
}

```File Path: `includes/discovery/sensors.inc.php`
Modification Time: Sun Oct 26 14:15:09 2025

```php
<?php

use App\Facades\LibrenmsConfig;
use LibreNMS\Enum\Sensor;
use LibreNMS\OS;

/** @var OS $os */
$pre_cache = $os->preCache();

if ($device['os'] == 'rittal-cmc-iii-pu' || $device['os'] == 'rittal-lcp') {
    include 'includes/discovery/sensors/rittal-cmc-iii-sensors.inc.php';
} else {
    // Run custom sensors
    require 'includes/discovery/sensors/cisco-entity-sensor.inc.php';
    require 'includes/discovery/sensors/entity-sensor.inc.php';
    require 'includes/discovery/sensors/ipmi.inc.php';
}

if ($device['os'] == 'netscaler') {
    include 'includes/discovery/sensors/netscaler.inc.php';
}

if ($device['os'] == 'openbsd') {
    include 'includes/discovery/sensors/openbsd.inc.php';
}

if ($device['os'] == 'linux') {
    include 'includes/discovery/sensors/rpigpiomonitor.inc.php';
}

if (isset($device['hardware']) && strstr($device['hardware'], 'Dell')) {
    include 'includes/discovery/sensors/fanspeed/dell.inc.php';
    include 'includes/discovery/sensors/power/dell.inc.php';
    include 'includes/discovery/sensors/voltage/dell.inc.php';
    include 'includes/discovery/sensors/state/dell.inc.php';
    include 'includes/discovery/sensors/temperature/dell.inc.php';
}

if (isset($device['hardware']) && strstr($device['hardware'], 'ProLiant')) {
    include 'includes/discovery/sensors/state/hp.inc.php';
}

if ($device['os'] == 'gw-eydfa') {
    include 'includes/discovery/sensors/gw-eydfa.inc.php';
}

// REST API sensor discovery (if enabled)
include 'includes/discovery/sensors/rest-api.inc.php';

// filter submodules
$run_sensors = array_intersect(Sensor::values(), LibrenmsConfig::get('discovery_submodules.sensors', Sensor::values()));

sensors($run_sensors, $os, $pre_cache);
unset(
    $pre_cache,
    $run_sensors,
    $entitysensor
);

```File Path: `includes/discovery/ports.inc.php`
Modification Time: Sun Oct 26 14:15:56 2025

```php
<?php

// Build SNMP Cache Array
use App\Facades\LibrenmsConfig;
use App\Models\PortGroup;
use LibreNMS\Enum\PortAssociationMode;
use LibreNMS\Util\StringHelpers;

$descrSnmpFlags = '-OQUs';
$typeSnmpFlags = '-OQUs';
$operStatusSnmpFlags = '-OQUs';
if ($device['os'] == 'bintec-beip-plus') {
    $descrSnmpFlags = ['-OQUs', '-Cc'];
    $typeSnmpFlags = ['-OQUs', '-Cc'];
    $operStatusSnmpFlags = ['-OQUs', '-Cc'];
}

$port_stats = [];
$port_stats = snmpwalk_cache_oid($device, 'ifDescr', $port_stats, 'IF-MIB', null, $descrSnmpFlags);
$port_stats = snmpwalk_cache_oid($device, 'ifName', $port_stats, 'IF-MIB');
$port_stats = snmpwalk_cache_oid($device, 'ifAlias', $port_stats, 'IF-MIB');
$port_stats = snmpwalk_cache_oid($device, 'ifType', $port_stats, 'IF-MIB', null, $typeSnmpFlags);
$port_stats = snmpwalk_cache_oid($device, 'ifOperStatus', $port_stats, 'IF-MIB', null, $operStatusSnmpFlags);

// Add ports from other snmp context
if ($device['os'] == 'nokia-isam') {
    require base_path('includes/discovery/ports/nokia-isam.inc.php');
}

//Get Bison ports
if ($device['os'] == 'bison') {
    require base_path('includes/discovery/ports/bison.inc.php');
}

// Get adva-fsp150cp
if ($device['os'] == 'adva-fsp150cp') {
    require base_path('includes/discovery/ports/adva-fsp150cp.inc.php');
}

// Get Trellix NSP ports
if ($device['os'] == 'mlos-nsp') {
    require base_path('includes/discovery/ports/mlos-nsp.inc.php');
}

//Get UFiber OLT ports
if ($device['os'] == 'edgeosolt') {
    require base_path('includes/discovery/ports/edgeosolt.inc.php');
}

//Get loop-telecom line card interfaces
if ($device['os'] == 'loop-telecom') {
    require base_path('includes/discovery/ports/loop-telecom.inc.php');
}

//Change Zynos ports from swp to 1/1
if ($device['os'] == 'zynos') {
    require base_path('includes/discovery/ports/zynos.inc.php');
}

// Get correct eth0 port status for AirFiber 5XHD devices
if ($device['os'] == 'airos-af-ltu') {
    require 'ports/airos-af-ltu.inc.php';
}

//Teleste Luminato ifOperStatus
if ($device['os'] == 'luminato') {
    require base_path('includes/discovery/ports/luminato.inc.php');
}

//Moxa Etherdevice portName mapping
if ($device['os'] == 'moxa-etherdevice') {
    require base_path('includes/discovery/ports/moxa-etherdevice.inc.php');
}

//Remove extra ports on Zhone slms devices
if ($device['os'] == 'slms') {
    require base_path('includes/discovery/ports/slms.inc.php');
}

//Cambium cnMatrix port description mapping
if ($device['os'] == 'cnmatrix') {
    require base_path('includes/discovery/ports/cnmatrix.inc.php');
}

//Get Tachyon ports
if ($device['os'] == 'tachyon') {
    require base_path('includes/discovery/ports/tachyon.inc.php');
}

// REST API port discovery (if enabled)
require base_path('includes/discovery/ports/rest-api.inc.php');

// End Building SNMP Cache Array
d_echo($port_stats);

// By default libreNMS uses the ifIndex to associate ports on devices with ports discoverd/polled
// before and stored in the database. On Linux boxes this is a problem as ifIndexes may be
// unstable between reboots or (re)configuration of tunnel interfaces (think: GRE/OpenVPN/Tinc/...)
// The port association configuration allows to choose between association via ifIndex, ifName,
// or maybe other means in the future. The default port association mode still is ifIndex for
// compatibility reasons.
$port_association_mode = LibrenmsConfig::get('default_port_association_mode');
if ($device['port_association_mode']) {
    $port_association_mode = PortAssociationMode::getName($device['port_association_mode']);
}

// Build array of ports in the database and an ifIndex/ifName -> port_id map
$ports_mapped = get_ports_mapped($device['device_id']);
$ports_db = $ports_mapped['ports'];

// Fill ifAlias for fibrechannel ports
if ($device['os'] == 'fabos') {
    require base_path('includes/discovery/ports/brocade.inc.php');
}

//Shorten Ekinops Interfaces
if ($device['os'] == 'ekinops') {
    require base_path('includes/discovery/ports/ekinops.inc.php');
}

$default_port_group = LibrenmsConfig::get('default_port_group');

// New interface detection
foreach ($port_stats as $ifIndex => $snmp_data) {
    $snmp_data['ifIndex'] = $ifIndex; // Store ifIndex in port entry
    $snmp_data['ifAlias'] = StringHelpers::inferEncoding($snmp_data['ifAlias'] ?? null);

    // Get port_id according to port_association_mode used for this device
    $port_id = get_port_id($ports_mapped, $snmp_data, $port_association_mode);

    if (is_port_valid($snmp_data, $device)) {
        port_fill_missing_and_trim($snmp_data, $device);

        if ($device['os'] == 'vmware-vcsa' && preg_match('/Device ([a-z0-9]+) at .*/', $snmp_data['ifDescr'], $matches)) {
            $snmp_data['ifName'] = $matches[1];
        }

        // Port newly discovered?
        if (! isset($ports_db[$port_id]) || ! is_array($ports_db[$port_id])) {
            $snmp_data['device_id'] = $device['device_id'];
            $port_id = dbInsert($snmp_data, 'ports');

            //default Port Group for new Ports defined?
            if (! empty($default_port_group)) {
                $port_group = PortGroup::find($default_port_group);
                if (isset($port_group)) {
                    $port_group->ports()->attach([$port_id]);
                }
            }

            $ports[$port_id] = dbFetchRow('SELECT * FROM `ports` WHERE `device_id` = ? AND `port_id` = ?', [$device['device_id'], $port_id]);
            d_echo('Adding: ' . $snmp_data['ifName'] . '(' . $ifIndex . ')(' . $port_id . ')');
            echo '+';
        } elseif ($ports_db[$port_id]['deleted'] == 1) {
            // Port re-discovered after previous deletion?
            $snmp_data['deleted'] = 0;
            dbUpdate($snmp_data, 'ports', '`port_id` = ?', [$port_id]);
            $ports_db[$port_id]['deleted'] = 0;
            echo 'U';
        } else { // port is existing, let's update it with some data we have collected here
            dbUpdate($snmp_data, 'ports', '`port_id` = ?', [$port_id]);
            echo '.';
        }
    } else {
        // Port vanished (mark as deleted)
        if (isset($ports_db[$port_id]) && is_array($ports_db[$port_id])) {
            if ($ports_db[$port_id]['deleted'] != 1) {
                dbUpdate(['deleted' => 1], 'ports', '`port_id` = ?', [$port_id]);
                $ports_db[$port_id]['deleted'] = 1;
                echo '-';
            }
        }
    }//end if
}//end foreach

unset(
    $ports_mapped,
    $port
);

echo "\n";

// Clear Variables Here
unset($port_stats);
unset($ports_db);

```File Path: `includes/discovery/sensors/rest-api.inc.php`
Modification Time: Sun Oct 26 14:24:05 2025

```php
<?php

/**
 * REST API Sensor Discovery
 *
 * Discovers sensors from devices with REST API enabled
 * Uses vendor-specific API clients to fetch metrics
 */

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

echo "\n";

// Convert device array to Model object
$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    d_echo("Device {$device['device_id']} not found in database, skipping REST sensor discovery\n");
    return;
}

// Check if REST API is enabled for this device
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    d_echo("REST API disabled for device {$device['device_id']}, skipping REST sensor discovery\n");
    return;
}

d_echo("REST API Discovery: Device {$device['hostname']} ({$device['device_id']})\n");

try {
    // Create vendor-specific API client
    $apiClient = DeviceApiClientFactory::make($deviceModel);

    if (!$apiClient) {
        d_echo("No REST API client available for device {$device['hostname']}\n");
        return;
    }

    d_echo("Using API client: " . get_class($apiClient) . "\n");

    // Check if client supports sensors
    if (!in_array('sensors', $apiClient->capabilities())) {
        d_echo("API client does not support sensor discovery\n");
        return;
    }

    // Fetch sensors from API
    d_echo("Fetching sensors from REST API...\n");
    $sensors = $apiClient->fetchSensors($deviceModel);

    if (empty($sensors)) {
        d_echo("No sensors returned from REST API\n");
        return;
    }

    d_echo("Received " . count($sensors) . " sensors from REST API\n");

    // Discover each sensor
    $discovered_count = 0;
    foreach ($sensors as $sensor_data) {
        // Required fields check
        if (!isset($sensor_data['sensor_class']) ||
            !isset($sensor_data['sensor_type']) ||
            !isset($sensor_data['sensor_descr']) ||
            !isset($sensor_data['sensor_index'])) {
            d_echo("Skipping invalid sensor data (missing required fields)\n");
            continue;
        }

        // Extract sensor data
        $class = $sensor_data['sensor_class'];
        $type = $sensor_data['sensor_type'];
        $descr = $sensor_data['sensor_descr'];
        $index = $sensor_data['sensor_index'];
        $current = $sensor_data['sensor_current'] ?? null;
        $divisor = $sensor_data['sensor_divisor'] ?? 1;
        $multiplier = $sensor_data['sensor_multiplier'] ?? 1;
        $low_limit = $sensor_data['sensor_limit_low'] ?? null;
        $low_warn_limit = $sensor_data['sensor_limit_low_warn'] ?? null;
        $warn_limit = $sensor_data['sensor_limit_warn'] ?? null;
        $high_limit = $sensor_data['sensor_limit'] ?? null;
        $entPhysicalIndex = $sensor_data['entPhysicalIndex'] ?? null;
        $group = $sensor_data['group'] ?? null;
        $rrd_type = $sensor_data['rrd_type'] ?? 'GAUGE';

        // For state sensors, we need to create state entries
        if ($class === 'state' && isset($sensor_data['states'])) {
            $states = $sensor_data['states'];

            // Create state index
            $state_name = $type . '-' . $index;
            create_sensor_to_state_index($device, $state_name, $states);

            // Discover the sensor
            $discovered = discover_sensor(
                $unused,
                $class,
                $device,
                '',  // oid - not applicable for REST API
                $index,
                $type,
                $descr,
                $divisor,
                $multiplier,
                $low_limit,
                $low_warn_limit,
                $warn_limit,
                $high_limit,
                $current,
                'rest-api',  // poller_type
                $entPhysicalIndex,
                null,  // entPhysicalIndex_measured
                null,  // user_func
                $group,
                $rrd_type
            );
        } else {
            // Discover regular sensor
            $discovered = discover_sensor(
                $unused,
                $class,
                $device,
                '',  // oid - not applicable for REST API
                $index,
                $type,
                $descr,
                $divisor,
                $multiplier,
                $low_limit,
                $low_warn_limit,
                $warn_limit,
                $high_limit,
                $current,
                'rest-api',  // poller_type
                $entPhysicalIndex,
                null,  // entPhysicalIndex_measured
                null,  // user_func
                $group,
                $rrd_type
            );
        }

        if ($discovered) {
            $discovered_count++;
            d_echo("Discovered: [$class] $descr (index: $index, current: " . ($current ?? 'N/A') . ")\n");
        }
    }

    echo "REST API Discovery: Discovered $discovered_count sensors\n";

} catch (\Throwable $e) {
    // Log error but don't fail discovery
    Log::error("REST API sensor discovery failed for device {$device['device_id']}: " . $e->getMessage());
    d_echo("REST API Discovery Error: " . $e->getMessage() . "\n");
    if (getenv('APP_DEBUG')) {
        d_echo($e->getTraceAsString() . "\n");
    }
}

```File Path: `includes/discovery/ports/rest-api.inc.php`
Modification Time: Sun Oct 26 14:24:20 2025

```php
<?php

/**
 * REST API Port Discovery
 *
 * Discovers network ports/interfaces from devices with REST API enabled
 * Uses vendor-specific API clients to fetch interface information
 */

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

// Convert device array to Model object
$deviceModel = \App\Models\Device::find($device['device_id']);
if (!$deviceModel) {
    d_echo("Device {$device['device_id']} not found in database, skipping REST port discovery\n");
    return;
}

// Check if REST API is enabled for this device
if (!DeviceApiSettings::restEnabled($deviceModel)) {
    d_echo("REST API disabled for device, skipping REST port discovery\n");
    return;
}

d_echo("REST API Port Discovery: Device {$device['hostname']}\n");

try {
    // Create vendor-specific API client
    $apiClient = DeviceApiClientFactory::make($deviceModel);

    if (!$apiClient) {
        d_echo("No REST API client available for port discovery\n");
        return;
    }

    // Check if client supports ports
    if (!in_array('ports', $apiClient->capabilities())) {
        d_echo("API client does not support port discovery\n");
        return;
    }

    // Fetch ports from API
    d_echo("Fetching ports from REST API...\n");
    $api_ports = $apiClient->fetchPorts($deviceModel);

    if (empty($api_ports)) {
        d_echo("No ports returned from REST API\n");
        return;
    }

    d_echo("Received " . count($api_ports) . " ports from REST API\n");

    // Add REST API ports to the port_stats array
    // These will be processed by the main port discovery logic
    foreach ($api_ports as $port_data) {
        // Required fields check
        if (!isset($port_data['ifIndex']) || !isset($port_data['ifName'])) {
            d_echo("Skipping invalid port data (missing ifIndex or ifName)\n");
            continue;
        }

        $ifIndex = $port_data['ifIndex'];

        // Add to port_stats array (merge with SNMP data if exists)
        if (!isset($port_stats[$ifIndex])) {
            $port_stats[$ifIndex] = [];
        }

        // Map REST API port data to LibreNMS port fields
        $port_stats[$ifIndex]['ifIndex'] = $ifIndex;
        $port_stats[$ifIndex]['ifName'] = $port_data['ifName'] ?? '';
        $port_stats[$ifIndex]['ifDescr'] = $port_data['ifDescr'] ?? $port_data['ifName'] ?? '';
        $port_stats[$ifIndex]['ifAlias'] = $port_data['ifAlias'] ?? '';
        $port_stats[$ifIndex]['ifType'] = $port_data['ifType'] ?? 'ethernetCsmacd';
        $port_stats[$ifIndex]['ifOperStatus'] = $port_data['ifOperStatus'] ?? 'unknown';
        $port_stats[$ifIndex]['ifAdminStatus'] = $port_data['ifAdminStatus'] ?? 'unknown';
        $port_stats[$ifIndex]['ifSpeed'] = $port_data['ifSpeed'] ?? 0;
        $port_stats[$ifIndex]['ifHighSpeed'] = isset($port_data['ifSpeed']) ? ($port_data['ifSpeed'] / 1000000) : 0;
        $port_stats[$ifIndex]['ifMtu'] = $port_data['ifMtu'] ?? 1500;
        $port_stats[$ifIndex]['ifPhysAddress'] = $port_data['ifPhysAddress'] ?? '';
        $port_stats[$ifIndex]['ifLastChange'] = $port_data['ifLastChange'] ?? 0;

        // Mark this port as coming from REST API
        $port_stats[$ifIndex]['_rest_api'] = true;

        d_echo("Added REST API port: ifIndex=$ifIndex, ifName=" . $port_stats[$ifIndex]['ifName'] . "\n");
    }

    echo "REST API Port Discovery: Added " . count($api_ports) . " ports\n";

} catch (\Throwable $e) {
    // Log error but don't fail discovery
    Log::error("REST API port discovery failed for device {$device['device_id']}: " . $e->getMessage());
    d_echo("REST API Port Discovery Error: " . $e->getMessage() . "\n");
    if (getenv('APP_DEBUG')) {
        d_echo($e->getTraceAsString() . "\n");
    }
}

```
File Path: `includes/html/pages/device/edit.inc.php`
Modification Time: Sun Oct 26 13:42:16 2025

```php
<?php

$no_refresh = true;

$link_array = ['page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'edit', ];

if (! Auth::user()->hasGlobalAdmin()) {
    print_error('Insufficient Privileges');
} else {
    $panes['device'] = 'Device Settings';
    $panes['api'] = 'API';
    $panes['snmp'] = 'SNMP';
    if (! $device['snmp_disable']) {
        $panes['ports'] = 'Port Settings';
    }

    if (dbFetchCell('SELECT COUNT(*) FROM `bgpPeers` WHERE `device_id` = ? LIMIT 1', [$device['device_id']]) > 0) {
        $panes['routing'] = 'Routing';
    }

    if (count(\App\Facades\LibrenmsConfig::get("os.{$device['os']}.icons", []))) {
        $panes['icon'] = 'Icon';
    }

    if (! $device['snmp_disable']) {
        $panes['apps'] = 'Applications';
    }
    $panes['alert-rules'] = 'Alert Rules';
    if (! $device['snmp_disable']) {
        $panes['modules'] = 'Modules';
    }

    if (\App\Facades\LibrenmsConfig::get('show_services')) {
        $panes['services'] = 'Services';
    }

    $panes['ipmi'] = 'IPMI';

    if (dbFetchCell("SELECT COUNT(*) FROM `sensors` WHERE `device_id` = ? AND `sensor_deleted`='0' LIMIT 1", [$device['device_id']]) > 0) {
        $panes['health'] = 'Health';
    }

    if (dbFetchCell("SELECT COUNT(*) FROM `wireless_sensors` WHERE `device_id` = ? AND `sensor_deleted`='0' LIMIT 1", [$device['device_id']]) > 0) {
        $panes['wireless-sensors'] = 'Wireless Sensors';
    }

    if (! $device['snmp_disable']) {
        $panes['storage'] = 'Storage';
        $panes['processors'] = 'Processors';
        $panes['mempools'] = 'Memory';
    }
    $panes['misc'] = 'Misc';

    $panes['component'] = 'Components';

    $panes['customoid'] = 'Custom OID';

    print_optionbar_start();

    $sep = '';
    foreach ($panes as $type => $text) {
        if (! isset($vars['section'])) {
            $vars['section'] = $type;
        }
        echo $sep;
        if ($vars['section'] == $type) {
            echo "<span class='pagemenu-selected'>";
        } else {
        }

        if ($type == 'device') {
            echo '<a href="' . route('device.edit', [$device['device_id']]) . "\">$text</a>";
        } elseif ($type == 'api') {
            echo '<a href="' . route('device.edit', [$device['device_id'], 'section' => 'api']) . "\">$text</a>";
        } else {
            echo generate_link($text, $link_array, ['section' => $type]);
        }

        if ($vars['section'] == $type) {
            echo '</span>';
        }
        $sep = ' | ';
    }

    print_optionbar_end();

    $section = basename($vars['section']);
    if (is_file("includes/html/pages/device/edit/$section.inc.php")) {
        require "includes/html/pages/device/edit/$section.inc.php";
    }
}

$pagetitle[] = 'Settings';

```

File Path: `LibreNMS/Util/DeviceApiSettings.php`
Modification Time: Sat Oct 25 21:15:01 2025

```php
<?php
namespace LibreNMS\Util;

use App\Models\Device;
use Illuminate\Support\Facades\Crypt;

class DeviceApiSettings
{
    public static function restEnabled(Device $device): bool
    {
        return (bool) (($device->attribs['rest_enabled'] ?? 0));
    }

    public static function vendor(Device $device): ?string
    {
        return $device->attribs['rest_vendor'] ?? null; // e.g., 'purestorage', 'proxmox'
    }

    public static function httpOptions(Device $device): array
    {
        $a = $device->attribs ?? [];

        $headers = array();
        if (!empty($a['rest_headers'])) {
            $decoded = json_decode($a['rest_headers'], true);
            if (is_array($decoded)) {
                $headers = $decoded;
            }
        }

        return array(
            'base_url'   => rtrim(($a['rest_base_url'] ?? ($a['proxmox_base_url'] ?? '')), '/'),
            'verify_tls' => (bool)(($a['rest_verify_tls'] ?? ($a['proxmox_verify_tls'] ?? true))),
            'timeout_ms' => (int)(($a['rest_timeout_ms'] ?? ($a['proxmox_timeout_ms'] ?? 5000))),
            'proxy'      => $a['rest_proxy'] ?? ($a['proxmox_proxy'] ?? null),
            'headers'    => $headers,
        );
    }

    // Pure Storage specific
    public static function pureOptions(Device $device): array
    {
        $a = $device->attribs ?? array();
        $token = '';
        if (!empty($a['rest_token_enc'])) {
            $token = Crypt::decryptString($a['rest_token_enc']);
        } elseif (!empty($a['rest_token'])) {
            $token = $a['rest_token'];
        }

        return array(
            'auth_type' => $a['rest_auth_type'] ?? 'apikey',
            'token'     => $token,
        );
    }

    // Proxmox specific
    public static function proxmoxOptions(Device $device): array
    {
        $a = $device->attribs ?? array();
        $mode = $a['proxmox_auth_type'] ?? 'token';

        $opts = array('auth_type' => $mode);

        if ($mode === 'token') {
            $secret = '';
            if (!empty($a['proxmox_token_enc'])) {
                $secret = Crypt::decryptString($a['proxmox_token_enc']);
            }
            $opts = array_merge($opts, array(
                'token_user' => $a['proxmox_token_user'] ?? '',
                'token_id'   => $a['proxmox_token_id'] ?? '',
                'token'      => $secret,
            ));
        } else {
            $password = '';
            if (!empty($a['proxmox_password_enc'])) {
                $password = Crypt::decryptString($a['proxmox_password_enc']);
            }
            $opts = array_merge($opts, array(
                'username' => $a['proxmox_username'] ?? '',
                'password' => $password,
            ));
        }

        return $opts;
    }

    /**
     * Get rate limit queries per second for the device
     *
     * @param Device $device
     * @return int Queries per second (default 10)
     */
    public static function rateLimitQps(Device $device): int
    {
        return (int) ($device->attribs['rest_rate_limit_qps'] ?? 10);
    }

    /**
     * Record a successful API call
     *
     * @param Device $device
     * @param int $latencyMs Response latency in milliseconds
     * @return void
     */
    public static function recordSuccess(Device $device, int $latencyMs): void
    {
        $device->setAttrib('rest_last_success', time());
        $device->setAttrib('rest_error_count', 0);

        // Update rolling average latency
        $currentAvg = (int) ($device->attribs['rest_avg_latency_ms'] ?? 0);
        $newAvg = $currentAvg === 0 ? $latencyMs : (int) (($currentAvg * 0.8) + ($latencyMs * 0.2));
        $device->setAttrib('rest_avg_latency_ms', $newAvg);
    }

    /**
     * Record a failed API call
     *
     * @param Device $device
     * @param string $error Error message (will be truncated if too long)
     * @return void
     */
    public static function recordError(Device $device, string $error): void
    {
        $device->setAttrib('rest_last_error', time());
        $device->setAttrib('rest_last_error_message', substr($error, 0, 255));

        $errorCount = (int) ($device->attribs['rest_error_count'] ?? 0);
        $device->setAttrib('rest_error_count', $errorCount + 1);
    }

    /**
     * Get API health status
     *
     * @param Device $device
     * @return array Array with keys: healthy, last_success, last_error, error_count, avg_latency_ms
     */
    public static function getHealthStatus(Device $device): array
    {
        $lastSuccess = (int) ($device->attribs['rest_last_success'] ?? 0);
        $lastError = (int) ($device->attribs['rest_last_error'] ?? 0);
        $errorCount = (int) ($device->attribs['rest_error_count'] ?? 0);
        $avgLatency = (int) ($device->attribs['rest_avg_latency_ms'] ?? 0);

        // Consider healthy if last success was more recent than last error, or no errors
        $healthy = $errorCount === 0 || ($lastSuccess > 0 && $lastSuccess >= $lastError);

        return [
            'healthy' => $healthy,
            'last_success' => $lastSuccess,
            'last_error' => $lastError,
            'last_error_message' => $device->attribs['rest_last_error_message'] ?? null,
            'error_count' => $errorCount,
            'avg_latency_ms' => $avgLatency,
        ];
    }

    /**
     * Check if the circuit breaker should trip (too many consecutive errors)
     *
     * @param Device $device
     * @param int $threshold Number of errors before tripping (default 5)
     * @return bool True if circuit breaker should trip
     */
    public static function shouldTripCircuitBreaker(Device $device, int $threshold = 5): bool
    {
        $errorCount = (int) ($device->attribs['rest_error_count'] ?? 0);
        return $errorCount >= $threshold;
    }

    /**
     * Reset circuit breaker and error counters
     *
     * @param Device $device
     * @return void
     */
    public static function resetCircuitBreaker(Device $device): void
    {
        $device->setAttrib('rest_error_count', 0);
        $device->setAttrib('rest_last_error', 0);
        $device->setAttrib('rest_last_error_message', '');
    }
}
```File Path: `LibreNMS/Util/ApiTemplateManager.php`
Modification Time: Sun Oct 26 14:00:44 2025

```php
<?php

namespace LibreNMS\Util;

use Illuminate\Support\Facades\File;

/**
 * Manages API templates for vendor device connections
 */
class ApiTemplateManager
{
    protected static string $templatePath = 'config/api-templates';

    /**
     * Get all available templates
     *
     * @return array Array of template metadata
     */
    public static function getAllTemplates(): array
    {
        $templates = [];
        $path = base_path(self::$templatePath);

        if (!File::isDirectory($path)) {
            return [];
        }

        $files = File::glob($path . '/*.json');

        foreach ($files as $file) {
            $content = File::get($file);
            $template = json_decode($content, true);

            if ($template && isset($template['vendor'])) {
                $templates[$template['vendor']] = [
                    'vendor' => $template['vendor'],
                    'name' => $template['name'],
                    'description' => $template['description'] ?? '',
                    'os' => $template['os'] ?? [],
                ];
            }
        }

        return $templates;
    }

    /**
     * Get templates filtered by device OS
     *
     * @param string $os Device OS
     * @return array Array of template metadata matching the OS
     */
    public static function getTemplatesForOs(string $os): array
    {
        $allTemplates = self::getAllTemplates();
        $osSpecific = [];
        $generic = [];

        foreach ($allTemplates as $vendor => $template) {
            if (empty($template['os'])) {
                // Generic template (no OS specified)
                $generic[$vendor] = $template;
            } elseif (in_array($os, $template['os'])) {
                // OS-specific template
                $osSpecific[$vendor] = $template;
            }
        }

        // Return OS-specific templates if available, otherwise return generic templates
        return !empty($osSpecific) ? $osSpecific : $generic;
    }

    /**
     * Load a specific template by vendor
     *
     * @param string $vendor
     * @return array|null
     */
    public static function loadTemplate(string $vendor): ?array
    {
        $filePath = base_path(self::$templatePath . '/' . $vendor . '.json');

        if (!File::exists($filePath)) {
            return null;
        }

        $content = File::get($filePath);
        $template = json_decode($content, true);

        return $template ?: null;
    }

    /**
     * Get supported authentication types
     *
     * @return array
     */
    public static function getAuthTypes(): array
    {
        return [
            'bearer' => [
                'name' => 'Bearer Token',
                'fields' => ['token'],
                'description' => 'Authorization: Bearer {token}',
            ],
            'apikey' => [
                'name' => 'API Key / Token',
                'fields' => ['token'],
                'description' => 'X-API-Key: {token}',
            ],
            'basic' => [
                'name' => 'Basic Authentication',
                'fields' => ['username', 'password'],
                'description' => 'Authorization: Basic base64(username:password)',
            ],
            'token' => [
                'name' => 'Proxmox API Token',
                'fields' => ['proxmox_token_user', 'proxmox_token_id', 'proxmox_token'],
                'description' => 'PVEAPIToken=user@realm!tokenid=secret',
            ],
            'ticket' => [
                'name' => 'Proxmox Ticket (Username/Password)',
                'fields' => ['proxmox_username', 'proxmox_password'],
                'description' => 'Username/password authentication with cookie session',
            ],
        ];
    }

    /**
     * Get fields required for a specific auth type
     *
     * @param string $authType
     * @return array
     */
    public static function getAuthFields(string $authType): array
    {
        $authTypes = self::getAuthTypes();
        return $authTypes[$authType]['fields'] ?? [];
    }

    /**
     * Validate template structure
     *
     * @param array $template
     * @return bool
     */
    public static function validateTemplate(array $template): bool
    {
        $required = ['name', 'vendor', 'auth_type', 'endpoints'];

        foreach ($required as $field) {
            if (!isset($template[$field])) {
                return false;
            }
        }

        return true;
    }
}

```File Path: `LibreNMS/HTTP/RateLimiter.php`
Modification Time: Sat Oct 25 21:14:28 2025

```php
<?php

namespace LibreNMS\HTTP;

/**
 * Token bucket rate limiter for API clients
 *
 * Implements a per-key rate limiting strategy using the token bucket algorithm.
 * Each key (typically a base URL or device ID) gets its own bucket that refills
 * at a specified rate.
 */
class RateLimiter
{
    /**
     * @var array<string, array{tokens: float, last: float}> Token buckets keyed by identifier
     */
    protected array $buckets = [];

    /**
     * Check if a request is allowed under the rate limit
     *
     * @param string $key Unique identifier (e.g., base URL or device ID)
     * @param int $qps Queries per second allowed
     * @param float $burstMultiplier Allow bursting up to qps * burstMultiplier tokens
     * @return bool True if request is allowed, false if rate limited
     */
    public function allow(string $key, int $qps, float $burstMultiplier = 2.0): bool
    {
        $now = microtime(true);
        $maxTokens = $qps * $burstMultiplier;

        // Initialize bucket if it doesn't exist
        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = [
                'tokens' => $maxTokens,
                'last' => $now,
            ];
        }

        $bucket = &$this->buckets[$key];

        // Refill tokens based on elapsed time
        $elapsed = $now - $bucket['last'];
        $bucket['tokens'] = min($maxTokens, $bucket['tokens'] + ($elapsed * $qps));
        $bucket['last'] = $now;

        // Check if we have at least one token available
        if ($bucket['tokens'] >= 1.0) {
            $bucket['tokens'] -= 1.0;
            return true;
        }

        return false;
    }

    /**
     * Wait until a request is allowed (blocking)
     *
     * @param string $key Unique identifier
     * @param int $qps Queries per second allowed
     * @param float $burstMultiplier Burst multiplier
     * @param int $maxWaitMs Maximum time to wait in milliseconds (default 5000)
     * @return bool True if allowed after waiting, false if timeout
     */
    public function waitForAllow(string $key, int $qps, float $burstMultiplier = 2.0, int $maxWaitMs = 5000): bool
    {
        $start = microtime(true);
        $maxWaitSeconds = $maxWaitMs / 1000;

        while (!$this->allow($key, $qps, $burstMultiplier)) {
            if ((microtime(true) - $start) > $maxWaitSeconds) {
                return false;
            }
            usleep(50000); // Sleep 50ms between checks
        }

        return true;
    }

    /**
     * Get the current token count for a key
     *
     * @param string $key Unique identifier
     * @return float Current number of tokens (0 if bucket doesn't exist)
     */
    public function getTokens(string $key): float
    {
        if (!isset($this->buckets[$key])) {
            return 0.0;
        }

        $now = microtime(true);
        $bucket = $this->buckets[$key];
        $elapsed = $now - $bucket['last'];

        return $bucket['tokens'] + $elapsed;
    }

    /**
     * Reset a specific bucket
     *
     * @param string $key Unique identifier
     * @return void
     */
    public function reset(string $key): void
    {
        unset($this->buckets[$key]);
    }

    /**
     * Reset all buckets
     *
     * @return void
     */
    public function resetAll(): void
    {
        $this->buckets = [];
    }
}

```
File Path: `LibreNMS/Modules/Ipv4Addresses.php`
Modification Time: Sun Oct 26 14:25:02 2025

```php
<?php

/**
 * Ipv4Address.php
 *
 * Ipv4 addresses discovery module
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @link       http://librenms.org
 *
 * @copyright  2025 Peca Nesovanovic
 * @author     Peca Nesovanovic <peca.nesovanovic@sattrakt.com>
 */

namespace LibreNMS\Modules;

use App\ApiClients\DeviceApiClientFactory;
use App\Facades\PortCache;
use App\Models\Device;
use App\Models\Ipv4Address;
use App\Models\Ipv4Network;
use App\Observers\ModuleModelObserver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\DB\SyncsModels;
use LibreNMS\Exceptions\InvalidIpException;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Discovery\Ipv4AddressDiscovery;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;
use LibreNMS\Util\IPv4;
use LibreNMS\Util\DeviceApiSettings;
use SnmpQuery;

class Ipv4Addresses implements Module
{
    use SyncsModels;

    /**
     * @inheritDoc
     */
    public function dependencies(): array
    {
        return ['ports'];
    }

    /**
     * @inheritDoc
     */
    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        return $status->isEnabledAndDeviceUp($os->getDevice());
    }

    /**
     * @inheritDoc
     */
    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function discover(OS $os): void
    {
        $ips = new Collection;

        // Get device from OS object
        $device = $os->getDevice();

        // REST API branch (vendor-agnostic via factory)
        if (DeviceApiSettings::restEnabled($device)) {
            $client = DeviceApiClientFactory::make($device);
            if ($client && in_array('ipv4', $client->capabilities(), true)) {
                try {
                    // Expect entries with keys: ifIndex, ipv4_address, ipv4_prefixlen, context_name
                    $entries = $client->fetchIpv4Addresses($device) ?? [];
                    foreach ($entries as $row) {
                        $ifIndex = (int)($row['ifIndex'] ?? 0);
                        $portId = $ifIndex ? PortCache::getIdFromIfIndex($ifIndex, $device) : null;
                        if (!$portId) {
                            // Skip if we can't map to a port; ports module should run first (dependency)
                            continue;
                        }
                        $ips->push(new Ipv4Address([
                            'port_id' => $portId,
                            'ipv4_address' => trim((string)($row['ipv4_address'] ?? '')),
                            'ipv4_prefixlen' => $row['ipv4_prefixlen'] ?? '',
                            'context_name' => (string)($row['context_name'] ?? ''),
                        ]));
                    }
                    Log::debug('IPv4Addresses REST entries: ' . count($entries));
                } catch (\Throwable $e) {
                    Log::warning('IPv4Addresses REST fetch failed: ' . $e->getMessage());
                }
            }
        }

        if ($os instanceof Ipv4AddressDiscovery) {
            $ips = $os->discoverIpv4Addresses();
        }
        if ($ips->isEmpty()) {
            $ips = $this->discoverIpMib($os->getDevice());
        }

        // Fetch all networks with blank contexts
        $nets = Ipv4Network::where('context_name', '')->get()->groupBy('ipv4_network');
        Log::debug('Networks: ' . $nets->keys()->implode(','));

        $ips = $ips->filter(function ($data) use ($nets) {
            $addr = trim(str_replace('"', '', $data->ipv4_address ?? ''));
            $context = trim(str_replace('"', '', $data->context_name ?? ''));
            $prefix = trim($data->ipv4_prefixlen ?? '');

            if ($prefix == 0 || $prefix == '0.0.0.0' || $prefix == '') {
                $prefix = IPv4::classfullNetmaskFromRfc($addr);
                Log::info('Classfull netmask from RFC: ' . $addr . ' - ' . $prefix);
            }

            if (empty($addr) || $addr == '0.0.0.0' || $prefix == '') { // invalid address or prefix
                Log::info('Invalid data: ' . $addr . ' / ' . $prefix);

                return null;
            }

            try {
                preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', (string) $prefix, $tmp); // is it netmask or cidr?
                $pfxLen = (empty($tmp[1])) ? intval($prefix) : IPv4::netmask2cidr($tmp[1]);
                Log::debug($addr . ' - ' . $pfxLen);
                $tst = new IPv4($addr . '/' . $pfxLen);
            } catch (InvalidIpException $e) {
                Log::error('Failed to parse IP: ' . $e->getMessage());

                return null;
            }

            if (! $data->port_id) {
                Log::debug('Skipping ' . $data->ipv4_address . ' due to no matching port');

                return null;
            }

            $data->ipv4_prefixlen = $pfxLen;
            $data->context_name = $context;

            if ($data->ipv4_prefixlen > 0 && $data->ipv4_prefixlen < 32) {
                $addr = new IPv4($data->ipv4_address . '/' . $data->ipv4_prefixlen);
                $netaddr = $addr->getNetwork();
                if ($nets->has($netaddr)) {
                    $data->ipv4_network_id = $nets->get($netaddr)[0]->ipv4_network_id;
                } else {
                    $network = Ipv4Network::firstOrCreate([
                        'ipv4_network' => $netaddr,
                        'context_name' => '',
                    ]);

                    $data->ipv4_network_id = $network->ipv4_network_id;
                }
            }

            return $data;
        });

        ModuleModelObserver::observe(Ipv4Address::class);
        $this->syncModels($os->getDevice(), 'ipv4', $ips);
    }

    /**
     * @inheritDoc
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        // no polling
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        return $device->ipv4()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->ipv4()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        if ($type == 'polling') {
            return null;
        }

        return [
            'ipv4_addresses' => $device->ipv4()
                ->leftJoin('ipv4_networks', 'ipv4_addresses.ipv4_network_id', 'ipv4_networks.ipv4_network_id')
                ->select(['ipv4_addresses.*', 'ipv4_network', 'ifIndex']) // already joined with ports
                ->orderBy('ipv4_address')->orderBy('ipv4_prefixlen')->orderBy('ifIndex')->orderBy('ipv4_addresses.context_name')
                ->get()->map->makeHidden(['ipv4_address_id', 'ipv4_network_id', 'port_id', 'laravel_through_key']),
        ];
    }

    private function discoverIpMib(Device $device): Collection
    {
        $ips = new Collection;
        foreach ($device->getVrfContexts() as $context_name) {
            $ips = $ips->merge(SnmpQuery::context($context_name)->hideMib()->enumStrings()->walk(
                ['IP-MIB::ipAdEntAddr', 'IP-MIB::ipAdEntIfIndex', 'IP-MIB::ipAdEntNetMask'])
            ->mapTable(function ($data, $ipAddr = '') use ($context_name, $device) {
                //on some devices, ipAddr is broken, so use ipAdEntAddr as primary
                $entAddr = $data['ipAdEntAddr'] ?? '';
                $addr = (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', (string) $entAddr, $tmp)) ? $entAddr : $ipAddr;

                return new Ipv4Address([
                    'port_id' => PortCache::getIdFromIfIndex($data['ipAdEntIfIndex'] ?? 0, $device),
                    'ipv4_address' => $addr,
                    'ipv4_prefixlen' => $data['ipAdEntNetMask'] ?? '',
                    'context_name' => $context_name,
                ]);
            }));
        }

        return $ips->filter();
    }
}

```File Path: `LibreNMS/Modules/Support/RestNormalizers.php`
Modification Time: Sun Oct 26 14:12:48 2025

```php
<?php

namespace LibreNMS\Modules\Support;

/**
 * REST API response normalizers
 * Transform vendor-specific API responses into LibreNMS standard format
 */
class RestNormalizers
{
    // ========================================
    // Pure Storage FlashArray Normalizers
    // ========================================

    /**
     * Normalize Pure Storage array-level sensors (performance and capacity)
     *
     * @param array $arrayPayload Response from /arrays endpoint
     * @param array $perfPayload Response from /arrays/performance endpoint
     * @return array Sensors in LibreNMS format
     */
    public static function normalizePureArraySensors(array $arrayPayload, array $perfPayload): array
    {
        $sensors = [];

        // Array info from /arrays endpoint
        if (isset($arrayPayload['items']) && is_array($arrayPayload['items'])) {
            foreach ($arrayPayload['items'] as $array) {
                $arrayName = $array['name'] ?? 'array';

                // Capacity sensors
                if (isset($array['capacity'])) {
                    $sensors[] = [
                        'sensor_class' => 'storage',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Total Capacity',
                        'sensor_index' => 'array_capacity_total',
                        'sensor_current' => $array['capacity'] ?? 0,
                        'sensor_limit' => null,
                        'sensor_limit_low' => null,
                    ];
                }

                if (isset($array['space'])) {
                    $space = $array['space'];

                    // Data reduction ratio
                    if (isset($space['data_reduction'])) {
                        $sensors[] = [
                            'sensor_class' => 'count',
                            'sensor_type' => 'purestorage',
                            'sensor_descr' => $arrayName . ' Data Reduction Ratio',
                            'sensor_index' => 'array_data_reduction',
                            'sensor_current' => $space['data_reduction'],
                            'sensor_limit' => null,
                            'sensor_limit_low' => 1,
                        ];
                    }

                    // Space usage percentage
                    if (isset($space['total_physical']) && $space['total_physical'] > 0) {
                        $usedPercent = ($space['total_physical'] / $array['capacity']) * 100;
                        $sensors[] = [
                            'sensor_class' => 'percent',
                            'sensor_type' => 'purestorage',
                            'sensor_descr' => $arrayName . ' Space Used',
                            'sensor_index' => 'array_space_used_pct',
                            'sensor_current' => round($usedPercent, 2),
                            'sensor_limit' => 90,
                            'sensor_limit_low' => 0,
                        ];
                    }
                }
            }
        }

        // Performance metrics from /arrays/performance endpoint
        if (isset($perfPayload['items']) && is_array($perfPayload['items'])) {
            foreach ($perfPayload['items'] as $perf) {
                $arrayName = $perf['name'] ?? 'array';

                // Read IOPS
                if (isset($perf['reads_per_sec'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Read IOPS',
                        'sensor_index' => 'array_read_iops',
                        'sensor_current' => $perf['reads_per_sec'],
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Write IOPS
                if (isset($perf['writes_per_sec'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Write IOPS',
                        'sensor_index' => 'array_write_iops',
                        'sensor_current' => $perf['writes_per_sec'],
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Read bandwidth (bytes/sec)
                if (isset($perf['read_bytes_per_sec'])) {
                    $sensors[] = [
                        'sensor_class' => 'rate',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Read Bandwidth',
                        'sensor_index' => 'array_read_bw',
                        'sensor_current' => $perf['read_bytes_per_sec'],
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Write bandwidth (bytes/sec)
                if (isset($perf['write_bytes_per_sec'])) {
                    $sensors[] = [
                        'sensor_class' => 'rate',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Write Bandwidth',
                        'sensor_index' => 'array_write_bw',
                        'sensor_current' => $perf['write_bytes_per_sec'],
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Read latency (microseconds)
                if (isset($perf['usec_per_read_op'])) {
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Read Latency',
                        'sensor_index' => 'array_read_latency',
                        'sensor_current' => $perf['usec_per_read_op'],
                        'sensor_limit' => 10000, // 10ms warning
                        'sensor_limit_low' => 0,
                    ];
                }

                // Write latency (microseconds)
                if (isset($perf['usec_per_write_op'])) {
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Write Latency',
                        'sensor_index' => 'array_write_latency',
                        'sensor_current' => $perf['usec_per_write_op'],
                        'sensor_limit' => 10000, // 10ms warning
                        'sensor_limit_low' => 0,
                    ];
                }

                // Queue depth
                if (isset($perf['queue_depth'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $arrayName . ' Queue Depth',
                        'sensor_index' => 'array_queue_depth',
                        'sensor_current' => $perf['queue_depth'],
                        'sensor_limit' => 1000,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        }

        return $sensors;
    }

    /**
     * Normalize Pure Storage hardware components
     *
     * @param array $payload Response from /hardware endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
    public static function normalizePureHardware(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['items'] as $hw) {
            $name = $hw['name'] ?? 'unknown';
            $type = $hw['type'] ?? 'unknown';
            $status = $hw['status'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Inventory entry
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => $name,
                'entPhysicalClass' => self::mapPureHardwareType($type),
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $hw['model'] ?? '',
                'entPhysicalSerialNum' => $hw['serial'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Pure Storage',
                'entPhysicalParentRelPos' => $hw['slot'] ?? -1,
                'entPhysicalVendorType' => $type,
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => $hw['version'] ?? '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 1,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // State sensor for component health
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'purestorage',
                'sensor_descr' => $name . ' Status',
                'sensor_index' => 'hw_' . $index,
                'sensor_current' => self::pureStatusToNumeric($status),
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'critical'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'degraded'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'healthy'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                ],
            ];

            // Temperature sensors
            if (isset($hw['temperature']) && is_numeric($hw['temperature'])) {
                $sensors[] = [
                    'sensor_class' => 'temperature',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Temperature',
                    'sensor_index' => 'hw_temp_' . $index,
                    'sensor_current' => $hw['temperature'],
                    'sensor_limit' => 85,
                    'sensor_limit_low' => 0,
                ];
            }

            // Voltage sensors (for PSUs)
            if ($type === 'psu' && isset($hw['voltage']) && is_numeric($hw['voltage'])) {
                $sensors[] = [
                    'sensor_class' => 'voltage',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name . ' Voltage',
                    'sensor_index' => 'hw_volt_' . $index,
                    'sensor_current' => $hw['voltage'],
                    'sensor_limit' => 13,
                    'sensor_limit_low' => 11,
                ];
            }

            // Fan speed (RPM)
            if ($type === 'fan' && isset($hw['speed']) && is_numeric($hw['speed'])) {
                $sensors[] = [
                    'sensor_class' => 'fanspeed',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => $name,
                    'sensor_index' => 'hw_fan_' . $index,
                    'sensor_current' => $hw['speed'],
                    'sensor_limit' => 20000,
                    'sensor_limit_low' => 1000,
                ];
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    /**
     * Normalize Pure Storage network interfaces to LibreNMS ports
     *
     * @param array $payload Response from /network-interfaces endpoint
     * @return array Ports in LibreNMS format
     */
    public static function normalizePureNetworkInterfaces(array $payload): array
    {
        $ports = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $ports;
        }

        foreach ($payload['items'] as $idx => $iface) {
            $name = $iface['name'] ?? "port_$idx";
            $enabled = ($iface['enabled'] ?? false) ? 'up' : 'down';
            $speed = $iface['speed'] ?? 0;

            // Convert speed to bits per second (Pure returns in Gbps)
            $speedBps = $speed * 1000000000;

            $ports[] = [
                'ifIndex' => self::stableIndexFromName($name),
                'ifName' => $name,
                'ifDescr' => $iface['description'] ?? $name,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => $speedBps,
                'ifOperStatus' => $enabled,
                'ifAdminStatus' => $enabled,
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $iface['hwaddr'] ?? '',
                'ifAlias' => $iface['description'] ?? '',
                'ifLastChange' => 0,
            ];
        }

        return $ports;
    }

    /**
     * Normalize Pure Storage network performance
     * Convert rates to counters for RRD storage
     *
     * @param array $payload Response from /network-interfaces/performance endpoint
     * @param int $pollIntervalSec Polling interval in seconds
     * @return array Port statistics
     */
    public static function normalizePureNetworkPerformance(array $payload, int $pollIntervalSec): array
    {
        $stats = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $stats;
        }

        foreach ($payload['items'] as $perf) {
            $name = $perf['name'] ?? '';
            $ifIndex = self::stableIndexFromName($name);

            // Convert bytes/sec to counter (multiply by poll interval)
            $rxBytes = ($perf['received_bytes_per_sec'] ?? 0) * $pollIntervalSec;
            $txBytes = ($perf['transmitted_bytes_per_sec'] ?? 0) * $pollIntervalSec;

            $stats[$ifIndex] = [
                'ifInOctets' => $rxBytes,
                'ifOutOctets' => $txBytes,
                'ifInErrors' => $perf['received_errors_per_sec'] ?? 0,
                'ifOutErrors' => $perf['transmitted_errors_per_sec'] ?? 0,
                'ifInUcastPkts' => $perf['received_packets_per_sec'] ?? 0,
                'ifOutUcastPkts' => $perf['transmitted_packets_per_sec'] ?? 0,
            ];
        }

        return $stats;
    }

    /**
     * Normalize Pure Storage port optics (SFP/QSFP sensors)
     *
     * @param array $payload Response from /ports endpoint
     * @return array Optics sensors
     */
    public static function normalizePurePortOptics(array $payload): array
    {
        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $sensors;
        }

        foreach ($payload['items'] as $port) {
            $name = $port['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Optical power sensors (dBm)
            if (isset($port['wwn'])) {
                if (isset($port['rx_power']) && is_numeric($port['rx_power'])) {
                    $sensors[] = [
                        'sensor_class' => 'dbm',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $name . ' RX Power',
                        'sensor_index' => 'port_rx_' . $index,
                        'sensor_current' => $port['rx_power'],
                        'sensor_limit' => 0,
                        'sensor_limit_low' => -20,
                    ];
                }

                if (isset($port['tx_power']) && is_numeric($port['tx_power'])) {
                    $sensors[] = [
                        'sensor_class' => 'dbm',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => $name . ' TX Power',
                        'sensor_index' => 'port_tx_' . $index,
                        'sensor_current' => $port['tx_power'],
                        'sensor_limit' => 2,
                        'sensor_limit_low' => -10,
                    ];
                }
            }
        }

        return $sensors;
    }

    /**
     * Normalize Pure Storage volumes
     *
     * @param array $volumesPayload Response from /volumes endpoint
     * @param array $volPerfPayload Response from /volumes/performance endpoint
     * @return array Volume sensors
     */
    public static function normalizePureVolumes(array $volumesPayload, array $volPerfPayload = []): array
    {
        $sensors = [];

        if (!isset($volumesPayload['items']) || !is_array($volumesPayload['items'])) {
            return $sensors;
        }

        // Index performance data by volume name
        $perfByName = [];
        if (isset($volPerfPayload['items']) && is_array($volPerfPayload['items'])) {
            foreach ($volPerfPayload['items'] as $perf) {
                $volName = $perf['name'] ?? '';
                if ($volName) {
                    $perfByName[$volName] = $perf;
                }
            }
        }

        foreach ($volumesPayload['items'] as $vol) {
            $name = $vol['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Volume size
            if (isset($vol['provisioned'])) {
                $sensors[] = [
                    'sensor_class' => 'storage',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => 'Vol ' . $name . ' Provisioned',
                    'sensor_index' => 'vol_prov_' . $index,
                    'sensor_current' => $vol['provisioned'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }

            // Add performance metrics if available
            if (isset($perfByName[$name])) {
                $perf = $perfByName[$name];

                // Volume IOPS
                if (isset($perf['reads_per_sec']) && isset($perf['writes_per_sec'])) {
                    $totalIops = $perf['reads_per_sec'] + $perf['writes_per_sec'];
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => 'Vol ' . $name . ' IOPS',
                        'sensor_index' => 'vol_iops_' . $index,
                        'sensor_current' => $totalIops,
                        'sensor_limit' => null,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Volume latency
                if (isset($perf['usec_per_read_op']) && isset($perf['usec_per_write_op'])) {
                    $avgLatency = ($perf['usec_per_read_op'] + $perf['usec_per_write_op']) / 2;
                    $sensors[] = [
                        'sensor_class' => 'delay',
                        'sensor_type' => 'purestorage',
                        'sensor_descr' => 'Vol ' . $name . ' Latency',
                        'sensor_index' => 'vol_lat_' . $index,
                        'sensor_current' => $avgLatency,
                        'sensor_limit' => 10000,
                        'sensor_limit_low' => 0,
                    ];
                }
            }
        }

        return $sensors;
    }

    /**
     * Normalize Pure Storage attached hosts
     *
     * @param array $payload Response from /hosts endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
    public static function normalizePureHosts(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['items'] as $host) {
            $name = $host['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Inventory for connected hosts
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => 'Host: ' . $name,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $host['personality'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => '',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'host',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // Connection state sensor
            $isConnected = ($host['is_local'] ?? false) ? 2 : 0;
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'purestorage',
                'sensor_descr' => 'Host ' . $name . ' Connection',
                'sensor_index' => 'host_conn_' . $index,
                'sensor_current' => $isConnected,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'disconnected'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'connected'],
                ],
            ];

            // Host space usage if available
            if (isset($host['space']) && isset($host['space']['total_physical'])) {
                $sensors[] = [
                    'sensor_class' => 'storage',
                    'sensor_type' => 'purestorage',
                    'sensor_descr' => 'Host ' . $name . ' Space Used',
                    'sensor_index' => 'host_space_' . $index,
                    'sensor_current' => $host['space']['total_physical'],
                    'sensor_limit' => null,
                    'sensor_limit_low' => 0,
                ];
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    // ========================================
    // Proxmox Normalizers
    // ========================================

    /**
     * Normalize Proxmox node status
     *
     * @param array $payload Response from /api2/json/nodes/{node}/status endpoint
     * @return array ['sensors' => [...], 'processors' => [...], 'mempools' => [...]]
     */
    public static function normalizeProxmoxNodeStatus(array $payload): array
    {
        $sensors = [];
        $processors = [];
        $mempools = [];

        if (!isset($payload['data'])) {
            return ['sensors' => $sensors, 'processors' => $processors, 'mempools' => $mempools];
        }

        $data = $payload['data'];

        // CPU usage
        if (isset($data['cpu'])) {
            $cpuPercent = $data['cpu'] * 100;
            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'CPU Usage',
                'sensor_index' => 'node_cpu',
                'sensor_current' => round($cpuPercent, 2),
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];

            $processors[] = [
                'processor_index' => 0,
                'processor_type' => 'proxmox-cpu',
                'processor_descr' => 'Node CPU',
                'processor_usage' => round($cpuPercent, 2),
            ];
        }

        // Memory usage
        if (isset($data['memory']) && isset($data['memory']['used']) && isset($data['memory']['total'])) {
            $memUsed = $data['memory']['used'];
            $memTotal = $data['memory']['total'];
            $memPercent = ($memTotal > 0) ? ($memUsed / $memTotal) * 100 : 0;

            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Memory Usage',
                'sensor_index' => 'node_mem',
                'sensor_current' => round($memPercent, 2),
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];

            $mempools[] = [
                'mempool_index' => 0,
                'mempool_type' => 'proxmox',
                'mempool_descr' => 'Node Memory',
                'mempool_used' => $memUsed,
                'mempool_free' => $memTotal - $memUsed,
                'mempool_total' => $memTotal,
                'mempool_perc' => round($memPercent, 2),
            ];
        }

        // Root filesystem usage
        if (isset($data['rootfs']) && isset($data['rootfs']['used']) && isset($data['rootfs']['total'])) {
            $rootUsed = $data['rootfs']['used'];
            $rootTotal = $data['rootfs']['total'];
            $rootPercent = ($rootTotal > 0) ? ($rootUsed / $rootTotal) * 100 : 0;

            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Root FS Usage',
                'sensor_index' => 'node_rootfs',
                'sensor_current' => round($rootPercent, 2),
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];
        }

        // Uptime
        if (isset($data['uptime'])) {
            $sensors[] = [
                'sensor_class' => 'runtime',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Uptime',
                'sensor_index' => 'node_uptime',
                'sensor_current' => $data['uptime'],
                'sensor_limit' => null,
                'sensor_limit_low' => 0,
            ];
        }

        // Load average
        if (isset($data['loadavg']) && is_array($data['loadavg'])) {
            $load1 = $data['loadavg'][0] ?? 0;
            $sensors[] = [
                'sensor_class' => 'load',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Load Average (1m)',
                'sensor_index' => 'node_load1',
                'sensor_current' => $load1,
                'sensor_limit' => 10,
                'sensor_limit_low' => 0,
            ];
        }

        return ['sensors' => $sensors, 'processors' => $processors, 'mempools' => $mempools];
    }

    /**
     * Normalize Proxmox node network interfaces
     *
     * @param array $payload Response from /api2/json/nodes/{node}/network endpoint
     * @return array Ports in LibreNMS format
     */
    public static function normalizeProxmoxNodeNetwork(array $payload): array
    {
        $ports = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return $ports;
        }

        foreach ($payload['data'] as $idx => $iface) {
            $name = $iface['iface'] ?? "iface_$idx";
            $active = ($iface['active'] ?? 0) ? 'up' : 'down';

            $ports[] = [
                'ifIndex' => self::stableIndexFromName($name),
                'ifName' => $name,
                'ifDescr' => $iface['comments'] ?? $name,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => 1000000000, // Default to 1Gbps
                'ifOperStatus' => $active,
                'ifAdminStatus' => ($iface['autostart'] ?? 1) ? 'up' : 'down',
                'ifMtu' => 1500,
                'ifPhysAddress' => $iface['address'] ?? '',
                'ifAlias' => $iface['comments'] ?? '',
                'ifLastChange' => 0,
            ];
        }

        return $ports;
    }

    /**
     * Normalize Proxmox storage pools
     *
     * @param array $payload Response from /api2/json/storage endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
    public static function normalizeProxmoxNodeStorage(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['data'] as $storage) {
            $name = $storage['storage'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            // Storage inventory
            $inventory[] = [
                'entPhysicalIndex' => $index,
                'entPhysicalDescr' => 'Storage: ' . $name,
                'entPhysicalClass' => 'container',
                'entPhysicalName' => $name,
                'entPhysicalModelName' => $storage['type'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'Proxmox',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'storage',
                'entPhysicalHardwareRev' => '',
                'entPhysicalFirmwareRev' => '',
                'entPhysicalSoftwareRev' => '',
                'entPhysicalIsFRU' => 0,
                'entPhysicalAlias' => '',
                'entPhysicalAssetID' => '',
            ];

            // Storage usage
            if (isset($storage['used']) && isset($storage['total']) && $storage['total'] > 0) {
                $usedPercent = ($storage['used'] / $storage['total']) * 100;
                $sensors[] = [
                    'sensor_class' => 'percent',
                    'sensor_type' => 'proxmox',
                    'sensor_descr' => 'Storage ' . $name . ' Usage',
                    'sensor_index' => 'storage_' . $index,
                    'sensor_current' => round($usedPercent, 2),
                    'sensor_limit' => 90,
                    'sensor_limit_low' => 0,
                ];
            }

            // Storage enabled state
            $isEnabled = ($storage['enabled'] ?? 1) ? 2 : 0;
            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'proxmox',
                'sensor_descr' => 'Storage ' . $name . ' Status',
                'sensor_index' => 'storage_state_' . $index,
                'sensor_current' => $isEnabled,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'disabled'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unknown'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'enabled'],
                ],
            ];
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    /**
     * Normalize Proxmox cluster status
     *
     * @param array $payload Response from /api2/json/cluster/status endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
    public static function normalizeProxmoxClusterStatus(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        foreach ($payload['data'] as $item) {
            $type = $item['type'] ?? 'unknown';
            $name = $item['name'] ?? 'unknown';
            $index = self::stableIndexFromName($name);

            if ($type === 'node') {
                // Node inventory
                $inventory[] = [
                    'entPhysicalIndex' => $index,
                    'entPhysicalDescr' => 'Node: ' . $name,
                    'entPhysicalClass' => 'chassis',
                    'entPhysicalName' => $name,
                    'entPhysicalModelName' => '',
                    'entPhysicalSerialNum' => '',
                    'entPhysicalContainedIn' => 0,
                    'entPhysicalMfgName' => 'Proxmox',
                    'entPhysicalParentRelPos' => $item['nodeid'] ?? -1,
                    'entPhysicalVendorType' => 'node',
                    'entPhysicalHardwareRev' => '',
                    'entPhysicalFirmwareRev' => '',
                    'entPhysicalSoftwareRev' => '',
                    'entPhysicalIsFRU' => 0,
                    'entPhysicalAlias' => '',
                    'entPhysicalAssetID' => '',
                ];

                // Node online state
                $isOnline = ($item['online'] ?? 0) ? 2 : 0;
                $sensors[] = [
                    'sensor_class' => 'state',
                    'sensor_type' => 'proxmox',
                    'sensor_descr' => 'Node ' . $name . ' Status',
                    'sensor_index' => 'node_online_' . $index,
                    'sensor_current' => $isOnline,
                    'sensor_limit' => null,
                    'sensor_limit_low' => null,
                    'states' => [
                        ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'offline'],
                        ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unknown'],
                        ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'online'],
                    ],
                ];
            } elseif ($type === 'cluster') {
                // Cluster quorum state
                if (isset($item['quorate'])) {
                    $hasQuorum = $item['quorate'] ? 2 : 0;
                    $sensors[] = [
                        'sensor_class' => 'state',
                        'sensor_type' => 'proxmox',
                        'sensor_descr' => 'Cluster Quorum',
                        'sensor_index' => 'cluster_quorum',
                        'sensor_current' => $hasQuorum,
                        'sensor_limit' => null,
                        'sensor_limit_low' => null,
                        'states' => [
                            ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'no quorum'],
                            ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'unknown'],
                            ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'quorate'],
                        ],
                    ];
                }

                // Cluster nodes count
                if (isset($item['nodes'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'proxmox',
                        'sensor_descr' => 'Cluster Nodes',
                        'sensor_index' => 'cluster_nodes',
                        'sensor_current' => $item['nodes'],
                        'sensor_limit' => null,
                        'sensor_limit_low' => 1,
                    ];
                }
            }
        }

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    /**
     * Normalize Proxmox cluster resources (VMs, containers)
     *
     * @param array $payload Response from /api2/json/cluster/resources endpoint
     * @return array ['sensors' => [...], 'inventory' => [...]]
     */
    public static function normalizeProxmoxClusterResources(array $payload): array
    {
        $sensors = [];
        $inventory = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return ['sensors' => $sensors, 'inventory' => $inventory];
        }

        // Count VMs and containers
        $vmCount = 0;
        $ctCount = 0;
        $runningVms = 0;
        $runningCts = 0;

        foreach ($payload['data'] as $resource) {
            $type = $resource['type'] ?? '';
            $status = $resource['status'] ?? '';

            if ($type === 'qemu') {
                $vmCount++;
                if ($status === 'running') {
                    $runningVms++;
                }
            } elseif ($type === 'lxc') {
                $ctCount++;
                if ($status === 'running') {
                    $runningCts++;
                }
            }
        }

        // VM count sensors
        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox',
            'sensor_descr' => 'Total VMs',
            'sensor_index' => 'resource_vm_total',
            'sensor_current' => $vmCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox',
            'sensor_descr' => 'Running VMs',
            'sensor_index' => 'resource_vm_running',
            'sensor_current' => $runningVms,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        // Container count sensors
        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox',
            'sensor_descr' => 'Total Containers',
            'sensor_index' => 'resource_ct_total',
            'sensor_current' => $ctCount,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'proxmox',
            'sensor_descr' => 'Running Containers',
            'sensor_index' => 'resource_ct_running',
            'sensor_current' => $runningCts,
            'sensor_limit' => null,
            'sensor_limit_low' => 0,
        ];

        return ['sensors' => $sensors, 'inventory' => $inventory];
    }

    // ========================================
    // Helper Functions
    // ========================================

    /**
     * Convert Pure Storage status strings to numeric values
     *
     * @param string $status
     * @return int
     */
    protected static function pureStatusToNumeric(string $status): int
    {
        return match (strtolower($status)) {
            'healthy', 'ok', 'normal' => 2,
            'degraded', 'warning' => 1,
            'critical', 'failed', 'unhealthy' => 0,
            default => 3, // unknown
        };
    }

    /**
     * Map Pure Storage hardware type to entPhysicalClass
     *
     * @param string $type
     * @return string
     */
    protected static function mapPureHardwareType(string $type): string
    {
        return match (strtolower($type)) {
            'controller', 'ch' => 'module',
            'drive', 'shelf', 'ssd' => 'container',
            'psu', 'power supply' => 'powerSupply',
            'fan' => 'fan',
            'eth', 'fc' => 'port',
            default => 'other',
        };
    }

    /**
     * Convert status to standardized string (up/down/testing/unknown)
     *
     * @param mixed $v
     * @return string
     */
    protected static function toStatus($v): string
    {
        if (is_bool($v)) {
            return $v ? 'up' : 'down';
        }

        $str = strtolower((string)$v);
        return match ($str) {
            'up', 'online', 'active', 'enabled', 'healthy', 'ok', '1', 'true' => 'up',
            'down', 'offline', 'inactive', 'disabled', 'failed', '0', 'false' => 'down',
            'testing', 'initializing', 'starting' => 'testing',
            default => 'unknown',
        };
    }

    /**
     * Generate stable numeric index from name (for ifIndex, entPhysicalIndex, etc.)
     *
     * @param string $name
     * @return int
     */
    protected static function stableIndexFromName(string $name): int
    {
        // Use CRC32 to generate a stable numeric index
        // This ensures the same name always gets the same index
        return abs(crc32($name));
    }
}

```

File Path: `tmp/project plan.txt`
Modification Time: Sat Oct 25 21:04:27 2025

```php
If this branch introduces “REST API polling,” here are key areas to review or plan for:
•	Transport abstraction:
o	LibreNMS polling is SNMP-centric via classes like SnmpQuery. A clean approach is to add a transport interface and implement an HTTP client (e.g., Guzzle) for REST polling, so modules can swap transport without duplicating logic.
•	Device/OS modeling:
o	New OS definitions live under LibreNMS/OS/ and YAML in includes/definitions/*.yaml. You’d likely add flags to indicate a device is REST-polled, plus endpoint/auth configuration (tokens, base URL).
•	Configuration and secrets:
o	Store per-device REST credentials securely (device attributes or config). Avoid global tokens in config.php.
o	Support OAuth2/Bearer/API key, TLS verification, and proxy settings.
•	Scheduling and performance:
o	Respect rate limits and backoff. Batch endpoints if available.
o	Cache stable data (inventory, capabilities) in device_attributes to reduce calls.
•	Module parity:
o	Map existing poller modules (ports, sensors, health, ARP/LLDP, inventory) to REST endpoints. Where REST cannot deliver SNMP-like depth, mark modules as unsupported to keep validate.php clean.
•	Error handling and observability:
o	Timeouts, retries, circuit breaker on consecutive failures.
o	Emit metrics/logs so you can see REST call counts, latency, and error rates.
•	Discovery:
o	Provide discovery logic that can identify REST-capable devices and seed endpoints.
•	Security:
o	Enforce TLS, certificate validation, and avoid logging secrets.
o	Consider per-device token rotation and least-privilege scopes.
•	UI/UX:
o	Indicate transport type (SNMP vs REST) on the device page and About → System.
o	Offer configuration fields in the device edit modal for base URL and auth.
Compatibility checks for LibreNMS 25.9:
•	PHP 8.1+ recommended, with common extensions (mbstring, gd, curl, json, xml, pdo_mysql).
•	Composer autoload should include any new namespaces; update composer.json if you add classes.
•	Run ./validate.php and fix any reported issues before polling.
If you tell me specifically what you’d like me to look for (API design, code structure, test plan, performance considerations), or paste snippets like new classes, YAML definitions, or changes in poller.php, I’ll provide a detailed review and suggestions.
LibreNMS discovers and polls devices through a well defined flow that starts when you add a device (UI or CLI), stores it in the database, triggers the discovery pipeline (identify OS/capabilities and build inventory), and then runs the poller pipeline (collect time series and state). The code is split between legacy procedural modules under includes/ and newer namespaced classes under LibreNMS/ plus Laravel controllers/models for the web UI.
Below is a practical, step by step walkthrough for 25.10, with where to find the code and the key functions involved. File paths and function names are representative of current LibreNMS and may vary slightly by tag and your local tree; I’ll note alternatives when they commonly differ.
1.	Adding a device (UI or CLI)
•	Web UI path:
o	Navigate to Devices → Add Device, fill hostname/IP, SNMP version/community or v3 creds, port, transport, and any overrides; submit.
o	Controller and request handling:
	app/Http/Controllers/DeviceController.php (or AddDeviceController.php) handles the form submission (store action).
	app/Http/Requests/AddDeviceRequest.php validates inputs (hostname format, SNMP fields, etc.).
	Device is created via the Eloquent model:
	app/Models/Device.php (model) → Device::create([...]) inserts into the devices table.
	Credentials and per device overrides are persisted to devices and device_attributes tables.
	A discovery job is queued/triggered:
	Either via Laravel jobs (e.g., app/Jobs/Device/Discover.php) dispatched with the new device id, or by calling the CLI discovery script.
•	CLI path:
o	Run ./addhost.php -h -p -c -v [v3 options...]
o	Script and helper:
	addhost.php calls addHost() from includes/functions.php (sometimes includes/device.inc.php).
	addHost() writes the row into devices, sets defaults (status=0, last_discovered null), and may immediately trigger discovery.php for that host.
2.	Initial connectivity tests on add
•	ICMP reachability:
o	LibreNMS runs a ping to ensure the host is up.
o	Functions:
	includes/functions.php → isPingable(), device_ping() wrappers
	Namespaced client:
	LibreNMS\Util\Ping or LibreNMS\Util\IP (depending on tag) manages the actual command or PHP socket.
•	SNMP probe:
o	Before or as part of discovery, LibreNMS validates SNMP credentials and version.
o	Functions/classes:
	includes/snmp.inc.php provides wrappers: snmp_get(), snmp_walk(), snmp_get_multi(), snmpwalk_cache_oid()
	Namespaced client:
	LibreNMS\Snmp\SnmpClient and LibreNMS\Snmp\SnmpQuery encapsulate authentication, retries, timeouts, and transport.
3.	Discovery pipeline (identify OS/capabilities and build inventory)
•	Entry point:
o	CLI: ./discovery.php -h <device_id> (or -h all via discovery-wrapper.py)
o	Wrapper:
	discovery-wrapper.py schedules discovery across devices and worker slots.
•	Main orchestration:
o	includes/discovery/functions.inc.php → discover_device() and discover_modules()
o	Configuration decides which modules run:
	config.php and database settings → $config['discovery_modules'] list and per device module enable/disable.
•	OS detection:
o	Module:
	includes/discovery/os.php
o	Definitions:
	includes/definitions/*.yaml contain OS signatures (sysObjectID, sysDescr patterns, OIDs) and capability maps.
o	Classes:
	LibreNMS\OS\Factory, LibreNMS\OS\Base, LibreNMS\Device\OS resolve the OS and attach attributes (graph type, default modules).
•	Core discovery modules (typical ordering, each is a file and has a discover_* function):
o	General device data:
	includes/discovery/sysname.inc.php, includes/discovery/system.inc.php → sysName, sysDescr, locations, contact
o	Hardware/platform/serial:
	includes/discovery/entity-physical.inc.php → Entity MIB walk; helper: discover_entity_physical()
o	Interfaces/ports:
	includes/discovery/ports.inc.php → discover_ports(); helpers: port_index(), port_ifAlias(), discover_port()
	DB model: app/Models/Port.php (ports table)
o	VLANs/bridging:
	includes/discovery/vlans.inc.php → discover_vlans(), FDB tables
o	L2 neighbor protocols:
	includes/discovery/cdp.inc.php, lldp.inc.php → discover_cdp(), discover_lldp()
o	IPs/ARP routing:
	includes/discovery/ipv4.inc.php, ipv6.inc.php, arp.inc.php → discover_ipv4(), discover_ipv6(), discover_arp()
o	Services/routing:
	includes/discovery/bgp.inc.php, ospf.inc.php → discover_bgp(), discover_ospf()
o	Sensors and health:
	includes/discovery/sensors/*.inc.php (temperature, power, voltage, fans, humidity, etc.) → discover_sensor()
	helpers: discover_mempool(), discover_processor()
	DB models: app/Models/Sensor.php, Mempool.php, Processor.php
o	Inventory:
	includes/discovery/inventory.inc.php → discover_inventory() (entPhysical/Component lists)
•	Common helpers/utilities used throughout discovery modules:
o	includes/discovery/functions.inc.php:
	discover_new_device(), discover_additional_modules(), rewrite_entity_name(), get_device_os(), is_module_enabled()
o	includes/snmp.inc.php and LibreNMS\Snmp\SnmpQuery for data collection and caching:
	SnmpQuery::get(), ::walk(), ::getOids(), ::cacheWalk(), plus helper cache like snmpwalk_cache_oid()
4.	What gets written to the database during discovery
•	devices: core identity, OS, status, last_discovered, features, hardware, serial, version, uptime
•	ports: one per interface with indexes, admin/oper status, speeds, counters (ifHC*), ifAlias, ifDescr
•	sensors: one per physical/logical sensor with type, index, current/limits
•	mempools, processors: memory and CPU entities
•	vlans, ports_vlans, neighbours (LLDP/CDP), bgpPeers, ospfInstances/Neighbors
•	ip_addresses, ipv6_addresses, arp_table, fdb tables
•	device_attributes: key/value extras (REST endpoints, custom flags, etc. if present)
5.	Poller pipeline (collect time series and state)
•	Entry point:
o	CLI: ./poller.php -h <device_id> -r -v
o	Wrapper:
	poller-wrapper.py distributes poller work across threads/hosts.
•	Main orchestration:
o	includes/polling/functions.inc.php → poll_device(), poll_modules()
o	Module selection:
	$config['poller_modules'] and per device overrides (device_attributes) are checked by is_module_enabled().
•	Typical poller modules (each is a file with poll_* functions; many parallel the discovery modules):
o	OS/system:
	includes/polling/os.inc.php → poll_os(), uptime/version/hardware revalidation
o	Ports:
	includes/polling/ports.inc.php → poll_ports(), grabs counters (ifHCInOctets, ifHCOutOctets), errors, discards
	RRD/Time series write via Rrd and Graph classes; DB updates: ports.state, ports_counters tables (depending on tag)
o	Services and routing:
	includes/polling/bgp.inc.php, ospf.inc.php → poll_bgp(), poll_ospf()
o	ARP/IP/FDB:
	includes/polling/arp.inc.php, ipv4.inc.php, ipv6.inc.php, fdb.inc.php → poll_arp(), poll_ip(), poll_fdb()
o	Sensors/health:
	includes/polling/sensors/*.inc.php → poll_sensor(); writes current values/limits and feeds RRD
o	Mempools/Processors:
	includes/polling/mempools.inc.php, processors.inc.php → poll_mempools(), poll_processors()
o	Wireless/Environmental/Modules specific to OS:
	includes/polling/wireless.inc.php, power.inc.php, fans.inc.php, etc.
•	SNMP data collection during polling:
o	Same wrappers and classes:
	includes/snmp.inc.php → snmp_get(), snmp_walk(), snmp_getnext()
	LibreNMS\Snmp\SnmpQuery with caching and retries
•	Data persistence/graphing:
o	RRD files under rrd// (per metric type), managed by Rrd/Graph classes in LibreNMS\RRD
o	State/last change timestamps in corresponding tables
o	Eventlog and syslog updates if enabled:
	includes/polling/eventlog.inc.php, syslog.inc.php; DB: eventlog, syslog tables
6.	Scheduling and orchestration
•	daily.sh:
o	Runs maintenance tasks, updates, and can kick off discovery for new devices; also runs ./validate.php
•	discovery-wrapper.py:
o	Python wrapper that reads the devices list and schedules discovery.php across threads/parallel workers; honors device downtime windows and module configs.
•	poller-wrapper.py:
o	Similar scheduler for poller.php, supports distributed polling via poller groups.
•	Config and per device overrides:
o	config.php holds global defaults for modules, timeouts, workers, SNMP settings.
o	device_attributes can override modules, timeouts, SNMP v3 params, and special behavior per device.
7.	Key files and functions summary (most commonly involved)
•	Add device:
o	Web: app/Http/Controllers/DeviceController.php (store), app/Http/Requests/AddDeviceRequest.php, app/Models/Device.php
o	CLI: addhost.php, includes/functions.php → addHost()
•	Discovery:
o	Entrypoints: discovery.php, discovery-wrapper.py
o	Orchestration: includes/discovery/functions.inc.php → discover_device(), discover_modules()
o	OS and definitions: includes/discovery/os.php; includes/definitions/*.yaml; LibreNMS\OS*
o	Modules: includes/discovery/.inc.php and includes/discovery/sensors/.inc.php
o	SNMP: includes/snmp.inc.php; LibreNMS\Snmp\SnmpClient, LibreNMS\Snmp\SnmpQuery
•	Polling:
o	Entrypoints: poller.php, poller-wrapper.py
o	Orchestration: includes/polling/functions.inc.php → poll_device(), poll_modules()
o	Modules: includes/polling/.inc.php and includes/polling/sensors/.inc.php
o	RRD/graphing: LibreNMS\RRD* (classes), rrd/ directory per device
•	Common utilities:
o	includes/functions.php (helpers like addHost(), isPingable(), device_up/down, logging)
o	./validate.php for environment checks
o	config.php for configuration
8.	What happens, step by step, when you add a device and the first cycle runs
•	You submit the device in UI or run addhost.php.
•	The device row is created; per device attributes and credentials saved.
•	Ping/SNMP probes confirm reachability and credentials; failures are logged to eventlog.
•	A discovery job runs:
o	OS detection via SNMP sysObjectID/sysDescr against includes/definitions/*.yaml
o	Ports and basic inventory are created; sensors, L2/L3 neighbors, VLANs, IPs, routing are discovered.
o	Device metadata (hardware, version, serial) filled in.
•	The device now appears in the UI with initial data.
•	The poller runs on its schedule:
o	Pulls counters and state from discovered entities (ports, sensors, routing, ARP/IP)
o	Writes time series to RRD and updates DB state
o	Alerts and rules begin evaluating as data arrives
•	Ongoing cycles refine inventory (e.g., new interfaces, changing sensors) and keep measurements current.
Notes and caveats
•	The project is gradually migrating legacy includes/* modules into namespaced classes; in 25.10 you will likely see both styles in use.
•	Exact filenames for controllers and jobs can differ between tags; if you need the precise path in your checkout, I can adapt this to your tree if you share a directory listing for app/Http/Controllers and LibreNMS/.
•	If your instance uses REST based polling for certain devices (custom branch), substitute the SNMP functions with your HTTP client and transport, but the discovery/poller orchestration remains similar.
If you want, tell me if you’re using bare metal or Docker and whether you prefer UI or CLI, and I can give you concrete command/UI clicks and logs to watch for each stage (including which module files executed on your device in 25.10).
Blade is for UI views, while those files are backend polling/discovery modules that still live under includes/ and are executed by CLI scripts like discovery.php and poller.php.
What has moved to Blade are the legacy UI pages that used to be rendered via PHP includes under html/ (and sometimes includes/print-*.inc.php). Those have been migrated over the past few years to Laravel controllers plus Blade templates under resources/views.
If you’re specifically tracking what’s been migrated to Blade, here’s how it breaks down:
•	Still procedural backend (not Blade):
o	includes/discovery/.inc.php and includes/discovery/sensors/.inc.php
o	includes/polling/.inc.php and includes/polling/sensors/.inc.php
o	includes/snmp.inc.php, includes/functions.php
o	discovery.php, poller.php, discovery-wrapper.py, poller-wrapper.py
These are runtime modules and helpers, not UI.
•	UI that has been migrated to Blade (examples and typical locations):
o	Device pages:
	resources/views/device/overview.blade.php
	resources/views/device/ports.blade.php
	resources/views/device/services.blade.php
	resources/views/device/sensors.blade.php
	resources/views/device/inventory.blade.php
o	Device list and add/edit:
	resources/views/device/index.blade.php
	resources/views/device/create.blade.php
	resources/views/device/edit.blade.php
o	Alerts:
	resources/views/alerts/rules/index.blade.php
	resources/views/alerts/notifications/index.blade.php
	resources/views/alerts/templates/*.blade.php
o	Graphs and dashboards:
	resources/views/graphs/*.blade.php
	resources/views/widgets/*.blade.php
o	Maps/topology:
	resources/views/maps/*.blade.php
o	Authentication and users:
	resources/views/auth/*.blade.php
	resources/views/users/*.blade.php
o	Layout and shared UI:
	resources/views/layouts/*.blade.php
	resources/views/components/.blade.php
These are driven by controllers in app/Http/Controllers/ and routes in routes/web.php.
If you had specific legacy UI includes in mind (e.g., html/includes/device/ports.inc.php, print-ports.inc.php, etc.), share the exact filenames and I can tell you their Blade equivalents. But to be clear: discovery/polling includes have not been “moved to Blade” because they aren’t UI; the migration there is towards namespaced PHP classes and improved module structure, not Blade templates.
LibreNMS has been migrating discovery/polling “includes” into a namespaced module framework under LibreNMS/Modules. I don’t have the exact 25.10 tree, so I can’t give a definitive list for that tag, but I can explain what’s been moved by pattern and how to verify in your checkout.
What the “improved module structure” looks like
•	Location: LibreNMS/Modules/
•	Each module is a PHP class with a namespace like LibreNMS\Modules and typically implements standardized methods such as discover() and poll(), plus helper hooks (isEnabled(), cleanup(), getName()).
•	Orchestration: a ModuleManager (or equivalent dispatcher inside poll_device()/discover_device()) resolves a module name to a class. If a class exists, it uses the new code; if not, it falls back to legacy includes under includes/discovery/.inc.php or includes/polling/.inc.php.
•	Config mapping: the names in $config['poller_modules'] and $config['discovery_modules'] correspond to module names/classes. Per-device overrides still work.
How to see exactly what’s migrated in your 25.10 tree
•	List the new module classes:
o	ls LibreNMS/Modules
•	Or search by namespace:
o	grep -R "namespace LibreNMS\Modules" -n
•	Map modules to legacy includes:
o	For each class (e.g., LibreNMS/Modules/Ports.php), check if the matching legacy file (includes/polling/ports.inc.php or includes/discovery/ports.inc.php) is still referenced or has been reduced to a shim.
Common modules that have already been migrated in recent releases
These are the ones most commonly found under LibreNMS/Modules in current trees; exact filenames may differ slightly, but this will give you the lay of the land and what to look for:
•	Ports: LibreNMS/Modules/Ports.php
o	Replaces includes/discovery/ports.inc.php and includes/polling/ports.inc.php
•	Sensors: LibreNMS/Modules/Sensors.php
o	Consolidates various sensor types; replaces includes/discovery/sensors/.inc.php and includes/polling/sensors/.inc.php
•	Mempools: LibreNMS/Modules/Mempools.php
o	Replaces includes/discovery/mempools.inc.php and includes/polling/mempools.inc.php
•	Processors: LibreNMS/Modules/Processors.php
o	Replaces includes/discovery/processors.inc.php and includes/polling/processors.inc.php
•	Inventory/Entity Physical: LibreNMS/Modules/EntityPhysical.php or Inventory.php
o	Replaces includes/discovery/entity-physical.inc.php and includes/discovery/inventory.inc.php
•	IPv4/IPv6 Addresses: LibreNMS/Modules/Ipv4.php and Ipv6.php
o	Replaces includes/discovery/ipv4.inc.php, ipv6.inc.php and their polling counterparts
•	ARP and FDB: LibreNMS/Modules/Arp.php and Fdb.php
o	Replaces includes/discovery/arp.inc.php, fdb.inc.php and polling variants
•	VLANs/Bridging: LibreNMS/Modules/Vlans.php
o	Replaces includes/discovery/vlans.inc.php (+ polling where applicable)
•	Neighbors (LLDP/CDP): LibreNMS/Modules/Lldp.php and Cdp.php
o	Replaces includes/discovery/lldp.inc.php, cdp.inc.php (+ polling)
•	Routing protocols:
o	BGP: LibreNMS/Modules/Bgp.php → replaces includes/discovery/bgp.inc.php and includes/polling/bgp.inc.php
o	OSPF: LibreNMS/Modules/Ospf.php → replaces includes/discovery/ospf.inc.php and includes/polling/ospf.inc.php
•	OS/System: LibreNMS/Modules/Os.php
o	Handles common OS metadata, uptime/version refresh; replaces parts of includes/discovery/os.php and includes/polling/os.inc.php
•	Wireless/Environmental: LibreNMS/Modules/Wireless.php, Power.php, Fans.php (names can vary)
o	Replaces corresponding includes/* modules
•	Services (agent/daemons): LibreNMS/Modules/Services.php
o	Replaces includes/discovery/services.inc.php and includes/polling/services.inc.php
How the new modules are invoked
•	Discovery:
o	discovery.php calls discover_device(), which delegates to a module runner. For a module name X, it tries LibreNMS\Modules\X::discover($device) first; if no class exists, it includes includes/discovery/x.inc.php.
•	Polling:
o	poller.php calls poll_device(), similarly trying LibreNMS\Modules\X::poll($device) before falling back to includes/polling/x.inc.php.
Files/functions you’ll typically see involved now
•	LibreNMS/Modules/Module.php (base class or interface)
•	LibreNMS/Modules/ModuleManager.php (or similar resolver/dispatcher)
•	Methods:
o	discover(Device $device, SnmpClient $snmp, array $config)
o	poll(Device $device, SnmpClient $snmp, array $config)
o	isEnabled(Device $device, array $config)
•	Legacy shims:
o	includes/discovery/functions.inc.php → discover_modules() now checks for class existence
o	includes/polling/functions.inc.php → poll_modules() with the same pattern
•	SNMP client:
o	LibreNMS\Snmp\SnmpQuery / SnmpClient passed into modules, replacing direct global snmp_* calls
•	Models:
o	app/Models/* (Port, Sensor, Processor, Mempool, etc.) are used for DB writes rather than manual SQL in newer modules
If you want, paste the output of ls LibreNMS/Modules from your 25.10 branch, and I’ll give you a precise, itemized mapping to the legacy includes that have been superseded and note any modules that still fall back to includes.
Here’s a pragmatic way to integrate REST API discovery and polling into LibreNMS 25.10 so it runs alongside the native module framework, only on devices that have REST enabled, and persists data to the same tables with the same logic used for SNMP.
Architecture goals
•	Use the existing module framework under LibreNMS/Modules so REST runs in the same discovery/polling phases and writes to native tables (ports, sensors, mempools, processors, neighbors, etc.).
•	Add a REST transport (HTTP client) that modules can call, similar to how they call SNMP.
•	Enable per-device settings to control whether REST is used and how to authenticate.
•	Keep behavior transparent: if a device has REST enabled and is reachable, modules use REST; otherwise fall back to SNMP (or skip if REST-specific).
•	Respect rate limits, timeouts, and avoid logging secrets.
Key building blocks and where they live
•	Transport client for REST:
o	Add a reusable client: LibreNMS/HTTP/RestClient.php
	Wrap Guzzle or native curl with:
	Base URL, headers, auth (Bearer/API key/OAuth2), TLS settings, proxy, timeouts, retries/backoff.
	Methods like get(path, params), post(path, body), with JSON decode and error normalization.
o	Optional rate limiter:
	LibreNMS/HTTP/RateLimiter.php (token bucket per device/base_url).
•	Per-device configuration (stored in device_attributes):
o	Keys to add:
	rest_enabled = 0|1
	rest_base_url = https://device.example/api/v1
	rest_auth_type = bearer|apikey|basic|oauth2
	rest_token or rest_api_key
	rest_headers (JSON of extra headers if needed)
	rest_verify_tls = 0|1
	rest_timeout_ms = 5000
	rest_rate_limit_qps = 5
o	Access helpers:
	LibreNMS/Util/DeviceSettings.php → functions to read/write typed device attributes safely.
•	UI to configure per-device REST:
o	Blade templates:
	resources/views/device/edit.blade.php → add a “REST API” section with fields above.
o	Controller:
	app/Http/Controllers/DeviceController.php → persist to device_attributes on update.
o	Request validation:
	app/Http/Requests/UpdateDeviceRequest.php → validate URL, auth type, token presence.
•	Module integration (prefer augmenting existing module classes):
o	Pattern: inside a module’s discover() and poll(), branch on rest_enabled to collect via REST and then reuse the same upsert/DB write logic.
o	Example modules to update:
	LibreNMS/Modules/Ports.php
	LibreNMS/Modules/Sensors.php
	LibreNMS/Modules/Mempools.php
	LibreNMS/Modules/Processors.php
	LibreNMS/Modules/Lldp.php, Cdp.php
	LibreNMS/Modules/Ipv4.php, Ipv6.php, Arp.php, Fdb.php
	LibreNMS/Modules/Bgp.php, Ospf.php (if the device REST exposes these)
o	Shared normalizers:
	Add small mappers that convert REST JSON into the canonical arrays/objects the existing module uses for inserts. Example:
	LibreNMS/Modules/Support/RestNormalizers.php → normalizePorts(), normalizeSensors(), normalizeNeighbors(), etc.
•	Discovery orchestration:
o	includes/discovery/functions.inc.php → discover_modules() already resolves module classes. No change needed, but inside each module, add REST branches.
o	OS detection:
	Option A: keep SNMP OS detection as-is; REST modules run based on rest_enabled and not OS.
	Option B: allow REST to assist OS identification if SNMP sysObjectID/sysDescr is missing. Extend includes/discovery/os.php or LibreNMS\OS\Factory to accept hints via REST (optional).
•	Polling orchestration:
o	includes/polling/functions.inc.php → poll_modules() already calls module classes. The modules determine transport at runtime per-device.
•	Error handling and observability:
o	Centralize REST errors (HTTP status, JSON parse) in RestClient and return standardized exceptions.
o	Logging:
	Use Log::warning/info with tag [REST] and device_id. Avoid logging tokens.
o	Metrics:
	Optional counters in device_attributes or runtime stats: rest_calls, rest_errors, avg_latency.
Concrete implementation steps
1.	Create the REST client and helpers
•	Files to add:
o	LibreNMS/HTTP/RestClient.php
o	LibreNMS/HTTP/RateLimiter.php (optional)
o	LibreNMS/Util/DeviceSettings.php
•	RestClient skeleton:
<?php
namespace LibreNMS\HTTP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

class RestClient {
    private Client $client;
    private string $baseUrl;

    public function __construct(array $opts) {
        $this->baseUrl = rtrim($opts['base_url'], '/');
        $headers = $opts['headers'] ?? [];
        if ($opts['auth_type'] === 'bearer' && !empty($opts['token'])) {
            $headers['Authorization'] = 'Bearer ' . $opts['token'];
        } elseif ($opts['auth_type'] === 'apikey' && !empty($opts['token'])) {
            $headers['X-API-Key'] = $opts['token']; // or configurable header name
        }
        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'headers' => $headers,
            'timeout' => ($opts['timeout_ms'] ?? 5000) / 1000,
            'verify' => (bool)($opts['verify_tls'] ?? true),
            'proxy' => $opts['proxy'] ?? null,
        ]);
    }

    public function get(string $path, array $query = []) : array {
        try {
            $resp = $this->client->get(ltrim($path, '/'), ['query' => $query]);
            $json = json_decode((string)$resp->getBody(), true);
            if (!is_array($json)) {
                throw new \RuntimeException('Invalid JSON');
            }
            return $json;
        } catch (TransferException $e) {
            throw new \RuntimeException('REST GET failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
 •  DeviceSettings helper:<?php
namespace LibreNMS\Util;

use App\Models\Device;

class DeviceSettings {
    public static function restEnabled(Device $device): bool {
        return (bool) $device->attribs['rest_enabled'] ?? false;
    }
    public static function restOptions(Device $device): array {
        $a = $device->attribs ?? [];
        return [
            'base_url' => $a['rest_base_url'] ?? '',
            'auth_type' => $a['rest_auth_type'] ?? 'bearer',
            'token' => $a['rest_token'] ?? '',
            'headers' => !empty($a['rest_headers']) ? json_decode($a['rest_headers'], true) : [],
            'timeout_ms' => (int)($a['rest_timeout_ms'] ?? 5000),
            'verify_tls' => (bool)($a['rest_verify_tls'] ?? true),
            'proxy' => $a['rest_proxy'] ?? null,
        ];
    }
}
2.	Extend modules to support REST
•	Example: LibreNMS/Modules/Ports.php

<?php
namespace LibreNMS\Modules;

use App\Models\Device;
use LibreNMS\HTTP\RestClient;
use LibreNMS\Util\DeviceSettings;

class Ports extends Module {
    public function discover(Device $device): void {
        if (DeviceSettings::restEnabled($device)) {
            $client = new RestClient(DeviceSettings::restOptions($device));
            $ports = $client->get('interfaces'); // device REST endpoint
            $normalized = \LibreNMS\Modules\Support\RestNormalizers::normalizePorts($ports);
            $this->upsertPorts($device, $normalized); // reuse existing write logic
            return;
        }
        // fallback to SNMP code path (existing)
        $this->discoverViaSnmp($device);
    }

    public function poll(Device $device): void {
        if (DeviceSettings::restEnabled($device)) {
            $client = new RestClient(DeviceSettings::restOptions($device));
            $counters = $client->get('interfaces/counters');
            $normalized = \LibreNMS\Modules\Support\RestNormalizers::normalizePortCounters($counters);
            $this->updatePortCounters($device, $normalized); // reuse existing logic/rrd updates
            return;
        }
        $this->pollViaSnmp($device);
    }
}•
•	Apply similar changes to Sensors, Mempools, Processors, Neighbors, IP/ARP/FDB, and routing protocol modules. Each module:
o	Checks DeviceSettings::restEnabled($device)
o	Calls RestClient endpoints
o	Normalizes JSON to the same internal arrays used by SNMP path
o	Calls the existing upsert/update helpers to write DB and RRD
3.	Add normalizers to keep DB logic identical
•	File: LibreNMS/Modules/Support/RestNormalizers.php
o	Functions convert REST payloads to the canonical module structures. For instance:
	normalizePorts: returns array keyed by ifIndex with fields ifDescr, ifAlias, speed, admin/oper status.
	normalizeSensors: returns entries with type, index, current, limits, unit.
o	This keeps DB writing code unchanged.
4.	UI and API configuration
•	Add fields in device edit form for REST settings, persisted to device_attributes.
•	Optional: add bulk edit and device group defaults.
5.	Discovery integration details
•	If you want REST to help identify OS when SNMP is off or minimal:
o	In includes/discovery/os.php or a new helper class, if rest_enabled, call a “capabilities” endpoint to set device->os, hardware, version.
o	Update devices table columns the same way SNMP path does.
6.	Scheduling and performance
•	REST runs within the same module order during discovery and polling.
•	Implement rate limiting per device/base_url in RestClient or the module:
o	Backoff on HTTP 429 or server errors.
o	Cache static data (hardware inventory) and only refresh on discovery runs, not every poll.
•	Respect module enable/disable:
o	isEnabled(Device $device, $config) should also check if the device’s REST endpoints exist, if you have a capabilities map.
7.	Security and secrets
•	Do not log tokens or full URLs with query strings containing secrets.
•	Consider encrypting rest_token at rest. LibreNMS typically stores device_attributes in plaintext; if stricter security is required, integrate Laravel’s encryption:
o	Store rest_token_enc and decrypt on use via Crypt::decryptString().
•	TLS verification enabled by default; allow controlled override per device.
8.	Fallbacks and compatibility
•	If rest_enabled but REST fails repeatedly, log event and temporarily fall back to SNMP for critical modules (ports, sensors), if SNMP is configured.
•	If a device supports REST for some data but not others, modules can mix transports:
o	Example: Ports via REST, BGP via SNMP; make transport choice per module.
9.	Testing and rollout
•	Unit tests:
o	Add tests for RestClient, normalizers, and module REST branches with fixture JSON.
•	Integration tests:
o	Run discovery.php and poller.php on a test device with rest_enabled and compare DB rows to SNMP results.
•	Observability:
o	Add a small per-device status panel: “REST enabled” and last REST error timestamp in the device overview page.
•	Validate:
o	./validate.php should check for required PHP extensions (curl/json) and warn if devices have rest_enabled without base_url/token.
Example device_attributes values
•	rest_enabled=1
•	rest_base_url=https://router-1/api/v1
•	rest_auth_type=bearer
•	rest_token=abcd1234
•	rest_headers={"X-Org":"netops"}
•	rest_verify_tls=1
•	rest_timeout_ms=5000
•	rest_rate_limit_qps=5
Where the data lands (unchanged)
•	ports, sensors, processors, mempools, neighbours, ip_addresses/ipv6_addresses, arp_table, bgpPeers, ospfInstances, fdb tables, rrd files — you reuse the same upsert/update functions used by SNMP, so graphing, alerting, and UI remain identical.
Alternative approach (if you prefer keeping SNMP-only modules unchanged)
•	Create parallel REST-specific modules like LibreNMS/Modules/Rest/Ports.php and register them conditionally in ModuleManager when rest_enabled is true. They call the same DB helpers. This keeps SNMP modules untouched but adds more classes to maintain. The hybrid approach (REST branch inside existing modules) usually avoids duplication.
If you share your current LibreNMS/Modules directory listing and which endpoints your devices expose, I can sketch the exact normalize functions and the module branches for two or three modules (e.g., Ports and Sensors) to get you started quickly.
Great — if you already have app/APIClients, stick with that convention and avoid introducing a separate “RestClient” namespace.
Here are solid, Laravel-friendly naming options, with suggested locations and when to use each:
•	App\APIClients\DeviceApiClient
o	Generic, per-device REST/HTTP client that reads device attributes and exposes methods like getInterfaces(), getSensors(), etc.
o	Good default if you’ll support multiple vendors behind common endpoints.
•	App\APIClients\HttpApiClient
o	Transport-focused, reusable HTTP API client (JSON, headers, retry). Other clients can compose it.
•	App\APIClients\RestApiClient
o	Explicit that it’s REST/JSON; aligns with “APIClients” without clashing with “RestClient”.
•	App\APIClients\JsonApiClient
o	Emphasizes JSON parsing and error normalization; transport via HTTP.
•	App\APIClients\VendorApiClient
o	Use as a base and subclass per vendor:
	App\APIClients\Cisco\NxosApiClient
	App\APIClients\Fortinet\FortiGateApiClient
	App\APIClients\Arista\EosApiClient
•	App\Services\DeviceApi
o	If you prefer “Service” over “Client” naming; still callable from Modules.
•	App\APIClients\Contracts\ApiClientInterface
o	Interface to abstract transport; modules depend on this, concrete client can be DeviceApiClient or vendor-specific client.
Recommended structure for broad reuse
•	Interface
o	File: app/APIClients/Contracts/ApiClientInterface.php
o	Methods: get(path, params), post(path, body), with JSON return and normalized exceptions.
•	Concrete client
o	File: app/APIClients/DeviceApiClient.php
o	Reads per-device options from device_attributes and wraps your existing HTTP tooling.
•	Optional vendor-specific clients
o	Files under app/APIClients//… that extend DeviceApiClient and add endpoint helpers.
Example minimal skeleton using your existing APIClients pattern<?php
namespace App\APIClients;

use App\Models\Device;
use App\APIClients\Contracts\ApiClientInterface;
use Illuminate\Support\Facades\Http;

class DeviceApiClient implements ApiClientInterface
{
    protected string $baseUrl;
    protected array $headers;
    protected int $timeout;

    public function __construct(Device $device)
    {
        $a = $device->attribs ?? [];
        $this->baseUrl = rtrim($a['rest_base_url'] ?? '', '/');
        $this->timeout = (int)($a['rest_timeout_ms'] ?? 5000);
        $this->headers = $this->buildHeaders($a);
    }

    public function get(string $path, array $query = []): array
    {
        $resp = Http::withHeaders($this->headers)
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => (bool)($this->headers['_verify_tls'] ?? true)])
            ->get($this->baseUrl . '/' . ltrim($path, '/'), $query);

        if ($resp->failed()) {
            throw new \RuntimeException('API GET failed: ' . $resp->status());
        }

        $json = $resp->json();
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid JSON');
        }
        return $json;
    }

    protected function buildHeaders(array $a): array
    {
        $headers = [];
        $type = $a['rest_auth_type'] ?? 'bearer';
        $token = $a['rest_token'] ?? '';
        if ($type === 'bearer' && $token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        } elseif ($type === 'apikey' && $token) {
            $headers['X-API-Key'] = $token;
        }
        if (!empty($a['rest_headers'])) {
            $headers = array_merge($headers, json_decode($a['rest_headers'], true) ?: []);
        }
        $headers['_verify_tls'] = (bool)($a['rest_verify_tls'] ?? true); // internal flag
        return $headers;
    }
}How modules would use it
•	In LibreNMS/Modules/Ports.php (or any module), inject or construct App\APIClients\DeviceApiClient when rest_enabled is true, and call endpoint helpers.
•	Keep the same normalization and DB write paths so data lands in native tables.
If you share the exact names under app/APIClients in your tree, I can align the recommendation to your existing interface/class naming to avoid any churn.

Here’s how I’d integrate REST-based discovery and polling using the current LibreNMS directories you shared, with concrete file/class names that fit those conventions and run alongside SNMP, only for devices with REST enabled, and writing to the same tables.
What those directories are used for today
•	app/ApiClients: client classes for calling external HTTP APIs. Good place for per-device REST clients.
•	app/Api/Controllers: controllers for LibreNMS’ own web API. Not needed for device polling; leave as-is.
•	LibreNMS/Authentication and app/Guards: user authentication (local, LDAP, SSO) and Laravel guards. Not for device REST auth; don’t tie device tokens into user auth.
Integration plan, aligned to current structure
1.	Per-device settings and storage
•	Device attributes to add (stored in device_attributes):
o	rest_enabled = 0|1
o	rest_base_url
o	rest_auth_type = bearer|apikey|basic|oauth2
o	rest_token (optionally encrypted)
o	rest_headers (JSON for extra headers)
o	rest_verify_tls = 0|1
o	rest_timeout_ms = 5000
o	rest_rate_limit_qps = 5
•	Encryption of sensitive tokens:
o	Use Laravel’s Crypt to store rest_token_enc instead of plaintext.
o	Access pattern: decrypt on use inside the client.
2.	API client(s) under app/ApiClients
•	Create a generic device client that reads per-device attributes and exposes HTTP helpers:
o	File: app/ApiClients/DeviceApiClient.php
o	Namespace: App\ApiClients
o	Responsibilities:
	Build base URL, headers/auth from device attributes
	Provide get(path, params) and post(path, body) that return decoded arrays
	Handle timeouts, TLS verification, basic retries/backoff, 429 handling
•	Optional vendors:
o	If you need vendor-specific endpoints, subclass:
	app/ApiClients/Cisco/NxosApiClient.php
	app/ApiClients/Fortinet/FortiGateApiClient.php
o	Each can add semantic methods like getInterfaces(), getSensors(), etc., but still inherit transport.
Example minimal client<?php
namespace App\ApiClients;

use App\Models\Device;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class DeviceApiClient
{
    protected string $baseUrl;
    protected array $headers;
    protected int $timeout;
    protected bool $verifyTls;

    public function __construct(Device $device)
    {
        $a = $device->attribs ?? [];
        $this->baseUrl = rtrim($a['rest_base_url'] ?? '', '/');
        $this->timeout = (int)($a['rest_timeout_ms'] ?? 5000);
        $this->verifyTls = (bool)($a['rest_verify_tls'] ?? true);
        $this->headers = $this->buildHeaders($a);
    }

    public function get(string $path, array $query = []): array
    {
        $resp = Http::withHeaders($this->headers)
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls])
            ->get($this->baseUrl . '/' . ltrim($path, '/'), $query);

        if ($resp->failed()) {
            throw new \RuntimeException('API GET failed: ' . $resp->status());
        }

        $json = $resp->json();
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid JSON');
        }
        return $json;
    }

    protected function buildHeaders(array $a): array
    {
        $headers = [];
        $type = $a['rest_auth_type'] ?? 'bearer';
        $token = $a['rest_token_enc'] ?? null;
        if ($token) {
            $token = Crypt::decryptString($token);
        } else {
            $token = $a['rest_token'] ?? '';
        }

        if ($type === 'bearer' && $token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        } elseif ($type === 'apikey' && $token) {
            $headers['X-API-Key'] = $token; // adjust header name per vendor
        } elseif ($type === 'basic' && $token) {
            // token format: username:password
            $headers['Authorization'] = 'Basic ' . base64_encode($token);
        }

        if (!empty($a['rest_headers'])) {
            $extra = json_decode($a['rest_headers'], true) ?: [];
            $headers = array_merge($headers, $extra);
        }
        return $headers;
    }
}3.	Normalizers to convert REST JSON to native module structures
•	File: LibreNMS/Modules/Support/RestNormalizers.php
•	Namespace: LibreNMS\Modules\Support
•	Functions:
o	normalizePorts(json): map to ifIndex, ifDescr, ifAlias, speed, admin/oper, type
o	normalizePortCounters(json): map to ifHCInOctets, ifHCOutOctets, errors/discards
o	normalizeSensors(json): map to type, index, current, limits, unit
o	normalizeNeighbors(json), normalizeIps(json), normalizeArp(json), normalizeFdb(json), etc.
4.	Module changes to branch on REST
•	In each migrated module under LibreNMS/Modules, add a REST code path inside discover() and poll() that:
o	Checks device attribute rest_enabled
o	Instantiates App\ApiClients\DeviceApiClient
o	Calls the appropriate endpoints
o	Normalizes payloads with RestNormalizers
o	Reuses existing upsert/update methods so data lands in native tables and RRDs
Example: LibreNMS/Modules/Ports.php

<?php
namespace LibreNMS\Modules;

use App\Models\Device;
use App\ApiClients\DeviceApiClient;
use LibreNMS\Modules\Support\RestNormalizers;

class Ports extends Module
{
    public function discover(Device $device): void
    {
        if ($device->attribs['rest_enabled'] ?? false) {
            $api = new DeviceApiClient($device);
            $json = $api->get('interfaces'); // adjust path per vendor
            $ports = RestNormalizers::normalizePorts($json);
            $this->upsertPorts($device, $ports); // existing helper
            return;
        }
        $this->discoverViaSnmp($device); // existing path
    }

    public function poll(Device $device): void
    {
        if ($device->attribs['rest_enabled'] ?? false) {
            $api = new DeviceApiClient($device);
            $json = $api->get('interfaces/counters');
            $counters = RestNormalizers::normalizePortCounters($json);
            $this->updatePortCounters($device, $counters); // existing helper that writes DB + RRD
            return;
        }
        $this->pollViaSnmp($device);
    }
}Apply the same pattern to:
•	LibreNMS/Modules/Sensors.php
•	LibreNMS/Modules/Mempools.php
•	LibreNMS/Modules/Processors.php
•	LibreNMS/Modules/Lldp.php and Cdp.php
•	LibreNMS/Modules/Ipv4.php, Ipv6.php, Arp.php, Fdb.php
•	LibreNMS/Modules/Bgp.php, Ospf.php (if endpoints exist)
5.	Discovery OS hints (optional)
•	Keep SNMP OS detection as the primary mechanism.
•	Optionally augment with REST when SNMP is limited:
o	In includes/discovery/os.php or a helper used by Modules\Os, if rest_enabled, call an endpoint like /system to fill hardware/version/serial and set device->os when identifiable.
o	Write to devices table same as SNMP path.
6.	UI changes to configure REST on a device
•	Add a “REST API” section in the device edit page:
o	resources/views/device/edit.blade.php
•	Update controller to persist attributes:
o	app/Http/Controllers/DeviceController.php (update/store)
•	Request validation:
o	app/Http/Requests/UpdateDeviceRequest.php to validate URL format, auth type, required token, numeric timeouts.
•	If encrypting tokens:
o	Encrypt in the controller: $device->setAttrib('rest_token_enc', Crypt::encryptString($request->rest_token))
7.	Orchestration
•	No change to discovery-wrapper.py or poller-wrapper.py; modules will run in the same order.
•	Module enable/disable continues via config and per-device attributes; your REST branch only triggers when rest_enabled is set.
8.	Rate limiting, retries, and observability
•	Implement basic backoff on 429/5xx in DeviceApiClient (e.g., retry with sleep).
•	Optional per-device rate limit memory:
o	Store last-call timestamps in device cache or attributes if needed.
•	Logging:
o	Use Log::warning('[REST] device_id=... ' . $message)
o	Avoid logging secrets.
•	Optional status surface:
o	Add a small indicator on device overview that REST is enabled and last REST error time.
9.	Fallback behavior
•	If rest_enabled is true but REST calls fail, you can:
o	Fall back to SNMP for that module if SNMP creds exist, or
o	Skip the module and log a warning.
•	Make this choice per-module; ports/sensors might prefer fallback, niche modules can skip.
10.	Unit and integration tests
•	Unit tests for App\ApiClients\DeviceApiClient using Http::fake() with fixtures.
•	Tests for RestNormalizers with JSON samples.
•	Integration tests for a device with rest_enabled running discovery.php and poller.php to confirm DB rows match expectations.
Naming recap tailored to your tree
•	Client: App\ApiClients\DeviceApiClient
•	Optional vendors: App\ApiClients\Vendor\XyzApiClient
•	Normalizers: LibreNMS/Modules/Support/RestNormalizers.php
•	Module changes: add REST branch inside existing LibreNMS/Modules/* classes
•	UI/Controller: resources/views/device/edit.blade.php and app/Http/Controllers/DeviceController.php
•	Validation: app/Http/Requests/UpdateDeviceRequest.php
•	Encryption: Illuminate\Support\Facades\Crypt
If you share which vendors/endpoints you plan to support first, I can sketch the normalize functions for Ports and Sensors against example payloads and show how they map to LibreNMS’ expected structures.
TASK LIST AND FILE INTEGRATION
o	ask: Discovery/polling of IP addresses into ip_addresses/ipv6_addresses.
•	LibreNMS/Modules/Arp.php, LibreNMS/Modules/Fdb.php
o	Task: Discovery/polling of ARP and MAC forwarding tables.
•	LibreNMS/Modules/Vlans.php
o	Task: Discovery of VLANs and port membership.
•	LibreNMS/Modules/Bgp.php, LibreNMS/Modules/Ospf.php (if REST endpoints exist)
o	Task: Discovery/polling of routing protocol state and peers.
•	LibreNMS/Modules/Os.php (optional augmentation)
o	Task: If desired, fetch hardware/version/serial via REST during discovery to supplement SNMP OS metadata.
Device attributes helper (optional convenience)
•	LibreNMS/Util/DeviceSettings.php (or use existing attribute accessors)
o	Task: Typed accessors for REST-related device attributes; centralize defaults and token decryption.
Security and encryption (optional files)
•	No new files required; use existing Laravel facilities:
o	Illuminate\Support\Facades\Crypt in DeviceController to store rest_token_enc.
o	Ensure you do not log secrets in modules or clients.
No changes required to orchestration
•	discovery.php, poller.php, discovery-wrapper.py, poller-wrapper.py
o	Task: Remain unchanged; modules now decide at runtime to use REST or SNMP per device.
•	includes/discovery/functions.inc.php, includes/polling/functions.inc.php
o	Task: Likely unchanged; the module classes implement the REST branch internally.
Testing (recommended)
•	tests/Feature/DeviceApiClientTest.php
o	Task: Unit test DeviceApiClient using Http::fake() with sample payloads, timeouts, TLS options, error handling.
•	tests/Unit/RestNormalizersTest.php
o	Task: Verify normalization of example JSON into expected arrays/structures.
•	tests/Integration/RestDiscoveryPollingTest.php
o	Task: Run discovery/poll against a fixture device with rest_enabled and assert DB rows/metrics updated as expected.
Summary of per-device attributes (persisted by controller)
•	rest_enabled (bool)
•	rest_base_url (string)
•	rest_auth_type (bearer|apikey|basic|oauth2)
•	rest_token or rest_token_enc (string, encrypted preferred)
•	rest_headers (JSON string of extra headers)
•	rest_verify_tls (bool)
•	rest_timeout_ms (int)
•	rest_rate_limit_qps (int, optional)
•	rest_proxy (string, optional)
With these files and updates, REST discovery/polling runs inside the existing module lifecycle, only for devices configured to use it, and writes into the same tables and RRDs as SNMP, keeping UI, graphs, and alerts consistent.
Here’s a practical design plan that fits LibreNMS 25.10’s architecture and conventions, gives users clean control per device, and keeps credentials secure without introducing unnecessary schema churn.
What the user will see and how they enable it
•	Device Edit page
o	Add a “REST API” section to the device edit form with:
	Enable toggle: “Enable REST API discovery/polling” (rest_enabled)
	Base URL: https://device.example/api/v1 (rest_base_url)
	Auth type selector: Bearer token, API key, Basic, OAuth2 (rest_auth_type)
	Credential input(s):
	Bearer/API key: token/key field
	Basic: username and password fields
	OAuth2: token field or client credentials if you plan to support token acquisition
	Optional extras:
	Custom headers (JSON key/value)
	Verify TLS (checkbox)
	Timeout (ms), Proxy
•	Device list bulk edit (optional)
o	Add bulk actions to set rest_enabled and default base URL/auth type across selected devices.
Where the creds are entered and stored
•	Controller and validation
o	Update app/Http/Controllers/DeviceController.php to read/save REST fields.
o	Extend app/Http/Requests/UpdateDeviceRequest.php to validate:
	URL format, allowed auth types, presence of token/user/pass as required, numeric timeout, JSON headers.
•	Storage
o	Use device_attributes for all REST-related settings. No new tables required.
o	Attribute keys:
	rest_enabled, rest_base_url, rest_auth_type
	rest_token_enc (encrypted token), or rest_username/rest_password_enc for Basic
	rest_headers, rest_verify_tls, rest_timeout_ms, rest_proxy
o	Encryption
	Encrypt sensitive values using Laravel’s Crypt before saving:
	Crypt::encryptString($token), Crypt::encryptString($password)
	Store only encrypted forms (e.g., rest_token_enc, rest_password_enc). Do not store plaintext.
o	Access
	Decrypt on use inside the API client class:
	Crypt::decryptString($device->attribs['rest_token_enc'])
Do we need new tables?
•	No. device_attributes already supports per-device key/value storage and is the standard place for non-SNMP device-specific settings.
•	If you foresee many REST attributes and want stricter typing, you could add a convenience accessor class (not a new table) like LibreNMS\Util\DeviceSettings to centralize defaults and decryption.
How modules will use REST when enabled
•	Transport client
o	Implement App\ApiClients\DeviceApiClient that reads device attributes, builds headers/auth, and exposes get()/post() returning decoded JSON.
•	Module branching
o	In LibreNMS/Modules/* classes (Ports, Sensors, Mempools, Processors, Neighbors, IP/ARP/FDB, etc.):
	If rest_enabled is true, call the API client and normalize payloads via LibreNMS/Modules/Support/RestNormalizers.
	Reuse existing upsert/update logic to write into native tables and RRDs.
	If REST fails, optionally fall back to SNMP when SNMP creds exist; log events without exposing secrets.
Security and credential handling
•	At-rest encryption
o	Use Laravel’s application key–backed encryption (AES-256-GCM via Crypt). This ensures tokens/passwords are not stored in plaintext.
•	In-UI masking
o	Do not display decrypted values in the UI. Show masked placeholders (e.g., “••••••”) and only allow replacement.
•	Logging hygiene
o	Never log tokens, passwords, or full URLs with query secrets. Log minimal context with device_id and endpoint path.
•	TLS and verification
o	Default rest_verify_tls to true; allow per-device override if necessary. Consider CA bundle management via PHP/cURL.
•	Rotation and revocation
o	Provide a quick action to clear stored tokens (set attribute null) and force modules to stop using REST until reconfigured.
•	Permissions
o	Limit who can set REST credentials using existing device edit permissions. No changes needed to guards; this uses device edit authorization already in place.
UX details
•	Device overview indicator
o	Show a badge “REST API enabled” on the device overview, with last REST success/error timestamp.
•	Validation warnings
o	If rest_enabled is true but required fields are missing or invalid, show a warning in the device page and in ./validate.php output.
Operational considerations
•	Timeouts and rate limits
o	Expose per-device timeout and optional QPS limit attributes; implement simple backoff on HTTP 429/5xx in the client.
•	Discovery vs polling cadence
o	Use REST more heavily during discovery for static inventory. During polling, call only endpoints needed for counters/state to minimize API load.
•	Mixed transport
o	Support module-by-module choices: if a device’s API lacks certain data, that module continues via SNMP.
Implementation checklist
•	UI: add REST section to resources/views/device/edit.blade.php with masked credential fields.
•	Controller: persist attributes in DeviceController (encrypt sensitive values).
•	Validation: add rules to UpdateDeviceRequest.
•	Client: app/ApiClients/DeviceApiClient.php for HTTP calls with decryption.
•	Normalizers: LibreNMS/Modules/Support/RestNormalizers.php.
•	Modules: update LibreNMS/Modules/* classes to branch on rest_enabled and call the client.
•	Optional: LibreNMS/Util/DeviceSettings.php for typed attribute access.
•	Tests: unit tests for client and normalizers; integration tests for a REST-enabled device.
This plan keeps everything within established LibreNMS patterns, avoids new tables, enables REST per device with secure credential storage, and ensures data flows into the same tables and graphs used by SNMP.

Deployment Task List
Here’s a clear task list to deploy REST API discovery/polling, followed by initial file skeletons to get you started. I’ve aligned names/paths to LibreNMS conventions you referenced.
Deployment task list
1.	UI and validation
•	Add REST fields to device edit form.
•	Validate REST fields on save.
2.	Persist secure credentials
•	Encrypt and store token/password in device_attributes.
•	Mask credentials in UI; allow replace only.
3.	API client
•	Implement a per-device HTTP client under app/ApiClients that reads attributes and exposes get()/post() with JSON parsing, TLS, timeout, and basic retry/backoff.
4.	Normalizers
•	Add mappers to convert REST JSON payloads into structures expected by existing DB write logic for ports, sensors, etc.
5.	Module integration
•	Update key Modules (Ports, Sensors, Mempools, Processors; neighbors/IP/ARP/FDB as needed) to:
o	Check rest_enabled attribute.
o	Call DeviceApiClient, normalize payloads.
o	Reuse existing upsert/update helpers.
6.	Optional helpers
•	Add a typed DeviceSettings accessor for REST attributes and centralized defaults.
7.	Logging and fallback
•	Ensure REST errors are logged without secrets.
•	Decide per-module SNMP fallback when REST fails.
8.	Tests
•	Unit tests for client and normalizers.
•	Integration test for a REST-enabled device through discovery/polling cycle.
9.	Validation tooling
•	Extend validate.php to warn about devices with rest_enabled but missing required fields (optional).
Initial file skeletons and snippets
1.	UI: add REST section to device edit form
File: resources/views/device/edit.blade.php

{{-- REST API Configuration --}}
<div class="card mt-3">
  <div class="card-header">REST API</div>
  <div class="card-body">
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" id="rest_enabled" name="rest_enabled" value="1"
             {{ old('rest_enabled', $device->attribs['rest_enabled'] ?? 0) ? 'checked' : '' }}>
      <label class="form-check-label" for="rest_enabled">Enable REST API discovery/polling</label>
    </div>

    <div class="mb-2">
      <label class="form-label">Base URL</label>
      <input type="url" class="form-control" name="rest_base_url"
             value="{{ old('rest_base_url', $device->attribs['rest_base_url'] ?? '') }}"
             placeholder="https://device.example/api/v1">
    </div>

    <div class="mb-2">
      <label class="form-label">Auth Type</label>
      <select class="form-select" name="rest_auth_type">
        @php $auth = old('rest_auth_type', $device->attribs['rest_auth_type'] ?? 'bearer'); @endphp
        <option value="bearer" {{ $auth === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
        <option value="apikey" {{ $auth === 'apikey' ? 'selected' : '' }}>API Key</option>
        <option value="basic" {{ $auth === 'basic' ? 'selected' : '' }}>Basic (username/password)</option>
      </select>
    </div>

    <div class="mb-2">
      <label class="form-label">Token / API Key</label>
      <input type="password" class="form-control" name="rest_token"
             placeholder="Enter to set or replace"
             value="">
      @if(!empty($device->attribs['rest_token_enc']))
        <small class="text-muted">A token is stored. Enter a new value to replace.</small>
      @endif
    </div>

    <div class="mb-2">
      <label class="form-label">Basic Auth Username</label>
      <input type="text" class="form-control" name="rest_username"
             value="{{ old('rest_username') }}">
    </div>
    <div class="mb-2">
      <label class="form-label">Basic Auth Password</label>
      <input type="password" class="form-control" name="rest_password" value="">
      @if(!empty($device->attribs['rest_password_enc']))
        <small class="text-muted">A password is stored. Enter a new value to replace.</small>
      @endif
    </div>

    <div class="mb-2">
      <label class="form-label">Extra Headers (JSON)</label>
      <textarea class="form-control" name="rest_headers" rows="2"
        placeholder='{"X-Org":"netops"}'>{{ old('rest_headers', $device->attribs['rest_headers'] ?? '') }}</textarea>
    </div>

    <div class="form-check mb-2">
      @php $verify = old('rest_verify_tls', $device->attribs['rest_verify_tls'] ?? 1); @endphp
      <input class="form-check-input" type="checkbox" id="rest_verify_tls" name="rest_verify_tls" value="1"
             {{ $verify ? 'checked' : '' }}>
      <label class="form-check-label" for="rest_verify_tls">Verify TLS certificates</label>
    </div>

    <div class="mb-2">
      <label class="form-label">Timeout (ms)</label>
      <input type="number" class="form-control" name="rest_timeout_ms"
             value="{{ old('rest_timeout_ms', $device->attribs['rest_timeout_ms'] ?? 5000) }}">
    </div>

    <div class="mb-2">
      <label class="form-label">Proxy (optional)</label>
      <input type="text" class="form-control" name="rest_proxy"
             value="{{ old('rest_proxy', $device->attribs['rest_proxy'] ?? '') }}">
    </div>
  </div>
</div>
2.	Validation: enforce field rules
File: app/Http/Requests/UpdateDeviceRequest.php

public function rules()
{
    $rules = parent::rules(); // keep existing device rules
    $rules += [
        'rest_enabled' => ['nullable', 'boolean'],
        'rest_base_url' => ['nullable', 'url'],
        'rest_auth_type' => ['nullable', 'in:bearer,apikey,basic'],
        'rest_token' => ['nullable', 'string', 'max:4096'],
        'rest_username' => ['nullable', 'string', 'max:255'],
        'rest_password' => ['nullable', 'string', 'max:4096'],
        'rest_headers' => ['nullable', 'json'],
        'rest_verify_tls' => ['nullable', 'boolean'],
        'rest_timeout_ms' => ['nullable', 'integer', 'min:100', 'max:60000'],
        'rest_proxy' => ['nullable', 'string', 'max:1024'],
    ];
    return $rules;
}3.	Controller: persist attributes securely
File: app/Http/Controllers/DeviceController.phpuse Illuminate\Support\Facades\Crypt;

public function update(UpdateDeviceRequest $request, Device $device)
{
    // existing device update logic...

    // REST attributes
    $device->setAttrib('rest_enabled', $request->boolean('rest_enabled') ? 1 : 0);
    $device->setAttrib('rest_base_url', $request->input('rest_base_url', ''));
    $device->setAttrib('rest_auth_type', $request->input('rest_auth_type', 'bearer'));
    $device->setAttrib('rest_headers', $request->input('rest_headers', ''));
    $device->setAttrib('rest_verify_tls', $request->boolean('rest_verify_tls') ? 1 : 0);
    $device->setAttrib('rest_timeout_ms', (int)$request->input('rest_timeout_ms', 5000));
    $device->setAttrib('rest_proxy', $request->input('rest_proxy', ''));

    // Token: only set when provided
    if ($request->filled('rest_token')) {
        $enc = Crypt::encryptString($request->input('rest_token'));
        $device->setAttrib('rest_token_enc', $enc);
    }
    // Basic auth: only set when provided
    if ($request->filled('rest_username')) {
        $device->setAttrib('rest_username', $request->input('rest_username'));
    }
    if ($request->filled('rest_password')) {
        $enc = Crypt::encryptString($request->input('rest_password'));
        $device->setAttrib('rest_password_enc', $enc);
    }

    $device->save();

    // redirect or return
}4.	Optional: typed attribute helper
File: LibreNMS/Util/DeviceSettings.php

<?php
namespace LibreNMS\Util;

use App\Models\Device;
use Illuminate\Support\Facades\Crypt;

class DeviceSettings
{
    public static function restEnabled(Device $device): bool
    {
        return (bool) ($device->attribs['rest_enabled'] ?? 0);
    }

    public static function restOptions(Device $device): array
    {
        $a = $device->attribs ?? [];
        $token = !empty($a['rest_token_enc']) ? Crypt::decryptString($a['rest_token_enc']) : null;
        $password = !empty($a['rest_password_enc']) ? Crypt::decryptString($a['rest_password_enc']) : null;

        return [
            'base_url' => rtrim($a['rest_base_url'] ?? '', '/'),
            'auth_type' => $a['rest_auth_type'] ?? 'bearer',
            'token' => $token,
            'username' => $a['rest_username'] ?? null,
            'password' => $password,
            'headers' => !empty($a['rest_headers']) ? (json_decode($a['rest_headers'], true) ?: []) : [],
            'verify_tls' => (bool)($a['rest_verify_tls'] ?? 1),
            'timeout_ms' => (int)($a['rest_timeout_ms'] ?? 5000),
            'proxy' => $a['rest_proxy'] ?? null,
        ];
    }
}5.	API client
File: app/ApiClients/DeviceApiClient.php

<?php
namespace App\ApiClients;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceSettings;

class DeviceApiClient
{
    protected string $baseUrl;
    protected array $headers;
    protected int $timeout;
    protected bool $verifyTls;
    protected ?string $proxy;

    public function __construct(Device $device)
    {
        $opts = DeviceSettings::restOptions($device);
        $this->baseUrl = $opts['base_url'];
        $this->timeout = $opts['timeout_ms'];
        $this->verifyTls = $opts['verify_tls'];
        $this->proxy = $opts['proxy'];
        $this->headers = $this->buildHeaders($opts);
    }

    public function get(string $path, array $query = []): array
    {
        $req = Http::withHeaders($this->headers)
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls]);

        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        $resp = $req->get($this->baseUrl . '/' . ltrim($path, '/'), $query);

        if ($resp->failed()) {
            throw new \RuntimeException('REST GET ' . $path . ' failed: ' . $resp->status());
        }

        $json = $resp->json();
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid JSON response');
        }
        return $json;
    }

    protected function buildHeaders(array $opts): array
    {
        $headers = $opts['headers'] ?? [];
        $type = $opts['auth_type'];
        if ($type === 'bearer' && !empty($opts['token'])) {
            $headers['Authorization'] = 'Bearer ' . $opts['token'];
        } elseif ($type === 'apikey' && !empty($opts['token'])) {
            $headers['X-API-Key'] = $opts['token']; // adjust per vendor
        } elseif ($type === 'basic' && !empty($opts['username'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($opts['username'] . ':' . ($opts['password'] ?? ''));
        }
        return $headers;
    }
}
6.	Normalizers
File: LibreNMS/Modules/Support/RestNormalizers.php<?php
namespace LibreNMS\Modules\Support;

class RestNormalizers
{
    public static function normalizePorts(array $json): array
    {
        $out = [];
        foreach ($json as $p) {
            $idx = $p['ifIndex'] ?? $p['id'] ?? null;
            if ($idx === null) { continue; }
            $out[$idx] = [
                'ifIndex' => (int)$idx,
                'ifDescr' => $p['name'] ?? $p['ifDescr'] ?? '',
                'ifAlias' => $p['description'] ?? $p['ifAlias'] ?? '',
                'ifType'  => $p['type'] ?? null,
                'speed'   => (int)($p['speed'] ?? 0),
                'admin_status' => self::toStatus($p['admin_status'] ?? $p['admin'] ?? null),
                'oper_status'  => self::toStatus($p['oper_status'] ?? $p['oper'] ?? null),
            ];
        }
        return $out;
    }

    public static function normalizePortCounters(array $json): array
    {
        $out = [];
        foreach ($json as $p) {
            $idx = $p['ifIndex'] ?? $p['id'] ?? null;
            if ($idx === null) { continue; }
            $out[$idx] = [
                'ifHCInOctets'  => (int)($p['in_octets'] ?? $p['ifHCInOctets'] ?? 0),
                'ifHCOutOctets' => (int)($p['out_octets'] ?? $p['ifHCOutOctets'] ?? 0),
                'in_errors'     => (int)($p['in_errors'] ?? 0),
                'out_errors'    => (int)($p['out_errors'] ?? 0),
                'in_discards'   => (int)($p['in_discards'] ?? 0),
                'out_discards'  => (int)($p['out_discards'] ?? 0),
            ];
        }
        return $out;
    }

    public static function normalizeSensors(array $json): array
    {
        $out = [];
        foreach ($json as $s) {
            $out[] = [
                'type' => $s['type'] ?? 'temperature',
                'index' => (string)($s['index'] ?? $s['id'] ?? ''),
                'description' => $s['name'] ?? $s['description'] ?? '',
                'value' => (float)($s['value'] ?? 0),
                'low_limit' => isset($s['low']) ? (float)$s['low'] : null,
                'warn_limit' => isset($s['warn']) ? (float)$s['warn'] : null,
                'high_limit' => isset($s['high']) ? (float)$s['high'] : null,
                'unit' => $s['unit'] ?? null,
            ];
        }
        return $out;
    }

    protected static function toStatus($v): string
    {
        if (is_numeric($v)) {
            return ((int)$v) === 1 ? 'up' : 'down';
        }
        $v = strtolower((string)$v);
        return in_array($v, ['up','down','testing','unknown']) ? $v : 'unknown';
    }
}

7.	Module integration example: Ports
File: LibreNMS/Modules/Ports.php (add REST branches; example shown)<?php
namespace LibreNMS\Modules;

use App\Models\Device;
use App\ApiClients\DeviceApiClient;
use LibreNMS\Util\DeviceSettings;
use LibreNMS\Modules\Support\RestNormalizers;

class Ports extends Module
{
    public function discover(Device $device): void
    {
        if (DeviceSettings::restEnabled($device)) {
            $api = new DeviceApiClient($device);
            $json = $api->get('interfaces'); // adjust per vendor
            $ports = RestNormalizers::normalizePorts($json);
            $this->upsertPorts($device, $ports); // existing helper
            return;
        }
        $this->discoverViaSnmp($device); // existing path
    }

    public function poll(Device $device): void
    {
        if (DeviceSettings::restEnabled($device)) {
            $api = new DeviceApiClient($device);
            $json = $api->get('interfaces/counters'); // adjust per vendor
            $counters = RestNormalizers::normalizePortCounters($json);
            $this->updatePortCounters($device, $counters); // existing helper
            return;
        }
        $this->pollViaSnmp($device); // existing path
    }
}
8.	Tests
File: tests/Feature/DeviceApiClientTest.php<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use App\Models\Device;
use App\ApiClients\DeviceApiClient;

class DeviceApiClientTest extends TestCase
{
    public function test_get_success()
    {
        Http::fake([
            'https://device.example/api/v1/interfaces' => Http::response([['id'=>1,'name'=>'Eth1']], 200),
        ]);
        $device = Device::factory()->create();
        $device->setAttrib('rest_enabled', 1);
        $device->setAttrib('rest_base_url', 'https://device.example/api/v1');
        $client = new DeviceApiClient($device);
        $data = $client->get('interfaces');
        $this->assertIsArray($data);
        $this->assertEquals('Eth1', $data[0]['name']);
    }
}
File: tests/Unit/RestNormalizersTest.php<?php
namespace Tests\Unit;

use Tests\TestCase;
use LibreNMS\Modules\Support\RestNormalizers;

class RestNormalizersTest extends TestCase
{
    public function test_normalize_ports()
    {
        $json = [['id'=>10,'name'=>'Eth10','admin'=>1,'oper'=>0,'speed'=>1000000000]];
        $ports = RestNormalizers::normalizePorts($json);
        $this->assertArrayHasKey(10, $ports);
        $this->assertEquals('Eth10', $ports[10]['ifDescr']);
        $this->assertEquals('up', $ports[10]['admin_status']);
        $this->assertEquals('down', $ports[10]['oper_status']);
    }
}
Rollout notes
•	You don’t need new tables; use device_attributes for configuration.
•	Ensure encryption key is set (APP_KEY) so Crypt works.
•	Start with one module (Ports) to validate the path end-to-end, then expand to Sensors/Mempools/Processors.
•	Decide and document endpoint paths per vendor, or abstract them in vendor-specific clients if needed.
If you want, tell me the first vendor/API you plan to support and I’ll tailor the endpoint paths and normalizers to their payloads, then replicate for Sensors next.
Excellent choice. Pure Storage has solid REST APIs, and it maps well to LibreNMS sensors, ports, and inventory. Below is a focused plan to ship initial support for Pure Storage (start with FlashArray; you can extend to FlashBlade next). I’ll include a concrete task list and initial file skeletons tailored to Pure’s API conventions.
High-level approach for Pure Storage
•	Add a vendor-specific API client under app/ApiClients that handles Pure’s authentication headers and versioning.
•	Extend existing Modules (Sensors, Ports, Inventory/EntityPhysical) to branch to REST when a device is Pure and rest_enabled.
•	Normalize Pure payloads to LibreNMS structures and reuse existing DB write paths, so data lands in native tables and RRDs.
•	Store creds in device_attributes with encryption; users enable REST per device in the edit form you just added.
Target: Pure FlashArray first
•	Authentication: local API token; typically header X-Auth-Token. Base URL is https:///api/ or https:///api depending on SDK; we can manage version negotiation.
•	Useful endpoints to start:
o	Array identity and capacity: GET /api/2.0/array (or /array)
o	Performance summary: GET /api/2.0/array/performance
o	Ports (FC/iSCSI) and status: GET /api/2.0/ports
o	Port performance: GET /api/2.0/ports/performance
o	Hardware components (controller, power, fans, temps): GET /api/2.0/hardware
o	Network interfaces: GET /api/2.0/network (if needed)
Notes: Exact paths vary by API version; we’ll add simple version detection.
Task list to deliver Pure Storage support
1.	Vendor API client
•	Implement Pure client with:
o	Base URL, X-Auth-Token header, TLS verify, timeout, proxy.
o	Version negotiation: call /api/versions (or similar) and pick a supported version.
o	Helpers: getArray(), getArrayPerformance(), getPorts(), getPortPerformance(), getHardware().
2.	Normalizers for Pure payloads
•	Map Pure JSON into:
o	Sensors: capacity used %, throughput, IOPS, latency, temperatures, PSU status, fan speeds, component health.
o	Ports: FC/iSCSI ports with admin/oper status, speed, WWN/iSCSI IQN, counters if available.
o	Inventory: controllers, shelves, modules.
3.	Module integration
•	Update these modules to call the Pure client when rest_enabled and OS matches Pure:
o	LibreNMS/Modules/Sensors.php
o	LibreNMS/Modules/Ports.php
o	LibreNMS/Modules/EntityPhysical.php (or Inventory.php)
o	Optional later: Mempools/Processors if you want to represent internal CPU/memory; not essential for storage arrays.
4.	OS identification and device metadata
•	Keep SNMP OS detection for now; optionally augment via REST:
o	On discovery, if rest_enabled, call getArray() to set hardware, version, serial, model, and mark os to a Pure-specific value if not set.
•	Add an OS definition for Pure to includes/definitions/purestorage.yaml with branding, graphs, and module defaults.
5.	UI and credentials
•	Use the REST section already added:
o	rest_enabled toggle
o	rest_base_url: https://
o	rest_auth_type: apikey (maps to X-Auth-Token)
o	rest_token: Pure API token
o	TLS verify, timeout, proxy as needed
•	Encrypt token in device_attributes.
6.	Testing
•	Unit tests for Pure client (Http::fake()) and normalizers.
•	Integration on a test device to verify discovery/poll populates sensors and ports.
Files to add and their responsibilities
Vendor API client
•	app/ApiClients/PureStorage/FlashArrayApiClient.php
o	Task: Per-device HTTP client for Pure FlashArray. Handles X-Auth-Token, version negotiation, and endpoint helpers.
Example skeleton<?php
namespace App\ApiClients\PureStorage;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceSettings;

class FlashArrayApiClient
{
    protected string $base;
    protected array $headers;
    protected int $timeout;
    protected bool $verifyTls;
    protected ?string $proxy;
    protected string $apiVersion;

    public function __construct(Device $device)
    {
        $opts = DeviceSettings::restOptions($device);
        $this->base = rtrim($opts['base_url'], '/'); // e.g. https://array.example
        $this->timeout = $opts['timeout_ms'];
        $this->verifyTls = $opts['verify_tls'];
        $this->proxy = $opts['proxy'] ?? null;

        // Pure FlashArray token via X-Auth-Token
        $this->headers = $opts['headers'] ?? [];
        if (!empty($opts['token'])) {
            $this->headers['X-Auth-Token'] = $opts['token'];
        }

        $this->apiVersion = $this->negotiateVersion();
    }

    protected function request(string $path, array $query = []): array
    {
        $url = sprintf('%s/api/%s/%s', $this->base, $this->apiVersion, ltrim($path, '/'));
        $req = Http::withHeaders($this->headers)
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls]);

        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        $resp = $req->get($url, $query);
        if ($resp->failed()) {
            throw new \RuntimeException('Pure API GET ' . $path . ' failed: ' . $resp->status());
        }
        $data = $resp->json();
        return is_array($data) ? $data : [];
    }

    protected function negotiateVersion(): string
    {
        // Try known versions in descending order; adjust based on your arrays
        foreach (['2.10', '2.9', '2.8', '2.0'] as $ver) {
            $probe = Http::withHeaders($this->headers)
                ->timeout($this->timeout / 1000)
                ->withOptions(['verify' => $this->verifyTls])
                ->get(sprintf('%s/api/%s/array', $this->base, $ver));
            if ($probe->ok()) {
                return $ver;
            }
        }
        // Fallback (some arrays support /api/array without version)
        return '2.0';
    }

    // Endpoint helpers
    public function getArray(): array { return $this->request('array'); }
    public function getArrayPerformance(): array { return $this->request('array/performance'); }
    public function getPorts(): array { return $this->request('ports'); }
    public function getPortPerformance(): array { return $this->request('ports/performance'); }
    public function getHardware(): array { return $this->request('hardware'); }
}

Normalizers for Pure
•	LibreNMS/Modules/Support/RestNormalizers.php (add Pure-specific mappers or keep generic functions with Pure-aware keys)
Example additionspublic static function normalizePureArraySensors(array $array, array $perf, array $hw): array
{
    $out = [];

    // Capacity utilization
    if (isset($array['capacity']) && isset($array['space'])) {
        $total = (float)($array['capacity'] ?? 0);
        $used = (float)($array['space']['used'] ?? 0);
        if ($total > 0) {
            $out[] = [
                'type' => 'storage',
                'index' => 'capacity_used_pct',
                'description' => 'Array Capacity Used',
                'value' => ($used / $total) * 100.0,
                'unit' => 'percent',
            ];
        }
    }

    // Performance sensors (IOPS, bandwidth, latency)
    if (!empty($perf)) {
        $out[] = [
            'type' => 'iops',
            'index' => 'array_iops',
            'description' => 'Array IOPS',
            'value' => (float)($perf['usec_per_read_op'] ?? $perf['iops'] ?? 0), // adjust keys based on actual payload
            'unit' => 'ops',
        ];
        $out[] = [
            'type' => 'bandwidth',
            'index' => 'array_bandwidth',
            'description' => 'Array Bandwidth',
            'value' => (float)($perf['bytes_per_second'] ?? $perf['bandwidth'] ?? 0),
            'unit' => 'bytes',
        ];
        $out[] = [
            'type' => 'latency',
            'index' => 'array_latency_usec',
            'description' => 'Array Latency',
            'value' => (float)($perf['usec_per_op'] ?? 0),
            'unit' => 'usec',
        ];
    }

    // Environmental sensors from hardware
    foreach ($hw ?? [] as $comp) {
        if (($comp['type'] ?? '') === 'fan' && isset($comp['rpm'])) {
            $out[] = [
                'type' => 'fanspeed',
                'index' => 'fan_' . ($comp['id'] ?? $comp['name'] ?? ''),
                'description' => 'Fan ' . ($comp['name'] ?? $comp['id'] ?? ''),
                'value' => (float)$comp['rpm'],
                'unit' => 'rpm',
            ];
        }
        if (($comp['type'] ?? '') === 'temperature' && isset($comp['temperature'])) {
            $out[] = [
                'type' => 'temperature',
                'index' => 'temp_' . ($comp['id'] ?? $comp['name'] ?? ''),
                'description' => 'Temp ' . ($comp['name'] ?? $comp['id'] ?? ''),
                'value' => (float)$comp['temperature'],
                'unit' => 'C',
            ];
        }
        if (($comp['type'] ?? '') === 'psu' && isset($comp['status'])) {
            $out[] = [
                'type' => 'state',
                'index' => 'psu_' . ($comp['id'] ?? ''),
                'description' => 'PSU ' . ($comp['name'] ?? $comp['id'] ?? ''),
                'value' => ($comp['status'] === 'ok') ? 1 : 0,
                'unit' => 'state',
            ];
        }
    }

    return $out;
}

public static function normalizePurePorts(array $ports, array $perf = []): array
{
    $out = [];
    // Map FC/iSCSI ports
    foreach ($ports as $p) {
        $idx = $p['id'] ?? $p['name'] ?? null;
        if ($idx === null) { continue; }
        $out[$idx] = [
            'ifIndex' => is_numeric($idx) ? (int)$idx : crc32((string)$idx),
            'ifDescr' => $p['name'] ?? ($p['wwn'] ?? $p['iqn'] ?? ''),
            'ifAlias' => $p['port'] ?? '',
            'ifType'  => ($p['protocol'] ?? '') === 'fc' ? 'fiber' : 'other',
            'speed'   => (int)($p['speed'] ?? 0),
            'admin_status' => self::toStatus($p['status'] ?? null),
            'oper_status'  => self::toStatus($p['status'] ?? null),
        ];
    }
    // Optionally merge perf counters per port using $perf array keyed by port name/id
    return $out;
}
Module integration examples
Sensors module
•	LibreNMS/Modules/Sensors.php
•	Task: On discover(), call Pure client endpoints and create sensors; on poll(), update values.
Snippetuse App\ApiClients\PureStorage\FlashArrayApiClient;
use LibreNMS\Util\DeviceSettings;
use LibreNMS\Modules\Support\RestNormalizers;

public function discover(Device $device): void
{
    if (DeviceSettings::restEnabled($device) && $this->isPure($device)) {
        $api = new FlashArrayApiClient($device);
        $array = $api->getArray();
        $perf = $api->getArrayPerformance();
        $hw   = $api->getHardware();

        $sensors = RestNormalizers::normalizePureArraySensors($array, $perf, $hw);
        $this->upsertSensors($device, $sensors);
        return;
    }
    $this->discoverViaSnmp($device);
}

public function poll(Device $device): void
{
    if (DeviceSettings::restEnabled($device) && $this->isPure($device)) {
        $api = new FlashArrayApiClient($device);
        $array = $api->getArray();
        $perf = $api->getArrayPerformance();
        $hw   = $api->getHardware();

        $sensors = RestNormalizers::normalizePureArraySensors($array, $perf, $hw);
        $this->updateSensorValues($device, $sensors);
        return;
    }
    $this->pollViaSnmp($device);
}

protected function isPure(Device $device): bool
{
    $os = $device->os;
    return $os === 'purestorage' || $os === 'pure-flasharray' || ($device->attribs['pure_candidate'] ?? false);
}

Ports module
•	LibreNMS/Modules/Ports.php
•	Task: Discover/poll FC/iSCSI ports via Pure endpoints; write to ports table and counters.
Snippetuse App\ApiClients\PureStorage\FlashArrayApiClient;
use LibreNMS\Modules\Support\RestNormalizers;

public function discover(Device $device): void
{
    if (DeviceSettings::restEnabled($device) && $this->isPure($device)) {
        $api = new FlashArrayApiClient($device);
        $ports = RestNormalizers::normalizePurePorts($api->getPorts());
        $this->upsertPorts($device, $ports);
        return;
    }
    $this->discoverViaSnmp($device);
}OS definition (optional but recommended)
•	includes/definitions/purestorage.yaml
•	Task: Identify Pure devices by sysDescr/sysObjectID (if SNMP present) or set via REST during discovery; define module defaults and branding.
Example minimal YAMLos: pure-flasharray
type: storage
group: purestorage
text: Pure Storage FlashArray
accuracy: 90
over:
  - hardware: ''
  - version: ''
discovery:
  - sysObjectID: .1.3.6.1.4.1.19536 # Pure OID if available
  - sysDescr: 'Pure Storage FlashArray'
modules:
  ports: true
  sensors: true
  inventory: true

Security and credentials
•	In your DeviceController update, store the Pure token as rest_token_enc using Crypt; set rest_auth_type to apikey to signal X-Auth-Token header.
•	Do not log tokens; mask in UI.
Notes on Pure API variations
•	API paths differ by array OS version. The version negotiation in the client should be adjusted once you confirm your array’s supported versions and exact endpoints:
o	Many FlashArray endpoints are under /api/2.0/… and have object lists with keys like name, id, status, speed, protocol.
o	If your arrays use newer v2.14+ or v3 endpoints, update negotiateVersion() and helper paths accordingly.
•	FlashBlade uses Bearer tokens and different endpoints; you can add App/ApiClients/PureStorage/FlashBladeApiClient.php later and branch based on device model reported by getArray().
Next steps
•	Confirm your FlashArray endpoint paths and sample payloads:
o	array, array/performance, ports, ports/performance, hardware
•	I’ll adapt the normalizers to match exact keys and add the poller counter mapping for ports if provided by Pure.
•	Implement Sensors first (capacity, IOPS, bandwidth, latency, temps/PSU/fans), then Ports. Inventory can follow with hardware components listing.
Share a couple of JSON samples from your FlashArray and I’ll refine the normalizers and complete the module code paths to make this production-ready.
No — you don’t need a physical Pure Storage array to develop and test this feature. You can fully exercise the client, normalizers, and module paths using mocks and fixtures, then do an end-to-end run with a local mock server.
Practical ways to test without hardware:
•	Laravel HTTP mocking
o	Use Http::fake() in unit/feature tests to return fixture JSON for the Pure endpoints your client calls. This lets you validate the API client, normalizers, and module logic.
o	Example:
	Http::fake([
'https://pure.local/api/2.0/array' => Http::response($fixtures['array'], 200),
'https://pure.local/api/2.0/array/performance' => Http::response($fixtures['perf'], 200),
'https://pure.local/api/2.0/ports' => Http::response($fixtures['ports'], 200),
'https://pure.local/api/2.0/hardware' => Http::response($fixtures['hardware'], 200),
]);
•	Local mock server
o	Run a lightweight mock API that serves your JSON fixtures at the expected paths:
	Option A: json-server (Node) or wiremock (Java) to serve static responses.
	Option B: a simple PHP/Express server with routes /api/2.0/array, /api/2.0/array/performance, /api/2.0/ports, /api/2.0/hardware.
o	Point the device’s rest_base_url to http://localhost:3000 and set rest_token to any placeholder.
•	Integration tests against LibreNMS
o	Seed a test device in the DB and set device_attributes:
	rest_enabled=1
	rest_base_url=http://localhost:3000
	rest_auth_type=apikey
	rest_token_enc=(encrypt any string)
o	Run discovery.php and poller.php; assert DB rows are created/updated (ports, sensors) using your fixtures.
Suggested test setup steps:
1.	Create fixture JSON files under tests/Fixtures/PureStorage/
•	array.json, array_performance.json, ports.json, hardware.json
2.	Write unit tests for the client and normalizers
•	Client: verifies headers, version negotiation, and parsing with Http::fake().
•	Normalizers: feed fixture arrays and assert mapped LibreNMS structures.
3.	Write an integration test for modules
•	Create a Device model with rest_enabled and Pure attributes.
•	Fake HTTP responses for the Pure endpoints.
•	Call module discover() and poll() methods; assert DB inserts/updates.
4.	Optional: run end-to-end with a mock server
•	docker run mockserver or node json-server to serve fixtures.
•	./discovery.php -h <device_id> and ./poller.php -h <device_id> should complete using REST.
Notes about Pure’s public resources:
•	Pure1 public API is different from array-local APIs and requires a Pure account; it’s useful for broader fleet data but not necessary for array-local polling tests.
•	There isn’t an official FlashArray simulator, so mocks/fixtures are the practical route for development.
When you’re ready to validate against real hardware:
•	Use a read-only API token on a lab array.
•	Keep TLS verification on; set rest_verify_tls=1.
•	Start with discovery-only to verify OS/metadata and sensors, then enable polling modules incrementally.
If you share sample payloads from your target array model/version, I can tailor the fixture JSON and normalizers to match exactly, so your test run mirrors production behavior closely.
What /hosts contains and how we’ll use it
•	Each item represents a storage host connected to the array, with:
o	Identity: name, personality (e.g., esxi), vlan
o	Connectivity: connection_count, port_connectivity {status, details}
o	Access: iqns, wwns, nqns, host_group, chap info
o	Space metrics: snapshots, total_used, total_provisioned, virtual, unique, data_reduction, thin_provisioning, total_reduction, used_provisioned
We’ll map these to:
•	Inventory entries (Entity Physical) for each host (no new tables)
•	Sensors for per-host metrics (capacity used/provisioned/snapshots, data reduction, thin provisioning)
•	State sensors for host connectivity status and connection count
Client addition
•	In App\ApiClients\PureStorage\FlashArrayApiClient.php, add:
o	public function getHosts(): array { return $this->request('hosts'); }
This mirrors the other endpoints and returns the items list for normalization. 1
Normalizers
•	Add a normalizer for hosts in LibreNMS/Modules/Support/RestNormalizers.php:public static function normalizePureHosts(array $hosts): array
{
    $inventory = [];
    $hostSensors = [];

    foreach (($hosts['items'] ?? []) as $h) {
        $name = $h['name'] ?? null;
        if (!$name) { continue; }

        // Inventory component for the host
        $inventory[] = [
            'class' => 'host',                 // logical component classification
            'name' => $name,
            'descr' => sprintf(
                '%s (group: %s, vlan: %s)',
                ($h['personality'] ?? 'unknown'),
                ($h['host_group']['name'] ?? 'none'),
                ($h['vlan'] ?? 'any')
            ),
            'serial' => null,                  // not provided; leave null
            'model' => $h['personality'] ?? null,
            'attributes' => [
                'iqns' => implode(', ', $h['iqns'] ?? []),
                'wwns' => implode(', ', $h['wwns'] ?? []),
                'nqns' => implode(', ', $h['nqns'] ?? []),
                'chap' => json_encode($h['chap'] ?? []),
                'port_connectivity_status' => $h['port_connectivity']['status'] ?? 'unknown',
                'port_connectivity_details' => $h['port_connectivity']['details'] ?? '',
                'connection_count' => (int) ($h['connection_count'] ?? 0),
            ],
        ];

        // Per-host sensors: capacity and ratios
        $space = $h['space'] ?? [];
        $hostIndexPrefix = 'host:' . $name . ':';

        if (isset($space['total_used'])) {
            $hostSensors[] = [
                'type' => 'storage',
                'index' => $hostIndexPrefix . 'total_used_bytes',
                'description' => 'Host Total Used (bytes)',
                'value' => (float) $space['total_used'],
                'unit' => 'bytes',
            ];
        }
        if (isset($space['total_provisioned'])) {
            $hostSensors[] = [
                'type' => 'storage',
                'index' => $hostIndexPrefix . 'total_provisioned_bytes',
                'description' => 'Host Total Provisioned (bytes)',
                'value' => (float) $space['total_provisioned'],
                'unit' => 'bytes',
            ];
        }
        if (isset($space['snapshots'])) {
            $hostSensors[] = [
                'type' => 'storage',
                'index' => $hostIndexPrefix . 'snapshots_bytes',
                'description' => 'Host Snapshots (bytes)',
                'value' => (float) $space['snapshots'],
                'unit' => 'bytes',
            ];
        }
        if (isset($space['virtual'])) {
            $hostSensors[] = [
                'type' => 'storage',
                'index' => $hostIndexPrefix . 'virtual_bytes',
                'description' => 'Host Virtual (bytes)',
                'value' => (float) $space['virtual'],
                'unit' => 'bytes',
            ];
        }
        if (isset($space['unique'])) {
            $hostSensors[] = [
                'type' => 'storage',
                'index' => $hostIndexPrefix . 'unique_bytes',
                'description' => 'Host Unique (bytes)',
                'value' => (float) $space['unique'],
                'unit' => 'bytes',
            ];
        }
        if (isset($space['used_provisioned'])) {
            $hostSensors[] = [
                'type' => 'storage',
                'index' => $hostIndexPrefix . 'used_provisioned_bytes',
                'description' => 'Host Used Provisioned (bytes)',
                'value' => (float) $space['used_provisioned'],
                'unit' => 'bytes',
            ];
        }
        if (isset($space['data_reduction'])) {
            $hostSensors[] = [
                'type' => 'ratio',
                'index' => $hostIndexPrefix . 'data_reduction',
                'description' => 'Host Data Reduction Ratio',
                'value' => (float) $space['data_reduction'],
                'unit' => 'x',
            ];
        }
        if (isset($space['total_reduction'])) {
            $hostSensors[] = [
                'type' => 'ratio',
                'index' => $hostIndexPrefix . 'total_reduction',
                'description' => 'Host Total Reduction Ratio',
                'value' => (float) $space['total_reduction'],
                'unit' => 'x',
            ];
        }
        if (isset($space['thin_provisioning'])) {
            $hostSensors[] = [
                'type' => 'percent',
                'index' => $hostIndexPrefix . 'thin_provisioning_pct',
                'description' => 'Host Thin Provisioning',
                'value' => (float) $space['thin_provisioning'] * 100.0,
                'unit' => 'percent',
            ];
        }

        // Connectivity state sensors
        $hostSensors[] = [
            'type' => 'state',
            'index' => $hostIndexPrefix . 'port_connectivity',
            'description' => 'Host Port Connectivity',
            'value' => (($h['port_connectivity']['status'] ?? '') === 'healthy') ? 1.0 : 0.0,
            'unit' => 'state',
        ];
        $hostSensors[] = [
            'type' => 'count',
            'index' => $hostIndexPrefix . 'connection_count',
            'description' => 'Host Connection Count',
            'value' => (float) ($h['connection_count'] ?? 0),
            'unit' => 'count',
        ];
    }

    return ['inventory' => $inventory, 'sensors' => $hostSensors];
}
This function consumes the items array and outputs two lists: inventory components and sensors keyed per host. It uses the exact fields shown in your sample. 1
Module integration
•	Inventory/EntityPhysical module (LibreNMS/Modules/EntityPhysical.php or Inventory.php):
o	During discovery, if rest_enabled and device is Pure, call getHosts(), normalize, and insert components via the existing inventory upsert helper.
o	Each component’s attributes (IQNs, WWNs, NQNs, connectivity) can be stored in the component attributes map so they are visible on the device’s Inventory tab. 1
•	Sensors module (LibreNMS/Modules/Sensors.php):
o	During discovery, register the host sensors produced by normalizePureHosts() so they show up under Sensors.
o	During polling, refresh these sensors; values are directly updated from /hosts. 1
Example use inside Sensors module:$api = new FlashArrayApiClient($device);
$hostsPayload = $api->getHosts(); // returns the JSON object with items[]
$mapped = RestNormalizers::normalizePureHosts($hostsPayload);
$this->upsertSensors($device, $mapped['sensors']); // discovery
// ...
$this->updateSensorValues($device, $mapped['sensors']); // polling

And inside Inventory module:$api = new FlashArrayApiClient($device);
$hostsPayload = $api->getHosts();
$mapped = RestNormalizers::normalizePureHosts($hostsPayload);
$this->upsertInventoryComponents($device, $mapped['inventory']);Both calls use existing DB write paths, so no schema changes are required. 1
Why inventory for hosts?
•	LibreNMS doesn’t have a dedicated “storage hosts” table. Inventory components are the native way to represent non-interface, non-sensor entities tied to a device, and they display well in the UI. Storing the IQNs/WWNs/NQNs with the component gives operators a quick view of initiator identities attached to the array. 1
Notes and options
•	If you prefer to graph per-host capacity trends, these sensors will produce RRD files automatically and show graphs like any other sensor.
•	If you later want per-host volumes or per-path metrics, we can add additional normalizers once you provide those endpoints and payloads.
Next step
•	I can wire this into the Pure client and modules we outlined earlier. If you share a full hosts payload (or confirm this structure is representative), I’ll finalize the normalize function and add tests with a fixture using the exact keys. 1
What the /hosts endpoint provides
•	Per host: name, personality (e.g., esxi), host_group.name, iqns/wwns/nqns, connection_count, port_connectivity {status, details}, vlan, chap, and detailed space metrics (total_used, total_provisioned, virtual, unique, data_reduction, thin_provisioning, total_reduction, snapshots). 1
How we’ll use it in LibreNMS
•	Inventory: add an “Attached Host” component per item with attributes like IQNs, host group, connectivity status, and counts. This shows under the device’s Inventory tab without new tables. 1
•	Sensors: create per-host sensors for capacity and ratios (bytes: total_used, total_provisioned, snapshots, virtual, unique, used_provisioned; ratios: data_reduction, total_reduction; percent: thin_provisioning), plus state/count sensors for port connectivity and connection_count. These will graph over time like standard sensors. 1
Files to touch
•	App\ApiClients\PureStorage\FlashArrayApiClient.php
o	Add: public function getHosts(): array { return $this->request('hosts'); } 1
•	LibreNMS/Modules/Support/RestNormalizers.php
o	Add normalizer tailored to your schema:
public static function normalizePureHosts(array $payload): array
{
    $inventory = [];
    $sensors = [];

    foreach (($payload['items'] ?? []) as $h) {
        $name = $h['name'] ?? null;
        if (!$name) { continue; }

        // Inventory component
        $inventory[] = [
            'class' => 'host',
            'name' => $name,
            'descr' => sprintf('%s (group: %s, vlan: %s)',
                $h['personality'] ?? 'unknown',
                $h['host_group']['name'] ?? 'none',
                $h['vlan'] ?? 'any'
            ),
            'model' => $h['personality'] ?? null,
            'serial' => null,
            'attributes' => [
                'iqns' => implode(', ', $h['iqns'] ?? []),
                'wwns' => implode(', ', $h['wwns'] ?? []),
                'nqns' => implode(', ', $h['nqns'] ?? []),
                'chap' => json_encode($h['chap'] ?? []),
                'port_connectivity_status' => $h['port_connectivity']['status'] ?? 'unknown',
                'port_connectivity_details' => $h['port_connectivity']['details'] ?? '',
                'connection_count' => (int)($h['connection_count'] ?? 0),
            ],
        ];

        // Per-host sensors
        $space = $h['space'] ?? [];
        $idx = 'host:' . $name . ':';

        $bytesKeys = [
            'total_used' => 'Host Total Used (bytes)',
            'total_provisioned' => 'Host Total Provisioned (bytes)',
            'snapshots' => 'Host Snapshots (bytes)',
            'virtual' => 'Host Virtual (bytes)',
            'unique' => 'Host Unique (bytes)',
            'used_provisioned' => 'Host Used Provisioned (bytes)',
        ];
        foreach ($bytesKeys as $key => $desc) {
            if (isset($space[$key])) {
                $sensors[] = [
                    'type' => 'storage',
                    'index' => $idx . $key . '_bytes',
                    'description' => $desc,
                    'value' => (float)$space[$key],
                    'unit' => 'bytes',
                ];
            }
        }
        if (isset($space['data_reduction'])) {
            $sensors[] = [
                'type' => 'ratio',
                'index' => $idx . 'data_reduction',
                'description' => 'Host Data Reduction Ratio',
                'value' => (float)$space['data_reduction'],
                'unit' => 'x',
            ];
        }
        if (isset($space['total_reduction'])) {
            $sensors[] = [
                'type' => 'ratio',
                'index' => $idx . 'total_reduction',
                'description' => 'Host Total Reduction Ratio',
                'value' => (float)$space['total_reduction'],
                'unit' => 'x',
            ];
        }
        if (isset($space['thin_provisioning'])) {
            $sensors[] = [
                'type' => 'percent',
                'index' => $idx . 'thin_provisioning_pct',
                'description' => 'Host Thin Provisioning (%)',
                'value' => (float)$space['thin_provisioning'] * 100.0,
                'unit' => 'percent',
            ];
        }

        // Connectivity and count
        $status = strtolower((string)($h['port_connectivity']['status'] ?? 'unknown'));
        $sensors[] = [
            'type' => 'state',
            'index' => $idx . 'port_connectivity',
            'description' => 'Host Port Connectivity',
            'value' => in_array($status, ['healthy','redundant']) ? 1.0 : 0.0,
            'unit' => 'state',
        ];
        $sensors[] = [
            'type' => 'count',
            'index' => $idx . 'connection_count',
            'description' => 'Host Connection Count',
            'value' => (float)($h['connection_count'] ?? 0),
            'unit' => 'count',
        ];
    }

    return ['inventory' => $inventory, 'sensors' => $sensors];
}

This matches the keys in your response and yields inventory plus sensors per host. 1
•	Modules integration
o	Inventory module (EntityPhysical/Inventory):
	On discovery: call Pure client → normalize → upsert components via existing inventory helper.
	On polling: inventory usually doesn’t change often; you can refresh during discovery runs.
o	Sensors module:
	On discovery: register the host sensors.
	On polling: refresh values from /hosts.
Example module usage:use App\ApiClients\PureStorage\FlashArrayApiClient;
use LibreNMS\Modules\Support\RestNormalizers;
use LibreNMS\Util\DeviceSettings;

public function discover(Device $device): void
{
    if (DeviceSettings::restEnabled($device) && $this->isPure($device)) {
        $api = new FlashArrayApiClient($device);
        $hosts = $api->getHosts(); // /api/2.26/hosts
        $mapped = RestNormalizers::normalizePureHosts($hosts);
        $this->upsertInventoryComponents($device, $mapped['inventory']);
        $this->upsertSensors($device, $mapped['sensors']);
        return;
    }
    $this->discoverViaSnmp($device);
}
public function poll(Device $device): void
{
    if (DeviceSettings::restEnabled($device) && $this->isPure($device)) {
        $api = new FlashArrayApiClient($device);
        $hosts = $api->getHosts();
        $mapped = RestNormalizers::normalizePureHosts($hosts);
        $this->updateSensorValues($device, $mapped['sensors']);
        return;
    }
    $this->pollViaSnmp($device);
}

This keeps data in native tables and reuses existing write/update logic. 1
Testing without hardware
•	Create a fixture file tests/Fixtures/PureStorage/hosts.json with the payload you pasted.
•	Unit test RestNormalizers::normalizePureHosts() to assert:
o	Inventory components created for each name
o	Sensors generated with expected indices and values (e.g., thin_provisioning_pct = thin_provisioning * 100)
o	Connectivity state set to 1 for “healthy”, 0 for “critical”
•	Feature test with Http::fake() mapping the /hosts URL to your fixture, then run Sensors discovery/poll and assert DB rows exist. 1
Next endpoints to add after hosts
•	arrays, array_performance, hardware, network-interfaces, network-performance, port_details, drives, volumes/volume_performance to complete Pure coverage for sensors and ports. The same client/normalizer/module pattern applies. 2 3 4 5 6 7 8 9 10
If you want, I can generate the unit test skeleton for normalizePureHosts based on this payload and a small inventory upsert stub next.PROXMOX POLLING

Perfect. Since you want REST API polling for both Proxmox nodes and Proxmox clusters, here’s a concrete plan and starter implementation that uses the Proxmox API and maps data into LibreNMS modules. I’ll anchor the normalization to the sample payloads you’ve provided, including nodes, cluster status, cluster resources, and host storage, so you can test immediately.
High-level design
•	Add per-device REST settings for Proxmox (base URL, auth type, token or username/password+realm) stored in device_attributes with encryption. No new tables.
•	Create a Proxmox API client under app/ApiClients/Proxmox for node and cluster endpoints.
•	Implement normalizers to convert Proxmox JSON into LibreNMS-native structures for:
o	Sensors: CPU percent, load averages, uptime, memory/swap usage, cluster quorum, node online state.
o	Processors: CPU usage percent per node.
o	Mempools: memory and swap per node.
o	Ports: node NICs (speed/state).
o	Inventory: node component metadata.
o	Optional: host storage pools (local-lvm, local dir) as inventory or sensors.
•	Update Modules (Processors, Mempools, Sensors, Ports, Inventory) to branch on “proxmox” vendor + rest_enabled. Nodes poll node endpoints; cluster devices poll cluster endpoints.
Key Proxmox endpoints and mapping
•	Node status: /nodes/{node}/status → uptime, memory, cpu, loadavg, swap, kernel version, pve version. Map to sensors, processors, mempools, inventory. 1
•	Node storage: /nodes/{node}/storage → lvmthin, dir pools with totals/used/avail. Map to inventory and optional storage sensors. 2
•	Cluster status: /cluster/status → items that include type=cluster, nodes count, quorate, version; also per-node records type=node, online, name. Map to sensors for quorum and per-node online state, and inventory entries for nodes. 3
•	Cluster resources: /cluster/resources → list of cluster and node resources and VM/LXC metadata; useful for inventory and optional counts metric. 4
Tasks to deliver
1.	Device attributes and UI
•	Extend device edit form with Proxmox REST settings (base URL, auth type, token or username/password+realm, TLS verify, timeout).
•	Controller: encrypt sensitive values (token secret, password) via Crypt and store in device_attributes.
•	Validation: enforce URL format, required fields per auth type.
Attributes to use:
•	rest_enabled=1
•	rest_vendor=proxmox
•	proxmox_base_url=https://host:8006
•	proxmox_auth_type=token|ticket
•	proxmox_token_user, proxmox_token_id, proxmox_token_enc
•	proxmox_username, proxmox_password_enc
•	proxmox_verify_tls, proxmox_timeout_ms, proxmox_proxy
2.	Proxmox API client
•	app/ApiClients/Proxmox/ProxmoxApiClient.php
o	Methods: getNodes(), getNodeStatus(node), getNodeNetwork(node), getNodeStorage(node), getClusterStatus(), getClusterResources()
o	Ticket login flow when auth_type=ticket; API token headers when auth_type=token.
3.	Normalizers aligned to your payloads
•	LibreNMS/Modules/Support/RestNormalizers.php
o	normalizeProxmoxNodeStatus(payload): produce sensors (uptime, load1, cpu%), processors (usage%), mempools (memory, swap).
	Example fields: uptime, memory.total/used, cpu (fraction), loadavg array, swap.total/used, pveversion, kversion. 1
o	normalizeProxmoxNodeNetwork(payload): map NICs to ports (ifIndex from name, speed, admin/oper state).
	Optional if you plan to use /nodes/{node}/network; not in your sample, but same pattern.
o	normalizeProxmoxNodeStorage(payload): map storage pools to inventory components and optional storage sensors (total/used/avail). 2
o	normalizeProxmoxClusterStatus(payload): sensors for quorum (state), nodes online count; inventory entries for cluster and nodes. 3
o	normalizeProxmoxClusterResources(payload): optional inventory of VMs/LXCs, node maxmem/maxcpu, etc. 4
4.	Module integration
•	Processors.php:
o	For proxmox node devices: call getNodeStatus → normalizeProxmoxNodeStatus → upsert processor usage (CPU%).
•	Mempools.php:
o	Use node status → upsert memory and swap pools.
•	Sensors.php:
o	Node devices: uptime, loadavg (1m), cpu% as sensor if desired; Proxmox-specific state sensors.
o	Cluster devices: quorum (state), nodes online count; optional per-node online status sensors.
•	Ports.php:
o	Optionally use node network endpoint to build ports; or skip if NICs aren’t needed.
•	Inventory.php (or EntityPhysical):
o	Node devices: add node inventory component with kernel, pveversion, cpu model if available from status.
o	Cluster devices: add cluster inventory with version and list nodes as child components.
5.	Tests
•	Unit tests for ProxmoxApiClient (Http::fake(), ticket login).
•	Normalizers tests with fixtures based on your sample payloads.
•	Integration tests: seed devices and run discovery/poll to assert DB writes.
Starter normalizers and usage examples
•	Node status to sensors, processors, mempools (matches your sample)
public static function normalizeProxmoxNodeStatus(array $payload): array
{
    $d = $payload['data'] ?? $payload; // your sample is already a data-like struct <sup><a href="null" class="markdown-link" target="_blank">1</a></sup>
    $sensors = [];
    if (isset($d['uptime'])) {
        $sensors[] = ['type' => 'uptime', 'index' => 'node:uptime', 'description' => 'Node Uptime (s)', 'value' => (float)$d['uptime'], 'unit' => 'seconds']; // <sup><a href="null" class="markdown-link" target="_blank">1</a></sup>
    }
    if (isset($d['loadavg'][0])) {
        $sensors[] = ['type' => 'load', 'index' => 'node:load1', 'description' => 'Load Average (1m)', 'value' => (float)$d['loadavg'][0], 'unit' => 'ratio']; // <sup><a href="null" class="markdown-link" target="_blank">1</a></sup>
    }
    if (isset($d['cpu'])) {
        $sensors[] = ['type' => 'cpu', 'index' => 'node:cpu_pct', 'description' => 'CPU Usage (%)', 'value' => (float)$d['cpu'] * 100.0, 'unit' => 'percent']; // <sup><a href="null" class="markdown-link" target="_blank">1</a></sup>
    }
    $processors = [];
    if (isset($d['cpu'])) {
        $processors[] = ['index' => 'node:cpu', 'descr' => 'Node CPU', 'usage' => (float)$d['cpu'] * 100.0]; // <sup><a href="null" class="markdown-link" target="_blank">1</a></sup>
    }
    $mempools = [];
    if (isset($d['memory']['total'])) {
        $mempools[] = [
            'index' => 'node:mem',
            'descr' => 'Memory',
            'total' => (int)$d['memory']['total'],
            'used'  => (int)($d['memory']['used'] ?? ($d['memory']['total'] - ($d['memory']['free'] ?? 0))),
        ]; // <sup><a href="null" class="markdown-link" target="_blank">1</a></sup>
    }
    if (isset($d['swap']['total'])) {
        $mempools[] = [
            'index' => 'node:swap',
            'descr' => 'Swap',
            'total' => (int)$d['swap']['total'],
            'used'  => (int)($d['swap']['used'] ?? 0),
        ]; // <sup><a href="null" class="markdown-link" target="_blank">1</a></sup>
    }
    return compact('sensors','processors','mempools');
}

•	Node storage pools to inventory/sensors (based on your host storage sample)
public static function normalizeProxmoxNodeStorage(array $payload): array
{
    $inv = [];
    $sensors = [];
    foreach ($payload['data'] ?? $payload['data'] ?? [] as $pool) {
        $name = $pool['storage'] ?? '';
        $inv[] = [
            'class' => 'storage-pool',
            'name' => $name,
            'descr' => $pool['type'] ?? '',
            'model' => $pool['content'] ?? '',
            'serial' => null,
            'attributes' => [
                'shared' => (int)($pool['shared'] ?? 0),
                'active' => (int)($pool['active'] ?? 0),
                'enabled' => (int)($pool['enabled'] ?? 0),
                'type' => $pool['type'] ?? '',
            ],
        ];
        if (isset($pool['total'])) {
            $sensors[] = [
                'type' => 'storage',
                'index' => 'pool:' . $name . ':total_bytes',
                'description' => $name . ' Total',
                'value' => (float)$pool['total'],
                'unit' => 'bytes',
            ];
        }
        if (isset($pool['used'])) {
            $sensors[] = [
                'type' => 'storage',
                'index' => 'pool:' . $name . ':used_bytes',
                'description' => $name . ' Used',
                'value' => (float)$pool['used'],
                'unit' => 'bytes',
            ];
        }
        if (isset($pool['avail'])) {
            $sensors[] = [
                'type' => 'storage',
                'index' => 'pool:' . $name . ':avail_bytes',
                'description' => $name . ' Available',
                'value' => (float)$pool['avail'],
                'unit' => 'bytes',
            ];
        }
    }
    return ['inventory' => $inv, 'sensors' => $sensors]; // <sup><a href="null" class="markdown-link" target="_blank">2</a></sup>
}
•	Cluster status to sensors/inventory (based on your schema)
public static function normalizeProxmoxClusterStatus(array $payload): array
{
    $d = $payload['items'] ?? $payload; // schema provided shows items with type=cluster/node <sup><a href="null" class="markdown-link" target="_blank">3</a></sup>
    $inventory = [];
    $sensors = [];

    foreach ($d as $item) {
        if (($item['type'] ?? '') === 'cluster') {
            if (isset($item['quorate'])) {
                $sensors[] = [
                    'type' => 'state',
                    'index' => 'cluster:quorum',
                    'description' => 'Cluster Quorum',
                    'value' => ($item['quorate'] ? 1.0 : 0.0),
                    'unit' => 'state',
                ]; // <sup><a href="null" class="markdown-link" target="_blank">3</a></sup>
            }
            if (isset($item['nodes'])) {
                $sensors[] = [
                    'type' => 'count',
                    'index' => 'cluster:nodes_count',
                    'description' => 'Cluster Nodes Count',
                    'value' => (float)$item['nodes'],
                    'unit' => 'count',
                ]; // <sup><a href="null" class="markdown-link" target="_blank">3</a></sup>
            }
            $inventory[] = [
                'class' => 'cluster',
                'name' => $item['name'] ?? 'Proxmox Cluster',
                'descr' => 'Proxmox VE Cluster',
                'model' => 'corosync v' . ($item['version'] ?? ''),
                'serial' => null,
                'attributes' => [
                    'quorate' => $item['quorate'] ?? null,
                    'version' => $item['version'] ?? null,
                ],
            ]; // <sup><a href="null" class="markdown-link" target="_blank">3</a></sup>
        } elseif (($item['type'] ?? '') === 'node') {
            $inventory[] = [
                'class' => 'node',
                'name' => $item['name'] ?? '',
                'descr' => 'Proxmox Node',
                'model' => '',
                'serial' => null,
                'attributes' => [
                    'online' => $item['online'] ?? null,
                    'ip' => $item['ip'] ?? null,
                    'level' => $item['level'] ?? null,
                ],
            ]; // <sup><a href="null" class="markdown-link" target="_blank">3</a></sup>
            if (isset($item['online'])) {
                $sensors[] = [
                    'type' => 'state',
                    'index' => 'node:' . ($item['name'] ?? '') . ':online',
                    'description' => 'Node Online',
                    'value' => ($item['online'] ? 1.0 : 0.0),
                    'unit' => 'state',
                ]; // <sup><a href="null" class="markdown-link" target="_blank">3</a></sup>
            }
        }
    }
    return ['inventory' => $inventory, 'sensors' => $sensors];
}

•	Cluster resources (optional enhancements)
You can use /cluster/resources to enrich inventory and sensors (e.g., total VMs/LXCs, per-node maxmem/maxcpu, and current cpu/mem fractions) based on the fields described in your schema (maxmem, mem, maxcpu, cpu fraction, etc.). 4
Module wiring examples
•	For a device representing a Proxmox node:
o	Sensors.php discover/poll: call getNodeStatus → normalizeProxmoxNodeStatus → upsert sensors; Processors.php and Mempools.php use the same payload to upsert their entities. 1
o	Inventory.php: add a node component using pveversion, kversion, cpuinfo if needed. 1
o	Optional: Ports.php discover/poll using getNodeNetwork; Storage inventory using getNodeStorage. 2
•	For a device representing a Proxmox cluster:
o	Sensors.php discover/poll: call getClusterStatus → normalizeProxmoxClusterStatus → upsert sensors for quorum and node online states. 3
o	Inventory.php: add cluster component + child node components from cluster status. 3
o	Optional: Sensors from cluster resources (aggregate cpu/mem per node) if you prefer. 4
Credentials and security
•	Store token secret and password encrypted using Crypt::encryptString() in device_attributes.
•	Never log secrets or tickets; log only device_id and endpoint path on errors.
•	Default to TLS verify enabled; allow override per device with proxmox_verify_tls.
Testing with your fixtures
•	Unit tests:
o	ProxmoxApiClient using Http::fake() for /api2/json/nodes/{node}/status and /cluster/status with your JSON.
o	RestNormalizers for node status and cluster status using your sample field names.
•	Integration tests:
o	Seed one device as a Proxmox node, one as a Proxmox cluster, set rest_enabled and proxmox_* attributes; fake endpoints to return fixtures; run discovery.php and poller.php; assert sensors, processors, mempools, and inventory entries exist and have the expected values.
If you confirm the device modeling approach (one LibreNMS device per Proxmox node, plus an optional device representing the cluster), I can produce the ProxmoxApiClient skeleton and the exact module method stubs next so you can drop them in and start testing.Thanks — those directories confirm the conventions we should follow. Here’s how to align the REST device polling design to LibreNMS’s existing structure, plus concrete next steps and file names that won’t clash.
What these directories are for
•	app/ApiClients: Place HTTP client classes for external device APIs. This is where your device-specific clients (Pure Storage, Proxmox) should live.
•	app/Api/Controllers: Controllers for LibreNMS’s own API (serving data to users). Not used for device polling; leave as-is.
•	LibreNMS/Authentication and app/Guards: User auth and access control for the web/UI/API. Not involved in device REST auth; don’t mix device tokens into user auth.
Recommended structure and naming
•	Per-device, vendor-specific clients:
o	app/ApiClients/PureStorage/FlashArrayApiClient.php
o	app/ApiClients/Proxmox/ProxmoxApiClient.php
•	Optional generic device HTTP client (transport only) if you want a shared base:
o	app/ApiClients/DeviceHttpClient.php
o	Or LibreNMS/HTTP/DeviceHttpClient.php if you prefer to keep low-level transport outside app/. Either is fine; the modules will call the vendor clients.
•	Optional interface:
o	app/ApiClients/Contracts/DeviceApiClientInterface.php
o	Methods like get(path), post(path), with JSON return, to decouple modules from a specific client.
Where credentials and settings live
•	Per-device REST configuration and credentials go in device_attributes (no new tables), set through the Device edit UI and controller:
o	Pure Storage: rest_enabled, rest_vendor=purestorage, rest_base_url, rest_auth_type=apikey, rest_token_enc, rest_verify_tls, rest_timeout_ms, rest_proxy.
o	Proxmox: rest_enabled, rest_vendor=proxmox, proxmox_base_url, proxmox_auth_type=token|ticket, proxmox_token_user, proxmox_token_id, proxmox_token_enc, proxmox_username, proxmox_password_enc, proxmox_verify_tls, proxmox_timeout_ms, proxmox_proxy.
•	Encryption: use Laravel Crypt to store secrets (token, password) as *_enc attributes. Never log or display decrypted values in UI.
Modules integration pattern
•	Update modules under LibreNMS/Modules (e.g., Ports, Sensors, Mempools, Processors, Inventory) to:
o	Check rest_enabled and rest_vendor for the device.
o	Instantiate the appropriate client (FlashArrayApiClient or ProxmoxApiClient).
o	Call endpoint helpers, normalize JSON with RestNormalizers, and reuse existing upsert/update logic to write into native tables and RRDs.
•	Keep orchestration unchanged (discovery.php, poller.php). Modules decide per device whether to use REST or SNMP.
Concrete next steps
1.	Create vendor clients in app/ApiClients:
•	Pure Storage: FlashArrayApiClient with helpers getArray(), getArrayPerformance(), getPorts(), getPortPerformance(), getHardware(), getHosts().
•	Proxmox: ProxmoxApiClient with helpers getNodes(), getNodeStatus(node), getNodeNetwork(node), getNodeStorage(node), getClusterStatus(), getClusterResources().
•	Both read device_attributes, build headers/cookies, honor TLS verification, timeout, proxy; handle retries/backoff.
2.	Add a small typed accessor for device attributes (optional):
•	LibreNMS/Util/DeviceSettings.php with restEnabled($device) and restOptions($device) returning base_url, auth type, token/password (decrypted), headers, timeout, verify_tls, proxy.
3.	Extend RestNormalizers:
•	Add normalizePure* functions (Array sensors, Ports, Hardware/env, Hosts).
•	Add normalizeProxmox* functions (Node status to sensors/processors/mempools, Node network to ports, Node storage to inventory/sensors, Cluster status/resources to sensors/inventory).
4.	UI and controller changes:
•	In resources/views/device/edit.blade.php, add REST sections for Pure and Proxmox.
•	In app/Http/Controllers/DeviceController.php, persist attributes and encrypt secrets; validate via app/Http/Requests/UpdateDeviceRequest.php.
5.	Update modules:
•	Sensors.php: branch on vendor; Pure: array perf + hardware + hosts; Proxmox: node status and cluster status.
•	Ports.php: Pure: network-interfaces + performance; Proxmox: node network.
•	Mempools.php and Processors.php: Proxmox node status → mempools and CPU usage.
•	Inventory/EntityPhysical.php: Pure hardware/components and hosts; Proxmox node/cluster components.
6.	Tests:
•	Use Http::fake() and fixtures under tests/Fixtures/PureStorage and tests/Fixtures/Proxmox to unit-test clients and normalizers.
•	Feature/integration tests that seed devices with attributes, run discovery/poll, and assert DB rows for sensors/ports/mempools/processors/inventory.
On the DeviceHttpClient naming
•	If you keep a shared transport client, DeviceHttpClient is clearer than RestClient. It avoids implying strict REST semantics and matches the “device” scope. But since LibreNMS already uses app/ApiClients, it’s fine to skip the shared client and implement vendor clients directly in app/ApiClients to stay consistent with the repo.
If you’d like, I can draft the initial FlashArrayApiClient and ProxmoxApiClient class skeletons and the corresponding RestNormalizers functions next, tailored to the JSON shapes you provided, so you can drop them in and begin testing immediately.

Below is a production-ready, reusable DeviceHttpClient you can drop into app/ApiClients. It’s a small wrapper around Laravel’s HTTP client that handles base URL, headers, TLS verification, proxy, timeouts, retries/backoff, and JSON parsing. It exposes get/post methods and centralized error handling. You can compose this inside your vendor clients (PureStorage, Proxmox).
File: app/ApiClients/DeviceHttpClient.php
<?php

namespace App\ApiClients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Generic HTTP client for device APIs.
 * - Base URL handling
 * - Common headers (auth, custom)
 * - TLS verification, timeout, proxy
 * - Retries with exponential backoff
 * - JSON parsing and error normalization
 *
 * Compose this inside vendor-specific clients (e.g., PureStorage, Proxmox).
 */
class DeviceHttpClient
{
    protected string $baseUrl;
    protected array $headers;
    protected bool $verifyTls;
    protected int $timeoutMs;
    protected ?string $proxy;
    protected int $maxRetries;
    protected int $retryInitialDelayMs;

    public function __construct(array $options)
    {
        $this->baseUrl = rtrim((string)($options['base_url'] ?? ''), '/');
        $this->headers = (array)($options['headers'] ?? []);
        $this->verifyTls = (bool)($options['verify_tls'] ?? true);
        $this->timeoutMs = (int)($options['timeout_ms'] ?? 5000);
        $this->proxy = $options['proxy'] ?? null;
        $this->maxRetries = (int)($options['max_retries'] ?? 2);
        $this->retryInitialDelayMs = (int)($options['retry_initial_delay_ms'] ?? 250);

        if ($this->baseUrl === '') {
            throw new \InvalidArgumentException('DeviceHttpClient requires base_url');
        }
    }

    /**
     * HTTP GET returning decoded JSON array.
     */
    public function get(string $path, array $query = []): array
    {
        $resp = $this->send('GET', $path, ['query' => $query]);
        return $this->parseJson($resp, $path);
    }

    /**
     * HTTP POST returning decoded JSON array.
     * $body is sent as JSON by default.
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        $resp = $this->send('POST', $path, ['json' => $body, 'query' => $query]);
        return $this->parseJson($resp, $path);
    }

    /**
     * Core request sender with retries/backoff.
     */
    protected function send(string $method, string $path, array $opts = []): Response
    {
        $url = $this->buildUrl($path);

        $req = Http::withHeaders($this->headers)
            ->timeout($this->timeoutMs / 1000)
            ->withOptions(['verify' => $this->verifyTls]);

        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        // Attach cookies if provided in headers via special key
        // Example: $options['cookies'] = ['Name' => 'Value'] set in headers via setCookies()
        if (!empty($this->headers['_cookies']) && is_array($this->headers['_cookies'])) {
            $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '';
            $req = $req->withCookies($this->headers['_cookies'], $host);
        }

        $attempt = 0;
        $delay = $this->retryInitialDelayMs;

        while (true) {
            $attempt++;

            try {
                $resp = $this->dispatch($req, $method, $url, $opts);

                // Retry on transient errors (HTTP 429/5xx)
                if ($this->shouldRetry($resp) && $attempt <= $this->maxRetries + 1) {
                    usleep($delay * 1000);
                    $delay = min($delay * 2, 2000);
                    continue;
                }

                return $resp;
            } catch (\Throwable $e) {
                // Network/timeout/transport exceptions should retry
                if ($attempt <= $this->maxRetries + 1) {
                    usleep($delay * 1000);
                    $delay = min($delay * 2, 2000);
                    continue;
                }
                throw new \RuntimeException(sprintf('HTTP %s %s failed: %s', $method, $url, $e->getMessage()), 0, $e);
            }
        }
    }

    protected function dispatch($req, string $method, string $url, array $opts): Response
    {
        // Query params
        $query = Arr::get($opts, 'query', []);
        // Body options
        $json = Arr::get($opts, 'json', null);
        $form = Arr::get($opts, 'form_params', null);

        if (strtoupper($method) === 'GET') {
            return $req->get($url, $query);
        }

        if ($json !== null) {
            return $req->withHeaders(['Content-Type' => 'application/json'])->post($url . $this->querySuffix($query), $json);
        }

        if ($form !== null) {
            return $req->asForm()->post($url . $this->querySuffix($query), $form);
        }

        // Default POST without body
        return $req->post($url . $this->querySuffix($query));
    }

    protected function parseJson(Response $resp, string $path): array
    {
        if ($resp->failed()) {
            $status = $resp->status();
            $body = $this->safeBodyPreview($resp);
            throw new \RuntimeException(sprintf('HTTP %s returned %d: %s', $path, $status, $body));
        }

        $data = $resp->json();

        if (is_null($data)) {
            // Non-JSON or empty body; treat as empty array
            return [];
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response for ' . $path);
        }

        return $data;
    }

    protected function buildUrl(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    protected function querySuffix(array $query): string
    {
        if (empty($query)) {
            return '';
        }
        return '?' . http_build_query($query);
    }

    protected function shouldRetry(Response $resp): bool
    {
        $code = $resp->status();
        if ($code === 429) {
            return true;
        }
        // Retry on 5xx server errors
        return $code >= 500 && $code <= 599;
    }

    protected function safeBodyPreview(Response $resp, int $maxLen = 256): string
    {
        $raw = (string) $resp->body();
        // Avoid logging secrets; truncate and strip newlines
        $raw = preg_replace('/\s+/', ' ', $raw);
        return mb_substr($raw, 0, $maxLen);
    }

    /**
     * Helper to add or override headers (e.g., auth) at runtime.
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);
        return $clone;
    }

    /**
     * Helper to set cookies, e.g., Proxmox ticket auth.
     * Usage: $client->withCookies(['PVEAuthCookie' => $ticket])
     */
    public function withCookies(array $cookies): self
    {
        $clone = clone $this;
        $clone->headers['_cookies'] = $cookies;
        return $clone;
    }

    /**
     * Factory convenience to build client from a generic options array.
     * Expected keys:
     *  - base_url (string)
     *  - headers (array)
     *  - verify_tls (bool)
     *  - timeout_ms (int)
     *  - proxy (string|null)
     *  - max_retries (int)
     *  - retry_initial_delay_ms (int)
     */
    public static function fromOptions(array $options): self
    {
        return new self($options);
    }
}
Usage examples
•	Pure Storage vendor client
use App\ApiClients\DeviceHttpClient;

$client = new DeviceHttpClient([
    'base_url' => 'https://array.example/api/2.26',
    'headers' => ['X-Auth-Token' => $token],
    'verify_tls' => true,
    'timeout_ms' => 5000,
]);

$array = $client->get('arrays');
$ports = $client->get('network-interfaces');•	Proxmox token auth

$client = new DeviceHttpClient([
    'base_url' => 'https://pve.example:8006/api2/json',
    'headers' => ['Authorization' => "PVEAPIToken={$user}@{$realm}!{$tokenId}={$secret}"],
    'verify_tls' => true,
    'timeout_ms' => 5000,
]);

$status = $client->get("nodes/{$node}/status");•	Proxmox ticket auth (cookie + CSRF header)

$loginClient = new DeviceHttpClient([
    'base_url' => 'https://pve.example:8006',
    'verify_tls' => true,
    'timeout_ms' => 5000,
]);

$login = $loginClient->post('api2/json/access/ticket', [
    'username' => "{$user}@{$realm}",
    'password' => $password,
]);

$data = $login['data'] ?? [];
$ticket = $data['ticket'] ?? '';
$csrf = $data['CSRFPreventionToken'] ?? '';

$api = new DeviceHttpClient([
    'base_url' => 'https://pve.example:8006/api2/json',
    'headers' => $csrf ? ['CSRFPreventionToken' => $csrf] : [],
    'verify_tls' => true,
    'timeout_ms' => 5000,
]);
$api = $api->withCookies(['PVEAuthCookie' => $ticket]);

$status = $api->get("nodes/{$node}/status");

Notes
•	It’s intentionally vendor-neutral. Your vendor clients (FlashArrayApiClient, ProxmoxApiClient) should compose this and provide semantic helpers like getArray(), getNodeStatus(), etc.
•	Retries/backoff are conservative; adjust max_retries and retry_initial_delay_ms if your APIs rate-limit more aggressively.
•	Avoid logging secrets; this client only logs a truncated body on errors and never headers.
If you prefer to keep this transport under LibreNMS/HTTP, rename the namespace and path to LibreNMS/HTTP/DeviceHttpClient.php and adjust imports accordingly.
<?php
namespace App\ApiClients\Proxmox;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;

class ProxmoxApiClient
{
    protected string $base;
    protected int $timeout;
    protected bool $verifyTls;
    protected ?string $proxy;
    protected string $authType;
    protected array $headers = [];
    protected array $cookies = [];

    public function __construct(Device $device)
    {
        $a = $device->attribs ?? [];
        $this->base = rtrim($a['proxmox_base_url'] ?? '', '/');
        $this->timeout = (int)($a['proxmox_timeout_ms'] ?? 5000);
        $this->verifyTls = (bool)($a['proxmox_verify_tls'] ?? true);
        $this->proxy = $a['proxmox_proxy'] ?? null;
        $this->authType = $a['proxmox_auth_type'] ?? 'token';

        if ($this->authType === 'token') {
            $user = $a['proxmox_token_user'] ?? '';
            $tokenid = $a['proxmox_token_id'] ?? '';
            $secret = !empty($a['proxmox_token_enc']) ? Crypt::decryptString($a['proxmox_token_enc']) : '';
            $this->headers['Authorization'] = "PVEAPIToken={$user}!{$tokenid}={$secret}";
        } else {
            $this->login($a); // sets cookie/header
        }
    }

    protected function login(array $a): void
    {
        $user = $a['proxmox_username'] ?? '';
        $password = !empty($a['proxmox_password_enc']) ? Crypt::decryptString($a['proxmox_password_enc']) : '';
        $resp = Http::timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls])
            ->post($this->base . '/api2/json/access/ticket', ['username' => $user, 'password' => $password]);

        if ($resp->failed()) {
            throw new \RuntimeException('Proxmox login failed: ' . $resp->status());
        }
        $data = $resp->json()['data'] ?? [];
        $ticket = $data['ticket'] ?? '';
        $csrf = $data['CSRFPreventionToken'] ?? '';
        $this->cookies = ['PVEAuthCookie' => $ticket];
        if ($csrf) {
            $this->headers['CSRFPreventionToken'] = $csrf;
        }
    }

    protected function request(string $path): array
    {
        $req = Http::withHeaders($this->headers)
            ->withCookies($this->cookies, parse_url($this->base, PHP_URL_HOST))
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls]);
        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        $resp = $req->get($this->base . '/api2/json/' . ltrim($path, '/'));
        if ($resp->failed()) {
            throw new \RuntimeException('Proxmox GET ' . $path . ' failed: ' . $resp->status());
        }
        $json = $resp->json();
        return is_array($json) ? $json : [];
    }

    public function getNodes(): array { return $this->request('nodes'); }
    public function getNodeStatus(string $node): array { return $this->request("nodes/{$node}/status"); }
    public function getNodeNetwork(string $node): array { return $this->request("nodes/{$node}/network"); }
    public function getClusterStatus(): array { return $this->request('cluster/status'); }
}
LibreNMS/HTTP/DeviceHttpClient.php and adjust imports accordingly.

Here’s a comprehensive checklist of files to add (and existing files to modify) to your LibreNMS instance to deliver REST API discovery/polling for Pure Storage and Proxmox, using the app/ApiClients convention and the improved Modules framework. I’ve grouped them by area so you can track work and PRs cleanly.
New files to add
App-side HTTP clients and vendor clients
•	app/ApiClients/DeviceHttpClient.php
o	Generic HTTP transport wrapper (base URL, headers, TLS verify, timeout, proxy, retries/backoff, JSON parsing). Used by vendor clients.
•	app/ApiClients/PureStorage/FlashArrayApiClient.php
o	Pure FlashArray client. Helpers like getArray(), getArrayPerformance(), getHosts(), getHardware(), getNetworkInterfaces(), getNetworkPerformance(), getPortDetails(), getDrives(), getVolumes(), getVolumePerformance.
•	app/ApiClients/Proxmox/ProxmoxApiClient.php
o	Proxmox client. Handles token or ticket auth. Helpers like getNodes(), getNodeStatus(node), getNodeNetwork(node), getNodeStorage(node), getClusterStatus(), getClusterResources().
Device attribute accessors (optional but recommended)
•	LibreNMS/Util/ DeviceApiSettings.php
o	Typed helpers to read per-device REST attributes, decrypt secrets, and supply defaults (base URL, auth type, token/password, headers, timeout, TLS verify, proxy, vendor tag).
Normalization helpers
•	LibreNMS/Modules/Support/RestNormalizers.php
o	Pure Storage mappings:
	normalizePureArraySensors(array, perf, hardware)
	normalizePurePorts(interfaces, perf)
	normalizePurePortOptics(portDetails)
	normalizePureHardwareInventory(hardware, drives)
	normalizePureHosts(hosts)
o	Proxmox mappings:
	normalizeProxmoxNodeStatus(status) → sensors, processors, mempools
	normalizeProxmoxNodeNetwork(interfaces) → ports
	normalizeProxmoxNodeStorage(storage) → inventory and storage sensors
	normalizeProxmoxClusterStatus(clusterStatus) → sensors and inventory
	normalizeProxmoxClusterResources(clusterResources) → optional inventory/sensors
Module integrations (augment existing module classes with REST branches)
•	LibreNMS/Modules/Sensors.php
o	Add REST branch:
	Pure: array performance/env/hosts into sensors
	Proxmox node: uptime, load, CPU% sensors; Proxmox cluster: quorum, node online sensors
•	LibreNMS/Modules/Ports.php
o	Add REST branch:
	Pure: network interfaces + perf into ports and counters
	Proxmox: node NICs into ports (status/speed; counters if available)
•	LibreNMS/Modules/Mempools.php
o	Add REST branch:
	Proxmox node memory/swap pools from node status
•	LibreNMS/Modules/Processors.php
o	Add REST branch:
	Proxmox node CPU usage percent from node status
•	LibreNMS/Modules/EntityPhysical.php or LibreNMS/Modules/Inventory.php
o	Add REST branch:
	Pure: controllers/chassis/drives/hosts as inventory components
	Proxmox: node component (node devices) and cluster + node components (cluster devices)
Device OS definition (optional, recommended for branding)
•	includes/definitions/purestorage.yaml
o	Identify Pure devices and set module defaults/branding for FlashArray.
•	includes/definitions/proxmox.yaml
o	Identify Proxmox nodes; optional if you rely solely on REST vendor selection.
UI and controller changes (to enable per-device configuration)
•	resources/views/device/edit.blade.php
o	Add a “REST API” configuration section:
	Global enable toggle
	Vendor selector (purestorage, proxmox)
	Base URL
	Auth type selector (apikey/bearer/basic for Pure; token/ticket for Proxmox)
	Credential inputs (masked): token/key, username/password, token user@realm and token id for Proxmox
	Extra headers (JSON), TLS verify checkbox, timeout, proxy
•	app/Http/Requests/UpdateDeviceRequest.php
o	Add validation rules for REST fields (URL format, auth type, required credentials, numeric timeouts, JSON headers).
•	app/Http/Controllers/DeviceController.php
o	Persist device_attributes and encrypt sensitive values via Crypt::encryptString():
	Pure: rest_enabled, rest_vendor, rest_base_url, rest_auth_type, rest_token_enc, rest_headers, rest_verify_tls, rest_timeout_ms, rest_proxy
	Proxmox: rest_enabled, rest_vendor, proxmox_base_url, proxmox_auth_type, proxmox_token_user, proxmox_token_id, proxmox_token_enc, proxmox_username, proxmox_password_enc, proxmox_verify_tls, proxmox_timeout_ms, proxmox_proxy
Validation tooling (optional but useful)
•	validate.php
o	Add checks to warn if a device has rest_enabled but is missing required fields (base URL, token/user credentials), or TLS verification is disabled.
Tests and fixtures
•	tests/Feature/ApiClients/DeviceHttpClientTest.php
o	Http::fake() coverage for GET/POST, retries/backoff, JSON parsing, error handling.
•	tests/Feature/ApiClients/PureStorage/FlashArrayApiClientTest.php
o	Fake endpoints and assert headers, parsing, and method outputs.
•	tests/Feature/ApiClients/Proxmox/ProxmoxApiClientTest.php
o	Ticket login flow, token header flow, and request behavior.
•	tests/Unit/RestNormalizersPureTest.php
o	Unit tests for Pure normalizers using fixtures (arrays.json, array_performance.json, hosts.json, hardware.json, network-interfaces.json, network-performance.json, port_details.json, drives.json).
•	tests/Unit/RestNormalizersProxmoxTest.php
o	Unit tests for Proxmox normalizers using fixtures (cluster-status.json, cluster-resources.json, nodes-status.json, node-storage.json, node-network.json).
•	tests/Integration/RestDiscoveryPollingPureTest.php
o	Seed a Pure device with rest_enabled and attributes; fake HTTP; run discovery/poll; assert sensors, ports, inventory DB rows.
•	tests/Integration/RestDiscoveryPollingProxmoxTest.php
o	Seed a Proxmox node and a Proxmox cluster device; fake HTTP; run discovery/poll; assert processors, mempools, sensors, inventory.
Existing files to modify
•	LibreNMS/Modules/Sensors.php
o	Insert REST branches calling vendor clients and RestNormalizers; reuse existing upsert/update helpers.
•	LibreNMS/Modules/Ports.php
o	Insert REST branches for Pure and Proxmox; reuse existing upsert/update logic and RRD updates.
•	LibreNMS/Modules/Mempools.php
•	LibreNMS/Modules/Processors.php
•	LibreNMS/Modules/EntityPhysical.php or LibreNMS/Modules/Inventory.php
o	Add REST paths to create inventory components.
•	resources/views/device/edit.blade.php
o	Add new UI fields section(s) for REST configuration.
•	app/Http/Requests/UpdateDeviceRequest.php
o	Extend validation rules.
•	app/Http/Controllers/DeviceController.php
o	Persist encrypted device_attributes and handle masking semantics (only replace secrets when a new value is submitted).
•	includes/definitions/purestorage.yaml and includes/definitions/proxmox.yaml
o	Add OS definitions (optional).
•	validate.php
o	Add optional checks/warnings for REST configuration completeness.
Notes and guidance
•	No new tables are needed; use device_attributes for configuration and existing tables (ports, sensors, mempools, processors, inventory) for data.
•	Keep secrets encrypted at rest and never rendered in plaintext; show masked indicators in UI and replace only on user input.
•	Modules should choose transport per device per module:
o	If rest_enabled && vendor is supported, use the vendor client; else fall back to SNMP.
o	If a REST call fails and SNMP exists, optionally fallback to SNMP for critical modules.
•	Keep orchestration unchanged (discovery.php, poller.php, wrapper scripts); modules handle REST at runtime.
If you want, I can generate the initial skeletons for FlashArrayApiClient, ProxmoxApiClient, and RestNormalizers with the exact method signatures you plan to use, plus a patch stub for Sensors.php and Ports.php that shows where the REST branches plug into the existing module flow.
Yes — you can avoid hardcoding vendor branches in every Module by introducing a small, generic “API client registry” and capabilities model. Modules then talk to “the device API” via a common interface, and the registry selects the right vendor client at runtime based on device attributes or a lightweight probe. This keeps Modules vendor-agnostic and makes adding new vendors a drop-in action under app/ApiClients.
Design overview
•	Define a common interface for all device API clients that declares what data they can provide (sensors, ports, mempools, processors, inventory) and methods to fetch it.
•	Implement a registry/factory that:
o	Looks at the device’s attributes (rest_vendor, base_url) and returns the matching client.
o	Optionally runs a quick probe to auto-detect a vendor if rest_vendor isn’t set.
•	Each vendor client implements the interface and internally uses its own endpoints and normalizers.
•	Modules call the interface generically; no vendor-specific branching in Modules.
Key components
1.	Common interface
File: app/ApiClients/Contracts/DeviceApiClientInterface.php
•	Methods reflect capabilities, return normalized data structures expected by Modules:
o	supports(Device $device): bool
o	capabilities(): array of strings, e.g., ['sensors','ports','mempools','processors','inventory']
o	fetchSensors(Device $device): array
o	fetchPorts(Device $device): array
o	fetchMempools(Device $device): array
o	fetchProcessors(Device $device): array
o	fetchInventory(Device $device): array
•	You can make methods optional by returning empty arrays if not supported by a vendor.
2.	Client registry/factory
File: app/ApiClients/DeviceApiClientFactory.php
•	Responsibilities:
o	Maintain a list of known client classes (PureStorage\FlashArrayApiClient, Proxmox\ProxmoxApiClient, etc.).
o	Resolve the client by:
	First, device attribute rest_vendor (fast path).
	Else, call supports($device) on each client until one returns true (probe path).
o	Cache the resolved client class per device_id to avoid repeated probes.
Example:
•	registerClients(): returns an array of class names
•	make(Device $device): returns an instance implementing DeviceApiClientInterface or null
3.	Vendor clients implement the interface
•	app/ApiClients/PureStorage/FlashArrayApiClient.php implements DeviceApiClientInterface
•	app/ApiClients/Proxmox/ProxmoxApiClient.php implements DeviceApiClientInterface
•	Each client:
o	Reads device attributes via DeviceApiSettings
o	Uses DeviceHttpClient internally
o	Implements fetchSensors(), fetchPorts(), etc., using RestNormalizers
o	supports($device) returns true if rest_vendor matches or the base_url pattern matches or a quick probe succeeds
4.	Modules become vendor-agnostic
•	LibreNMS/Modules/Sensors.php
•	LibreNMS/Modules/Ports.php
•	LibreNMS/Modules/Mempools.php
•	LibreNMS/Modules/Processors.php
•	LibreNMS/Modules/Inventory.php
Pattern:
•	If DeviceApiSettings::restEnabled($device):
o	$client = DeviceApiClientFactory::make($device)
o	If $client and 'sensors' in $client->capabilities():
	discovery: $this->upsertSensors($device, $client->fetchSensors($device))
	polling: $this->updateSensorValues($device, $client->fetchSensors($device))
•	Similar calls for ports/mempools/processors/inventory, guarded by capabilities.
This eliminates vendor-specific if/else blocks in modules.
5.	Optional auto-discovery of vendor
•	supports($device) implementation examples:
o	PureStorage client:
	Check rest_vendor === 'purestorage' OR base_url matches /api/2.x/ path OR a HEAD/GET to /api/2.x/arrays succeeds
o	Proxmox client:
	Check rest_vendor === 'proxmox' OR base_url ends with /api2/json OR GET /api2/json/nodes or /cluster/status succeeds
•	Factory caches the result per device to keep performance predictable.
Code sketch highlights
Interface
•	app/ApiClients/Contracts/DeviceApiClientInterface.phpnamespace App\ApiClients\Contracts;

use App\Models\Device;

interface DeviceApiClientInterface
{
    public function supports(Device $device): bool;
    public function capabilities(): array; // e.g., ['sensors','ports','mempools','processors','inventory']

    public function fetchSensors(Device $device): array;
    public function fetchPorts(Device $device): array;
    public function fetchMempools(Device $device): array;
    public function fetchProcessors(Device $device): array;
    public function fetchInventory(Device $device): array;
}Factory
•	app/ApiClients/DeviceApiClientFactory.php

namespace App\ApiClients;

use App\Models\Device;
use App\ApiClients\Contracts\DeviceApiClientInterface;
use Illuminate\Support\Facades\Log;

class DeviceApiClientFactory
{
    protected static array $clientClasses = [
        \App\ApiClients\PureStorage\FlashArrayApiClient::class,
        \App\ApiClients\Proxmox\ProxmoxApiClient::class,
        // Add new vendors here only once
    ];

    protected static array $cache = []; // device_id => class

    public static function make(Device $device): ?DeviceApiClientInterface
    {
        $id = $device->device_id ?? $device->device_id ?? $device->device_id;
        if (isset(self::$cache[$id])) {
            $class = self::$cache[$id];
            return new $class($device);
        }

        // Fast path: vendor attribute
        $vendor = $device->attribs['rest_vendor'] ?? null;
        if ($vendor) {
            foreach (self::$clientClasses as $class) {
                if (method_exists($class, 'VENDOR') && $class::VENDOR === $vendor) {
                    self::$cache[$id] = $class;
                    return new $class($device);
                }
            }
        }

        // Probe path: ask each client if it supports the device
        foreach (self::$clientClasses as $class) {
            $client = new $class($device);
            try {
                if ($client->supports($device)) {
                    self::$cache[$id] = $class;
                    return $client;
                }
            } catch (\Throwable $e) {
                Log::debug("API client probe failed for $class on device {$id}: " . $e->getMessage());
            }
        }

        return null;
    }
}

Module usage (example for Sensors)

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;

public function discover(Device $device): void
{
    if (DeviceApiSettings::restEnabled($device)) {
        $client = DeviceApiClientFactory::make($device);
        if ($client && in_array('sensors', $client->capabilities(), true)) {
            $sensors = $client->fetchSensors($device);
            if (!empty($sensors)) {
                $this->upsertSensors($device, $sensors);
                return;
            }
        }
    }
    $this->discoverViaSnmp($device);
}

public function poll(Device $device): void
{
    if (DeviceApiSettings::restEnabled($device)) {
        $client = DeviceApiClientFactory::make($device);
        if ($client && in_array('sensors', $client->capabilities(), true)) {
            $sensors = $client->fetchSensors($device);
            if (!empty($sensors)) {
                $this->updateSensorValues($device, $sensors);
                return;
            }
        }
    }
    $this->pollViaSnmp($device);
}
Vendor clients implement fetch* generically
•	PureStorage client:
o	capabilities(): ['sensors','ports','inventory']
o	fetchSensors(): combine array_performance, hardware, hosts via RestNormalizers
o	fetchPorts(): network-interfaces and network-performance mappings
o	fetchInventory(): hardware+drives+hosts components
•	Proxmox client:
o	capabilities(): ['sensors','ports','mempools','processors','inventory']
o	fetchSensors(): node or cluster sensors
o	fetchPorts(): node NICs
o	fetchMempools(): from node status
o	fetchProcessors(): from node status
o	fetchInventory(): node/cluster components
Benefits
•	Modules stay clean and future-proof; you add new vendors by implementing a client and registering it once in the factory.
•	Adding/removing vendors doesn’t require touching Modules.
•	You can unit-test each vendor client in isolation.
•	You can extend capabilities later without changing module code.
Considerations
•	Performance: probing multiple clients should be quick; prefer attribute-based vendor selection but keep probe fallback for flexibility.
•	Caching: cache resolved client class per device_id in memory; optionally persist a “rest_vendor” attribute during probe to avoid future probes.
•	Error handling: client methods should catch and normalize HTTP errors, returning empty arrays when endpoints fail, so modules can fall back to SNMP smoothly.
If you want, I can provide concrete implementations of FlashArrayApiClient and ProxmoxApiClient that implement DeviceApiClientInterface with the fetch* methods wired to your JSON schemas, so you can plug them into the factory and immediately remove vendor-specific branching from Modules.
Yes. You can integrate the vendor agnostic factory only into the Modules that exist in your tree. The minimal work is:
•	Add the common interface and factory once.
•	In each existing module (Ports, Mempools, Processors, Inventory or EntityPhysical), insert a REST pre branch that:
o	Checks if REST is enabled for the device.
o	Resolves the appropriate client via the factory.
o	Uses client capabilities to decide whether to fetch and upsert/update data.
o	Falls back to the existing SNMP path if nothing is fetched.
Below are compact drop in templates for the modules that typically exist. If your file names differ slightly, paste the same pattern into their discover() and poll() methods.
Prereqs to add once
•	app/ApiClients/Contracts/DeviceApiClientInterface.php
•	app/ApiClients/DeviceApiClientFactory.php
•	LibreNMS/Util/DeviceApiSettings.php (helper to read per device REST attributes)
Module: LibreNMS/Modules/Ports.php
Insert at top:
•	use App\ApiClients\DeviceApiClientFactory;
•	use LibreNMS\Util\DeviceApiSettings;
Add at the beginning of discover(Device $device):

```File Path: `routes/web.php`
Modification Time: Sat Oct 25 21:18:20 2025

```php
<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\Ajax;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertRuleController;
use App\Http\Controllers\AlertRuleTemplateController;
use App\Http\Controllers\AlertTransportController;
use App\Http\Controllers\Auth;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardWidgetController;
use App\Http\Controllers\Device;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceGroupController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\Install;
use App\Http\Controllers\LegacyController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\Maps;
use App\Http\Controllers\Maps\CustomMapBackgroundController;
use App\Http\Controllers\Maps\CustomMapController;
use App\Http\Controllers\Maps\CustomMapDataController;
use App\Http\Controllers\Maps\CustomMapListController;
use App\Http\Controllers\Maps\CustomMapNodeImageController;
use App\Http\Controllers\Maps\DeviceDependencyController;
use App\Http\Controllers\NacController;
use App\Http\Controllers\OuiLookupController;
use App\Http\Controllers\OutagesController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PluginLegacyController;
use App\Http\Controllers\PluginPageController;
use App\Http\Controllers\PluginSettingsController;
use App\Http\Controllers\PollerController;
use App\Http\Controllers\PollerGroupController;
use App\Http\Controllers\PollerSettingsController;
use App\Http\Controllers\PortController;
use App\Http\Controllers\PortGroupController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\Search\PortSecuritySearchController;
use App\Http\Controllers\Select;
use App\Http\Controllers\SensorController;
use App\Http\Controllers\ServiceTemplateController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Table;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPreferencesController;
use App\Http\Controllers\ValidateController;
use App\Http\Controllers\Widgets;
use App\Http\Controllers\WidgetSettingsController;
use App\Http\Controllers\WirelessSensorController;
use App\Http\Middleware\AuthenticateGraph;
use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Auth
AuthFacade::routes(['register' => false, 'reset' => false, 'verify' => false]);

// Socialite
Route::prefix('auth')->name('socialite.')->group(function (): void {
    Route::post('{provider}/redirect', [SocialiteController::class, 'redirect'])->name('redirect');
    Route::match(['get', 'post'], '{provider}/callback', [SocialiteController::class, 'callback'])->name('callback');
    Route::get('{provider}/metadata', [SocialiteController::class, 'metadata'])->name('metadata');
});

Route::get('graph/{path?}', GraphController::class)
    ->where('path', '.*')
    ->middleware(['web', AuthenticateGraph::class])->name('graph');

// WebUI
Route::middleware(['auth'])->group(function (): void {
    // pages
    Route::post('alert/{alert}/ack', [AlertController::class, 'ack'])->name('alert.ack');
    Route::resource('device-groups', DeviceGroupController::class);
    Route::any('inventory', App\Http\Controllers\InventoryController::class)->name('inventory');
    Route::get('inventory/purge', [App\Http\Controllers\InventoryController::class, 'purge'])->name('inventory.purge');
    Route::get('outages', [OutagesController::class, 'index'])->name('outages');
    Route::resource('port', PortController::class)->only('update');
    Route::get('vlans', [App\Http\Controllers\VlansController::class, 'index'])->name('vlans.index');
    Route::prefix('poller')->group(function (): void {
        Route::get('', [PollerController::class, 'pollerTab'])->name('poller.index');
        Route::get('log', [PollerController::class, 'logTab'])->name('poller.log');
        Route::get('groups', [PollerController::class, 'groupsTab'])->name('poller.groups');
        Route::get('settings', [PollerController::class, 'settingsTab'])->name('poller.settings');
        Route::get('performance', [PollerController::class, 'performanceTab'])->name('poller.performance');
        Route::resource('{id}/settings', PollerSettingsController::class, ['as' => 'poller'])->only(['update', 'destroy']);
    });
    Route::prefix('services')->name('services.')->group(function (): void {
        Route::resource('templates', ServiceTemplateController::class);
        Route::post('templates/applyAll', [ServiceTemplateController::class, 'applyAll'])->name('templates.applyAll');
        Route::post('templates/apply/{template}', [ServiceTemplateController::class, 'apply'])->name('templates.apply');
        Route::post('templates/remove/{template}', [ServiceTemplateController::class, 'remove'])->name('templates.remove');
    });
    Route::get('locations', [LocationController::class, 'index']);
    Route::resource('preferences', UserPreferencesController::class)->only('index', 'store');
    Route::resource('users', UserController::class);
    Route::get('about', [AboutController::class, 'index'])->name('about');
    Route::delete('reporting', [AboutController::class, 'clearReportingData'])->name('reporting.clear');
    Route::get('authlog', [UserController::class, 'authlog']);
    Route::get('overview', [OverviewController::class, 'index'])->name('overview');
    Route::get('/', [OverviewController::class, 'index'])->name('home');
    Route::view('vminfo', 'vminfo');

    Route::get('nac', [NacController::class, 'index']);

    // Device Tabs
    Route::middleware('can:admin')->group(function (): void {
        Route::get('/device/{device}/edit', [Device\EditDeviceController::class, 'index'])->name('device.edit');
        Route::put('/device/{device}/edit', [Device\EditDeviceController::class, 'update'])->name('device.edit.update');
        Route::post('/device/{device}/rediscover', [DeviceController::class, 'rediscover'])->name('device.rediscover');
        Route::post('/device/{device}/test-api-connection', [Device\EditDeviceController::class, 'testApiConnection'])->name('device.test-api-connection');
        Route::post('/device/{device}/reset-circuit-breaker', [Device\EditDeviceController::class, 'resetCircuitBreaker'])->name('device.reset-circuit-breaker');
    });

    Route::prefix('device/{device}')->name('device.')->group(function (): void {
        Route::redirect('logs', 'logs/eventlog')->name('logs');
        Route::get('logs/eventlog', Device\Tabs\EventlogController::class)->name('eventlog');
        Route::get('logs/graylog', Device\Tabs\GraylogController::class)->name('graylog');
        Route::get('logs/outages', Device\Tabs\OutagesController::class)->name('outages');
        Route::get('logs/syslog', Device\Tabs\SyslogController::class)->name('syslog');
        Route::get('popup', \App\Http\Controllers\DevicePopupController::class)->name('popup');
        Route::put('notes', [Device\Tabs\NotesController::class, 'update'])->name('notes.update');
        Route::put('module/{module}', [Device\Tabs\ModuleController::class, 'update'])->name('module.update');
        Route::delete('module/{module}', [Device\Tabs\ModuleController::class, 'delete'])->name('module.delete');
    });

    // fallback device routes
    Route::match(['get', 'post'], 'device/{device}/{tab?}/{vars?}', [DeviceController::class, 'index'])
        ->name('device')->where('vars', '.*');

    // Maps
    Route::get('fullscreenmap', [Maps\FullscreenMapController::class, 'fullscreenMap']);
    Route::get('availability-map', [Maps\AvailabilityMapController::class, 'availabilityMap']);
    Route::get('map/{vars?}', [Maps\NetMapController::class, 'netMap']);
    Route::prefix('maps')->group(function (): void {
        Route::resource('custom', CustomMapController::class, ['as' => 'maps'])
            ->parameters(['custom' => 'map'])->except('create');
        Route::post('custom/{map}/clone', [CustomMapController::class, 'clone'])->name('maps.custom.clone');
        Route::get('custom/{map}/background', [CustomMapBackgroundController::class, 'get'])->name('maps.custom.background');
        Route::post('custom/{map}/background', [CustomMapBackgroundController::class, 'save'])->name('maps.custom.background.save');
        Route::get('custom/{map}/data', [CustomMapDataController::class, 'get'])->name('maps.custom.data');
        Route::post('custom/{map}/data', [CustomMapDataController::class, 'save'])->name('maps.custom.data.save');
        Route::get('customlist', [CustomMapListController::class, 'index'])->name('maps.custom.list');
        Route::get('devicedependency', [DeviceDependencyController::class, 'dependencyMap']);
        Route::post('getdevices', [Maps\MapDataController::class, 'getDevices'])->name('maps.getdevices');
        Route::post('getdevicelinks', [Maps\MapDataController::class, 'getDeviceLinks'])->name('maps.getdevicelinks');
        Route::post('getgeolinks', [Maps\MapDataController::class, 'getGeographicLinks'])->name('maps.getgeolinks');
        Route::post('getservices', [Maps\MapDataController::class, 'getServices'])->name('maps.getservices');
        Route::get('nodeimage', [CustomMapNodeImageController::class, 'index'])->name('maps.nodeimage.index');
        Route::post('nodeimage', [CustomMapNodeImageController::class, 'store'])->name('maps.nodeimage.store');
        Route::delete('nodeimage/{image}', [CustomMapNodeImageController::class, 'destroy'])->name('maps.nodeimage.destroy');
        Route::get('nodeimage/{image}', [CustomMapNodeImageController::class, 'show'])->name('maps.nodeimage.show');
        Route::post('nodeimage/{image}', [CustomMapNodeImageController::class, 'update'])->name('maps.nodeimage.update');
    });
    Route::get('maps/devicedependency', [DeviceDependencyController::class, 'dependencyMap']);

    // dashboard
    Route::resource('dashboard', DashboardController::class)->except(['create', 'edit']);
    Route::post('dashboard/{dashboard}/copy', [DashboardController::class, 'copy'])->name('dashboard.copy');
    Route::post('dashboard/{dashboard}/widgets', [DashboardWidgetController::class, 'add'])->name('dashboard.widget.add');
    Route::delete('dashboard/{dashboard}/widgets', [DashboardWidgetController::class, 'clear'])->name('dashboard.widget.clear');
    Route::put('dashboard/{dashboard}/widgets', [DashboardWidgetController::class, 'update'])->name('dashboard.widget.update');
    Route::delete('dashboard/widgets/{widget}', [DashboardWidgetController::class, 'remove'])->name('dashboard.widget.remove');
    Route::put('dashboard/widgets/{widget}', [WidgetSettingsController::class, 'update'])->name('dashboard.widget.settings');

    Route::get('tool/oui-lookup', OuiLookupController::class)->name('tool.oui-lookup');

    // Push notifications
    Route::prefix('push')->group(function (): void {
        Route::get('token', [PushNotificationController::class, 'token'])->name('push.token');
        Route::get('key', [PushNotificationController::class, 'key'])->name('push.key');
        Route::post('register', [PushNotificationController::class, 'register'])->name('push.register');
        Route::post('unregister', [PushNotificationController::class, 'unregister'])->name('push.unregister');
    });

    // admin pages
    Route::middleware('can:admin')->group(function (): void {
        Route::get('settings/{tab?}/{section?}', [SettingsController::class, 'index'])->name('settings');
        Route::put('settings/{name}', [SettingsController::class, 'update'])->name('settings.update');
        Route::delete('settings/{name}', [SettingsController::class, 'destroy'])->name('settings.destroy');

        Route::post('alert/transports/{transport}/test', [AlertTransportController::class, 'test'])->name('alert.transports.test');
        Route::resource('alert-rule', AlertRuleController::class)->only(['show', 'store', 'update', 'destroy']);
        Route::put('alert-rule/{alert_rule}/toggle', [AlertRuleController::class, 'toggle'])->name('alert-rule.toggle');
        Route::get('alert-rule-from-template/{template_id}', [AlertRuleTemplateController::class, 'template'])->name('alert-rule-template');
        Route::get('alert-rule-from-rule/{alert_rule}', [AlertRuleTemplateController::class, 'rule'])->name('alert-rule-template.rule');

        Route::get('plugin/settings', App\Http\Controllers\PluginAdminController::class)->name('plugin.admin');
        Route::get('plugin/settings/{plugin:plugin_name}', PluginSettingsController::class)->name('plugin.settings');
        Route::post('plugin/settings/{plugin:plugin_name}', [PluginSettingsController::class, 'update'])->name('plugin.update');

        Route::resource('port-groups', PortGroupController::class);
        Route::get('validate', [ValidateController::class, 'index'])->name('validate');
        Route::get('validate/results', [ValidateController::class, 'runValidation'])->name('validate.results');
        Route::post('validate/fix', [ValidateController::class, 'runFixer'])->name('validate.fix');
    });    Route::get('plugin', [PluginLegacyController::class, 'redirect']);
    Route::redirect('plugin/view=admin', '/plugin/admin');
    Route::get('plugin/p={pluginName}', [PluginLegacyController::class, 'redirect']);
    Route::any('plugin/v1/{plugin:plugin_name}/{other?}', PluginLegacyController::class)->where('other', '(.*)')->name('plugin.legacy');
    Route::get('plugin/{plugin:plugin_name}', PluginPageController::class)->name('plugin.page');

    // Search pages
    Route::get('search/secureports', [PortSecuritySearchController::class, 'index'])->name('search.secureports');

    Route::get('health/{metric?}/{legacyview?}', [SensorController::class, 'index'])->name('sensor.index');
    Route::get('wireless/{metric}/{legacyview?}', [WirelessSensorController::class, 'index'])->name('wireless.index');

    // old route redirects
    Route::permanentRedirect('poll-log', 'poller/log');

    // Two Factor Auth
    Route::prefix('2fa')->group(function (): void {
        Route::get('', [Auth\TwoFactorController::class, 'showTwoFactorForm'])->name('2fa.form');
        Route::post('', [Auth\TwoFactorController::class, 'verifyTwoFactor'])->name('2fa.verify');
        Route::post('add', [Auth\TwoFactorController::class, 'create'])->name('2fa.add');
        Route::post('cancel', [Auth\TwoFactorController::class, 'cancelAdd'])->name('2fa.cancel');
        Route::post('remove', [Auth\TwoFactorController::class, 'destroy'])->name('2fa.remove');

        Route::post('{user}/unlock', [Auth\TwoFactorManagementController::class, 'unlock'])->name('2fa.unlock');
        Route::delete('{user}', [Auth\TwoFactorManagementController::class, 'destroy'])->name('2fa.delete');
    });

    // Ajax routes
    Route::prefix('ajax')->group(function (): void {
        // page ajax controllers
        Route::resource('location', LocationController::class)->only('update', 'destroy');
        Route::resource('pollergroup', PollerGroupController::class)->only('destroy');
        // misc ajax controllers
        Route::get('search/bgp', Ajax\BgpSearchController::class);
        Route::get('search/device', Ajax\DeviceSearchController::class);
        Route::get('search/port', Ajax\PortSearchController::class);
        Route::post('set_map_group', [Ajax\AvailabilityMapController::class, 'setGroup']);
        Route::post('set_map_view', [Ajax\AvailabilityMapController::class, 'setView']);
        Route::post('set_resolution', [Ajax\SessionController::class, 'resolution']);
        Route::post('set_style', [Ajax\SessionController::class, 'style']);
        Route::get('netcmd', [Ajax\NetCommand::class, 'run']);
        Route::post('ripe/raw', [Ajax\RipeNccApiController::class, 'raw']);
        Route::get('snmp/capabilities', Ajax\SnmpCapabilities::class)->name('snmp.capabilities');

        Route::get('settings/list', [SettingsController::class, 'listAll'])->name('settings.list');

        // js select2 data controllers
        Route::prefix('select')->group(function (): void {
            Route::get('alert-transport', Select\AlertTransportController::class)->name('ajax.select.alert-transport');
            Route::get('alert-transport-group', Select\AlertTransportGroupController::class)->name('ajax.select.alert-transport-group');
            Route::get('alert-transports-groups', Select\AlertTransportsAndGroupsController::class)->name('ajax.select.alert-transports-groups');
            Route::get('application', Select\ApplicationController::class)->name('ajax.select.application');
            Route::get('bill', Select\BillController::class)->name('ajax.select.bill');
            Route::get('custom-map', Select\CustomMapController::class)->name('ajax.select.custom-map');
            Route::get('custom-map-menu-group', Select\CustomMapMenuGroupController::class)->name('ajax.select.custom-map-menu-group');
            Route::get('dashboard', Select\DashboardController::class)->name('ajax.select.dashboard');
            Route::get('device', Select\DeviceController::class)->name('ajax.select.device');
            Route::get('devices-groups-locations', Select\DevicesGroupsAndLocationsController::class)->name('ajax.select.devices-groups-locations');
            Route::get('device-field', Select\DeviceFieldController::class)->name('ajax.select.device-field');
            Route::get('device-group', Select\DeviceGroupController::class)->name('ajax.select.device-group');
            Route::get('port-group', Select\PortGroupController::class)->name('ajax.select.port-group');
            Route::get('eventlog', Select\EventlogController::class)->name('ajax.select.eventlog');
            Route::get('graph', Select\GraphController::class)->name('ajax.select.graph');
            Route::get('graph-aggregate', Select\GraphAggregateController::class)->name('ajax.select.graph-aggregate');
            Route::get('graylog-streams', Select\GraylogStreamsController::class)->name('ajax.select.graylog-streams');
            Route::get('inventory', Select\InventoryController::class)->name('ajax.select.inventory');
            Route::get('syslog', Select\SyslogController::class)->name('ajax.select.syslog');
            Route::get('location', Select\LocationController::class)->name('ajax.select.location');
            Route::get('munin', Select\MuninPluginController::class)->name('ajax.select.munin');
            Route::get('role', Select\RoleController::class)->name('ajax.select.role');
            Route::get('service', Select\ServiceController::class)->name('ajax.select.service');
            Route::get('template', Select\ServiceTemplateController::class)->name('ajax.select.template');
            Route::get('poller-group', Select\PollerGroupController::class)->name('ajax.select.poller-group');
            Route::get('port', Select\PortController::class)->name('ajax.select.port');
            Route::get('port-field', Select\PortFieldController::class)->name('ajax.select.port-field');
        });

        // jquery bootgrid data controllers
        Route::prefix('table')->group(function (): void {
            Route::post('alert-schedule', Table\AlertScheduleController::class);
            Route::post('customers', Table\CustomersController::class);
            Route::post('diskio', Table\DiskioController::class)->name('table.diskio');
            Route::post('device', Table\DeviceController::class)->name('table.device');
            Route::get('device/export', [Table\DeviceController::class, 'export']);
            Route::post('edit-ports', Table\EditPortsController::class);
            Route::post('eventlog', Table\EventlogController::class)->name('table.eventlog');
            Route::post('fdb-tables', Table\FdbTablesController::class);
            Route::post('graylog', Table\GraylogController::class)->name('table.graylog');
            Route::post('inventory', Table\InventoryController::class)->name('table.inventory');
            Route::get('inventory/export', [Table\InventoryController::class, 'export']);
            Route::post('location', Table\LocationController::class);
            Route::post('mempools', Table\MempoolsController::class)->name('table.mempools');
            Route::get('mempools/export', [Table\MempoolsController::class, 'export']);
            Route::post('outages', Table\OutagesController::class)->name('table.outages');
            Route::get('outages/export', [Table\OutagesController::class, 'export']);
            Route::post('port-nac', Table\PortNacController::class)->name('table.port-nac');
            Route::post('port-security', Table\PortSecurityController::class)->name('table.port-security');
            Route::post('port-stp', Table\PortStpController::class);
            Route::post('ports', Table\PortsController::class)->name('table.ports');
            Route::get('ports/export', [Table\PortsController::class, 'export']);
            Route::post('processors', Table\ProcessorsController::class)->name('table.processors');
            Route::get('processors/export', [Table\ProcessorsController::class, 'export']);
            Route::post('routes', Table\RoutesTablesController::class);
            Route::post('sensors', Table\SensorsController::class)->name('table.sensors');
            Route::get('sensors/export', [Table\SensorsController::class, 'export']);
            Route::post('storages', Table\StoragesController::class)->name('table.storages');
            Route::get('storages/export', [Table\StoragesController::class, 'export']);
            Route::post('syslog', Table\SyslogController::class)->name('table.syslog');
            Route::post('printer-supply', Table\PrinterSupplyController::class)->name('table.printer-supply');
            Route::post('tnmsne', Table\TnmsneController::class)->name('table.tnmsne');
            Route::post('wireless', Table\WirelessSensorController::class)->name('table.wireless');
            Route::post('vlan-ports', Table\VlanPortsController::class)->name('table.vlan-ports');
            Route::post('vlan-devices', Table\VlanDevicesController::class)->name('table.vlan-devices');
            Route::post('vminfo', Table\VminfoController::class);
        });

        // dashboard widgets
        Route::prefix('dash')->group(function (): void {
            Route::post('alerts', Widgets\AlertsController::class);
            Route::post('alertlog', Widgets\AlertlogController::class);
            Route::post('alertlog-stats', Widgets\AlertlogStatsController::class);
            Route::post('availability-map', Widgets\AvailabilityMapController::class);
            Route::post('component-status', Widgets\ComponentStatusController::class);
            Route::post('custom-map', Widgets\CustomMapController::class);
            Route::post('device-summary-horiz', Widgets\DeviceSummaryHorizController::class);
            Route::post('device-summary-vert', Widgets\DeviceSummaryVertController::class);
            Route::post('device-types', Widgets\DeviceTypeController::class);
            Route::post('eventlog', Widgets\EventlogController::class);
            Route::post('generic-graph', Widgets\GraphController::class);
            Route::post('generic-image', Widgets\ImageController::class);
            Route::post('globe', Widgets\GlobeController::class);
            Route::post('graylog', Widgets\GraylogController::class);
            Route::post('placeholder', Widgets\PlaceholderController::class);
            Route::post('notes', Widgets\NotesController::class);
            Route::post('server-stats', Widgets\ServerStatsController::class);
            Route::post('syslog', Widgets\SyslogController::class);
            Route::post('top-devices', Widgets\TopDevicesController::class);
            Route::post('top-interfaces', Widgets\TopInterfacesController::class);
            Route::post('top-errors', Widgets\TopErrorsController::class);
            Route::post('worldmap', Widgets\WorldMapController::class)->name('widget.worldmap');
        });
    });

    // demo helper
    Route::permanentRedirect('demo', '/');
});

// routes that don't need authentication
Route::prefix('ajax')->group(function (): void {
    Route::post('set_timezone', [Ajax\TimezoneController::class, 'set']);
});

// installation routes
Route::prefix('install')->group(function (): void {
    Route::get('/', [Install\InstallationController::class, 'redirectToFirst'])->name('install');
    Route::get('/checks', [Install\ChecksController::class, 'index'])->name('install.checks');
    Route::get('/database', [Install\DatabaseController::class, 'index'])->name('install.database');
    Route::get('/user', [Install\MakeUserController::class, 'index'])->name('install.user');
    Route::get('/finish', [Install\FinalizeController::class, 'index'])->name('install.finish');

    Route::post('/finish', [Install\FinalizeController::class, 'saveConfig'])->name('install.finish.save');
    Route::post('/user/create', [Install\MakeUserController::class, 'create'])->name('install.action.user');
    Route::post('/database/test', [Install\DatabaseController::class, 'test'])->name('install.acton.test-database');
    Route::get('/ajax/database/migrate', [Install\DatabaseController::class, 'migrate'])->name('install.action.migrate');
    Route::get('/ajax/steps', [Install\InstallationController::class, 'stepsCompleted'])->name('install.action.steps');
    Route::any('{path?}', [Install\InstallationController::class, 'invalid'])->where('path', '.*'); // 404
});

// Legacy routes
Route::any('/dummy_legacy_auth/{path?}', [LegacyController::class, 'dummy'])->middleware('auth');
Route::any('/dummy_legacy_unauth/{path?}', [LegacyController::class, 'dummy']);
Route::any('/{path?}', [LegacyController::class, 'index'])
    ->where('path', '^((?!_debugbar).)*')
    ->middleware('auth');

```
