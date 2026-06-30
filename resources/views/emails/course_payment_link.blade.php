<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name') }} - Course payment</title>
</head>
<body style="font-family: sans-serif; color: #111827; line-height: 1.6;">
    <p>Dear {{ $student->first_name ?? 'Learner' }},</p>
    <p>
        This is a payment request for <strong>{{ $course->title ?? 'your course' }}</strong>
        @if($amount > 0)
            ({{ number_format($amount, 2) }} USD).
        @else
            .
        @endif
    </p>
    <p>You already have access to course materials. Please complete payment using the link below when you are ready:</p>
    <p><a href="{{ $paymentUrl }}" style="color: #2563eb;">Pay for this course</a></p>
    <p>Thank you,<br><strong>{{ config('app.name') }}</strong></p>
</body>
</html>
