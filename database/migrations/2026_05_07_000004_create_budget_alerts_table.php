<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('threshold'); // 50 | 75 | 90 | 100
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['budget_id', 'threshold']); // ← anti-duplikat di level DB
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_alerts');
    }
};
