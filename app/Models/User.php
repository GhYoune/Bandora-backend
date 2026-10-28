<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'google_id', 'whatsapp'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Credits given to every new account (the free AI-marked essay). */
    public const FREE_CREDITS = 1;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'credits' => 'integer',
            'pack_started_at' => 'datetime',
            'pack_expires_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin;
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /** free = never had a pack, paid = pack running, expired = pack ended (behaves like free). */
    public function tier(): string
    {
        if (! $this->pack_expires_at) {
            return 'free';
        }

        return $this->pack_expires_at->isFuture() ? 'paid' : 'expired';
    }

    public function hasActivePack(): bool
    {
        return $this->tier() === 'paid';
    }

    /** Unused credits expire with the pack. */
    public function availableCredits(): int
    {
        return $this->tier() === 'expired' ? 0 : $this->credits;
    }
}
