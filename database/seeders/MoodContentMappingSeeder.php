<?php

namespace Database\Seeders;

use App\Models\MoodContentMapping;
use Illuminate\Database\Seeder;

class MoodContentMappingSeeder extends Seeder
{
    public function run(): void
    {
        $mappings = [
            // Happy mood -> uplifting, funny, inspiring content
            ['mood' => 'Happy', 'tag' => 'funny',       'weight' => 0.9],
            ['mood' => 'Happy', 'tag' => 'comedy',      'weight' => 0.9],
            ['mood' => 'Happy', 'tag' => 'dance',       'weight' => 0.8],
            ['mood' => 'Happy', 'tag' => 'music',       'weight' => 0.8],
            ['mood' => 'Happy', 'tag' => 'party',       'weight' => 0.8],
            ['mood' => 'Happy', 'tag' => 'celebration', 'weight' => 0.7],
            ['mood' => 'Happy', 'tag' => 'travel',      'weight' => 0.7],
            ['mood' => 'Happy', 'tag' => 'food',        'weight' => 0.6],
            ['mood' => 'Happy', 'tag' => 'sports',      'weight' => 0.6],
            ['mood' => 'Happy', 'tag' => 'gaming',      'weight' => 0.6],
            ['mood' => 'Happy', 'tag' => 'inspiring',   'weight' => 0.5],
            ['mood' => 'Happy', 'tag' => 'uplifting',   'weight' => 0.5],

            // Neutral mood -> general interest, educational
            ['mood' => 'Neutral', 'tag' => 'technology',  'weight' => 0.8],
            ['mood' => 'Neutral', 'tag' => 'education',   'weight' => 0.8],
            ['mood' => 'Neutral', 'tag' => 'news',        'weight' => 0.7],
            ['mood' => 'Neutral', 'tag' => 'science',     'weight' => 0.7],
            ['mood' => 'Neutral', 'tag' => 'business',    'weight' => 0.7],
            ['mood' => 'Neutral', 'tag' => 'ai',          'weight' => 0.7],
            ['mood' => 'Neutral', 'tag' => 'design',      'weight' => 0.6],
            ['mood' => 'Neutral', 'tag' => 'photography', 'weight' => 0.6],
            ['mood' => 'Neutral', 'tag' => 'art',         'weight' => 0.6],
            ['mood' => 'Neutral', 'tag' => 'startups',    'weight' => 0.5],

            // Low mood -> motivational, calming, feel-good
            ['mood' => 'Low', 'tag' => 'motivation',   'weight' => 0.9],
            ['mood' => 'Low', 'tag' => 'inspiring',    'weight' => 0.9],
            ['mood' => 'Low', 'tag' => 'uplifting',    'weight' => 0.9],
            ['mood' => 'Low', 'tag' => 'nature',       'weight' => 0.8],
            ['mood' => 'Low', 'tag' => 'mindfulness',  'weight' => 0.8],
            ['mood' => 'Low', 'tag' => 'fitness',      'weight' => 0.7],
            ['mood' => 'Low', 'tag' => 'selfcare',     'weight' => 0.7],
            ['mood' => 'Low', 'tag' => 'music',        'weight' => 0.7],
            ['mood' => 'Low', 'tag' => 'cute',         'weight' => 0.6],
            ['mood' => 'Low', 'tag' => 'animals',      'weight' => 0.6],
            ['mood' => 'Low', 'tag' => 'travel',       'weight' => 0.5],
            ['mood' => 'Low', 'tag' => 'food',         'weight' => 0.5],

            // Angry mood -> calming, humor, physical activity
            ['mood' => 'Angry', 'tag' => 'funny',       'weight' => 0.9],
            ['mood' => 'Angry', 'tag' => 'comedy',      'weight' => 0.9],
            ['mood' => 'Angry', 'tag' => 'sports',      'weight' => 0.8],
            ['mood' => 'Angry', 'tag' => 'fitness',     'weight' => 0.8],
            ['mood' => 'Angry', 'tag' => 'nature',      'weight' => 0.7],
            ['mood' => 'Angry', 'tag' => 'mindfulness', 'weight' => 0.7],
            ['mood' => 'Angry', 'tag' => 'music',       'weight' => 0.7],
            ['mood' => 'Angry', 'tag' => 'gaming',      'weight' => 0.6],
            ['mood' => 'Angry', 'tag' => 'cute',        'weight' => 0.6],
            ['mood' => 'Angry', 'tag' => 'animals',     'weight' => 0.6],
            ['mood' => 'Angry', 'tag' => 'dance',       'weight' => 0.5],
            ['mood' => 'Angry', 'tag' => 'travel',      'weight' => 0.5],
        ];

        foreach ($mappings as $mapping) {
            MoodContentMapping::firstOrCreate(
                ['mood' => $mapping['mood'], 'tag' => $mapping['tag']],
                $mapping
            );
        }
    }
}
