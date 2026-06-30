<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $kindLabel }} published</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:#f3f4f6; color:#111827; }
        .wrapper { width:100%; padding:24px 0; }
        .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; box-shadow:0 10px 30px rgba(15,23,42,0.08); overflow:hidden; }
        .header { background:#012F6B; color:#f9fafb; padding:20px 28px; }
        .header-title { font-size:18px; font-weight:700; letter-spacing:0.05em; text-transform:uppercase; }
        .body { padding:24px 28px 28px; }
        .paragraph { font-size:14px; line-height:1.6; margin:0 0 12px; color:#374151; }
        .highlight { font-weight:600; }
        .btn { display:inline-block; margin-top:12px; padding:10px 18px; background:#012F6B; color:#ffffff !important; text-decoration:none; border-radius:999px; font-size:13px; font-weight:600; }
        .footer { padding:14px 28px 18px; border-top:1px solid #e5e7eb; background:#f9fafb; font-size:11px; color:#9ca3af; text-align:center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="container">
        <div class="header">
            <div class="header-title">{{ config('app.name') }}</div>
            <p style="margin-top:8px;font-size:14px;">New {{ strtolower($kindLabel) }} available</p>
        </div>
        <div class="body">
            <p class="paragraph">Dear {{ $student->first_name ?? $student->name ?? 'Student' }},</p>
            <p class="paragraph">
                A new <span class="highlight">{{ strtolower($kindLabel) }}</span> has been published for
                <span class="highlight">{{ $course->title ?? 'your course' }}</span>:
                <span class="highlight">{{ $quiz->title ?? 'Assessment' }}</span>.
            </p>
            @if(!empty($timeLimit))
                <p class="paragraph"><span class="highlight">Time limit:</span> {{ $timeLimit }} minutes</p>
            @endif
            <p class="paragraph"><span class="highlight">Pass mark:</span> {{ $passingScore }}%</p>
            @if(!empty($takeUrl))
                <a class="btn" href="{{ $takeUrl }}">Open in learner dashboard</a>
                <p class="paragraph" style="margin-top:16px;font-size:12px;color:#6b7280;">Or sign in and go to Materials → Assessments.</p>
            @else
                <p class="paragraph">Sign in to your learner dashboard and open Course Materials to start.</p>
            @endif
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </div>
    </div>
</div>
</body>
</html>
