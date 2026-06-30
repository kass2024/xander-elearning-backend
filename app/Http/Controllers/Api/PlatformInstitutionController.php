<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\InstitutionPaymentReminderMail;
use App\Models\InstitutionPromoCode;
use App\Models\PlatformInstitution;
use App\Models\User;
use App\Services\InstitutionSignupService;
use App\Services\InstitutionMailResolver;
use App\Services\MailDeliveryService;
use App\Support\PlatformInstitutionHelper;
use App\Support\FrontendUrl;
use App\Support\PublicStorageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PlatformInstitutionController extends Controller
{
    public function __construct(
        private readonly InstitutionSignupService $signupService,
        private readonly InstitutionMailResolver $mailResolver,
        private readonly MailDeliveryService $mailDelivery,
    ) {}

    public function index()
    {
        if (!Schema::hasTable('platform_institutions')) {
            return response()->json([]);
        }

        $rows = PlatformInstitution::query()
            ->with(['owner:id,name,email,status', 'payments'])
            ->orderByDesc('id')
            ->get()
            ->map(function (PlatformInstitution $inst) {
                $paid = $inst->payments->where('status', 'paid')->sum('amount_cents');

                return array_merge($inst->toAdminArray(), [
                    'owner' => $inst->owner ? [
                        'id' => $inst->owner->id,
                        'name' => $inst->owner->name,
                        'email' => $inst->owner->email,
                        'status' => $inst->owner->status,
                    ] : null,
                    'total_paid_cents' => $paid,
                    'payments_count' => $inst->payments->count(),
                ]);
            });

        return response()->json($rows);
    }

    public function context(Request $request)
    {
        $email = strtolower(trim((string) $request->query('email', '')));
        if ($email === '') {
            return response()->json(['institution' => null, 'is_main_admin' => false]);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user) {
            return response()->json(['institution' => null, 'is_main_admin' => false]);
        }

        $institution = PlatformInstitutionHelper::resolveForUser($user);

        return response()->json([
            'institution' => PlatformInstitutionHelper::institutionPayload($institution),
            'is_main_admin' => PlatformInstitutionHelper::isMainPlatformAdmin($user),
            'role' => $user->role,
        ]);
    }

    public function approve(PlatformInstitution $platformInstitution, Request $request)
    {
        $platformInstitution->status = 'active';
        $platformInstitution->approved_at = now();
        $platformInstitution->approved_by = $request->input('approved_by');
        $platformInstitution->save();

        if ($platformInstitution->owner_user_id) {
            $ownerStatus = $platformInstitution->payment_status === 'unpaid' ? 'Unpaid' : 'Active';
            User::where('id', $platformInstitution->owner_user_id)->update(['status' => $ownerStatus]);
        }

        return response()->json([
            'message' => 'Institution approved',
            'institution' => $platformInstitution->fresh()->toPublicArray(),
        ]);
    }

    public function disable(PlatformInstitution $platformInstitution)
    {
        $platformInstitution->status = 'disabled';
        $platformInstitution->save();

        User::where('platform_institution_id', $platformInstitution->id)
            ->update(['status' => 'Inactive']);

        return response()->json([
            'message' => 'Institution disabled',
            'institution' => $platformInstitution->fresh()->toPublicArray(),
        ]);
    }

    public function enable(PlatformInstitution $platformInstitution)
    {
        $platformInstitution->status = 'active';
        if (!$platformInstitution->approved_at) {
            $platformInstitution->approved_at = now();
        }
        $platformInstitution->save();

        if ($platformInstitution->owner_user_id) {
            $ownerStatus = $platformInstitution->payment_status === 'unpaid' ? 'Unpaid' : 'Active';
            User::where('id', $platformInstitution->owner_user_id)->update(['status' => $ownerStatus]);
        }

        return response()->json([
            'message' => 'Institution enabled',
            'institution' => $platformInstitution->fresh()->toPublicArray(),
        ]);
    }

    public function resendCredentials(PlatformInstitution $platformInstitution)
    {
        $platformInstitution->load('owner');
        $result = $this->signupService->resendOwnerCredentials($platformInstitution);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], $result['status'] ?? 500);
        }

        return response()->json(['message' => $result['message']]);
    }

    public function destroy(PlatformInstitution $platformInstitution)
    {
        User::where('platform_institution_id', $platformInstitution->id)->delete();
        $platformInstitution->payments()->delete();
        $platformInstitution->delete();

        return response()->json(['message' => 'Institution removed']);
    }

    public function sendPaymentReminder(PlatformInstitution $platformInstitution)
    {
        $checkout = $this->signupService->createSignupCheckout($platformInstitution);
        if (!$checkout['ok']) {
            return response()->json(
                ['message' => $checkout['message'] ?? 'Could not create payment link'],
                $checkout['status'] ?? 500
            );
        }

        $sent = $this->mailDelivery->sendToForInstitution(
            $platformInstitution->id,
            $platformInstitution->contact_email,
            new InstitutionPaymentReminderMail($platformInstitution, $checkout['checkout_url']),
        );

        if (!$sent) {
            return response()->json(['message' => 'Failed to send payment reminder email'], 500);
        }

        return response()->json([
            'message' => 'Payment reminder sent',
            'checkout_url' => $checkout['checkout_url'],
        ]);
    }

    public function show(PlatformInstitution $platformInstitution)
    {
        $platformInstitution->load(['owner:id,name,email,status', 'payments']);

        return response()->json($platformInstitution->toAdminArray());
    }

    public function update(PlatformInstitution $platformInstitution, Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'contact_email' => 'sometimes|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'address' => 'nullable|string|max:1000',
            'admin_notes' => 'nullable|string|max:5000',
            'mail_use_custom' => 'sometimes|boolean',
            'mail_host' => 'nullable|string|max:255',
            'mail_port' => 'nullable|integer|min:1|max:65535',
            'mail_username' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:255',
            'mail_encryption' => 'nullable|string|in:ssl,tls,none',
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:255',
            'mail_ehlo_domain' => 'nullable|string|max:255',
        ]);

        if (array_key_exists('mail_password', $data)) {
            $plain = trim((string) $data['mail_password']);
            if ($plain !== '') {
                $data['mail_password'] = $this->mailResolver->encryptPassword($plain);
            } else {
                unset($data['mail_password']);
            }
        }

        if (isset($data['mail_encryption']) && $data['mail_encryption'] === 'none') {
            $data['mail_encryption'] = null;
        }

        $platformInstitution->fill($data);
        $platformInstitution->save();

        return response()->json([
            'message' => 'Institution updated',
            'institution' => $platformInstitution->fresh()->toAdminArray(),
        ]);
    }

    public function mySettings(Request $request)
    {
        $email = strtolower(trim((string) $request->query('email', '')));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $institution = PlatformInstitutionHelper::resolveForUser($user);
        if (!$institution) {
            return response()->json(['message' => 'No institution linked'], 404);
        }

        return response()->json(['institution' => $institution->toPublicArray()]);
    }

    public function updateMyBranding(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'name' => 'sometimes|string|max:255',
            'website' => 'nullable|url|max:255',
            'address' => 'nullable|string|max:1000',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,gif,webp|max:5120',
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->first();
        if (!$user || strtolower((string) $user->role) !== 'partner_company') {
            return response()->json(['message' => 'Partner access required'], 403);
        }

        $institution = PlatformInstitutionHelper::resolveForUser($user);
        if (!$institution) {
            return response()->json(['message' => 'Institution not found'], 404);
        }

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('uploads', 'public');
            $institution->logo_path = $path;
        }

        if (isset($data['name'])) {
            $institution->name = $data['name'];
        }
        if (array_key_exists('website', $data)) {
            $institution->website = $data['website'];
        }
        if (array_key_exists('address', $data)) {
            $institution->address = $data['address'];
        }

        $institution->save();

        return response()->json([
            'message' => 'Institution branding updated',
            'institution' => $institution->fresh()->toPublicArray(),
        ]);
    }

    public function sendTestMail(PlatformInstitution $platformInstitution, Request $request)
    {
        $to = trim((string) $request->input('to', $platformInstitution->contact_email));
        if ($to === '') {
            return response()->json(['message' => 'Recipient email required'], 422);
        }

        $sent = $this->mailDelivery->sendToForInstitution(
            $platformInstitution->id,
            $to,
            new InstitutionPaymentReminderMail(
                $platformInstitution,
                FrontendUrl::base() . '/institution-signup',
            ),
        );

        return response()->json([
            'ok' => $sent,
            'message' => $sent ? 'Test email sent' : 'Test email failed — check SMTP settings or platform default mail',
        ], $sent ? 200 : 500);
    }

    public function promoCodes()
    {
        return response()->json(InstitutionPromoCode::orderByDesc('id')->get());
    }

    public function storePromoCode(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:64|unique:institution_promo_codes,code',
            'label' => 'nullable|string|max:255',
            'max_uses' => 'nullable|integer|min:1|max:10000',
            'expires_at' => 'nullable|date',
        ]);

        $promo = InstitutionPromoCode::create([
            'code' => strtoupper(trim($data['code'])),
            'label' => $data['label'] ?? null,
            'max_uses' => $data['max_uses'] ?? 1,
            'created_by' => $request->input('created_by'),
        ]);

        return response()->json(['message' => 'Promo code created', 'promo_code' => $promo], 201);
    }

    public function uploadLogo(Request $request, PlatformInstitution $platformInstitution)
    {
        $request->validate(['logo' => 'required|file|mimes:png,jpg,jpeg,gif,webp|max:5120']);
        $path = $request->file('logo')->store('uploads', 'public');
        $platformInstitution->logo_path = $path;
        $platformInstitution->save();

        return response()->json([
            'message' => 'Logo updated',
            'logo_url' => $platformInstitution->fresh()->logo_url,
        ]);
    }
}