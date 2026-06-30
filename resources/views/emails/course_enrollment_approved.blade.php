<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name') }} - Course Enrollment Approved</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #111827; }
        .wrapper { width: 100%; padding: 24px 0; background-color: #f3f4f6; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); overflow: hidden; }
        .header { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #f9fafb; padding: 20px 28px; }
        .header-title { font-size: 18px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
        .header-subtitle { margin-top: 8px; font-size: 14px; color: #e5e7eb; }
        .body { padding: 24px 28px 28px; }
        .greeting { font-size: 16px; margin-bottom: 16px; }
        .paragraph { font-size: 14px; line-height: 1.6; margin: 0 0 12px; color: #374151; }
        .highlight { background-color: #ecfdf5; border-radius: 10px; padding: 14px 16px; border: 1px solid #6ee7b7; margin: 18px 0; }
        .highlight-title { font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        .course-name { font-weight: 600; color: #111827; }
        .footer { padding: 14px 28px 18px; border-top: 1px solid #e5e7eb; background-color: #f9fafb; font-size: 11px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
  <div class="container">
    <div class="header">
      <div class="header-title">{{ config('app.name') }}</div>
      <div class="header-subtitle">Course enrollment approved</div>
    </div>
    <div class="body">
      <p class="greeting">Dear {{ $student->first_name ?? 'Student' }},</p>
      <p class="paragraph">
        Good news! Your enrollment for the course <span class="course-name">{{ $course->title ?? 'your selected course' }}</span>
        has been <strong>approved</strong> by our team.
      </p>
      <div class="highlight">
        <p class="highlight-title">What happens next?</p>
        <p class="paragraph" style="margin-bottom: 4px;">
          - You can now access your course materials from your learner dashboard under <strong>My Courses</strong>.
        </p>
        <p class="paragraph" style="margin-bottom: 4px;">
          - Watch your email and dashboard for class schedules, Zoom links and further instructions.
        </p>
        <p class="paragraph" style="margin-bottom: 0;">
          - Course payment can be completed later when you receive a payment link from our team.
        </p>
      </div>
      <p class="paragraph">
        Thank you for choosing <strong>{{ config('app.name') }}</strong> for your learning journey.
      </p>
      <p class="paragraph" style="margin-top: 20px;">
        Best regards,<br>
        <strong>{{ config('app.name') }}</strong>
      </p>
    </div>
    <div class="footer">
      You are receiving this email because a course enrollment was approved on your learner account.
    </div>
  </div>
</div>
</body>
</html>
