<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('note');
            $table->foreignId('created_by_admin_id')->nullable()->after('is_manual')
                ->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_admin_id');
            $table->dropColumn('is_manual');
        });
    }
};
