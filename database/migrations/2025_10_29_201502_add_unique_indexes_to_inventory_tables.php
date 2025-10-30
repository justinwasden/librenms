<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // entPhysical: ensure uniqueness per device + entPhysicalIndex
        Schema::table('entPhysical', function (Blueprint $table) {
            // Some installations may already have similar indexes; use a distinct name
            if (! $this->hasIndex('entPhysical', 'entPhysical_device_index_unique')) {
                $table->unique(['device_id', 'entPhysicalIndex'], 'entPhysical_device_index_unique');
            }
        });

        // hrDevice: ensure uniqueness per device + hrDeviceIndex
        Schema::table('hrDevice', function (Blueprint $table) {
            if (! $this->hasIndex('hrDevice', 'hrDevice_device_index_unique')) {
                $table->unique(['device_id', 'hrDeviceIndex'], 'hrDevice_device_index_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('entPhysical', function (Blueprint $table) {
            $table->dropUnique('entPhysical_device_index_unique');
        });

        Schema::table('hrDevice', function (Blueprint $table) {
            $table->dropUnique('hrDevice_device_index_unique');
        });
    }

    /**
     * Simple helper to check index presence; avoids exceptions on reruns.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        // Laravel doesn't expose schema index checks natively; fallback to DB introspection if needed.
        // For portability, we try-catch and assume false if introspection is unavailable.
        try {
            $connection = Schema::getConnection();
            $grammar = $connection->getSchemaGrammar();
            $db = $connection->getDoctrineSchemaManager();
            $indexes = $db->listTableIndexes($grammar->getTablePrefix() . $table);

            return array_key_exists($indexName, $indexes);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
