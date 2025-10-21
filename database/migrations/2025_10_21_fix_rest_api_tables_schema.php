<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing columns to rest_api tables and fix schema mismatches
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
            // Add connection_id if missing
            if (!Schema::hasColumn('rest_api_endpoints', 'connection_id')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->unsignedBigInteger('connection_id')->nullable()->after('id');
                    $table->foreign('connection_id')->references('id')->on('rest_api_connections')->onDelete('cascade');
                });
            }

            // Add http_method if only method exists
            if (Schema::hasColumn('rest_api_endpoints', 'method') && 
                !Schema::hasColumn('rest_api_endpoints', 'http_method')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->string('http_method')->default('GET')->after('path');
                });
            }

            // Add poll_interval if it doesn't exist
            if (!Schema::hasColumn('rest_api_endpoints', 'poll_interval')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->unsignedInteger('poll_interval')->default(300)->after('http_method');
                });
            }

            // Add metric_map if it doesn't exist
            if (!Schema::hasColumn('rest_api_endpoints', 'metric_map')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->json('metric_map')->nullable()->after('resource_type');
                });
            }

            // Make template_id nullable if it exists and isn't already
            if (Schema::hasColumn('rest_api_endpoints', 'template_id')) {
                // Check current definition
                $columns = Schema::getColumnListing('rest_api_endpoints');
                if (in_array('template_id', $columns)) {
                    try {
                        Schema::table('rest_api_endpoints', function (Blueprint $table) {
                            $table->unsignedBigInteger('template_id')->nullable()->change();
                        });
                    } catch (\Exception $e) {
                        // Silently fail if column is already nullable or can't be changed
                    }
                }
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
            if (Schema::hasColumn('rest_api_endpoints', 'connection_id')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->dropForeign(['connection_id']);
                    $table->dropColumn('connection_id');
                });
            }
        }
    }
};
