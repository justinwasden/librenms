<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $templateId = DB::table('device_api_templates')->where('key', 'netapp_ontap')->value('id');

        if ($templateId) {
            DB::table('device_api_template_endpoints')
                ->where('template_id', $templateId)
                ->where('capability', 'ports_statistics')
                ->update(['enabled' => 1]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $templateId = DB::table('device_api_templates')->where('key', 'netapp_ontap')->value('id');

        if ($templateId) {
            DB::table('device_api_template_endpoints')
                ->where('template_id', $templateId)
                ->where('capability', 'ports_statistics')
                ->update(['enabled' => 0]);
        }
    }
};
