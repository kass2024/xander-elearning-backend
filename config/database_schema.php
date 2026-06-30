<?php

/**
 * Expected database schema for parrotglobalstudyacademy.
 * Used by DatabaseSchemaService to detect incomplete deployments and trigger auto-migrate.
 * When adding a migration, append the table/columns here (or add to sync_parrot_hub_schema migration).
 */
return [
    'users' => ['id', 'name', 'email', 'password', 'role', 'status', 'phone', 'platform_institution_id'],
    'students' => ['id', 'email', 'first_name', 'last_name', 'status', 'password', 'country', 'phone', 'primary_goal', 'platform_institution_id'],
    'courses' => ['id', 'title', 'status', 'price', 'course_code', 'program_id', 'platform_institution_id'],
    'elearning_programs' => ['id', 'name', 'status', 'sort_order', 'platform_institution_id'],
    'course_enrollments' => ['id', 'student_id', 'course_id', 'status', 'level', 'study_shift_id'],
    'course_payments' => ['id', 'course_id', 'student_id', 'amount_cents', 'status', 'provider', 'platform_institution_id'],
    'platform_institutions' => [
        'id', 'name', 'slug', 'contact_email', 'status', 'payment_status', 'owner_user_id',
        'mail_use_custom', 'mail_host',
    ],
    'institution_promo_codes' => ['id', 'code', 'max_uses', 'uses_count', 'is_active'],
    'institution_payments' => ['id', 'platform_institution_id', 'amount_cents', 'status'],
    'assign_cours' => ['user_id', 'course_id'],
    'meeting_registrations' => ['id', 'email', 'status'],
    'available_schedules' => ['id', 'available_on_date'],
    'livezoom_cohort' => ['id', 'available_on_date', 'platform_institution_id'],
    'livezoom_cohort_queue_entries' => ['id'],
    'instructor_payout_requests' => ['id', 'instructor_id', 'amount', 'status', 'payment_method'],
    'webinar_settings' => ['id'],
    'study_shifts' => ['id', 'name', 'day_of_week', 'start_time', 'end_time', 'is_active', 'platform_institution_id'],
    'course_enrollment_study_shifts' => ['id', 'course_enrollment_id', 'study_shift_id'],
    'study_shift_change_requests' => ['id', 'course_enrollment_id', 'student_id', 'course_id', 'status'],
    'course_materials' => ['id', 'course_id'],
    'quiz_attempts' => ['id'],
];
