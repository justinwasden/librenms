<?php
/**
 * device-api.inc.php
 *
 * Legacy discovery stub for REST API discovery.
 *
 * API-based discovery is now integrated directly into OS classes
 * via the ApiPolling trait. This stub exists only for backward compatibility
 * with module enablement. The actual work is done by:
 *
 * - OS classes that use the ApiPolling trait (LibreNMS/OS/Traits/ApiPolling.php)
 * - Native modules (Storage, Processors, Mempools, etc.) that call OS discovery methods
 * - API clients (app/ApiClients/*) that handle authentication and HTTP requests
 *
 * This file intentionally does nothing as API data is collected during
 * the normal discovery flow through OS class methods.
 *
 * @link       https://www.librenms.org
 * @copyright  2024-2025
 */

// No-op - API discovery is handled by OS classes and native modules
