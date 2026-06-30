<?php

namespace App\Services;

use App\Models\InstitutionPayment;
use App\Models\InstitutionPromoCode;
use App\Models\PlatformInstitution;
use App\Models\User;
use App\Mail\InstitutionWelcomeMail;
use App\Support\FrontendUrl;
use App\Support\PlatformInstitutionHelper;
use App\Support\PlatformUserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Stripe;

class InstitutionSignupService
{
    public function __construct(
        private readonly StripePaymentService $stripePaymentService,
        private readonly MailDeliveryService $mailDelivery,
    ) {}

    public function signupFeeCents(): int
    {
        return (int) config('institution.signup_fee_cents', 9900);
    }

    public function register(array $data, ?string $promoCode = null): array
    {
        $email = strtolower(trim((string) ($data['contact_email'] ?? '')));
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return ['ok' => false, 'status' => 422, 'message' => 'An account with this email already exists.'];
        }

        $promo = null;
        if ($promoCode) {
            $promo = InstitutionPromoCode::whereRaw('UPPER(code) = ?', [strtoupper(trim($promoCode))])->first();
            if (!$promo || !$promo->isRedeemable()) {
                return ['ok' => false, 'status' => 422, 'message' => 'Invalid or expired promo code.'];
            }
        }

        return DB::transaction(function () use ($data, $email, $promo) {
            $feeCents = $this->signupFeeCents();
            $institution = PlatformInstitution::create([
                'name' => trim((string) $data['institution_name']),
                'slug' => PlatformInstitutionHelper::uniqueSlug((string) $data['institution_name']),
                'contact_email' => $email,
                'contact_phone' => $data['contact_phone'] ?? null,
                'website' => $data['website'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => 'pending_approval',
                'payment_status' => $promo ? 'promo' : 'unpaid',
                'signup_fee_cents' => $feeCents,
                'currency' => config('institution.signup_currency', 'usd'),
                'promo_code_id' => $promo?->id,
            ]);

            $plainPassword = $this->generateOwnerPassword();
            $user = User::create([
                'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                'email' => $email,
                'password' => $plainPassword,
                'role' => 'partner_company',
                'status' => $promo ? 'Unpaid' : 'Pending',
                'phone' => $data['contact_phone'] ?? '',
                'platform_institution_id' => $institution->id,
            ]);

            $institution->owner_user_id = $user->id;
            $institution->save();

            $this->sendOwnerCredentials($institution->fresh(), $user, $plainPassword);

            if ($promo) {
                $promo->increment('uses_count');
                return [
                    'ok' => true,
                    'institution' => $institution->fresh(),
                    'requires_payment' => false,
                    'message' => 'Registration submitted. Login credentials have been emailed to you. Pending admin approval.',
                ];
            }

            $checkout = $this->createSignupCheckout($institution);
            if (!$checkout['ok']) {
                throw new \RuntimeException($checkout['message'] ?? 'Stripe checkout failed');
            }

            return [
                'ok' => true,
                'institution' => $institution->fresh(),
                'requires_payment' => true,
                'checkout_url' => $checkout['checkout_url'],
                'message' => 'Registration started. Login credentials have been emailed to you. Complete payment to continue.',
            ];
        });
    }

    public function generateOwnerPassword(): string
    {
        return Str::password(12);
    }

    public function sendOwnerCredentials(PlatformInstitution $institution, User $user, string $plainPassword, bool $isResend = false): bool
    {
        return $this->mailDelivery->sendToForInstitution(
            $institution->id,
            $user->email,
            new InstitutionWelcomeMail(
                $institution,
                $user->email,
                $plainPassword,
                rtrim(FrontendUrl::base(), '/') . '/login',
                $isResend,
            ),
        );
    }

    public function resendOwnerCredentials(PlatformInstitution $institution): array
    {
        $user = $institution->owner;
        if (!$user) {
            return ['ok' => false, 'status' => 404, 'message' => 'Institution owner account not found.'];
        }

        $plainPassword = $this->generateOwnerPassword();
        PlatformUserService::setUserPassword($user, $plainPassword);

        $sent = $this->sendOwnerCredentials($institution->fresh(), $user, $plainPassword, true);
        if (!$sent) {
            return ['ok' => false, 'status' => 500, 'message' => 'Failed to send credentials email.'];
        }

        return ['ok' => true, 'message' => 'New login credentials emailed to ' . $user->email];
    }

    public function createSignupCheckout(PlatformInstitution $institution): array
    {
        $ready = $this->stripePaymentService->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        $payment = InstitutionPayment::create([
            'platform_institution_id' => $institution->id,
            'amount_cents' => $institution->signup_fee_cents ?: $this->signupFeeCents(),
            'currency' => $institution->currency ?: 'usd',
            'type' => 'signup',
            'status' => 'pending',
        ]);

        $frontend = rtrim(FrontendUrl::base(), '/');
        $session = StripeCheckoutSession::create([
            'mode' => 'payment',
            'customer_email' => $institution->contact_email,
            'line_items' => [[
                'price_data' => [
                    'currency' => $payment->currency,
                    'unit_amount' => $payment->amount_cents,
                    'product_data' => [
                        'name' => config('institution.signup_product_name', 'Partner Institution Platform Access'),
                        'description' => $institution->name,
                    ],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $frontend . '/institution-signup/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontend . '/institution-signup?cancelled=1',
            'metadata' => [
                'type' => 'institution_signup',
                'platform_institution_id' => (string) $institution->id,
                'institution_payment_id' => (string) $payment->id,
            ],
        ]);

        $payment->stripe_session_id = $session->id;
        $payment->save();

        return ['ok' => true, 'checkout_url' => $session->url];
    }

    public function completeSignupPayment(string $sessionId): ?PlatformInstitution
    {
        $ready = $this->stripePaymentService->assertReady();
        if (!$ready['ok']) {
            return null;
        }

        Stripe::setApiKey(config('services.stripe.secret'));
        $session = StripeCheckoutSession::retrieve($sessionId);

        if (($session->payment_status ?? '') !== 'paid') {
            return null;
        }

        $institutionId = (int) ($session->metadata['platform_institution_id'] ?? 0);
        $paymentId = (int) ($session->metadata['institution_payment_id'] ?? 0);
        $institution = PlatformInstitution::find($institutionId);
        $payment = InstitutionPayment::find($paymentId);

        if (!$institution || !$payment) {
            return null;
        }

        $payment->status = 'paid';
        $payment->stripe_payment_intent_id = $session->payment_intent ?? null;
        $payment->paid_at = now();
        $payment->save();

        $institution->payment_status = 'paid';
        $institution->stripe_customer_id = $session->customer ?? $institution->stripe_customer_id;
        $institution->save();

        if ($institution->owner_user_id) {
            User::where('id', $institution->owner_user_id)->update(['status' => 'Pending']);
        }

        return $institution->fresh();
    }
}
