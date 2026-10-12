<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Notifications\RenterOtpNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $role
 * @property Carbon|null $otp_verified_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'password', 'must_set_password', 'role', 'otp_verified_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        // A guest who booked without a password holds a random placeholder.
        // Any later change of password - the set-password page, a reset link,
        // the settings page - is one they chose, so stop asking for one.
        static::updating(function (User $user): void {
            if ($user->isDirty('password')) {
                $user->must_set_password = false;
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() || $this->isRenter();
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role->value;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::ADMIN);
    }

    public function isRenter(): bool
    {
        return $this->hasRole(UserRole::RENTER);
    }

    public function isUser(): bool
    {
        return $this->hasRole(UserRole::USER);
    }

    /**
     * A human label for the role, used by the admin panel.
     */
    public function getRoleLabelAttribute(): string
    {
        return match ($this->role) {
            UserRole::ADMIN->value => 'Administrator',
            UserRole::RENTER->value => 'Renter',
            default => 'Client',
        };
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'client_id');
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'client_id');
    }

    /**
     * @return HasManyThrough<Payment, Booking, $this>
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Booking::class, 'client_id', 'booking_id');
    }

    public function assignRole(string $role): self
    {
        if (! UserRole::isValid($role)) {
            throw new \InvalidArgumentException("Invalid user role: {$role}");
        }

        $this->forceFill(['role' => $role])->save();

        return $this;
    }

    /**
     * Only renters confirm a one-time code; admins and clients sign straight in.
     */
    public function requiresOtpVerification(): bool
    {
        return $this->isRenter() && ! $this->otp_verified_at;
    }

    public function sendOtpCode(): string
    {
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $this->notify(new RenterOtpNotification($code));

        return $code;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_set_password' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
