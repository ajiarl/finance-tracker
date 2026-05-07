<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->enum('type', ['income', 'expense']);
            $table->string('icon', 50)->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('categories')
            ->orderBy('id')
            ->get()
            ->each(function ($category): void {
                DB::table('categories_new')->insert([
                    'id' => $category->id,
                    'user_id' => $category->user_id,
                    'name' => $category->name,
                    'type' => $category->type,
                    'icon' => $category->icon,
                    'color' => $category->color,
                    'is_active' => $category->is_active ?? true,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ]);
            });

        Schema::drop('categories');
        Schema::rename('categories_new', 'categories');
    }

    public function down(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->enum('type', ['income', 'expense']);
            $table->string('icon', 50)->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('categories')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get()
            ->each(function ($category): void {
                DB::table('categories_old')->insert([
                    'id' => $category->id,
                    'user_id' => $category->user_id,
                    'name' => $category->name,
                    'type' => $category->type,
                    'icon' => $category->icon,
                    'color' => $category->color,
                    'is_active' => $category->is_active ?? true,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ]);
            });

        Schema::drop('categories');
        Schema::rename('categories_old', 'categories');
    }
};
