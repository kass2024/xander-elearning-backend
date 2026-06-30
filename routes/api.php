<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ElearningProgramController;
use App\Http\Controllers\Api\ProgramManagementController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseMaterialController;
use App\Http\Controllers\Api\ZoomController;
use App\Http\Controllers\Api\ZoomEmbedController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Api\LearnerExtrasController;
use App\Http\Controllers\Api\MeetingRegistrationController;
use App\Http\Controllers\Api\AvailableScheduleController;
use App\Http\Controllers\Api\StudyShiftController;
use App\Http\Controllers\Api\LiveZoomCohortController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AdminPayoutController;
use App\Http\Controllers\Api\AdminReportsController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\InstructorDashboardController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\PlatformInstitutionController;
use App\Http\Controllers\Api\InstitutionSignupController;
use App\Http\Controllers\Api\PublicStorageController;
use App\Http\Controllers\Api\LearnerDashboardController;
use App\Http\Controllers\Api\StudyShiftChangeRequestController;
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
    Route::get('system/pcloud', [SystemController::class, 'pcloudHealth']);
    Route::post('system/migrate', [SystemController::class, 'migrate']);
    Route::get('public-storage/{path}', [PublicStorageController::class, 'show'])->where('path', '.*');

    // Auth
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register-student', [AuthController::class, 'registerStudent']);
    Route::post('auth/register-instructor', [AuthController::class, 'registerInstructor']);
    Route::get('institution-signup/config', [InstitutionSignupController::class, 'config']);
    Route::get('institution-signup/choices', [InstitutionSignupController::class, 'choices']);
    Route::post('institution-signup/validate-promo', [InstitutionSignupController::class, 'validatePromo']);
    Route::post('institution-signup/register', [InstitutionSignupController::class, 'register']);
    Route::post('institution-signup/complete-payment', [InstitutionSignupController::class, 'completePayment']);
    Route::get('platform-institutions/context', [PlatformInstitutionController::class, 'context']);
    Route::get('platform-institutions/my-settings', [PlatformInstitutionController::class, 'mySettings']);
    Route::post('platform-institutions/my-branding', [PlatformInstitutionController::class, 'updateMyBranding']);
    Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

    // Meeting Registration
    Route::get('meeting-registrations/webinar/status', [MeetingRegistrationController::class, 'webinarStatus']);
    Route::post('meeting-registrations/webinar/start', [MeetingRegistrationController::class, 'startWebinar']);
    Route::post('meeting-registrations/webinar/sdk-auth', [ZoomEmbedController::class, 'webinarHostAuth']);
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
    Route::put('available-schedules/calendar', [AvailableScheduleController::class, 'updateCalendar']);
    Route::post('available-schedules/bulk', [AvailableScheduleController::class, 'bulkUpsert']);
    Route::post('available-schedules', [AvailableScheduleController::class, 'store']);
    Route::put('available-schedules/{availableSchedule}', [AvailableScheduleController::class, 'update']);
    Route::delete('available-schedules/{availableSchedule}', [AvailableScheduleController::class, 'destroy']);

    // Study shifts (learner registration)
    Route::get('study-shifts', [StudyShiftController::class, 'index']);
    Route::post('study-shifts', [StudyShiftController::class, 'store']);
    Route::put('study-shifts/{studyShift}', [StudyShiftController::class, 'update']);
    Route::delete('study-shifts/{studyShift}', [StudyShiftController::class, 'destroy']);

    // Live Zoom Cohort (separate table)
    Route::get('livezoom-cohort', [LiveZoomCohortController::class, 'index']);
    Route::post('livezoom-cohort', [LiveZoomCohortController::class, 'store']);
    Route::post('livezoom-cohort/bulk', [LiveZoomCohortController::class, 'bulkUpsert']);
    Route::put('livezoom-cohort/{liveZoomCohort}', [LiveZoomCohortController::class, 'update']);
    Route::delete('livezoom-cohort/{liveZoomCohort}', [LiveZoomCohortController::class, 'destroy']);
    Route::get('livezoom-cohort/{liveZoomCohort}/public', [LiveZoomCohortController::class, 'publicSession']);
    Route::get('livezoom-cohort/{liveZoomCohort}/queue/public', [LiveZoomCohortController::class, 'publicQueue']);
    Route::post('livezoom-cohort/{liveZoomCohort}/start', [LiveZoomCohortController::class, 'startSession']);
    Route::get('livezoom-cohort/{liveZoomCohort}/zoom', [LiveZoomCohortController::class, 'zoomDetails']);
    Route::post('livezoom-cohort/{liveZoomCohort}/end', [LiveZoomCohortController::class, 'endSession']);
    Route::get('livezoom-cohort/{liveZoomCohort}/queue', [LiveZoomCohortController::class, 'adminQueue']);
    Route::get('livezoom-cohort/{liveZoomCohort}/attendance', [LiveZoomCohortController::class, 'attendance']);
    Route::post('livezoom-cohort/{liveZoomCohort}/queue/release', [LiveZoomCohortController::class, 'releaseCurrent']);
    Route::post('livezoom-cohort/{liveZoomCohort}/queue/admit-next', [LiveZoomCohortController::class, 'admitNextWaiting']);
    Route::post('livezoom-cohort/{liveZoomCohort}/queue/admit-all', [LiveZoomCohortController::class, 'admitAllWaiting']);
    Route::post('livezoom-cohort/{liveZoomCohort}/queue/admit/{queueEntry}', [LiveZoomCohortController::class, 'admitWaitingEntry']);
    Route::post('livezoom-cohort/{liveZoomCohort}/join', [LiveZoomCohortController::class, 'joinQueue']);
    Route::get('livezoom-cohort/{liveZoomCohort}/queue/status', [LiveZoomCohortController::class, 'queueStatus']);
    Route::post('livezoom-cohort/{liveZoomCohort}/queue/leave', [LiveZoomCohortController::class, 'leaveQueue']);
    Route::post('livezoom-cohort/{liveZoomCohort}/queue/joined', [LiveZoomCohortController::class, 'markJoined']);
    Route::post('livezoom-cohort/{liveZoomCohort}/queue/done', [LiveZoomCohortController::class, 'releaseParticipant']);
    Route::post('livezoom-cohort/{liveZoomCohort}/queue/sdk-auth', [LiveZoomCohortController::class, 'participantSdkAuth']);
    Route::post('livezoom-cohort/{liveZoomCohort}/host/sdk-auth', [LiveZoomCohortController::class, 'hostSdkAuth']);
    Route::post('livezoom-cohort/{liveZoomCohort}/host/mark-in-meeting', [LiveZoomCohortController::class, 'markHostInMeeting']);
    Route::post('livezoom-cohort/{liveZoomCohort}/host/mark-left', [LiveZoomCohortController::class, 'markHostLeft']);
    Route::post('livezoom-cohort/{liveZoomCohort}/recording', [LiveZoomCohortController::class, 'toggleRecording']);

    /*** ---------------- E-LEARNING PROGRAMS ---------------- ***/
    Route::get('learning-programs', [ElearningProgramController::class, 'index']);
    Route::post('learning-programs', [ElearningProgramController::class, 'store']);
    Route::get('learning-programs/{elearningProgram}', [ElearningProgramController::class, 'show']);
    Route::put('learning-programs/{elearningProgram}', [ElearningProgramController::class, 'update']);
    Route::delete('learning-programs/{elearningProgram}', [ElearningProgramController::class, 'destroy']);
    Route::post('learning-programs/{elearningProgram}/assign-courses', [ElearningProgramController::class, 'assignCourses']);
    Route::post('learning-programs/auto-assign-courses', [ElearningProgramController::class, 'autoAssignCourses']);

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
    Route::post('students/{student}/move-institution', [StudentController::class, 'moveInstitution']);
    Route::delete('students/{student}', [StudentController::class, 'destroy']);
    Route::post('students/upload-document', [StudentController::class, 'uploadDocument']);
    Route::post('students/test-email', [StudentController::class, 'testEmail']);
    Route::get('students/{student}/course-enrollments', [CourseController::class, 'studentEnrollments']);
    Route::get('students/{student}/dashboard-summary', [StudentDashboardController::class, 'summary']);
    Route::get('learner/dashboard-extras', [LearnerExtrasController::class, 'index']);
    Route::get('learner/dashboard', [LearnerDashboardController::class, 'dashboard']);
    Route::get('learner/notifications', [LearnerDashboardController::class, 'notifications']);
    Route::get('learner/recordings', [LearnerDashboardController::class, 'recordings']);
    Route::post('learner/study-shift-change-requests', [StudyShiftChangeRequestController::class, 'store']);
    Route::get('study-shift-change-requests', [StudyShiftChangeRequestController::class, 'index']);
    Route::post('study-shift-change-requests/{studyShiftChangeRequest}/approve', [StudyShiftChangeRequestController::class, 'approve']);
    Route::post('study-shift-change-requests/{studyShiftChangeRequest}/reject', [StudyShiftChangeRequestController::class, 'reject']);
    Route::post('courses/{course}/enrollment-study-shifts', [StudyShiftChangeRequestController::class, 'updateEnrollmentShifts']);
    Route::post('learner/live-classes/{material}/sdk-auth', [ZoomEmbedController::class, 'learnerMaterialAuth']);
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
    Route::post('instructor/live-classes/{material}/sdk-auth', [ZoomEmbedController::class, 'instructorMaterialAuth']);
    Route::post('instructor/live-classes/{material}/preview-sdk-auth', [ZoomEmbedController::class, 'instructorPreviewMaterialAuth']);
    Route::get('instructor/students', [InstructorDashboardController::class, 'students']);
    Route::get('instructor/quizzes', [InstructorDashboardController::class, 'quizzes']);
    Route::post('instructor/quizzes', [InstructorDashboardController::class, 'storeQuiz']);
    Route::get('instructor/quizzes/ai-status', [QuizController::class, 'aiStatus']);
    Route::get('instructor/quizzes/topics', [QuizController::class, 'courseTopics']);
    Route::post('instructor/quizzes/analyze-material', [QuizController::class, 'analyzeMaterial']);
    Route::post('instructor/quizzes/generate', [QuizController::class, 'generate']);
    Route::post('instructor/quizzes/upload-prompt-audio', [QuizController::class, 'uploadPromptAudio']);
    Route::get('instructor/quizzes/pcloud-upload-config', [QuizController::class, 'prepareQuizAudioUpload']);
    Route::post('instructor/quizzes/register-prompt-audio', [QuizController::class, 'registerQuizPromptAudio']);
    Route::get('courses/{course}/assessment-audio/stream', [QuizController::class, 'streamAssessmentAudio']);
    Route::post('instructor/quizzes/ai', [QuizController::class, 'store']);
    Route::get('instructor/quizzes/{quiz}', [QuizController::class, 'showForInstructor']);
    Route::put('instructor/quizzes/{quiz}', [QuizController::class, 'update']);
    Route::post('instructor/quizzes/{quiz}/publish', [QuizController::class, 'publish']);
    Route::get('instructor/quizzes/{quiz}/analytics', [QuizController::class, 'analytics']);
    Route::get('instructor/quizzes/{quiz}/attempts', [QuizController::class, 'listAttempts']);
    Route::get('instructor/quizzes/{quiz}/attempts/{attempt}/marking-guide', [QuizController::class, 'downloadMarkingGuide']);
    Route::post('instructor/quizzes/{quiz}/attempts/{attempt}/grade', [QuizController::class, 'gradeAttempt']);
    Route::get('learner/quizzes/{quiz}', [QuizController::class, 'showForLearner']);
    Route::get('learner/quizzes/{quiz}/attempts/{attempt}/marking-guide', [QuizController::class, 'downloadMarkingGuide']);
    Route::post('learner/quizzes/{quiz}/upload-answer-audio', [QuizController::class, 'uploadAnswerAudio']);
    Route::post('learner/quizzes/{quiz}/submit', [QuizController::class, 'submit']);
    Route::post('instructor/courses', [InstructorDashboardController::class, 'createCourse']);
    Route::put('instructor/courses/{course}', [InstructorDashboardController::class, 'updateCourse']);
    Route::get('instructor/payout-payment-options', [InstructorDashboardController::class, 'payoutPaymentOptions']);
    Route::get('instructor/payout-requests', [InstructorDashboardController::class, 'payoutRequests']);
    Route::post('instructor/payout-requests', [InstructorDashboardController::class, 'requestPayout']);

    Route::post('users', [UserController::class, 'store']);
    Route::get('platform-institutions', [PlatformInstitutionController::class, 'index']);
    Route::get('platform-institutions/{platformInstitution}', [PlatformInstitutionController::class, 'show']);
    Route::put('platform-institutions/{platformInstitution}', [PlatformInstitutionController::class, 'update']);
    Route::post('platform-institutions/{platformInstitution}/test-mail', [PlatformInstitutionController::class, 'sendTestMail']);
    Route::post('platform-institutions/{platformInstitution}/approve', [PlatformInstitutionController::class, 'approve']);
    Route::post('platform-institutions/{platformInstitution}/disable', [PlatformInstitutionController::class, 'disable']);
    Route::post('platform-institutions/{platformInstitution}/enable', [PlatformInstitutionController::class, 'enable']);
    Route::post('platform-institutions/{platformInstitution}/resend-credentials', [PlatformInstitutionController::class, 'resendCredentials']);
    Route::post('platform-institutions/{platformInstitution}/payment-reminder', [PlatformInstitutionController::class, 'sendPaymentReminder']);
    Route::post('platform-institutions/{platformInstitution}/logo', [PlatformInstitutionController::class, 'uploadLogo']);
    Route::delete('platform-institutions/{platformInstitution}', [PlatformInstitutionController::class, 'destroy']);
    Route::get('institution-promo-codes', [PlatformInstitutionController::class, 'promoCodes']);
    Route::post('institution-promo-codes', [PlatformInstitutionController::class, 'storePromoCode']);
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

    /*** ---------------- INSTRUCTOR PAYOUTS (ADMIN) ---------------- ***/
    Route::get('instructor-payouts', [AdminPayoutController::class, 'index']);
    Route::post('instructor-payouts/{payout}/approve', [AdminPayoutController::class, 'approve']);
    Route::post('instructor-payouts/{payout}/reject', [AdminPayoutController::class, 'reject']);

    /*** ---------------- COURSES ---------------- ***/
    Route::get('courses/suggest-code', [CourseController::class, 'suggestCode']);
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
    Route::post('courses/{course}/remove-enrollment', [CourseController::class, 'removeEnrollment']);
    Route::post('courses/{course}/send-payment-link', [CourseController::class, 'sendPaymentLink']);
    Route::get('courses/{course}/enrolled-students', [CourseController::class, 'enrolledStudents']);

    // Course materials
    Route::get('courses/{course}/materials', [CourseMaterialController::class, 'index']);
    Route::post('courses/{course}/materials', [CourseMaterialController::class, 'store']);
    Route::put('courses/{course}/materials/{material}', [CourseMaterialController::class, 'update']);
    Route::delete('courses/{course}/materials/{material}', [CourseMaterialController::class, 'destroy']);
    Route::post('courses/{course}/materials/upload-document', [CourseMaterialController::class, 'uploadDocument']);
    Route::get('courses/{course}/materials/pcloud-upload-config', [CourseMaterialController::class, 'prepareDirectUpload']);
    Route::post('courses/{course}/materials/register-pcloud', [CourseMaterialController::class, 'registerDirectUpload']);
    Route::post('courses/{course}/materials/upload-pcloud', [CourseMaterialController::class, 'uploadPCloud']);
    Route::get('courses/{course}/materials/{material}/stream', [CourseMaterialController::class, 'streamMaterial']);

    /*** ---------------- PAYMENTS ---------------- ***/
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/stripe-config', [PaymentController::class, 'stripeConfig']);
    Route::patch('payments/{payment}', [PaymentController::class, 'updateStatus']);
    Route::post('payments/create-checkout', [PaymentController::class, 'createCheckout']);
    Route::post('payments/confirm-checkout', [PaymentController::class, 'confirmCheckout']);
    Route::post('payments/create-intent', [PaymentController::class, 'createIntent']);

    /*** ---------------- ZOOM ---------------- ***/
    Route::get('zoom/meetings', [ZoomController::class, 'listMeetings']);
    Route::get('zoom/embed/config', [ZoomEmbedController::class, 'config']);
    Route::post('zoom/embed/auth', [ZoomEmbedController::class, 'auth']);
    Route::post('zoom/meetings', [ZoomController::class, 'createMeeting']);
    Route::post('zoom/meetings/{id}/recording', [ZoomController::class, 'setMeetingRecording']);
    Route::delete('zoom/meetings/{id}', [ZoomController::class, 'deleteMeeting']);
    Route::get('zoom/recordings', [ZoomController::class, 'listRecordings']);
    Route::delete('zoom/recordings/{meetingId}', [ZoomController::class, 'deleteRecording']);
    Route::get('zoom/recordings/stream', [ZoomController::class, 'streamRecording']);
    Route::options('zoom/recordings/stream', [ZoomController::class, 'streamRecording']);
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
