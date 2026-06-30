<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstitutionPromoCode;
use App\Models\PlatformInstitution;
use App\Services\InstitutionSignupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class InstitutionSignupController extends Controller
{
    public function __construct(private readonly InstitutionSignupService $signupService) {}

    public function config()
    {
        return response()->json([
            'signup_fee_cents' => $this->signupService->signupFeeCents(),
            'currency' => config('institution.signup_currency', 'usd'),
            'product_name' => config('institution.signup_product_name'),
        ]);
    }

    /** Active partner institutions for learner signup dropdown */
    public function choices()
    {
        if (!Schema::hasTable('platform_institutions')) {
            return response()->json([]);
        }

        $institutions = PlatformInstitution::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return response()->json($institutions->map(fn (PlatformInstitution $i) => $i->toPublicArray())->values());
    }

    public function validatePromo(Request $request)
    {
        $code = strtoupper(trim((string) $request->input('code', '')));
        $promo = InstitutionPromoCode::whereRaw('UPPER(code) = ?', [$code])->first();

        if (!$promo || !$promo->isRedeemable()) {
            return response()->json(['valid' => false, 'message' => 'Invalid or expired promo code.']);
        }

        return response()->json(['valid' => true, 'label' => $promo->label]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'institution_name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'address' => 'nullable|string|max:1000',
            'promo_code' => 'nullable|string|max:64',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,gif,webp|max:5120',
        ]);

        $result = $this->signupService->register($data, $data['promo_code'] ?? null);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], $result['status'] ?? 422);
        }

        $institution = $result['institution'];
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('uploads', 'public');
            $institution->logo_path = $path;
            $institution->save();
        }

        return response()->json([
            'message' => $result['message'] ?? 'Registration started',
            'institution' => $institution->toPublicArray(),
            'requires_payment' => $result['requires_payment'] ?? false,
            'checkout_url' => $result['checkout_url'] ?? null,
        ], 201);
    }

    public function completePayment(Request $request)
    {
        $sessionId = (string) $request->input('session_id', '');
        if ($sessionId === '') {
            return response()->json(['message' => 'session_id required'], 422);
        }

        $institution = $this->signupService->completeSignupPayment($sessionId);
        if (!$institution) {
            return response()->json(['message' => 'Payment not completed'], 422);
        }

        return response()->json([
            'message' => 'Payment received. Your institution is pending admin approval.',
            'institution' => $institution->toPublicArray(),
        ]);
    }
}
