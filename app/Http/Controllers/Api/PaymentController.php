<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;

class PaymentController extends Controller
{
    public function index()
    {
        $payments = CoursePayment::query()
            ->with(['course:id,title,price', 'student:id,first_name,last_name,email,country'])
            ->orderByDesc('id')
            ->get()
            ->map(function (CoursePayment $payment) {
                $student = $payment->student;

                return [
                    'id' => $payment->id,
                    'course_id' => $payment->course_id,
                    'course_title' => $payment->course?->title,
                    'student_id' => $payment->student_id,
                    'student_name' => $student
                        ? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''))
                        : null,
                    'student_email' => $student?->email,
                    'student_country' => $student?->country,
                    'amount' => round($payment->amount_cents / 100, 2),
                    'currency' => strtoupper($payment->currency ?? 'usd'),
                    'provider' => $payment->provider ?? 'stripe',
                    'status' => $payment->status,
                    'paid_at' => $payment->paid_at,
                    'created_at' => $payment->created_at,
                ];
            });

        return response()->json($payments, 200);
    }

    public function updateStatus(Request $request, CoursePayment $payment)
    {
        $data = $request->validate([
            'status' => 'required|string|in:pending,processing,paid,succeeded,completed,failed,cancelled,refunded',
        ]);

        $payment->status = $data['status'];
        if (in_array($data['status'], ['paid', 'succeeded', 'completed'], true) && !$payment->paid_at) {
            $payment->paid_at = now();
        }
        $payment->save();

        return response()->json([
            'message' => 'Payment status updated',
            'payment' => $payment,
        ], 200);
    }

    public function stripeConfig()
    {
        $secret = config('services.stripe.secret');
        $public = config('services.stripe.key');

        return response()->json([
            'configured' => !empty($secret) && !empty($public),
            'publishable_key' => $public ?: null,
            'provider' => 'Stripe',
        ], 200);
    }

    public function createCheckout(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'student_id' => 'required|integer',
        ]);

        if (empty(config('services.stripe.secret'))) {
            return response()->json([
                'message' => 'Stripe is not configured. Set STRIPE_SECRET_KEY and STRIPE_PUBLIC_KEY in backend .env.',
            ], 503);
        }

        $course = Course::findOrFail($validated['course_id']);

        $enrollment = CourseEnrollment::where('student_id', $validated['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'You must apply for this course before paying.',
            ], 422);
        }

        if ($enrollment->status !== 'approved') {
            return response()->json([
                'message' => 'Your enrollment is pending administrator approval. An admin must approve your application in Student Management before you can pay.',
            ], 422);
        }

        $amount = (int) (($course->price ?? 0) * 100);
        if ($amount <= 0) {
            return response()->json([
                'message' => 'Course price is not set for payments.',
            ], 422);
        }

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

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
                    'student_id' => (string) $validated['student_id'],
                ],
                'success_url' => $frontend . '/payment/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $frontend . '/payment/cancel',
            ]);

            CoursePayment::updateOrCreate(
                [
                    'course_id' => $course->id,
                    'student_id' => $validated['student_id'],
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

            return response()->json([
                'url' => $session->url,
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe checkout error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to initiate payment. Please try again later.',
            ], 500);
        }
    }

    public function confirmCheckout(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        if (empty(config('services.stripe.secret'))) {
            return response()->json(['message' => 'Stripe is not configured.'], 503);
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $session = StripeCheckoutSession::retrieve($validated['session_id']);

            if ($session->payment_status !== 'paid') {
                return response()->json(['message' => 'Payment was not completed.'], 422);
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

            return response()->json([
                'message' => 'Payment confirmed. Your course access is now active.',
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe confirm checkout error', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Unable to confirm payment.'], 500);
        }
    }

    public function createIntent(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'student_id' => 'required|integer',
        ]);

        if (empty(config('services.stripe.secret'))) {
            return response()->json([
                'message' => 'Stripe is not configured. Set STRIPE_SECRET_KEY and STRIPE_PUBLIC_KEY in backend .env.',
            ], 503);
        }

        $course = Course::findOrFail($validated['course_id']);

        $amount = (int) (($course->price ?? 0) * 100);
        if ($amount <= 0) {
            return response()->json([
                'message' => 'Course price is not set for payments.',
            ], 422);
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $intent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'usd',
                'metadata' => [
                    'course_id' => (string) $course->id,
                    'student_id' => (string) $validated['student_id'],
                ],
            ]);

            CoursePayment::updateOrCreate(
                [
                    'course_id' => $course->id,
                    'student_id' => $validated['student_id'],
                    'stripe_payment_intent_id' => $intent->id,
                ],
                [
                    'amount_cents' => $amount,
                    'currency' => 'usd',
                    'provider' => 'stripe',
                    'status' => 'pending',
                ]
            );

            return response()->json([
                'client_secret' => $intent->client_secret,
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe payment intent error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to start payment. Please try again later.',
            ], 500);
        }
    }
}
