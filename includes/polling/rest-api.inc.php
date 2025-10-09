<?php
/**
 * rest-api.inc.php
 *
 * REST API Polling Module
 * Polls devices via REST API endpoints
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
 * @package    LibreNMS
 * @link       https://www.librenms.org
 * @copyright  2025 LibreNMS
 * @author     Justin Wasden
 */

use App\Pollers\RestApiPoller;

try {
    $poller = new RestApiPoller($device);
    $poller->poll();
} catch (\Exception $e) {
    echo 'REST API Polling FAILED: ' . $e->getMessage() . PHP_EOL;
}
