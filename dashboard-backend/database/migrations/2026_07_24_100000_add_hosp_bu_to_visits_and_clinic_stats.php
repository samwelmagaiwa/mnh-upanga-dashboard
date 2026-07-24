<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('hosp_bu', 100)->nullable()->after('status');
        });

        Schema::table('clinic_stats', function (Blueprint $table) {
            $table->string('hosp_bu', 100)->nullable()->after('pending');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('hosp_bu');
        });

        Schema::table('clinic_stats', function (Blueprint $table) {
            $table->dropColumn('hosp_bu');
        });
    }
};
