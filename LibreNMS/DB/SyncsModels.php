<?php

/**
 * SyncsModels.php
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
 * @copyright  2019 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\DB;

use App\Models\Device;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Interfaces\Models\Keyable;

trait SyncsModels
{
    /**
     * Sync several models for a device's relationship
     * Model must implement \LibreNMS\Interfaces\Models\Keyable interface
     *
     * @param  \Illuminate\Database\Eloquent\Model  $parentModel
     * @param  string  $relationship
     * @param  \Illuminate\Support\Collection<Keyable>  $models  \LibreNMS\Interfaces\Models\Keyable
     * @param  Collection|null  $existing  Existing models to sync against
     * @param  string|null  $source  Discovery source ('snmp', 'api', or null to delete all non-matching)
     * @return Collection
     */
    protected function syncModels($parentModel, $relationship, $models, $existing = null, ?string $source = null): Collection
    {
        $models = $models->keyBy->getCompositeKey();
        $existing = ($existing ?? $parentModel->$relationship)->groupBy->getCompositeKey();
        $hasDiscoveredVia = function () use ($models, $existing): bool {
            static $cached = null;
            if ($cached !== null) {
                return $cached;
            }

            $model = null;
            if ($existing->isNotEmpty()) {
                $firstGroup = $existing->first();
                $model = $firstGroup->first();
            }
            if (! $model && $models->isNotEmpty()) {
                $model = $models->first();
            }
            if (! $model) {
                $cached = false;
                return $cached;
            }

            $cached = Schema::hasColumn($model->getTable(), 'discovered_via');

            return $cached;
        };

        foreach ($existing as $exist_key => $existing_rows) {
            if ($models->offsetExists($exist_key)) {
                // update
                foreach ($existing_rows as $index => $existing_row) {
                    if ($index == 0) {
                        // fill attributes, ignoring mutators and fillable
                        $merged = array_merge($existing_row->getAttributes(), $models->get($exist_key)->getAttributes());
                        // If source is specified, update the discovered_via field
                        if ($source !== null && $hasDiscoveredVia()) {
                            // If discovered by both sources, mark as 'both'
                            $current = $merged['discovered_via'] ?? null;
                            if ($current === null) {
                                $merged['discovered_via'] = $source;
                            } elseif ($current !== $source && $current !== 'both') {
                                $merged['discovered_via'] = 'both';
                            }
                        }
                        $existing_row->setRawAttributes($merged);
                        $existing_row->save();
                    } else {
                        // delete extra rows at this key
                        $existing_row->delete();
                        $existing_rows->forget($index);
                    }
                }
            } else {
                // Delete only if source matches or source not specified (legacy behavior)
                // This prevents SNMP discovery from deleting API-discovered data
                foreach ($existing_rows as $existing_row) {
                    $rowSource = $existing_row->discovered_via ?? 'snmp';
                    // Only delete if:
                    // - No source specified (legacy behavior, delete all)
                    // - Source matches (snmp deletes snmp, api deletes api)
                    if ($source === null || $rowSource === $source) {
                        $existing_row->delete();
                    }
                    // If row was 'both' and we're only removing one source, update to remaining source
                    elseif ($rowSource === 'both' && $hasDiscoveredVia()) {
                        $remaining = ($source === 'snmp') ? 'api' : 'snmp';
                        $existing_row->discovered_via = $remaining;
                        $existing_row->save();
                    }
                }
                // Only forget if we actually deleted all rows for this key
                if ($source === null) {
                    $existing->forget($exist_key);
                }
            }
        }

        $new = $models->diffKeys($existing);

        // Set discovered_via for new models if source is specified
        if ($source !== null && $hasDiscoveredVia()) {
            $new = $new->map(function ($model) use ($source) {
                $model->discovered_via = $source;
                return $model;
            });
        }

        if (is_a($parentModel->$relationship(), HasManyThrough::class)) {
            // if this is a distant relation, the models need the intermediate relationship set
            // just save assuming things are correct
            $new->each->save();
        } else {
            $parentModel->$relationship()->saveMany($new);
        }

        return $existing->map->first()->filter()->merge($new);
    }

    /**
     * Sync a sub-group of models to the database
     *
     * @param  Collection<Keyable>  $models
     * @param  string|null  $source  Discovery source ('snmp', 'api', or null)
     */
    public function syncModelsByGroup(Device $device, string $relationship, Collection $models, array $where, ?string $source = null): Collection
    {
        $filter = function ($models, $params) {
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    $models = $models->where(...$value);
                } else {
                    $models = $models->where($key, '=', $value);
                }
            }

            return $models;
        };

        return $this->syncModels($device, $relationship, $models->when($where, $filter), $device->$relationship->when($where, $filter), $source);
    }

    /**
     * Combine a list of existing and potentially new models
     * If the model exists fill any new data from the new models
     *
     * @param  Collection  $existing  \LibreNMS\Interfaces\Models\Keyable
     * @param  Collection  $discovered  \LibreNMS\Interfaces\Models\Keyable
     * @return Collection
     */
    protected function fillNew(Collection $existing, Collection $discovered): Collection
    {
        $all = $existing->keyBy->getCompositeKey();
        foreach ($discovered as $new) {
            if ($found = $all->get($new->getCompositeKey())) {
                $found->fill($new->getAttributes());
            } else {
                $all->put($new->getCompositeKey(), $new);
            }
        }

        return $all;
    }
}
