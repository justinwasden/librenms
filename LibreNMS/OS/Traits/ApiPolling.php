<?php

namespace LibreNMS\OS\Traits;

/**
 * ApiPolling Trait
 *
 * Provides shared functionality for OS classes that use REST/SOAP APIs.
 * Reads API configuration from device attributes instead of device_api_configs table.
 *
 * Usage in OS class:
 *   use ApiPolling;
 *
 *   if ($this->hasApiConfig()) {
 *       $baseUrl = $this->getApiBaseUrl();
 *       $username = $this->getApiCredential('username');
 *       // ... use credentials
 *   }
 */
trait ApiPolling
{
    /**
     * Get the base URL for API requests
     *
     * @return string|null
     */
    protected function getApiBaseUrl(): ?string
    {
        return $this->getDevice()->getAttrib('api_base_url');
    }

    /**
     * Get a specific API credential by key
     *
     * Credentials are stored with 'api_credential_' prefix and are encrypted in the database
     *
     * @param string $key Credential key (e.g., 'username', 'password', 'api_token')
     * @param mixed $default Default value if attribute doesn't exist
     * @return mixed
     */
    protected function getApiCredential(string $key, $default = null)
    {
        return $this->getDevice()->getAttrib("api_credential_{$key}", $default);
    }

    /**
     * Check if SSL certificate verification should be enabled
     *
     * @return bool
     */
    protected function shouldVerifySSL(): bool
    {
        return (bool) $this->getDevice()->getAttrib('api_verify_ssl', true);
    }

    /**
     * Check if this device has API configuration
     *
     * @return bool
     */
    protected function hasApiConfig(): bool
    {
        return !empty($this->getApiBaseUrl());
    }

    /**
     * Get the API template key for this device
     *
     * @return string|null
     */
    protected function getApiTemplateKey(): ?string
    {
        return $this->getDevice()->getAttrib('api_template_key');
    }

    /**
     * Get the API authentication schema key
     *
     * @return string|null
     */
    protected function getApiAuthSchema(): ?string
    {
        return $this->getDevice()->getAttrib('api_auth_schema');
    }

    /**
     * Check if a specific capability is enabled for API polling
     *
     * Capabilities can be disabled by adding them to the 'api_disabled_capabilities' attribute
     *
     * @param string $capability Capability name (e.g., 'sensors', 'inventory', 'vlans')
     * @return bool True if enabled, false if disabled
     */
    protected function isCapabilityEnabled(string $capability): bool
    {
        $disabled = json_decode(
            $this->getDevice()->getAttrib('api_disabled_capabilities', '[]'),
            true
        );

        return !in_array($capability, $disabled ?? []);
    }

    /**
     * Get extra HTTP headers for API requests
     *
     * @return array
     */
    protected function getApiExtraHeaders(): array
    {
        $headers = $this->getDevice()->getAttrib('api_extra_headers');

        if (empty($headers)) {
            return [];
        }

        $decoded = json_decode($headers, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Store an API credential in device attributes
     *
     * This method should be used during device setup/configuration.
     * Credentials are automatically encrypted by Device::setAttrib()
     *
     * @param string $key Credential key (without 'api_credential_' prefix)
     * @param mixed $value Credential value
     * @return void
     */
    protected function setApiCredential(string $key, $value): void
    {
        $this->getDevice()->setAttrib("api_credential_{$key}", $value);
    }

    /**
     * Set the API base URL
     *
     * @param string $url Base URL for API requests
     * @return void
     */
    protected function setApiBaseUrl(string $url): void
    {
        $this->getDevice()->setAttrib('api_base_url', $url);
    }

    /**
     * Set SSL verification preference
     *
     * @param bool $verify Whether to verify SSL certificates
     * @return void
     */
    protected function setVerifySSL(bool $verify): void
    {
        $this->getDevice()->setAttrib('api_verify_ssl', $verify);
    }
}
