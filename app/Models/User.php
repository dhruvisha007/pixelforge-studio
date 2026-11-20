<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Support\Money;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user) {
            $user->creditTransactions()->create([
                'type' => CreditTransactionType::Welcome,
                'amount_nanodollars' => Money::fromUsd(config('studio.welcome_credit_usd')),
                'description' => CreditTransactionType::Welcome->label(),
            ]);
        });
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** Credits bought minus credits spent, in nanodollars. */
    public function balance(): int
    {
        return (int) $this->creditTransactions()->sum('amount_nanodollars');
    }

    /**
     * Held back for images still in progress: the most each one can cost. The hold
     * disappears when the image finishes (and is charged) or fails (and is free).
     */
    public function reservedCredit(): int
    {
        return (int) $this->generations()
            ->whereIn('status', GenerationStatus::inProgress())
            ->sum('estimated_max_charge_nanodollars');
    }

    public function availableCredit(): int
    {
        return $this->balance() - $this->reservedCredit();
    }
}
