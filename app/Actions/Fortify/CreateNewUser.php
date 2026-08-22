<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $requestedRole = $input['role'] ?? UserRole::USER->value;
        $role = in_array($requestedRole, [UserRole::ADMIN->value, UserRole::USER->value, UserRole::RENTER->value], true)
            ? $requestedRole
            : UserRole::USER->value;

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'role' => $role,
            'password' => $input['password'],
            'otp_verified_at' => $role === UserRole::RENTER->value ? null : now(),
        ]);

        return $user;
    }
}
