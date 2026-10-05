<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrative_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        $database = (string) \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        $disposable = app()->environment('testing') && ($database === ':memory:' || str_ends_with($database, '_test'));
        if (! $disposable && \Illuminate\Support\Facades\DB::table('administrative_audit_logs')->exists()) {
            throw new LogicException('Audit history is populated. Export and review it before a deliberate rollback; no records removed.');
        }
        Schema::dropIfExists('administrative_audit_logs');
    }
};
