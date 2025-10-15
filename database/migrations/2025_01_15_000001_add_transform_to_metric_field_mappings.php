<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('metric_field_mappings', 'transform')) {
            Schema::table('metric_field_mappings', function (Blueprint $table) {
                $table->string('transform', 50)->nullable()->after('unit');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('metric_field_mappings', 'transform')) {
            Schema::table('metric_field_mappings', function (Blueprint $table) {
                $table->dropColumn('transform');
            });
        }
    }
};
