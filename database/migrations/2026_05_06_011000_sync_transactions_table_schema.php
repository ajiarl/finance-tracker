<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        if (
            Schema::hasColumn('transactions', 'transaction_date') &&
            Schema::hasColumn('transactions', 'description') &&
            Schema::hasColumn('transactions', 'reference_number') &&
            Schema::hasColumn('transactions', 'notes')
        ) {
            return;
        }

        Schema::create('transactions_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['income', 'expense', 'transfer']);
            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();
            $table->date('transaction_date');
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        DB::table('transactions')
            ->orderBy('id')
            ->get()
            ->each(function ($transaction): void {
                DB::table('transactions_new')->insert([
                    'id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'account_id' => $transaction->account_id,
                    'category_id' => $transaction->category_id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'description' => $transaction->note,
                    'transaction_date' => $transaction->date,
                    'reference_number' => null,
                    'notes' => null,
                    'deleted_at' => $transaction->deleted_at,
                    'created_at' => $transaction->created_at,
                    'updated_at' => $transaction->updated_at,
                ]);
            });

        Schema::drop('transactions');
        Schema::rename('transactions_new', 'transactions');
    }

    public function down(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        Schema::create('transactions_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['income', 'expense', 'transfer'])->default('expense');
            $table->decimal('amount', 15, 2);
            $table->string('note')->nullable();
            $table->date('date');
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('transactions')
            ->orderBy('id')
            ->get()
            ->each(function ($transaction): void {
                DB::table('transactions_old')->insert([
                    'id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'account_id' => $transaction->account_id,
                    'category_id' => $transaction->category_id ?? 1,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'note' => $transaction->description,
                    'date' => $transaction->transaction_date,
                    'deleted_at' => $transaction->deleted_at,
                    'created_at' => $transaction->created_at,
                    'updated_at' => $transaction->updated_at,
                ]);
            });

        Schema::drop('transactions');
        Schema::rename('transactions_old', 'transactions');
    }
};
