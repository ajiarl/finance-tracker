<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budgets')) {
            return;
        }

        if (
            Schema::hasColumn('budgets', 'name') &&
            Schema::hasColumn('budgets', 'spent') &&
            Schema::hasColumn('budgets', 'start_date') &&
            Schema::hasColumn('budgets', 'end_date') &&
            Schema::hasColumn('budgets', 'is_active')
        ) {
            return;
        }

        Schema::create('budgets_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('amount', 15, 2);
            $table->decimal('spent', 15, 2)->default(0);
            $table->enum('period', ['monthly', 'weekly', 'yearly']);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('budgets')
            ->orderBy('id')
            ->get()
            ->each(function ($budget): void {
                $startDate = match ($budget->period) {
                    'weekly' => now()->startOfWeek()->toDateString(),
                    'monthly' => now()->startOfMonth()->toDateString(),
                    'yearly' => now()->startOfYear()->toDateString(),
                    default => now()->toDateString(),
                };

                $endDate = match ($budget->period) {
                    'weekly' => now()->endOfWeek()->toDateString(),
                    'monthly' => now()->endOfMonth()->toDateString(),
                    'yearly' => now()->endOfYear()->toDateString(),
                    default => now()->toDateString(),
                };

                DB::table('budgets_new')->insert([
                    'id' => $budget->id,
                    'user_id' => $budget->user_id,
                    'category_id' => $budget->category_id,
                    'name' => 'Budget '.$budget->id,
                    'amount' => $budget->amount,
                    'spent' => 0,
                    'period' => in_array($budget->period, ['monthly', 'weekly', 'yearly'], true) ? $budget->period : 'monthly',
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_active' => true,
                    'created_at' => $budget->created_at,
                    'updated_at' => $budget->updated_at,
                ]);
            });

        Schema::drop('budgets');
        Schema::rename('budgets_new', 'budgets');
    }

    public function down(): void
    {
        if (! Schema::hasTable('budgets')) {
            return;
        }

        Schema::create('budgets_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->enum('period', ['monthly', 'weekly'])->default('monthly');
            $table->integer('month')->nullable();
            $table->integer('year')->nullable();
            $table->timestamps();
        });

        DB::table('budgets')
            ->orderBy('id')
            ->get()
            ->each(function ($budget): void {
                DB::table('budgets_old')->insert([
                    'id' => $budget->id,
                    'user_id' => $budget->user_id,
                    'category_id' => $budget->category_id ?? 1,
                    'amount' => $budget->amount,
                    'period' => in_array($budget->period, ['monthly', 'weekly'], true) ? $budget->period : 'monthly',
                    'month' => null,
                    'year' => null,
                    'created_at' => $budget->created_at,
                    'updated_at' => $budget->updated_at,
                ]);
            });

        Schema::drop('budgets');
        Schema::rename('budgets_old', 'budgets');
    }
};
