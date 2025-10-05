<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_api_metrics', function (Blueprint $table) {
            $table->timestamp('matched_at')->nullable()->after('collected_at');
            $table->unsignedBigInteger('mapping_id')->nullable()->after('matched_at');
            
            $table->index('matched_at');
            
            // Optional foreign key to track which mapping was used
            $table->foreign('mapping_id')
                ->references('id')
                ->on('metric_field_mappings')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('device_api_metrics', function (Blueprint $table) {
            $table->dropForeign(['mapping_id']);
            $table->dropColumn(['matched_at', 'mapping_id']);
        });
    }
};
