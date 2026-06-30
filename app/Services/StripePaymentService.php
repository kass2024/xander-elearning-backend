<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;
use App\Support\EnrollmentStatusHelper;
use App\Support\FrontendUrl;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class StripePaymentService
{
    public function isSdkInstalled(): bool
    {
        return class_exists(Stripe::class);
    }

    public function isConfigured(): bool
    {
        return !empty(config('services.stripe.secret')) && !empty(config('services.stripe.key'));
    }

    /**
     * @return array{ok: true}|array{ok: false, message: string, status: int}
     */
    public function assertReady(): array
    {
        if (!$this->isSdkInstalled()) {
            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Stripe PHP SDK is not installed on the server. SSH into the API host and run: composer install --no-dev',
            ];
        }

        if (empty(config('services.stripe.secret'))) {
            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Stripe is not configured. Set STRIPE_SECRET_KEY and STRIPE_PUBLIC_KEY in backend .env.',
            ];
        }

        return ['ok' => true];
    }

    private function configureStripe(): void
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createCheckoutSession(Course $course, int $studentId): array
    {
        $ready = $this->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        $enrollment = CourseEnrollment::where('student_id', $studentId)
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'You must apply for this course before paying.',
            ];
        }

        if (!EnrollmentStatusHelper::canPay($enrollment->status)) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Payment is only available for approved enrollments that have not been paid yet.',
            ];
        }

        $amount = (int) (($course->price ?? 0) * 100);
        if ($amount <= 0) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Course price is not set for payments.',
            ];
        }

        $frontend = FrontendUrl::base();

        $this->configureStripe();

        $session = StripeCheckoutSession::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => (string) $course->title,
                    ],
                    'unit_amount' => $amount,
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'course_id' => (string) $course->id,
                'student_id' => (string) $studentId,
            ],
            'success_url' => $frontend . '/payment/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontend . '/payment/cancel',
        ]);

        CoursePayment::updateOrCreate(
            [
                'course_id' => $course->id,
                'student_id' => $studentId,
                'stripe_session_id' => $session->id,
            ],
            [
                'amount_cents' => $amount,
                'currency' => 'usd',
                'provider' => 'stripe',
                'status' => 'pending',
                'metadata' => [
                    'checkout_url' => $session->url,
                ],
            ]
        );

        return [
            'ok' => true,
            'url' => $session->url,
        ];
    }

    public function confirmCheckoutSession(string $sessionId): array
    {
        $ready = $this->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        $this->configureStripe();
        $session = StripeCheckoutSession::retrieve($sessionId);

        if ($session->payment_status !== 'paid') {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Payment was not completed.',
            ];
        }

        $courseId = (int) ($session->metadata['course_id'] ?? 0);
        $studentId = (int) ($session->metadata['student_id'] ?? 0);

        if ($courseId && $studentId) {
            $enrollment = CourseEnrollment::where('student_id', $studentId)
                ->where('course_id', $courseId)
                ->first();

            if ($enrollment && in_array($enrollment->status, ['approved', 'paid'], true)) {
                $enrollment->status = 'paid';
                $enrollment->save();
            }
        }

        CoursePayment::query()
            ->where('stripe_session_id', $session->id)
            ->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

        return [
            'ok' => true,
            'message' => 'Payment confirmed. Thank you for your payment.',
        ];
    }

    public function createPaymentIntent(Course $course, int $studentId): array
    {
        $ready = $this->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        $amount = (int) (($course->price ?? 0) * 100);
        if ($amount <= 0) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Course price is not set for payments.',
            ];
        }

        $this->configureStripe();

        $intent = PaymentIntent::create([
            'amount' => $amount,
            'currency' => 'usd',
            'metadata' => [
                'course_id' => (string) $course->id,
                'student_id' => (string) $studentId,
            ],
        ]);

        CoursePayment::updateOrCreate(
            [
                'course_id' => $course->id,
                'student_id' => $studentId,
                'stripe_payment_intent_id' => $intent->id,
            ],
            [
                'amount_cents' => $amount,
                'currency' => 'usd',
                'provider' => 'stripe',
                'status' => 'pending',
            ]
        );

        return [
            'ok' => true,
            'client_secret' => $intent->client_secret,
        ];
    }
}
