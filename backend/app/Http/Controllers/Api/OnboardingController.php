<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Services\HotelOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class OnboardingController extends ApiController
{
    public function store(Request $request, HotelOnboardingService $onboarding): JsonResponse
    {
        abort_if(Hotel::query()->exists(), 403, 'Initial onboarding has already been completed.');

        $data = $request->validate([
            'hotel_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'hotel_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'timezone'],
            'outlet_name' => ['required', 'string', 'max:255'],
            'outlet_code' => ['required', 'alpha_dash', 'max:30'],
            'invoice_prefix' => ['sometimes', 'alpha_dash', 'max:20'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $created = $onboarding->createFirstHotel($data);
        Auth::login($created['user'], true);
        $request->session()->regenerate();

        return $this->success([
            'user' => $created['user']->only(['id', 'name', 'email']),
            'hotel' => $created['hotel'],
            'outlet' => $created['outlet'],
        ], 'Hotel setup completed.', 201);
    }
}
