<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add all missing columns to rest_api tables
     */
    public function up(): void
    {
        // Fix rest_api_connections - add rate_limit
        if (Schema::hasTable('rest_api_connections')) {
            if (!Schema::hasColumn('rest_api_connections', 'rate_limit')) {
                Schema::table('rest_api_connections', function (Blueprint $table) {
                    $table->unsignedInteger('rate_limit')->default(60)->after('template_id');
                });
            }
        }

        // Fix rest_api_endpoints - add all missing columns
        if (Schema::hasTable('rest_api_endpoints')) {
            // Add connection_id
            if (!Schema::hasColumn('rest_api_endpoints', 'connection_id')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->unsignedBigInteger('connection_id')->nullable()->after('id');
                });
            }

            // Add http_method
            if (!Schema::hasColumn('rest_api_endpoints', 'http_method')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->string('http_method')->default('GET')->after('path');
                });
            }

            // Add poll_interval
            if (!Schema::hasColumn('rest_api_endpoints', 'poll_interval')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->unsignedInteger('poll_interval')->default(300)->after('http_method');
                });
            }

            // Add metric_map
            if (!Schema::hasColumn('rest_api_endpoints', 'metric_map')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->json('metric_map')->nullable()->after('resource_type');
                });
            }

            // Add method if missing (legacy support)
            if (!Schema::hasColumn('rest_api_endpoints', 'method')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->string('method')->default('GET')->after('http_method');
                });
            }

            // Add enabled if missing
            if (!Schema::hasColumn('rest_api_endpoints', 'enabled')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->boolean('enabled')->default(true)->after('template_response_mapping');
                });
            }

            // Add foreign key for connection_id if missing
            try {
                $table = Schema::getConnection()->getDoctrineSchemaManager()->listTableDetails('rest_api_endpoints');
                $hasForeignKey = false;
                foreach ($table->getForeignKeys() as $fk) {
                    if ($fk->getLocalColumns() === ['connection_id']) {
                        $hasForeignKey = true;
                        break;
                    }
                }
                if (!$hasForeignKey && Schema::hasColumn('rest_api_endpoints', 'connection_id')) {
                    Schema::table('rest_api_endpoints', function (Blueprint $table) {
                        $table->foreign('connection_id')
                            ->references('id')
                            ->on('rest_api_connections')
                            ->onDelete('cascade');
                    });
                }
            } catch (\Exception $e) {
                // Silently continue if we can't check foreign keys
            }
        }
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        if (Schema::hasTable('rest_api_connections')) {
            if (Schema::hasColumn('rest_api_connections', 'rate_limit')) {
                Schema::table('rest_api_connections', function (Blueprint $table) {
                    $table->dropColumn('rate_limit');
                });
            }
        }

        if (Schema::hasTable('rest_api_endpoints')) {
            if (Schema::hasColumn('rest_api_endpoints', 'connection_id')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    try {
                        $table->dropForeign(['connection_id']);
                    } catch (\Exception $e) {
                        // Foreign key might not exist
                    }
                    $table->dropColumn('connection_id');
                });
            }
            if (Schema::hasColumn('rest_api_endpoints', 'http_method')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->dropColumn('http_method');
                });
            }
            if (Schema::hasColumn('rest_api_endpoints', 'poll_interval')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->dropColumn('poll_interval');
                });
            }
            if (Schema::hasColumn('rest_api_endpoints', 'metric_map')) {
                Schema::table('rest_api_endpoints', function (Blueprint $table) {
                    $table->dropColumn('metric_map');
                });
            }
        }
    }
};
