<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProgramManagementController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseMaterialController;
use App\Http\Controllers\Api\ZoomController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Api\LearnerExtrasController;
use App\Http\Controllers\Api\MeetingRegistrationController;
use App\Http\Controllers\Api\AvailableScheduleController;
use App\Http\Controllers\Api\LiveZoomCohortController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AdminReportsController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\InstructorDashboardController;
use App\Http\Controllers\Api\LearnerDashboardController;
use App\Http\Controllers\Api\CertificateController;
use App\Services\ZoomService;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('admin')->group(function () {
    // System / schema (run before other routes on new servers)
    Route::get('system/health', [SystemController::class, 'health']);
    Route::post('system/migrate', [SystemController::class, 'migrate']);

    // Auth
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register-student', [AuthController::class, 'registerStudent']);
    Route::post('auth/register-instructor', [AuthController::class, 'registerInstructor']);
    Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

    // Meeting Registration
    Route::get('meeting-registrations/webinar/status', [MeetingRegistrationController::class, 'webinarStatus']);
    Route::post('meeting-registrations/webinar/start', [MeetingRegistrationController::class, 'startWebinar']);
    Route::post('meeting-registrations/webinar/recording', [MeetingRegistrationController::class, 'setWebinarRecording']);
    Route::get('meeting-registrations/webinar/recordings', [MeetingRegistrationController::class, 'webinarRecordings']);
    Route::post('meeting-registrations', [MeetingRegistrationController::class, 'store']);
    Route::get('meeting-registrations', [MeetingRegistrationController::class, 'index']);
    Route::put('meeting-registrations/{meetingRegistration}', [MeetingRegistrationController::class, 'update']);
    Route::post('meeting-registrations/{meetingRegistration}/approve', [MeetingRegistrationController::class, 'approve']);
    Route::post('meeting-registrations/{meetingRegistration}/reject', [MeetingRegistrationController::class, 'reject']);
    Route::post('meeting-registrations/{meetingRegistration}/remind', [MeetingRegistrationController::class, 'remind']);
    Route::post('meeting-registrations/{meetingRegistration}/resend-join-link', [MeetingRegistrationController::class, 'resendJoinLink']);
    Route::delete('meeting-registrations/{meetingRegistration}', [MeetingRegistrationController::class, 'destroy']);

    // Available Schedule (Time Slots)
    Route::get('available-schedules', [AvailableScheduleController::class, 'index']);
    Route::post('available-schedules', [AvailableScheduleController::class, 'store']);
    Route::put('available-schedules/{availableSchedule}', [AvailableScheduleController::class, 'update']);
    Route::delete('available-schedules/{availableSchedule}', [AvailableScheduleController::class, 'destroy']);

    // Live Zoom Cohort (separate table)
    Route::get('livezoom-cohort', [LiveZoomCohortController::class, 'index']);
    Route::post('livezoom-cohort', [LiveZoomCohortController::class, 'store']);
    Route::put('livezoom-cohort/{liveZoomCohort}', [LiveZoomCohortController::class, 'update']);
    Route::delete('livezoom-cohort/{liveZoomCohort}', [LiveZoomCohortController::class, 'destroy']);

    /*** ---------------- DESTINATIONS ---------------- ***/
    Route::get('destinations', [ProgramManagementController::class, 'getDestinations']);
    Route::post('destinations', [ProgramManagementController::class, 'createDestination']);
    Route::put('destinations/{id}', [ProgramManagementController::class, 'updateDestination']);
    Route::delete('destinations/{id}', [ProgramManagementController::class, 'deleteDestination']);

    /*** ---------------- INSTITUTIONS ---------------- ***/
    Route::get('institutions', [ProgramManagementController::class, 'getInstitutions']);
    Route::post('institutions', [ProgramManagementController::class, 'createInstitution']);
    Route::put('institutions/{id}', [ProgramManagementController::class, 'updateInstitution']);
    Route::delete('institutions/{id}', [ProgramManagementController::class, 'deleteInstitution']);
    Route::put('institutions/{id}/program-levels', [ProgramManagementController::class, 'assignProgramLevelsToInstitution']);
    // Institution + Program Level specific fields of study
    Route::get('institutions/{institutionId}/program-levels/{programLevelId}/fields', [ProgramManagementController::class, 'getFieldsForInstitutionProgramLevel']);
    Route::put('institutions/{institutionId}/program-levels/{programLevelId}/fields', [ProgramManagementController::class, 'assignFieldsForInstitutionProgramLevel']);

    // Intakes for Institution + Program Level + Field
    Route::get('institutions/{institutionId}/program-levels/{programLevelId}/fields/{fieldId}/intakes', [ProgramManagementController::class, 'getIntakesForInstitutionProgramLevelField']);
    Route::put('institutions/{institutionId}/program-levels/{programLevelId}/fields/{fieldId}/intakes', [ProgramManagementController::class, 'assignIntakesForInstitutionProgramLevelField']);

    /*** ---------------- PROGRAM LEVELS ---------------- ***/
    Route::get('program-levels', [ProgramManagementController::class, 'getProgramLevels']);
    Route::post('program-levels', [ProgramManagementController::class, 'createProgramLevel']);
    Route::put('program-levels/{id}', [ProgramManagementController::class, 'updateProgramLevel']);
    Route::delete('program-levels/{id}', [ProgramManagementController::class, 'deleteProgramLevel']);

    /*** ---------------- PROGRAM LEVEL CATEGORIES ---------------- ***/
    Route::get('categories', [ProgramManagementController::class, 'getProgramLevelCategories']);
    Route::post('categories', [ProgramManagementController::class, 'createProgramLevelCategory']);
    Route::put('categories/{id}', [ProgramManagementController::class, 'updateProgramLevelCategory']);
    Route::delete('categories/{id}', [ProgramManagementController::class, 'deleteProgramLevelCategory']);

    /*** ---------------- FIELDS OF STUDY ---------------- ***/
    Route::get('fields', [ProgramManagementController::class, 'getFields']);
    Route::post('fields', [ProgramManagementController::class, 'createField']);
    Route::put('fields/{id}', [ProgramManagementController::class, 'updateField']);
    Route::delete('fields/{id}', [ProgramManagementController::class, 'deleteField']);

    /*** ---------------- AGENTS ---------------- ***/
    Route::get('agents', [AgentController::class, 'index']);
    Route::post('agents', [AgentController::class, 'store']);
    Route::put('agents/{id}', [AgentController::class, 'update']);
    Route::delete('agents/{id}', [AgentController::class, 'destroy']);
    Route::post('agents/login', [AgentController::class, 'login']);
    Route::post('agents/{id}/avatar', [AgentController::class, 'uploadAvatar']);
    
    /*** ---------------- STUDENTS ---------------- ***/
    Route::get('students', [StudentController::class, 'index']);
    Route::post('students', [StudentController::class, 'store']);
    Route::put('students/{student}', [StudentController::class, 'update']);
    Route::delete('students/{student}', [StudentController::class, 'destroy']);
    Route::post('students/upload-document', [StudentController::class, 'uploadDocument']);
    Route::post('students/test-email', [StudentController::class, 'testEmail']);
    Route::get('students/{student}/course-enrollments', [CourseController::class, 'studentEnrollments']);
    Route::get('students/{student}/dashboard-summary', [StudentDashboardController::class, 'summary']);
    Route::get('learner/dashboard-extras', [LearnerExtrasController::class, 'index']);
    Route::get('learner/dashboard', [LearnerDashboardController::class, 'dashboard']);
    Route::get('learner/notifications', [LearnerDashboardController::class, 'notifications']);
    Route::get('learner/recordings', [LearnerDashboardController::class, 'recordings']);
    Route::get('learner/courses/{course}/materials', [CourseMaterialController::class, 'learnerIndex']);

    Route::get('certificates/verify/{courseId}/{studentId}', [CertificateController::class, 'verify'])
        ->whereNumber('courseId')
        ->whereNumber('studentId');

    /*** ---------------- USERS (ADMIN) ---------------- ***/
    Route::get('users', [UserController::class, 'index']);
    Route::get('instructors-with-courses', [UserController::class, 'instructorsWithCourses']);
    Route::get('instructor-assigned-courses', [UserController::class, 'instructorAssignedCourses']);

    /*** ---------------- INSTRUCTOR DASHBOARD ---------------- ***/
    Route::get('instructor/dashboard', [InstructorDashboardController::class, 'dashboard']);
    Route::get('instructor/live-classes', [InstructorDashboardController::class, 'liveClasses']);
    Route::post('instructor/live-classes/{material}/start', [InstructorDashboardController::class, 'startLiveSession']);
    Route::get('instructor/students', [InstructorDashboardController::class, 'students']);
    Route::get('instructor/quizzes', [InstructorDashboardController::class, 'quizzes']);
    Route::post('instructor/quizzes', [InstructorDashboardController::class, 'storeQuiz']);
    Route::post('instructor/courses', [InstructorDashboardController::class, 'createCourse']);
    Route::get('instructor/payout-requests', [InstructorDashboardController::class, 'payoutRequests']);
    Route::post('instructor/payout-requests', [InstructorDashboardController::class, 'requestPayout']);

    Route::post('users', [UserController::class, 'store']);
    Route::put('users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);

    /*** ---------------- ADMIN USER PROFILE ---------------- ***/
    Route::middleware('auth:sanctum')->group(function () {
        // User profile routes
        Route::prefix('users')->group(function () {
            Route::patch('/profile', [UserController::class, 'updateProfile']);
            Route::post('/change-password', [UserController::class, 'changePassword']);
        });
    });
    Route::post('users/{id}/avatar', [UserController::class, 'uploadAvatar']);

    /*** ---------------- APPLICATIONS ---------------- ***/
    Route::get('applications', [ApplicationController::class, 'index']);
    Route::post('applications', [ApplicationController::class, 'store']);
    Route::put('applications/{application}', [ApplicationController::class, 'update']);
    Route::delete('applications/{application}', [ApplicationController::class, 'destroy']);

    /*** ---------------- INTAKES ---------------- ***/
    Route::get('intakes', [ProgramManagementController::class, 'getIntakes']);
    Route::post('intakes', [ProgramManagementController::class, 'createIntake']);
    Route::put('intakes/{id}', [ProgramManagementController::class, 'updateIntake']);
    Route::delete('intakes/{id}', [ProgramManagementController::class, 'deleteIntake']);

    // Dashboard metrics
    Route::get('dashboard/metrics', [ProgramManagementController::class, 'getDashboardMetrics']);
    Route::get('dashboard/analytics', [AdminReportsController::class, 'analytics']);

    /*** ---------------- COURSES ---------------- ***/
    Route::get('courses', [CourseController::class, 'index']);
    Route::post('courses', [CourseController::class, 'store']);
    Route::put('courses/{course}', [CourseController::class, 'update']);
    Route::delete('courses/{course}', [CourseController::class, 'destroy']);
    Route::post('courses/{course}/assign', [CourseController::class, 'assignToUser']);
    Route::post('courses/{course}/unassign', [CourseController::class, 'unassignFromUser']);
    Route::post('courses/{course}/enroll', [CourseController::class, 'enroll']);
    Route::post('courses/{course}/approve-enrollment', [CourseController::class, 'approveEnrollment']);
    Route::post('courses/{course}/schedule-class', [CourseController::class, 'scheduleClass']);
    Route::post('courses/{course}/mark-paid', [CourseController::class, 'markPaid']);
    Route::post('courses/{course}/reject-enrollment', [CourseController::class, 'rejectEnrollment']);
    Route::get('courses/{course}/enrolled-students', [CourseController::class, 'enrolledStudents']);

    // Course materials
    Route::get('courses/{course}/materials', [CourseMaterialController::class, 'index']);
    Route::post('courses/{course}/materials', [CourseMaterialController::class, 'store']);
    Route::put('courses/{course}/materials/{material}', [CourseMaterialController::class, 'update']);
    Route::delete('courses/{course}/materials/{material}', [CourseMaterialController::class, 'destroy']);
    Route::post('courses/{course}/materials/upload-document', [CourseMaterialController::class, 'uploadDocument']);

    /*** ---------------- PAYMENTS ---------------- ***/
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/stripe-config', [PaymentController::class, 'stripeConfig']);
    Route::patch('payments/{payment}', [PaymentController::class, 'updateStatus']);
    Route::post('payments/create-checkout', [PaymentController::class, 'createCheckout']);
    Route::post('payments/confirm-checkout', [PaymentController::class, 'confirmCheckout']);
    Route::post('payments/create-intent', [PaymentController::class, 'createIntent']);

    /*** ---------------- ZOOM ---------------- ***/
    Route::get('zoom/meetings', [ZoomController::class, 'listMeetings']);
    Route::post('zoom/meetings', [ZoomController::class, 'createMeeting']);
    Route::post('zoom/meetings/{id}/recording', [ZoomController::class, 'setMeetingRecording']);
    Route::delete('zoom/meetings/{id}', [ZoomController::class, 'deleteMeeting']);
    Route::get('zoom/recordings', [ZoomController::class, 'listRecordings']);
    Route::get('zoom/recordings/stream', [ZoomController::class, 'streamRecording']);
    Route::get('zoom/webinars', [ZoomController::class, 'listWebinars']);
    Route::post('zoom/webinars', [ZoomController::class, 'createWebinar']);

    // Temporary: test if Zoom OAuth token can be obtained
    Route::get('zoom/test-token', function (ZoomService $zoom) {
        $ref = new \ReflectionClass($zoom);
        $method = $ref->getMethod('getAccessToken');
        $method->setAccessible(true);
        $token = $method->invoke($zoom);

        return response()->json([
            'token_present' => (bool) $token,
        ]);
    });

});
