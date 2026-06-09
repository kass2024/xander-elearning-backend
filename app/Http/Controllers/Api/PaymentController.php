<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use App\Models\Course;
use App\Models\CoursePayment;

class PaymentController extends Controller
{
    public function createCheckout(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'student_id' => 'required|integer',
        ]);

        $course = Course::findOrFail($validated['course_id']);

        $amount = (int) (($course->price ?? 0) * 100);
        if ($amount <= 0) {
            return response()->json([
                'message' => 'Course price is not set for payments.',
            ], 422);
        }

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
                'success_url' => config('app.url') . '/payment/success',
                'cancel_url' => config('app.url') . '/payment/cancel',
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

    public function createIntent(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'student_id' => 'required|integer',
        ]);

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
