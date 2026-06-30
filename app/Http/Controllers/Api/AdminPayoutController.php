<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstructorPayoutRequest;
use App\Support\InstructorPayoutMethods;
use App\Support\PlatformTenantScope;
use Illuminate\Http\Request;

class AdminPayoutController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $tenantId = PlatformTenantScope::resolveTenantId($request);

        $query = InstructorPayoutRequest::query()
            ->with('instructor:id,name,email,platform_institution_id')
            ->orderByDesc('id');

        if ($tenantId !== null) {
            $query->whereHas('instructor', fn ($q) => $q->where('platform_institution_id', $tenantId));
        }

        if ($status) {
            $query->where('status', $status);
        }

        $rows = $query->get()->map(fn (InstructorPayoutRequest $row) => [
            'id' => $row->id,
            'instructor_id' => $row->instructor_id,
            'instructor_name' => $row->instructor?->name,
            'instructor_email' => $row->instructor?->email,
            'amount' => $row->amount,
            'status' => $row->status,
            'payment_method' => $row->payment_method,
            'payment_method_label' => InstructorPayoutMethods::label($row->payment_method),
            'payment_details' => $row->payment_details,
            'notes' => $row->notes,
            'created_at' => $row->created_at?->toIso8601String(),
            'processed_at' => $row->processed_at?->toIso8601String(),
        ]);

        $pendingQuery = InstructorPayoutRequest::query()
            ->whereIn('status', ['pending', 'processing']);

        if ($tenantId !== null) {
            $pendingQuery->whereHas('instructor', fn ($q) => $q->where('platform_institution_id', $tenantId));
        }

        $pending = $pendingQuery->get();

        return response()->json([
            'payoutRequests' => $rows,
            'pendingCount' => $pending->count(),
            'pendingAmount' => round((float) $pending->sum('amount'), 2),
        ], 200);
    }

    public function approve(InstructorPayoutRequest $payout)
    {
        if (!in_array($payout->status, ['pending', 'processing'], true)) {
            return response()->json(['message' => 'This payout request is no longer pending.'], 422);
        }

        $payout->update([
            'status' => 'paid',
            'processed_at' => now(),
        ]);

        $payout->load('instructor:id,name,email');

        return response()->json([
            'message' => 'Payout approved and marked as paid.',
            'payoutRequest' => $payout,
        ]);
    }

    public function reject(Request $request, InstructorPayoutRequest $payout)
    {
        if (!in_array($payout->status, ['pending', 'processing'], true)) {
            return response()->json(['message' => 'This payout request is no longer pending.'], 422);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $notes = $payout->notes;
        if (!empty($data['reason'])) {
            $notes = trim(($notes ?? '') . "\n[Rejected by admin] " . $data['reason']);
        }

        $payout->update([
            'status' => 'rejected',
            'processed_at' => now(),
            'notes' => $notes ?: $payout->notes,
        ]);

        $payout->load('instructor:id,name,email');

        return response()->json([
            'message' => 'Payout request rejected.',
            'payoutRequest' => $payout,
        ]);
    }
}
