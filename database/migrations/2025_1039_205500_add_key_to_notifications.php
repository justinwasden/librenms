<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Add 'key' column if it doesn't exist
            $columns = Schema::getColumnListing('notifications');
            if (!in_array('key', $columns, true)) {
                // 'key' is a string identifier for the notification type
                $table->string('key', 191)->nullable()->after('checksum');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $columns = Schema::getColumnListing('notifications');
            if (in_array('key', $columns, true)) {
                $table->dropColumn('key');
            }
        });
    }
};
