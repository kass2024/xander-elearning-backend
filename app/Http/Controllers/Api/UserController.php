<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Support\ApiListCache;
use App\Support\PlatformTenantScope;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = PlatformTenantScope::resolveTenantId($request);

        if ($tenantId !== null) {
            $users = User::query()
                ->select(['id', 'name', 'email', 'role', 'status', 'phone', 'platform_institution_id', 'created_at'])
                ->where('platform_institution_id', $tenantId)
                ->with('platformInstitution:id,name,logo_path,logo_url,status,payment_status')
                ->orderByDesc('id')
                ->get();

            return response()->json($users, 200);
        }

        $users = ApiListCache::remember('users', 'all', 120, function () {
            return User::query()
                ->select(['id', 'name', 'email', 'role', 'status', 'phone', 'platform_institution_id', 'created_at'])
                ->with('platformInstitution:id,name,logo_path,logo_url,status,payment_status')
                ->orderByDesc('id')
                ->get();
        });

        return response()->json($users, 200);
    }

    public function instructorsWithCourses(Request $request)
    {
        $tenantId = PlatformTenantScope::resolveTenantId($request);

        if ($tenantId !== null) {
            $instructors = User::query()
                ->where('role', 'instructor')
                ->where('platform_institution_id', $tenantId)
                ->select(['id', 'name', 'email', 'role', 'status', 'phone', 'platform_institution_id'])
                ->with(['assignedCourses:id,title,status,platform_institution_id'])
                ->orderByDesc('id')
                ->get();

            return response()->json($instructors, 200);
        }

        $instructors = ApiListCache::remember('instructors', 'with_courses', 120, function () {
            return User::query()
                ->where('role', 'instructor')
                ->select(['id', 'name', 'email', 'role', 'status', 'phone'])
                ->with(['assignedCourses:id,title,status'])
                ->orderByDesc('id')
                ->get();
        });

        return response()->json($instructors, 200);
    }

    public function instructorAssignedCourses(Request $request)
    {
        $email = $request->query('email');

        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = User::where('email', $email)
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $instructor->load('assignedCourses');

        return response()->json([
            'instructor' => $instructor,
            'courses' => $instructor->assignedCourses,
        ], 200);
    }

    /**
     * Update the authenticated user's profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }

    /**
     * Change the authenticated user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        $user->update([
            'password' => $request->new_password,
        ]);

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'nullable|string|min:6',
            'role' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'platform_institution_id' => 'nullable|integer|exists:platform_institutions,id',
        ]);

        $currentUser = auth()->user();

        if (
            $currentUser &&
            $currentUser->role === 'staff' &&
            !empty($data['role']) &&
            $data['role'] === 'admin'
        ) {
            return response()->json([
                'message' => 'Staff users are not allowed to create admin accounts.',
            ], 403);
        }

        if (empty($data['role'])) {
            if ($currentUser && $currentUser->role === 'staff') {
                $data['role'] = 'staff';
            } else {
                $data['role'] = 'admin';
            }
        }

        if (!empty($data['password'])) {
            // plain — User model `hashed` cast hashes on save
        } elseif ($data['role'] === 'instructor' || $data['role'] === 'partner_company') {
            $data['password'] = '12345678';
        }

        if (empty($data['status'])) {
            $data['status'] = 'Active';
        }

        // Ensure phone is always set so DB insert does not fail if column is non-nullable
        if (!array_key_exists('phone', $data) || $data['phone'] === null) {
            $data['phone'] = '';
        }

        PlatformTenantScope::stampInstitutionId($request, $data);

        $user = User::create($data);

        ApiListCache::bump('users');
        if (($data['role'] ?? '') === 'instructor') {
            ApiListCache::bump('instructors');
        }

        return response()->json([
            'message' => 'User created',
            'user' => $user->makeHidden(['password']),
        ], 201);
    }

    public function updatePassword(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'password' => 'required|string|min:6',
        ]);
        $user->password = $data['password'];
        $user->save();
        return response()->json(['message' => 'Password updated', 'user' => $user->makeHidden(['password'])]);
    }

    public function uploadAvatar(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $validated = $request->validate([
            'avatar' => 'required|file|max:10240|mimes:png,jpg,jpeg',
        ]);
        $file = $validated['avatar'];
        $path = $file->store('uploads', 'public');
        $url = asset('storage/' . $path);
        // ensure column exists
        if (!Schema::hasColumn('users', 'avatar')) {
            return response()->json(['message' => 'Avatar column missing on users table'], 500);
        }
        $user->avatar = $url;
        $user->save();
        return response()->json(['message' => 'Avatar updated', 'url' => $url]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'platform_institution_id' => 'nullable|integer|exists:platform_institutions,id',
        ]);

        $currentUser = auth()->user();

        if (
            $currentUser &&
            $currentUser->role === 'staff' &&
            array_key_exists('role', $data) &&
            $data['role'] === 'admin'
        ) {
            return response()->json([
                'message' => 'Staff users are not allowed to assign admin role.',
            ], 403);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->fill($data);
        $user->save();

        ApiListCache::bump('users');
        ApiListCache::bump('instructors');

        return response()->json([
            'message' => 'User updated',
            'user' => $user->makeHidden(['password']),
        ]);
    }

    public function destroy(User $user)
    {
        $user->delete();
        ApiListCache::bump('users');
        ApiListCache::bump('instructors');

        return response()->json(['message' => 'User deleted']);
    }
}
