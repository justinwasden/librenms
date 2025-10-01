<?php
/**
 * RestApiController.php
 *
 * Device tab for REST API connections management
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
 * @copyright  2025 Justin Wasden
 * @author     Justin Wasden <justin@wasden.com>
 */

namespace App\Http\Controllers\Device\Tabs;

use App\Models\Device;
use App\Models\RestApiTemplate;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\UI\DeviceTab;

class RestApiController implements DeviceTab
{
    public function visible(Device $device): bool
    {
        // Show tab if user is admin or device has API connections
        return auth()->user()?->isAdmin() || $device->restApiConnections()->exists();
    }

    public function slug(): string
    {
        return 'rest-api';
    }

    public function icon(): string
    {
        return 'fa-cloud';
    }

    public function name(): string
    {
        return __('REST API');
    }

    public function data(Device $device, Request $request): array
    {
        // Load relationships for the view
        $device->load([
            'restApiConnections.credential',
            'restApiConnections.endpoints'
        ]);

        $templates = RestApiTemplate::all();

        return [
            'device' => $device,
            'templates' => $templates,
        ];
    }
}