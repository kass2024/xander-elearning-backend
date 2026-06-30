<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePayment;
use App\Support\ApiListCache;
use App\Support\PlatformTenantScope;
use App\Services\StripePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private readonly StripePaymentService $stripePayments
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = PlatformTenantScope::resolveTenantId($request);

        if ($tenantId !== null) {
            $courseIds = PlatformTenantScope::tenantCourseIds($tenantId);

            $payments = CoursePayment::query()
                ->whereIn('course_id', $courseIds ?: [-1])
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

        $payments = ApiListCache::remember('payments', 'admin_all', 120, function () {
            return CoursePayment::query()
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

        ApiListCache::bump('payments');
        ApiListCache::bump('analytics');

        return response()->json([
            'message' => 'Payment status updated',
            'payment' => $payment,
        ], 200);
    }

    public function stripeConfig()
    {
        $configured = $this->stripePayments->isConfigured() && $this->stripePayments->isSdkInstalled();

        return response()->json([
            'configured' => $configured,
            'publishable_key' => config('services.stripe.key') ?: null,
            'provider' => 'Stripe',
            'sdk_installed' => $this->stripePayments->isSdkInstalled(),
        ], 200);
    }

    public function createCheckout(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'student_id' => 'required|integer',
        ]);

        $course = Course::findOrFail($validated['course_id']);

        try {
            $result = $this->stripePayments->createCheckoutSession(
                $course,
                (int) $validated['student_id']
            );

            if (empty($result['ok'])) {
                return response()->json([
                    'message' => $result['message'] ?? 'Unable to initiate payment.',
                ], $result['status'] ?? 500);
            }

            return response()->json([
                'url' => $result['url'],
            ]);
        } catch (\Throwable $e) {
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

        try {
            $result = $this->stripePayments->confirmCheckoutSession($validated['session_id']);

            if (empty($result['ok'])) {
                return response()->json([
                    'message' => $result['message'] ?? 'Unable to confirm payment.',
                ], $result['status'] ?? 500);
            }

            return response()->json([
                'message' => $result['message'],
            ]);
        } catch (\Throwable $e) {
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

        $course = Course::findOrFail($validated['course_id']);

        try {
            $result = $this->stripePayments->createPaymentIntent(
                $course,
                (int) $validated['student_id']
            );

            if (empty($result['ok'])) {
                return response()->json([
                    'message' => $result['message'] ?? 'Unable to start payment.',
                ], $result['status'] ?? 500);
            }

            return response()->json([
                'client_secret' => $result['client_secret'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe payment intent error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to start payment. Please try again later.',
            ], 500);
        }
    }
}
