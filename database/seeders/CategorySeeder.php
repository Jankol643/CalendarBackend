<?php

declare(strict_types = 1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

final class CategorySeeder extends Seeder {

    public function run(): void {
        // Define the categories and subcategories
        $categories = [
            'Airplane' => ['Crash'],
            'Child' => [],
            'Computer' => ['Configuration'],
            'Digital' => ['Todo'],
            'Electricity' => ['Tools'],
            'Environment' => ['Water'],
            'Food' => ['Sweets'],
            'Hobby' => ['Climbing', 'Model Building'],
            'Journalism' => [],
            'Learning' => ['General'],
            'Military' => ['Equipment'],
            'Occupational Safety' => ['Hands'],
            'Pets' => ['Dogs'],
            'Physics' => ['Falling Speed', 'Energy Demand'],
            'Programming' => ['Todo', 'Task'],
            'Safety' => ['Self-Defense'],
            'Shopping' => ['Smoke Detectors', 'Tools'],
            'Small Animals' => ['Food'],
            'Tools' => ['Woodworking'],
            'Welding' => [],
        ];

        foreach ($categories as $categoryName => $subcategories) {
            $category = Category::create(['name' => $categoryName]);

            foreach ($subcategories as $subcategoryName) {
                Category::create(['name' => $subcategoryName, 'parent_id' => $category->id]);
            }
        }
    }

}
