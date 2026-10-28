<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Api;
use App\Models\Pack;
use App\Models\PaymentRequest;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentController extends Controller
{
    public function __construct(private Api $present, private PaymentService $payments) {}

    /** Public: the landing page can read the packs too. */
    public function packs(): JsonResponse
    {
        return response()->json(
            Pack::where('is_active', true)->orderBy('sort')->get()->map(fn (Pack $p) => $this->present->pack($p))
        );
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->paymentRequests()->with('user')->latest()->get()->map(fn (PaymentRequest $p) => $this->present->payment($p))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['whatsapp' => preg_replace('/\s+/', '', (string) $request->input('whatsapp'))]);
        $data = $request->validate([
            'packId' => ['required', 'string', 'exists:packs,id'],
            'whatsapp' => ['required', 'string', AccountController::PHONE_RULE],
        ], [
            'packId.exists' => 'Choose a pack.',
            'whatsapp.regex' => 'Enter a valid WhatsApp number, for example +213 550 12 34 56.',
        ]);

        $payment = $this->payments->request($request->user(), Pack::findOrFail($data['packId']), $data['whatsapp']);

        return response()->json($this->present->payment($payment->load('user')), 201);
    }

    public function destroy(Request $request, PaymentRequest $payment): Response
    {
        abort_unless($payment->user_id === $request->user()->id, 404);
        $this->payments->cancel($payment);

        return response()->noContent();
    }
}
