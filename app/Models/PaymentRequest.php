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

    /** wa.me link with the order written out, so the teacher can match the payment to the account. */
    public function whatsappUrl(): string
    {
        $text = "Hello! I would like the {$this->pack_name} pack ({$this->amount_dzd} DA).\n"
            ."Account: {$this->user->email}\n"
            ."Reference: {$this->reference}";

        return 'https://wa.me/'.config('bandora.teacher_whatsapp').'?text='.rawurlencode($text);
    }
}
