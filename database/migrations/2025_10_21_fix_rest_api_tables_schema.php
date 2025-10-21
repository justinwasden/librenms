<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing columns to rest_api_connections and rest_api_endpoints tables
     */
    public function up(): void
    {
        // Add rate_limit to rest_api_connections if it doesn't exist
        if (Schema::hasTable('rest_api_connections') && 
            !Schema::hasColumn('rest_api_connections', 'rate_limit')) {
            Schema::table('rest_api_connections', function (Blueprint $table) {
                $table->unsignedInteger('rate_limit')->default(60)->after('template_id');
            });
        }

        // Fix rest_api_endpoints table
        if (Schema::hasTable('rest_api_endpoints')) {
            // Rename method to http_method if it exists
            if (Schema::hasColumn('rest_api_endpoints', 'method')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->renameColumn('method', 'http_method');
                });
            }

            // Add poll_interval if it doesn't exist
            if (!Schema::hasColumn('rest_api_endpoints', 'poll_interval')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->unsignedInteger('poll_interval')->default(300)->after('http_method');
                });
            }

            // Drop connection_id if it exists (we use template_id instead)
            if (Schema::hasColumn('rest_api_endpoints', 'connection_id')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->dropForeign(['connection_id']);
                    $table->dropColumn('connection_id');
                });
            }

            // Drop metric_map if it exists
            if (Schema::hasColumn('rest_api_endpoints', 'metric_map')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->dropColumn('metric_map');
                });
            }

            // Make template_id non-nullable
            if (Schema::hasColumn('rest_api_endpoints', 'template_id')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->unsignedBigInteger('template_id')->nullable(false)->change();
                });
            }
        }
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        if (Schema::hasTable('rest_api_connections')) {
            Schema::table('rest_api_connections', function (Blueprint $table) {
                if (Schema::hasColumn('rest_api_connections', 'rate_limit')) {
                    $table->dropColumn('rate_limit');
                }
            });
        }

        if (Schema::hasTable('rest_api_endpoints')) {
            if (Schema::hasColumn('rest_api_endpoints', 'http_method')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->renameColumn('http_method', 'method');
                });
            }
        }
    }
};
