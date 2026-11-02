<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'pack_id', 'reference', 'pack_name', 'amount_dzd', 'whatsapp', 'status', 'decided_at', 'decided_by'])]
class PaymentRequest extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The order written out, so the teacher can match the payment to the account. */
    public function message(): string
    {
        return "Hello! I would like the {$this->pack_name} pack ({$this->amount_dzd} DA).\n"
            ."Account: {$this->user->email}\n"
            ."Reference: {$this->reference}";
    }

    public function whatsappUrl(): string
    {
        return 'https://wa.me/'.config('bandora.teacher_whatsapp').'?text='.rawurlencode($this->message());
    }

    /** Opens the chat with the teacher; the student pastes the message. */
    public function telegramUrl(): ?string
    {
        $username = ltrim((string) config('bandora.teacher_telegram'), '@');

        return $username !== '' ? "https://t.me/{$username}" : null;
    }

    public function teacherPhone(): ?string
    {
        return filled(config('bandora.teacher_phone')) ? (string) config('bandora.teacher_phone') : null;
    }
}
