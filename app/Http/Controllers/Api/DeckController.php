<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardProgress;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Vocabulary decks: flip a card, answer "I know it" or "Still learning". */
class DeckController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $decks = Deck::published()->withCount('cards')->orderBy('sort')->orderBy('created_at')->get();
        $known = CardProgress::query()
            ->where('user_id', $user->id)->where('status', 'known')
            ->join('cards', 'cards.id', '=', 'card_progress.card_id')
            ->selectRaw('cards.deck_id, count(*) as n')->groupBy('cards.deck_id')
            ->pluck('n', 'deck_id');

        return response()->json($decks->map(fn (Deck $d) => $this->summary($d, $user, (int) ($known[$d->id] ?? 0)))->values());
    }

    public function show(Request $request, Deck $deck): JsonResponse
    {
        $user = $request->user();
        $this->ensureAvailable($deck, $user);
        $cards = $deck->cards()->get();
        $progress = CardProgress::where('user_id', $user->id)->whereIn('card_id', $cards->modelKeys())->pluck('status', 'card_id');

        return response()->json([
            ...$this->summary($deck->setAttribute('cards_count', $cards->count()), $user, $progress->filter(fn ($s) => $s === 'known')->count()),
            'cards' => $cards->map(fn (Card $c) => [
                'id' => $c->id,
                'word' => $c->word,
                'definition' => $c->definition,
                'example' => $c->example,
                'translationFr' => $c->translation_fr,
                'translationAr' => $c->translation_ar,
                'status' => $progress[$c->id] ?? 'new',
            ])->values(),
        ]);
    }

    public function reset(Request $request, Deck $deck): Response
    {
        CardProgress::where('user_id', $request->user()->id)
            ->whereIn('card_id', $deck->cards()->select('id'))
            ->delete();

        return response()->noContent();
    }

    public function progress(Request $request, Card $card): Response
    {
        $data = $request->validate(['status' => ['required', 'in:known,learning']]);
        $this->ensureAvailable($card->deck, $request->user());
        CardProgress::updateOrCreate(['user_id' => $request->user()->id, 'card_id' => $card->id], ['status' => $data['status']]);

        return response()->noContent();
    }

    private function ensureAvailable(Deck $deck, User $user): void
    {
        abort_unless($deck->is_published, 404);
        if ($deck->accessFor($user) === 'locked') {
            throw new BusinessException('pack_required', 'Choose a pack to unlock this deck.');
        }
    }

    private function summary(Deck $deck, User $user, int $known): array
    {
        return [
            'id' => $deck->id,
            'title' => $deck->title,
            'description' => $deck->description,
            'topic' => $deck->topic,
            'cardCount' => (int) $deck->cards_count,
            'knownCount' => $known,
            'access' => $deck->accessFor($user),
        ];
    }
}
