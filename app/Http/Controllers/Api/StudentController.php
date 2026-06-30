<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\PlatformInstitution;
use App\Support\ApiListCache;
use App\Support\PlatformTenantScope;
use App\Services\MailDeliveryService;
use App\Services\StudentRegistrationEmailService;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = PlatformTenantScope::resolveTenantId($request);

        if ($tenantId !== null) {
            $students = Student::query()
                ->select(['id', 'first_name', 'last_name', 'email', 'status', 'phone', 'country', 'primary_goal', 'platform_institution_id', 'created_at'])
                ->where('platform_institution_id', $tenantId)
                ->with('platformInstitution:id,name')
                ->orderByDesc('id')
                ->get()
                ->map(function (Student $student) {
                    $row = $student->toArray();
                    $row['institution_name'] = $student->platformInstitution?->name;

                    return $row;
                });

            return response()->json($students, 200);
        }

        $students = ApiListCache::remember('students', 'all', 120, function () {
            return Student::query()
                ->select(['id', 'first_name', 'last_name', 'email', 'status', 'phone', 'country', 'primary_goal', 'platform_institution_id', 'created_at'])
                ->with('platformInstitution:id,name')
                ->orderByDesc('id')
                ->get()
                ->map(function (Student $student) {
                    $row = $student->toArray();
                    $row['institution_name'] = $student->platformInstitution?->name;

                    return $row;
                });
        });

        return response()->json($students, 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:students,email',
            'status' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string|max:255',
            'selected_courses' => 'nullable|array',
            'selected_courses.*' => 'nullable|string|max:255',
            'platform_institution_id' => 'nullable|integer|exists:platform_institutions,id',
        ]);

        $payload = [
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'],
            'email'      => $validated['email'],
            'status'     => $validated['status'] ?? 'Active',
            'phone'      => $validated['phone'] ?? '',
            'country'    => $validated['country'] ?? '',
            'primary_goal' => $validated['primary_goal'] ?? '',
            'platform_institution_id' => $validated['platform_institution_id'] ?? null,
            'password'   => '12345678',
        ];
        PlatformTenantScope::stampInstitutionId($request, $payload);
        $student = Student::create($payload);

        // Send welcome email with default password and any selected courses
        $selectedCourses = $validated['selected_courses'] ?? [];
        app(StudentRegistrationEmailService::class)->sendWelcomeEmail(
            $student,
            '12345678',
            $selectedCourses
        );

        $this->bumpStudentCaches();

        return response()->json(['message' => 'Student created', 'student' => $student], 201);
    }

    protected function bumpStudentCaches(): void
    {
        ApiListCache::bump('students');
        ApiListCache::bump('analytics');
    }

    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);
        PlatformTenantScope::assertCanAccess($request, $student);
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:students,email,' . $student->id,
            'status' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string|max:255',
            'platform_institution_id' => 'nullable|integer|exists:platform_institutions,id',
        ]);

        // Apply all validated fields (only columns that exist in the table)
        $student->fill($validated);
        $student->save();
        $this->bumpStudentCaches();

        return response()->json(['message' => 'Student updated', 'student' => $student->load('platformInstitution:id,name')]);
    }

    public function moveInstitution(Request $request, $id)
    {
        $student = Student::findOrFail($id);
        PlatformTenantScope::assertCanAccess($request, $student);
        $validated = $request->validate([
            'platform_institution_id' => 'nullable|integer|exists:platform_institutions,id',
        ]);

        $institutionId = $validated['platform_institution_id'] ?? null;
        if ($institutionId) {
            $institution = PlatformInstitution::query()
                ->where('id', $institutionId)
                ->where('status', 'active')
                ->first();
            if (!$institution) {
                return response()->json(['message' => 'Institution is not active or does not exist.'], 422);
            }
        }

        $student->platform_institution_id = $institutionId;
        $student->save();
        $this->bumpStudentCaches();

        return response()->json([
            'message' => 'Learner institution updated',
            'student' => $student->load('platformInstitution:id,name'),
        ]);
    }

    public function destroy($id)
    {
        Student::findOrFail($id)->delete();
        $this->bumpStudentCaches();

        return response()->json(['message' => 'Student deleted']);
    }

    public function uploadDocument(Request $request)
    {
        $validated = $request->validate([
            'document' => 'required|file|max:10240|mimes:png,jpg,jpeg,pdf', // 10MB
            'student_id' => 'nullable|integer|exists:students,id',
        ]);

        $file = $validated['document'];
        // store under public/uploads so path begins with 'uploads/...'
        $path = $file->store('uploads', 'public');
        $url = asset('storage/' . $path);

        // If a student_id is provided, persist on that student row
        if (!empty($validated['student_id'])) {
            $student = Student::find($validated['student_id']);
            if ($student) {
                $student->document_path = $path;
                $student->document_url = $url;
                $student->save();
            }
        }

        return response()->json([
            'message' => 'Document uploaded',
            'path' => $path,
            'url' => $url,
        ], 201);
    }

    public function testEmail(Request $request, MailDeliveryService $mail)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $toEmail = $request->input('email');
        $result = $mail->sendTest($toEmail);
        $diagnosis = $mail->diagnose();

        if ($result['ok']) {
            return response()->json([
                'message' => $result['message'],
                'diagnosis' => $diagnosis,
            ]);
        }

        return response()->json([
            'message' => 'Failed to send email.',
            'error' => $result['error'],
            'diagnosis' => $diagnosis,
        ], 500);
    }
}
