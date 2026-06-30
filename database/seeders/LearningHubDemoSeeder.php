<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Models\StudyShift;
use App\Models\User;
use App\Support\ApiListCache;
use Illuminate\Database\Seeder;

class LearningHubDemoSeeder extends Seeder
{
    public function run(): void
    {
        $instructor = User::updateOrCreate(
            ['email' => 'instructor@parrot.com'],
            [
                'name' => 'Instructor User',
                'password' => bcrypt('1234'),
                'role' => 'instructor',
                'status' => 'Active',
            ]
        );

        $instructorTwo = User::updateOrCreate(
            ['email' => 'instructor2@parrot.com'],
            [
                'name' => 'Jane Smith',
                'password' => bcrypt('1234'),
                'role' => 'instructor',
                'status' => 'Active',
            ]
        );

        $courses = [
            [
                'title' => 'English Basics',
                'description' => 'Foundational grammar, vocabulary, and everyday conversation.',
                'price' => 149.00,
                'duration' => '8 weeks',
                'requirements' => 'No prior experience required.',
                'status' => 'Active',
            ],
            [
                'title' => 'Advanced Conversation',
                'description' => 'Fluency-focused speaking practice for intermediate learners.',
                'price' => 199.00,
                'duration' => '10 weeks',
                'requirements' => 'Completed English Basics or equivalent level.',
                'status' => 'Active',
            ],
            [
                'title' => 'Business English',
                'description' => 'Professional communication, meetings, and presentations.',
                'price' => 249.00,
                'duration' => '12 weeks',
                'requirements' => 'Intermediate English recommended.',
                'status' => 'Active',
            ],
        ];

        $createdCourses = [];
        foreach ($courses as $courseData) {
            $course = Course::updateOrCreate(
                ['title' => $courseData['title']],
                $courseData
            );
            $createdCourses[] = $course;
            $instructor->assignedCourses()->syncWithoutDetaching([$course->id]);
        }

        $instructorTwo->assignedCourses()->syncWithoutDetaching([
            $createdCourses[2]->id,
        ]);

        $shiftTemplates = [
            ['name' => 'Morning', 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00'],
            ['name' => 'Afternoon', 'day_of_week' => 3, 'start_time' => '14:00', 'end_time' => '16:00'],
            ['name' => 'Evening', 'day_of_week' => 5, 'start_time' => '18:00', 'end_time' => '20:00'],
        ];

        foreach ($createdCourses as $course) {
            foreach ($shiftTemplates as $shift) {
                StudyShift::updateOrCreate(
                    [
                        'course_id' => $course->id,
                        'name' => $shift['name'],
                        'day_of_week' => $shift['day_of_week'],
                    ],
                    [
                        'start_time' => $shift['start_time'],
                        'end_time' => $shift['end_time'],
                        'timezone' => 'Africa/Kigali',
                        'max_students' => 20,
                        'is_active' => true,
                        'created_by' => $instructor->id,
                        'notes' => 'Demo study shift for testing registration.',
                    ]
                );
            }
        }

        $students = [
            [
                'email' => 'student1@parrot.com',
                'first_name' => 'Alice',
                'last_name' => 'Mukamana',
                'password' => '1234',
                'status' => 'Active',
                'phone' => '+250788000001',
                'country' => 'Rwanda',
            ],
            [
                'email' => 'student2@parrot.com',
                'first_name' => 'Bob',
                'last_name' => 'Habimana',
                'password' => '1234',
                'status' => 'Active',
                'phone' => '+250788000002',
                'country' => 'Rwanda',
            ],
        ];

        foreach ($students as $index => $studentData) {
            $student = Student::updateOrCreate(
                ['email' => $studentData['email']],
                $studentData
            );

            $course = $createdCourses[$index % count($createdCourses)];
            $shift = StudyShift::where('course_id', $course->id)
                ->where('name', 'Morning')
                ->first();

            $enrollment = CourseEnrollment::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'course_id' => $course->id,
                ],
                [
                    'status' => 'enrolled',
                    'level' => 'Beginner',
                    'study_shift_id' => $shift?->id,
                ]
            );

            if ($shift) {
                $enrollment->studyShifts()->syncWithoutDetaching([$shift->id]);
            }
        }

        ApiListCache::bump('courses');
        ApiListCache::bump('instructors');
        ApiListCache::bump('users');
        ApiListCache::bump('analytics');
    }
}
