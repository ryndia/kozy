<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->serialize($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->getKey())],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $user->fill($data)->save();

        return response()->json([
            'message' => 'Profile updated.',
            'user' => $this->serialize($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
