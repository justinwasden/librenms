<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration is now empty - all tables already exist
        // The mapper fields are already in rest_api_device_templates
    }

    public function down(): void
    {
        // No-op
    }
};
