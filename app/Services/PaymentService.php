<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Pack;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** WhatsApp payments: the student asks for a pack, the teacher confirms in Filament. */
class PaymentService
{
    public function request(User $user, Pack $pack, string $whatsapp): PaymentRequest
    {
        if (! $user->hasVerifiedEmail()) {
            throw new BusinessException('email_unverified', 'Verify your email address before choosing a pack.');
        }

        return DB::transaction(function () use ($user, $pack, $whatsapp) {
            if ($user->paymentRequests()->where('status', PaymentStatus::Pending)->lockForUpdate()->exists()) {
                throw new BusinessException('payment_pending', 'You already have a payment waiting for confirmation.', 409);
            }
            $user->update(['whatsapp' => $whatsapp]);

            return $user->paymentRequests()->create([
                'pack_id' => $pack->id,
                'reference' => $this->newReference(),
                'pack_name' => $pack->name,
                'amount_dzd' => $pack->price_dzd,
                'whatsapp' => $whatsapp,
                'status' => PaymentStatus::Pending,
            ]);
        });
    }

    public function cancel(PaymentRequest $payment): void
    {
        $this->decide($payment, PaymentStatus::Cancelled, null);
    }

    public function approve(PaymentRequest $payment, User $admin): void
    {
        DB::transaction(function () use ($payment, $admin) {
            $this->decide($payment, PaymentStatus::Approved, $admin);
            $this->activate($payment->user, $payment->pack);
        });
    }

    public function reject(PaymentRequest $payment, User $admin): void
    {
        $this->decide($payment, PaymentStatus::Rejected, $admin);
    }

    /**
     * Starts a pack, or extends the running one: new days are added after the current end date
     * and credits are added. After an expired pack, old credits are gone and the new pack starts today.
     */
    public function activate(User $user, Pack $pack): void
    {
        $active = $user->hasActivePack();
        $user->forceFill([
            'pack_id' => $pack->id,
            'pack_name' => $pack->name,
            'pack_started_at' => $active ? $user->pack_started_at : now(),
            'pack_expires_at' => ($active ? $user->pack_expires_at : now())->copy()->addDays($pack->duration_days),
            'credits' => ($user->tier() === 'expired' ? 0 : $user->credits) + $pack->credits,
        ])->save();
    }

    private function decide(PaymentRequest $payment, PaymentStatus $status, ?User $admin): void
    {
        $locked = PaymentRequest::whereKey($payment->id)->lockForUpdate()->first();
        if ($locked->status !== PaymentStatus::Pending) {
            throw new BusinessException('already_decided', 'This payment has already been handled.', 409);
        }
        $payment->update(['status' => $status, 'decided_at' => now(), 'decided_by' => $admin?->id]);
    }

    /** 4 characters, no look-alikes (0/O, 1/I), easy to read out on WhatsApp. */
    private function newReference(): string
    {
        do {
            $reference = collect(range(1, 4))
                ->map(fn () => Str::of('ABCDEFGHJKLMNPQRSTUVWXYZ23456789')->charAt(random_int(0, 31)))
                ->implode('');
        } while (PaymentRequest::where('reference', $reference)->exists());

        return $reference;
    }
}
