<?php

/**
 * Maps existing course titles to e-learning programs by keyword.
 * Edit this file before running: php artisan courses:assign-programs
 *
 * Matching is case-insensitive against course title (and course_code if set).
 * First matching program wins. Unmatched courses go to the fallback program.
 */
return [
  'fallback_program' => 'General',

  'programs' => [
    'Language' => [
      'description' => 'Language learning and international exam preparation',
      'sort_order' => 1,
      'keywords' => [
        'tcf',
        'ielts',
        'toefl',
        'delf',
        'dalf',
        'french',
        'english',
        'conversation',
        'business english',
        'advanced conversation',
        'english basics',
        'language',
        'spanish',
        'german',
      ],
    ],
    'AI & Technology' => [
      'description' => 'AI, technology, and digital skills',
      'sort_order' => 2,
      'keywords' => [
        'ai mastery',
        'parrot ai',
        'artificial intelligence',
        'machine learning',
        'data science',
        'programming',
        'python',
        'javascript',
      ],
    ],
    'Business & Career' => [
      'description' => 'Professional and career development',
      'sort_order' => 3,
      'keywords' => [
        'business',
        'career',
        'leadership',
        'management',
        'marketing',
        'entrepreneur',
      ],
    ],
    'General' => [
      'description' => 'Default program for uncategorized courses',
      'sort_order' => 99,
      'keywords' => [],
    ],
  ],
];
