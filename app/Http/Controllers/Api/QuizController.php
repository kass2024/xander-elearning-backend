<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Models\User;
use App\Services\Quiz\QuizAnalyticsService;
use App\Services\Quiz\QuizAntiCheatService;
use App\Services\Quiz\QuizAnswerMatcher;
use App\Services\Quiz\QuizMarkingGuideService;
use App\Services\Quiz\QuizOptionSorter;
use App\Services\Quiz\QuizPublishedNotificationService;
use App\Services\QuizAiService;
use App\Support\QuizMaterialHelper;
use App\Support\MaterialLanguageHelper;
use App\Services\MaterialDocumentReader;
use App\Support\QuizAudioHelper;
use App\Services\PCloudService;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function __construct(
        protected QuizAiService $quizAi,
        protected QuizAntiCheatService $antiCheat,
        protected QuizAnalyticsService $analytics,
        protected PCloudService $pcloud,
        protected QuizPublishedNotificationService $publishNotifications,
        protected QuizMarkingGuideService $markingGuide,
    ) {
    }

    public function aiStatus()
    {
        return response()->json([
            'configured' => $this->quizAi->isConfigured(),
            'claude' => $this->quizAi->hasClaude(),
            'gemini' => $this->quizAi->hasGemini(),
            'generation_provider' => config('services.quiz_ai.generation_provider', 'gemini'),
            'generation_model' => config('services.quiz_ai.generation_provider', 'gemini') === 'gemini'
                ? (config('services.quiz_ai.generation_model') ?: config('services.gemini.model', 'gemini-2.0-flash'))
                : (config('services.quiz_ai.claude_generation_model') ?: config('services.anthropic.model', 'claude-sonnet-4-6')),
            'fallback_provider' => config('services.quiz_ai.generation_provider', 'gemini') === 'gemini' ? 'claude' : 'gemini',
            'fallback_model' => config('services.quiz_ai.generation_provider', 'gemini') === 'gemini'
                ? (config('services.quiz_ai.claude_generation_model') ?: config('services.anthropic.model', 'claude-sonnet-4-6'))
                : (config('services.quiz_ai.generation_model') ?: config('services.gemini.model', 'gemini-2.0-flash')),
            'marking_primary' => config('services.quiz_ai.marking_primary', 'gemini'),
            'marking_secondary' => config('services.quiz_ai.marking_secondary', 'claude'),
            'local_document_extraction' => !filter_var(config('services.quiz_ai.use_ai_knowledge_map', false), FILTER_VALIDATE_BOOL),
            'embeddings_enabled' => filter_var(config('services.quiz_ai.enable_embeddings', false), FILTER_VALIDATE_BOOL),
            'quiz_modes' => [
                'quick' => 5,
                'standard' => 10,
                'comprehensive' => 20,
                'final_exam' => 50,
            ],
            'supported_types' => [
                'multiple_choice', 'multiple_response', 'true_false', 'matching',
                'fill_blank', 'short_answer', 'long_answer', 'essay',
                'case_study', 'problem_solving', 'scenario', 'hots', 'oral_listen',
            ],
            'oral_response_formats' => ['text', 'audio'],
            'gemini_only' => filter_var(config('services.quiz_ai.gemini_only', true), FILTER_VALIDATE_BOOL),
            'bloom_levels' => ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'],
        ]);
    }

    public function analyzeMaterial(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'material_id' => 'required|integer|exists:course_materials,id',
            'force' => 'nullable|boolean',
        ]);

        $material = CourseMaterial::findOrFail($data['material_id']);
        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $material->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        try {
            $analysis = app(\App\Services\Quiz\QuizMaterialAnalysisService::class)
                ->analyze($material, (bool) ($data['force'] ?? false));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($analysis);
    }

    public function courseTopics(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'instructor_email' => 'nullable|email',
        ]);

        $course = Course::findOrFail($data['course_id']);

        if (!empty($data['instructor_email'])) {
            $instructor = User::query()
                ->where('email', $data['instructor_email'])
                ->where('role', 'instructor')
                ->first();

            if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $course->id)->exists()) {
                return response()->json(['message' => 'You are not assigned to this course.'], 403);
            }
        }

        $studyMaterials = CourseMaterial::query()
            ->where('course_id', $course->id)
            ->whereNotIn('type', ['quiz', 'assessment', 'zoom'])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        $analysisService = app(\App\Services\Quiz\QuizMaterialAnalysisService::class);
        $aiTopics = $analysisService->buildCourseTopicGroupsFromMaterials($studyMaterials);

        $pdfMaterials = $studyMaterials
            ->filter(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m))
            ->map(fn (CourseMaterial $m) => QuizMaterialHelper::materialSummary($m))
            ->values();

        $hasPdfs = $pdfMaterials->isNotEmpty();
        $topicGroups = !empty($aiTopics['groups'])
            ? $aiTopics['groups']
            : ($hasPdfs ? [] : QuizMaterialHelper::buildTopicGroups($studyMaterials));

        $topics = collect($topicGroups)->pluck('label')->filter()->unique()->values();

        if ($topics->isEmpty() && !$hasPdfs && $studyMaterials->isNotEmpty()) {
            $topics = $studyMaterials
                ->map(fn (CourseMaterial $m) => trim((string) ($m->title ?? '')))
                ->filter()
                ->unique()
                ->values();
        }

        $extractionErrors = $aiTopics['errors'] ?? [];
        $topicsSource = 'materials';
        if (!empty($aiTopics['groups'])) {
            $providers = collect($aiTopics['analyzed'] ?? [])->pluck('provider')->filter()->unique();
            $topicsSource = $providers->contains(fn ($p) => in_array($p, ['gemini', 'claude'], true))
                ? 'ai_pdf'
                : 'local_pdf';
        } elseif ($hasPdfs && $topics->isEmpty()) {
            $topicsSource = 'failed';
        }

        $language = MaterialLanguageHelper::detectFromText('');
        $firstPdf = $studyMaterials->first(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m));
        if ($firstPdf instanceof CourseMaterial) {
            $sampleText = app(MaterialDocumentReader::class)->readMaterialText($firstPdf);
            if (is_string($sampleText) && trim($sampleText) !== '') {
                $language = MaterialLanguageHelper::detectFromText($sampleText, (string) ($firstPdf->title ?? ''));
            }
        }

        return response()->json([
            'course_id' => $course->id,
            'course_title' => $course->title,
            'has_materials' => $studyMaterials->isNotEmpty(),
            'materials_count' => $studyMaterials->count(),
            'materials' => $studyMaterials->map(fn (CourseMaterial $m) => QuizMaterialHelper::materialSummary($m))->values(),
            'pdf_materials' => $pdfMaterials,
            'topic_groups' => $topicGroups,
            'topics' => $topics,
            'topics_source' => $topicsSource,
            'pdf_analysis' => $aiTopics['analyzed'] ?? [],
            'extraction_errors' => $extractionErrors,
            'extraction_ok' => $hasPdfs ? ($topics->isNotEmpty() && $extractionErrors === []) : true,
            'assessment_language' => $language['code'],
            'assessment_language_label' => $language['label'],
        ]);
    }

    public function generate(Request $request)
    {
        if (!$this->quizAi->isConfigured()) {
            return response()->json([
                'message' => 'AI is not configured. Add ANTHROPIC_API_KEY and/or GEMINI_API_KEY to .env.',
            ], 503);
        }

        $data = $request->validate([
            'instructor_email' => 'required|email',
            'course_id' => 'required|integer|exists:courses,id',
            'topic' => 'required|string|max:255',
            'question_count' => 'required|integer|min:1|max:100',
            'difficulty' => 'nullable|string|in:easy,medium,hard,mixed',
            'material_id' => 'nullable|integer|exists:course_materials,id',
            'quiz_mode' => 'nullable|string|in:quick,standard,comprehensive,final_exam,custom',
            'bloom_levels' => 'nullable|array',
            'bloom_levels.*' => 'string|in:remember,understand,apply,analyze,evaluate,create',
            'question_types' => 'nullable|array',
            'question_types.*' => 'string|in:multiple_choice,multiple_response,true_false,matching,fill_blank,short_answer,long_answer,essay,case_study,problem_solving,scenario,hots,oral_listen',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $course = Course::findOrFail($data['course_id']);

        $studyMaterials = CourseMaterial::query()
            ->where('course_id', $course->id)
            ->whereNotIn('type', ['quiz', 'assessment', 'zoom'])
            ->get();

        if ($studyMaterials->isEmpty()) {
            return response()->json([
                'message' => 'No course materials found. Upload PDFs or lessons first, with module/chapter/topic in the title or description.',
            ], 422);
        }

        $pdfMaterials = $studyMaterials->filter(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m));
        $materialId = isset($data['material_id']) ? (int) $data['material_id'] : null;
        $topicMaterials = QuizMaterialHelper::materialsForTopic($studyMaterials, $data['topic']);
        $topicPdfCount = collect($topicMaterials)->filter(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m))->count();

        if ($pdfMaterials->count() > 1 && !$materialId && $topicPdfCount === 0) {
            return response()->json([
                'message' => 'This course has multiple PDF materials. Select a topic linked to a PDF or choose a source PDF.',
                'pdf_materials' => $pdfMaterials->map(fn (CourseMaterial $m) => QuizMaterialHelper::materialSummary($m))->values(),
            ], 422);
        }

        if (!$materialId && $topicPdfCount === 1) {
            $materialId = collect($topicMaterials)
                ->first(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m))?->id;
        }

        if (!$materialId && $pdfMaterials->count() === 1) {
            $materialId = $pdfMaterials->first()->id;
        }

        if ($materialId) {
            $selected = $studyMaterials->firstWhere('id', $materialId);
            if (!$selected) {
                return response()->json(['message' => 'Selected material does not belong to this course.'], 422);
            }
        }

        try {
            $result = $this->quizAi->generateQuestions(
                $course,
                $data['topic'],
                (int) $data['question_count'],
                $data['difficulty'] ?? 'medium',
                $materialId,
                [
                    'quiz_mode' => $data['quiz_mode'] ?? 'custom',
                    'bloom_levels' => $data['bloom_levels'] ?? null,
                    'question_types' => $data['question_types'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'topic' => $data['topic'],
            'material_id' => $materialId,
            'provider' => $result['provider'],
            'questions' => $result['questions'],
            'knowledge_map' => $result['knowledge_map'] ?? null,
            'rejected_count' => count($result['rejected'] ?? []),
            'assessment_language' => $result['assessment_language'] ?? null,
            'assessment_language_label' => $result['assessment_language_label'] ?? null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateQuizPayload($request);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $status = $data['status'] ?? 'draft';
        $publishedStudentIds = array_values(array_unique(array_map('intval', $data['published_student_ids'] ?? [])));

        $metadata = $this->buildQuizMetadata($data, $status, $publishedStudentIds);

        $quiz = CourseMaterial::create([
            'course_id' => $data['course_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? ('Topic: ' . $data['topic']),
            'type' => 'quiz',
            'resource_url' => null,
            'metadata' => $metadata,
            'sort_order' => 0,
        ]);

        $notified = 0;
        if ($status === 'published') {
            $notified = $this->publishNotifications->notify($quiz->load('course'), $publishedStudentIds);
        }

        $message = $status === 'published'
            ? 'Quiz published. Selected learners can now take it.'
            : 'Quiz saved as draft. Publish when you are ready.';

        return response()->json([
            'message' => $message,
            'quiz' => $this->formatQuizRow($quiz->load('course')),
            'notifications_sent' => $notified,
        ], 201);
    }

    public function showForInstructor(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $email = $request->query('instructor_email');
        if (!$email) {
            return response()->json(['message' => 'instructor_email is required'], 400);
        }

        $instructor = User::query()
            ->where('email', $email)
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);

        return response()->json([
            'quiz' => array_merge($this->formatQuizRow($quiz->load('course')), [
                'description' => $quiz->description,
                'questions' => $meta['questions'] ?? [],
                'generation_provider' => $meta['generation_provider'] ?? null,
                'source_material_id' => $meta['source_material_id'] ?? null,
                'published_student_ids' => QuizMaterialHelper::publishedStudentIds($quiz),
            ]),
        ]);
    }

    public function update(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $this->validateQuizPayload($request, false);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $existingMeta = QuizMaterialHelper::meta($quiz);
        $wasPublished = QuizMaterialHelper::isPublished($quiz);
        $status = $data['status'] ?? QuizMaterialHelper::quizStatus($quiz);
        $publishedStudentIds = array_key_exists('published_student_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['published_student_ids'] ?? [])))
            : QuizMaterialHelper::publishedStudentIds($quiz);

        $metadata = $this->buildQuizMetadata($data, $status, $publishedStudentIds, $existingMeta);

        $quiz->title = $data['title'];
        $quiz->description = $data['description'] ?? ('Topic: ' . $data['topic']);
        $quiz->metadata = $metadata;
        $quiz->save();

        $notified = 0;
        if ($status === 'published' && !$wasPublished) {
            $notified = $this->publishNotifications->notify($quiz->load('course'), $publishedStudentIds);
        }

        return response()->json([
            'message' => $status === 'published' ? 'Quiz updated and published.' : 'Quiz updated.',
            'quiz' => $this->formatQuizRow($quiz->load('course')),
            'notifications_sent' => $notified,
        ]);
    }

    public function publish(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate([
            'instructor_email' => 'required|email',
            'published_student_ids' => 'nullable|array',
            'published_student_ids.*' => 'integer|exists:students,id',
            'time_limit_minutes' => 'nullable|integer|min:1|max:240',
            'passing_score' => 'nullable|integer|min:40|max:100',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);
        if (empty($meta['questions'])) {
            return response()->json(['message' => 'Add questions before publishing.'], 422);
        }

        $publishedStudentIds = array_values(array_unique(array_map('intval', $data['published_student_ids'] ?? [])));
        $meta['status'] = 'published';
        $meta['published_at'] = now()->toIso8601String();
        $meta['published_student_ids'] = $publishedStudentIds;

        if (array_key_exists('time_limit_minutes', $data)) {
            $meta['time_limit_minutes'] = $data['time_limit_minutes'] !== null
                ? (int) $data['time_limit_minutes']
                : null;
        }
        if (array_key_exists('passing_score', $data)) {
            $meta['passing_score'] = (int) ($data['passing_score'] ?? 70);
        }

        $quiz->metadata = $meta;
        $quiz->save();

        $notified = $this->publishNotifications->notify($quiz->load('course'), $publishedStudentIds);

        return response()->json([
            'message' => empty($publishedStudentIds)
                ? 'Quiz published to all enrolled learners.'
                : 'Quiz published to selected learners.',
            'quiz' => $this->formatQuizRow($quiz->load('course')),
            'notifications_sent' => $notified,
        ]);
    }

    public function showForLearner(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $studentId = (int) $request->query('student_id');
        if (!$studentId) {
            return response()->json(['message' => 'student_id is required'], 400);
        }

        $student = Student::find($studentId);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $enrolled = CourseEnrollment::query()
            ->where('student_id', $student->id)
            ->where('course_id', $quiz->course_id)
            ->whereIn('status', \App\Support\EnrollmentStatusHelper::accessStatuses())
            ->exists();

        if (!$enrolled) {
            return response()->json(['message' => 'You are not enrolled in this course.'], 403);
        }

        if (!QuizMaterialHelper::isVisibleToStudent($quiz, $studentId)) {
            return response()->json(['message' => 'This quiz is not available to you yet.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);
        $allQuestions = $meta['questions'] ?? [];

        if (empty($allQuestions)) {
            return response()->json(['message' => 'This quiz has no questions yet.'], 422);
        }

        $attemptCount = QuizAttempt::query()
            ->where('student_id', $studentId)
            ->where('course_material_id', $quiz->id)
            ->count();

        $latestAttempt = QuizAttempt::query()
            ->where('student_id', $studentId)
            ->where('course_material_id', $quiz->id)
            ->orderByDesc('id')
            ->first();

        $maxAttemptsReached = $this->antiCheat->maxAttemptsReached($meta, $studentId, $quiz->id, $attemptCount);
        $canRetake = !$maxAttemptsReached;
        $pendingReview = $latestAttempt !== null && $latestAttempt->marked_at === null;

        if ($maxAttemptsReached && !$latestAttempt) {
            return response()->json(['message' => 'Maximum attempts reached for this quiz.'], 422);
        }

        $delivery = $this->antiCheat->prepareDelivery($meta, $studentId);
        $questions = $delivery['questions'];
        $antiCheat = is_array($meta['anti_cheat'] ?? null) ? $meta['anti_cheat'] : [];

        $attempts = QuizAttempt::query()
            ->where('student_id', $student->id)
            ->where('course_material_id', $quiz->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (QuizAttempt $attempt) => $attempt->toLearnerSummary())
            ->values()
            ->all();

        $viewResultsOnly = $latestAttempt !== null && (
            !$canRetake || $pendingReview || $latestAttempt->marked_at !== null
        );

        $resultRows = [];
        if ($viewResultsOnly && $latestAttempt) {
            $resultRows = $this->enrichQuestionResults($meta, $latestAttempt->question_results ?? []);
        }

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'course_id' => $quiz->course_id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'topic' => $meta['topic'] ?? null,
                'assessment_kind' => $meta['assessment_kind'] ?? 'quiz',
                'passing_score' => (int) ($meta['passing_score'] ?? 70),
                'time_limit_minutes' => QuizMaterialHelper::timeLimitMinutes($quiz),
                'question_count' => count($questions),
                'max_attempts' => (int) ($antiCheat['max_attempts'] ?? 0),
                'attempts_used' => $attemptCount,
                'detect_tab_switch' => (bool) ($antiCheat['detect_tab_switch'] ?? true),
                'server_now' => now()->toIso8601String(),
                'can_retake' => $canRetake,
                'view_mode' => $viewResultsOnly ? 'results' : 'take',
            ],
            'questions' => $viewResultsOnly ? [] : $this->quizAi->stripAnswersForLearner($questions),
            'delivered_question_ids' => $viewResultsOnly ? [] : $delivery['delivered_ids'],
            'latest_attempt' => $latestAttempt?->toLearnerSummary(),
            'attempts' => $attempts,
            'question_results' => $resultRows,
        ]);
    }

    public function analytics(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate(['instructor_email' => 'required|email']);
        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        return response()->json($this->analytics->forQuiz($quiz));
    }

    public function submit(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'answers' => 'required|array',
            'started_at' => 'nullable|date',
            'auto_submitted' => 'nullable|boolean',
            'tab_switch_count' => 'nullable|integer|min:0|max:500',
            'focus_lost_seconds' => 'nullable|integer|min:0|max:86400',
            'delivered_question_ids' => 'nullable|array',
            'delivered_question_ids.*' => 'string|max:50',
        ]);

        $student = Student::findOrFail($data['student_id']);

        $enrolled = CourseEnrollment::query()
            ->where('student_id', $student->id)
            ->where('course_id', $quiz->course_id)
            ->whereIn('status', \App\Support\EnrollmentStatusHelper::accessStatuses())
            ->exists();

        if (!$enrolled) {
            return response()->json(['message' => 'You are not enrolled in this course.'], 403);
        }

        if (!QuizMaterialHelper::isVisibleToStudent($quiz, $student->id)) {
            return response()->json(['message' => 'This quiz is not available to you.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);
        $allQuestions = $meta['questions'] ?? [];

        if (empty($allQuestions)) {
            return response()->json(['message' => 'This quiz has no questions.'], 422);
        }

        $attemptCount = QuizAttempt::query()
            ->where('student_id', $student->id)
            ->where('course_material_id', $quiz->id)
            ->count();

        if ($this->antiCheat->maxAttemptsReached($meta, $student->id, $quiz->id, $attemptCount)) {
            return response()->json(['message' => 'Maximum attempts reached for this quiz.'], 422);
        }

        $deliveredIds = array_values(array_filter($data['delivered_question_ids'] ?? []));
        $questions = $allQuestions;
        if ($deliveredIds !== []) {
            $questions = array_values(array_filter($allQuestions, fn ($q) => in_array((string) ($q['id'] ?? ''), $deliveredIds, true)));
        }

        if (empty($questions)) {
            $delivery = $this->antiCheat->prepareDelivery($meta, $student->id);
            $questions = $delivery['questions'];
            $deliveredIds = $delivery['delivered_ids'];
        }

        $questions = array_map(
            fn ($question) => is_array($question) ? QuizAnswerMatcher::normalizeQuestionAnswers($question) : $question,
            $questions
        );

        $timeLimit = QuizMaterialHelper::timeLimitMinutes($quiz);
        if ($timeLimit && !empty($data['started_at'])) {
            $startedAt = \Carbon\Carbon::parse($data['started_at']);
            $deadline = $startedAt->copy()->addMinutes($timeLimit);
            if (now()->greaterThan($deadline->copy()->addSeconds(30))) {
                return response()->json(['message' => 'Time is up. This quiz has ended.'], 422);
            }
        }

        $passingScore = (int) ($meta['passing_score'] ?? 70);
        $assessmentLanguage = is_string($meta['assessment_language'] ?? null) ? $meta['assessment_language'] : null;
        $markResult = $this->quizAi->markAttempt($questions, $data['answers'], $passingScore, $assessmentLanguage);

        if (!empty($data['auto_submitted'])) {
            $markResult['feedback'] = 'Time expired — your quiz was submitted automatically. '
                . 'Unanswered questions were marked incorrect. '
                . ($markResult['feedback'] ?? '');
        }

        $pendingManual = !empty($markResult['pending_manual_review']);

        $attempt = QuizAttempt::create([
            'student_id' => $student->id,
            'course_material_id' => $quiz->id,
            'answers' => $data['answers'],
            'question_results' => $markResult['question_results'],
            'score' => $markResult['score'],
            'max_score' => $markResult['max_score'],
            'percentage' => $markResult['percentage'],
            'passed' => $markResult['passed'],
            'feedback' => $markResult['feedback'],
            'marking_provider' => $markResult['marking_provider'],
            'tab_switch_count' => (int) ($data['tab_switch_count'] ?? 0),
            'focus_lost_seconds' => (int) ($data['focus_lost_seconds'] ?? 0),
            'integrity_flags' => [
                'auto_submitted' => (bool) ($data['auto_submitted'] ?? false),
                'tab_switch_count' => (int) ($data['tab_switch_count'] ?? 0),
            ],
            'delivered_question_ids' => $deliveredIds,
            'marked_at' => $pendingManual ? null : now(),
        ]);

        return response()->json([
            'message' => $pendingManual
                ? 'Submitted — awaiting instructor review.'
                : ($markResult['passed'] ? 'Quiz passed!' : 'Quiz submitted.'),
            'attempt' => $attempt,
            'results' => $markResult,
            'analytics' => $markResult['analytics'] ?? null,
        ]);
    }

    public function listAttempts(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate(['instructor_email' => 'required|email']);
        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);
        $questionsById = collect($meta['questions'] ?? [])->keyBy(fn ($q) => (string) ($q['id'] ?? ''));

        $attempts = QuizAttempt::query()
            ->with('student')
            ->where('course_material_id', $quiz->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (QuizAttempt $attempt) use ($questionsById) {
                $results = $attempt->question_results ?? [];
                $pendingOral = array_values(array_filter(
                    $results,
                    fn ($r) => ($r['type'] ?? '') === 'oral_listen' && !empty($r['pending_review'])
                ));

                return [
                    'id' => $attempt->id,
                    'student_id' => $attempt->student_id,
                    'student_name' => $attempt->student?->name
                        ?? $attempt->student?->email
                        ?? ('Student #' . $attempt->student_id),
                    'score' => $attempt->score,
                    'max_score' => $attempt->max_score,
                    'percentage' => $attempt->percentage,
                    'passed' => $attempt->passed,
                    'marking_provider' => $attempt->marking_provider,
                    'marked_at' => $attempt->marked_at,
                    'created_at' => $attempt->created_at,
                    'pending_oral_count' => count($pendingOral),
                    'question_results' => array_map(function ($row) use ($questionsById) {
                        $qid = (string) ($row['question_id'] ?? '');
                        $question = $questionsById->get($qid, []);

                        return array_merge($row, [
                            'instruction' => $question['instruction'] ?? $question['question'] ?? null,
                            'prompt_audio_url' => $question['prompt_audio_url'] ?? null,
                            'prompt_audio_filename' => $question['prompt_audio_filename'] ?? null,
                        ]);
                    }, $results),
                ];
            });

        return response()->json([
            'quiz_id' => $quiz->id,
            'quiz_title' => $quiz->title,
            'course_id' => $quiz->course_id,
            'attempts' => $attempts,
        ]);
    }

    public function gradeAttempt(Request $request, CourseMaterial $quiz, QuizAttempt $attempt)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        if ((int) $attempt->course_material_id !== (int) $quiz->id) {
            return response()->json(['message' => 'Attempt not found for this quiz.'], 404);
        }

        $data = $request->validate([
            'instructor_email' => 'required|email',
            'grades' => 'required|array|min:1',
            'grades.*.question_id' => 'required|string|max:50',
            'grades.*.score' => 'required|integer|min:0',
            'grades.*.feedback' => 'nullable|string|max:2000',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);
        $passingScore = (int) ($meta['passing_score'] ?? 70);
        $markResult = $this->quizAi->applyManualGrades(
            $attempt->question_results ?? [],
            $data['grades'],
            $passingScore
        );

        $attempt->update([
            'question_results' => $markResult['question_results'],
            'score' => $markResult['score'],
            'max_score' => $markResult['max_score'],
            'percentage' => $markResult['percentage'],
            'passed' => $markResult['passed'],
            'feedback' => $markResult['feedback'],
            'marking_provider' => !empty($markResult['pending_manual_review']) ? 'manual' : 'manual',
            'marked_at' => !empty($markResult['pending_manual_review']) ? null : now(),
        ]);

        return response()->json([
            'message' => !empty($markResult['pending_manual_review'])
                ? 'Partial grades saved.'
                : 'Attempt marked complete.',
            'attempt' => $attempt->fresh(),
            'results' => $markResult,
        ]);
    }

    public function downloadMarkingGuide(Request $request, CourseMaterial $quiz, QuizAttempt $attempt)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        if ((int) $attempt->course_material_id !== (int) $quiz->id) {
            return response()->json(['message' => 'Attempt not found for this quiz.'], 404);
        }

        $data = $request->validate([
            'student_id' => 'nullable|integer|exists:students,id',
            'instructor_email' => 'nullable|email',
            'admin_email' => 'nullable|email',
        ]);

        $audience = 'learner';
        $allowed = false;

        if (!empty($data['student_id'])) {
            $studentId = (int) $data['student_id'];
            $allowed = (int) $attempt->student_id === $studentId
                && CourseEnrollment::query()
                    ->where('student_id', $studentId)
                    ->where('course_id', $quiz->course_id)
                    ->whereIn('status', \App\Support\EnrollmentStatusHelper::accessStatuses())
                    ->exists();
            $audience = 'learner';
        }

        if (!$allowed && !empty($data['instructor_email'])) {
            $instructor = User::query()
                ->where('email', $data['instructor_email'])
                ->where('role', 'instructor')
                ->first();
            $allowed = $instructor && $instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists();
            $audience = 'instructor';
        }

        if (!$allowed && !empty($data['admin_email'])) {
            $admin = User::query()->where('email', $data['admin_email'])->first();
            $role = strtolower((string) ($admin->role ?? ''));
            $allowed = $admin && in_array($role, ['admin', 'superadmin', 'staff'], true);
            $audience = 'admin';
        }

        if (!$allowed) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $attempt->loadMissing('student');
        $payload = $this->markingGuide->buildPayload($quiz, $attempt, $audience);
        $html = $this->markingGuide->renderHtml($payload);
        $filename = sprintf(
            'marking-guide-quiz-%d-attempt-%d.html',
            $quiz->id,
            $attempt->id
        );

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  mixed  $results
     * @return array<int, array<string, mixed>>
     */
    protected function enrichQuestionResults(array $meta, mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }

        $questionsById = collect($meta['questions'] ?? [])->keyBy(fn ($q) => (string) ($q['id'] ?? ''));

        return array_values(array_map(function ($row) use ($questionsById) {
            if (!is_array($row)) {
                return [];
            }

            $qid = (string) ($row['question_id'] ?? '');
            $question = $questionsById->get($qid, []);

            return array_merge($row, [
                'question' => $question['question'] ?? $question['instruction'] ?? null,
                'instruction' => $question['instruction'] ?? null,
            ]);
        }, $results));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $publishedStudentIds
     * @return array<string, mixed>
     */
    protected function buildQuizMetadata(array $data, string $status, array $publishedStudentIds, ?array $existingMeta = null): array
    {
        $existingMeta = $existingMeta ?? [];
        $publishedAt = $existingMeta['published_at'] ?? null;

        if ($status === 'published') {
            $publishedAt = $publishedAt ?: now()->toIso8601String();
        } else {
            $publishedAt = null;
            $publishedStudentIds = [];
        }

        return [
            'topic' => $data['topic'],
            'assessment_kind' => $data['assessment_kind'] ?? ($existingMeta['assessment_kind'] ?? 'quiz'),
            'passing_score' => (int) ($data['passing_score'] ?? 70),
            'time_limit_minutes' => array_key_exists('time_limit_minutes', $data) && $data['time_limit_minutes'] !== null
                ? (int) $data['time_limit_minutes']
                : null,
            'questions' => array_map(
                fn ($question) => is_array($question) ? QuizAnswerMatcher::normalizeQuestionAnswers($question) : $question,
                is_array($data['questions'] ?? null) ? $data['questions'] : []
            ),
            'question_pool' => $this->resolveQuestionPool($data, $existingMeta),
            'anti_cheat' => array_merge([
                'shuffle_questions' => true,
                'shuffle_options' => false,
                'deliver_count' => 0,
                'max_attempts' => 0,
                'detect_tab_switch' => true,
            ], is_array($data['anti_cheat'] ?? null) ? $data['anti_cheat'] : ($existingMeta['anti_cheat'] ?? [])),
            'ai_generated' => (bool) ($data['ai_generated'] ?? ($existingMeta['ai_generated'] ?? false)),
            'generation_provider' => $data['generation_provider'] ?? ($existingMeta['generation_provider'] ?? null),
            'assessment_language' => $data['assessment_language'] ?? ($existingMeta['assessment_language'] ?? null),
            'assessment_language_label' => $data['assessment_language_label'] ?? ($existingMeta['assessment_language_label'] ?? null),
            'source_material_id' => isset($data['material_id'])
                ? (int) $data['material_id']
                : ($existingMeta['source_material_id'] ?? null),
            'status' => $status,
            'published_student_ids' => $publishedStudentIds,
            'published_at' => $publishedAt,
            'marking' => $existingMeta['marking'] ?? [
                'primary' => config('services.quiz_ai.marking_primary', 'gemini'),
                'secondary' => config('services.quiz_ai.marking_secondary', 'gemini'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $existingMeta
     * @return array<int, array<string, mixed>>
     */
    protected function resolveQuestionPool(array $data, array $existingMeta): array
    {
        $questions = is_array($data['questions'] ?? null) ? $data['questions'] : [];
        $antiCheat = is_array($data['anti_cheat'] ?? null) ? $data['anti_cheat'] : [];
        $deliverCount = (int) ($antiCheat['deliver_count'] ?? 0);

        if ($deliverCount > 0 && is_array($data['question_pool'] ?? null) && $data['question_pool'] !== []) {
            return $data['question_pool'];
        }

        return $questions;
    }

    public function prepareQuizAudioUpload(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'course_id' => 'required|integer|exists:courses,id',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        if (!$this->pcloud->isConfigured()) {
            return response()->json(['message' => 'pCloud is not configured. Set PCLOUD_ACCESS_TOKEN in .env.'], 503);
        }

        try {
            $this->pcloud->resolveApiHost();

            return response()->json(
                $this->pcloud->directUploadConfig(
                    (int) $data['course_id'],
                    PCloudService::ASSESSMENT_AUDIO_SUBFOLDER,
                    true
                )
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function registerQuizPromptAudio(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'course_id' => 'required|integer|exists:courses,id',
            'pcloud_file_id' => 'required|integer|min:1',
            'filename' => 'required|string|max:255',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        if (!$this->pcloud->isConfigured()) {
            return response()->json(['message' => 'pCloud is not configured.'], 503);
        }

        $fileId = (int) $data['pcloud_file_id'];
        if (!$this->pcloud->fileInCourseFolder((int) $data['course_id'], $fileId)) {
            return response()->json(['message' => 'Audio file not found in this course pCloud folder.'], 404);
        }

        $ref = QuizAudioHelper::pcloudRef($fileId);

        return response()->json([
            'path' => $ref,
            'url' => $ref,
            'pcloud_file_id' => $fileId,
            'filename' => $data['filename'],
            'storage' => 'pcloud',
        ]);
    }

    public function uploadPromptAudio(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'course_id' => 'required|integer|exists:courses,id',
            'audio' => 'required|file|mimes:mp3,wav,m4a,aac,ogg,webm|max:15360',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        if (!$this->pcloud->isConfigured()) {
            return response()->json(['message' => 'pCloud is not configured. Set PCLOUD_ACCESS_TOKEN in .env.'], 503);
        }

        try {
            $uploaded = $this->pcloud->uploadToCourse((int) $data['course_id'], $request->file('audio'), PCloudService::ASSESSMENT_AUDIO_SUBFOLDER);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'pCloud upload failed: ' . $e->getMessage()], 500);
        }

        $fileId = (int) ($uploaded['fileid'] ?? 0);
        if ($fileId <= 0) {
            return response()->json(['message' => 'pCloud upload returned no file id.'], 500);
        }

        $ref = QuizAudioHelper::pcloudRef($fileId);

        return response()->json([
            'path' => $ref,
            'url' => $ref,
            'pcloud_file_id' => $fileId,
            'filename' => $uploaded['name'] ?? $request->file('audio')->getClientOriginalName(),
            'storage' => 'pcloud',
        ]);
    }

    public function uploadAnswerAudio(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'question_id' => 'required|string|max:50',
            'audio' => 'required|file|mimes:mp3,wav,m4a,aac,ogg,webm|max:15360',
        ]);

        $student = Student::findOrFail($data['student_id']);

        $enrolled = CourseEnrollment::query()
            ->where('student_id', $student->id)
            ->where('course_id', $quiz->course_id)
            ->whereIn('status', \App\Support\EnrollmentStatusHelper::accessStatuses())
            ->exists();

        if (!$enrolled) {
            return response()->json(['message' => 'You are not enrolled in this course.'], 403);
        }

        if (!QuizMaterialHelper::isVisibleToStudent($quiz, $student->id)) {
            return response()->json(['message' => 'This assessment is not available to you.'], 403);
        }

        if (!$this->pcloud->isConfigured()) {
            return response()->json(['message' => 'pCloud is not configured.'], 503);
        }

        try {
            $uploaded = $this->pcloud->uploadToCourse((int) $quiz->course_id, $request->file('audio'), PCloudService::ASSESSMENT_AUDIO_SUBFOLDER);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'pCloud upload failed: ' . $e->getMessage()], 500);
        }

        $fileId = (int) ($uploaded['fileid'] ?? 0);
        if ($fileId <= 0) {
            return response()->json(['message' => 'pCloud upload returned no file id.'], 500);
        }

        $answerRef = QuizAudioHelper::answerPcloudRef($fileId);

        return response()->json([
            'path' => $answerRef,
            'answer_value' => $answerRef,
            'pcloud_file_id' => $fileId,
            'filename' => $uploaded['name'] ?? $request->file('audio')->getClientOriginalName(),
            'storage' => 'pcloud',
        ]);
    }

    public function streamAssessmentAudio(Request $request, Course $course)
    {
        $data = $request->validate([
            'pcloud_file_id' => 'required|integer|min:1',
            'filename' => 'nullable|string|max:255',
            'instructor_email' => 'nullable|email',
            'student_id' => 'nullable|integer|exists:students,id',
        ]);

        if (!$this->pcloud->isConfigured()) {
            return response()->json(['message' => 'pCloud is not configured.'], 503);
        }

        $fileId = (int) $data['pcloud_file_id'];
        if (!$this->pcloud->fileInCourseFolder($course->id, $fileId)) {
            return response()->json(['message' => 'Audio file not found for this course.'], 404);
        }

        $allowed = false;

        if (!empty($data['instructor_email'])) {
            $instructor = User::query()
                ->where('email', $data['instructor_email'])
                ->where('role', 'instructor')
                ->first();
            $allowed = $instructor && $instructor->assignedCourses()->where('courses.id', $course->id)->exists();
        }

        if (!$allowed && !empty($data['student_id'])) {
            $studentId = (int) $data['student_id'];
            $allowed = CourseEnrollment::query()
                ->where('student_id', $studentId)
                ->where('course_id', $course->id)
                ->whereIn('status', \App\Support\EnrollmentStatusHelper::accessStatuses())
                ->exists();
        }

        if (!$allowed) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $filename = $data['filename'] ?? 'assessment-audio.webm';

        return $this->pcloud->streamFileResponse($fileId, $filename, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateQuizPayload(Request $request, bool $creating = true): array
    {
        $rules = [
            'instructor_email' => 'required|email',
            'title' => 'required|string|max:255',
            'topic' => 'required|string|max:255',
            'description' => 'nullable|string',
            'passing_score' => 'nullable|integer|min:40|max:100',
            'time_limit_minutes' => 'nullable|integer|min:1|max:240',
            'questions' => 'required|array|min:1',
            'questions.*.id' => 'required|string|max:50',
            'questions.*.type' => 'required|string|max:50',
            'questions.*.question' => 'required|string|max:2000',
            'questions.*.points' => 'nullable|integer|min:1|max:20',
            'questions.*.instruction' => 'nullable|string|max:2000',
            'questions.*.prompt_audio_url' => 'nullable|string|max:500',
            'questions.*.prompt_audio_filename' => 'nullable|string|max:255',
            'questions.*.response_format' => 'nullable|string|in:text,audio',
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answer' => 'nullable|string|max:2000',
            'questions.*.model_answer' => 'nullable|string|max:5000',
            'questions.*.marking_rubric' => 'nullable|string|max:5000',
            'ai_generated' => 'nullable|boolean',
            'generation_provider' => 'nullable|string|max:50',
            'assessment_language' => 'nullable|string|max:12',
            'assessment_language_label' => 'nullable|string|max:50',
            'material_id' => 'nullable|integer|exists:course_materials,id',
            'status' => 'nullable|string|in:draft,published',
            'published_student_ids' => 'nullable|array',
            'published_student_ids.*' => 'integer|exists:students,id',
            'anti_cheat' => 'nullable|array',
            'anti_cheat.shuffle_questions' => 'nullable|boolean',
            'anti_cheat.shuffle_options' => 'nullable|boolean',
            'anti_cheat.deliver_count' => 'nullable|integer|min:0|max:100',
            'anti_cheat.max_attempts' => 'nullable|integer|min:0|max:20',
            'anti_cheat.detect_tab_switch' => 'nullable|boolean',
            'question_pool' => 'nullable|array',
            'assessment_kind' => 'nullable|string|in:quiz,test,exam',
        ];

        if ($creating) {
            $rules['course_id'] = 'required|integer|exists:courses,id';
        }

        $data = $request->validate($rules);
        $data['questions'] = array_map(function ($question) {
            if (!is_array($question)) {
                return $question;
            }
            $question = QuizAnswerMatcher::normalizeQuestionAnswers($question);
            $type = (string) ($question['type'] ?? '');
            if (in_array($type, ['multiple_choice', 'multiple_response'], true) && !empty($question['options']) && is_array($question['options'])) {
                $question['options'] = QuizOptionSorter::sort($question['options']);
            }

            return $question;
        }, $this->mergeQuestionFields(
            $request->input('questions', []),
            $data['questions'] ?? []
        ));

        return $data;
    }

    /**
     * Laravel validated() drops nested keys without rules — preserve full oral/ MCQ payloads.
     *
     * @param  array<int, mixed>  $raw
     * @param  array<int, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    protected function mergeQuestionFields(array $raw, array $validated): array
    {
        return array_values(array_map(function ($validatedQuestion, $index) use ($raw) {
            $rawQuestion = is_array($raw[$index] ?? null) ? $raw[$index] : [];
            $validatedQuestion = is_array($validatedQuestion) ? $validatedQuestion : [];

            return array_merge($rawQuestion, $validatedQuestion);
        }, $validated, array_keys($validated)));
    }

    protected function formatQuizRow(CourseMaterial $m): array
    {
        $meta = QuizMaterialHelper::meta($m);
        $publishedIds = QuizMaterialHelper::publishedStudentIds($m);

        return [
            'id' => $m->id,
            'course_id' => $m->course_id,
            'course_title' => $m->course->title ?? 'Course',
            'title' => $m->title,
            'description' => $m->description,
            'topic' => $meta['topic'] ?? null,
            'assessment_kind' => $meta['assessment_kind'] ?? 'quiz',
            'type' => $m->type,
            'resource_url' => $m->resource_url,
            'question_count' => count($meta['questions'] ?? []),
            'passing_score' => (int) ($meta['passing_score'] ?? 70),
            'time_limit_minutes' => QuizMaterialHelper::timeLimitMinutes($m),
            'status' => QuizMaterialHelper::quizStatus($m),
            'published_student_count' => count($publishedIds),
            'published_student_ids' => $publishedIds,
            'publish_to_all' => QuizMaterialHelper::isPublished($m) && empty($publishedIds),
            'ai_generated' => (bool) ($meta['ai_generated'] ?? false),
            'created_at' => $m->created_at?->toIso8601String(),
            'published_at' => $meta['published_at'] ?? null,
        ];
    }
}
