<?php

namespace Database\Seeders;

use App\Models\Deck;
use Illuminate\Database\Seeder;

/** Sample vocabulary decks. Run on its own with `php artisan db:seed --class=DeckSeeder`. */
class DeckSeeder extends Seeder
{
    public function run(): void
    {
        foreach (require __DIR__.'/content/decks.php' as $i => $deck) {
            $model = Deck::create([...collect($deck)->except('cards')->all(), 'is_published' => true, 'sort' => $i]);
            foreach ($deck['cards'] as $j => [$word, $definition, $example, $fr, $ar]) {
                $model->cards()->create([
                    'word' => $word, 'definition' => $definition, 'example' => $example,
                    'translation_fr' => $fr, 'translation_ar' => $ar, 'sort' => $j,
                ]);
            }
        }
    }
}
