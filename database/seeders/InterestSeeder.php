<?php

namespace Database\Seeders;

use App\Models\Interest;
use Illuminate\Database\Seeder;

class InterestSeeder extends Seeder
{
    public function run(): void
    {
        $interests = [
            ['name' => 'Technology',   'category' => 'Tech'],
            ['name' => 'AI',           'category' => 'Tech'],
            ['name' => 'Startups',     'category' => 'Business'],
            ['name' => 'Business',     'category' => 'Business'],
            ['name' => 'Music',        'category' => 'Entertainment'],
            ['name' => 'Travel',       'category' => 'Lifestyle'],
            ['name' => 'Sports',       'category' => 'Lifestyle'],
            ['name' => 'Gaming',       'category' => 'Entertainment'],
            ['name' => 'Food',         'category' => 'Lifestyle'],
            ['name' => 'Fashion',      'category' => 'Lifestyle'],
            ['name' => 'Education',    'category' => 'Knowledge'],
            ['name' => 'Culture',      'category' => 'Lifestyle'],
            ['name' => 'Photography',  'category' => 'Creative'],
            ['name' => 'Fitness',      'category' => 'Health'],
            ['name' => 'Movies',       'category' => 'Entertainment'],
            ['name' => 'Art',          'category' => 'Creative'],
            ['name' => 'Science',      'category' => 'Knowledge'],
            ['name' => 'Crypto',       'category' => 'Tech'],
            ['name' => 'Design',       'category' => 'Creative'],
            ['name' => 'Marketing',    'category' => 'Business'],
        ];

        foreach ($interests as $interest) {
            Interest::firstOrCreate(['name' => $interest['name']], $interest);
        }
    }
}
