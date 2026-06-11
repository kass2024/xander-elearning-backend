<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Services\MailDeliveryService;
use App\Services\StudentRegistrationEmailService;

class StudentController extends Controller
{
    public function index()
    {
        return response()->json(Student::orderByDesc('id')->get(), 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email',
            'status' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string|max:255',
            'selected_courses' => 'nullable|array',
            'selected_courses.*' => 'nullable|string|max:255',
        ]);

        $student = Student::create([
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'],
            'name'       => trim($validated['first_name'] . ' ' . $validated['last_name']) ?: $validated['email'],
            'email'      => $validated['email'],
            'status'     => $validated['status'] ?? 'Active',
            // ensure NOT NULL columns always get a value
            'phone'      => $validated['phone'] ?? '',
            'country'    => $validated['country'] ?? '',
            'primary_goal' => $validated['primary_goal'] ?? '',
            // default password for admin-created students
            'password'   => '12345678',
        ]);

        // Send welcome email with default password and any selected courses
        $selectedCourses = $validated['selected_courses'] ?? [];
        app(StudentRegistrationEmailService::class)->sendWelcomeEmail(
            $student,
            '12345678',
            $selectedCourses
        );

        return response()->json(['message' => 'Student created', 'student' => $student], 201);
    }

    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email,' . $student->id,
            'status' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string|max:255',
        ]);

        // Apply all validated fields (only columns that exist in the table)
        $student->fill($validated);
        $student->save();
        return response()->json(['message' => 'Student updated', 'student' => $student]);
    }

    public function destroy($id)
    {
        Student::findOrFail($id)->delete();
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
