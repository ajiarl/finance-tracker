<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class DefaultCategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'user_id' => null,
                'name' => 'Makanan & Minuman',
                'type' => 'expense',
                'icon' => 'utensils',
                'color' => '#F97316',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Transportasi',
                'type' => 'expense',
                'icon' => 'car',
                'color' => '#3B82F6',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Belanja',
                'type' => 'expense',
                'icon' => 'shopping-bag',
                'color' => '#EC4899',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Kesehatan',
                'type' => 'expense',
                'icon' => 'heart-pulse',
                'color' => '#EF4444',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Tagihan & Utilitas',
                'type' => 'expense',
                'icon' => 'file-text',
                'color' => '#8B5CF6',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Hiburan',
                'type' => 'expense',
                'icon' => 'gamepad-2',
                'color' => '#F59E0B',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Pendidikan',
                'type' => 'expense',
                'icon' => 'book-open',
                'color' => '#06B6D4',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Gaji',
                'type' => 'income',
                'icon' => 'briefcase',
                'color' => '#22C55E',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Freelance',
                'type' => 'income',
                'icon' => 'laptop',
                'color' => '#10B981',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Investasi',
                'type' => 'income',
                'icon' => 'trending-up',
                'color' => '#6366F1',
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'name' => 'Bonus',
                'type' => 'income',
                'icon' => 'gift',
                'color' => '#84CC16',
                'is_active' => true,
            ],
        ];

        foreach ($defaults as $category) {
            Category::firstOrCreate(
                [
                    'user_id' => null,
                    'name' => $category['name'],
                    'type' => $category['type'],
                ],
                [
                    'icon' => $category['icon'],
                    'color' => $category['color'],
                    'is_active' => $category['is_active'],
                ]
            );
        }

        $this->command?->info('Default categories seeded successfully.');
    }
}
