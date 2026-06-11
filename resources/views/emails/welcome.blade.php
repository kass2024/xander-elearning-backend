<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test email</title>
</head>
<body style="font-family: sans-serif; color: #111827;">
    <p>Hello,</p>
    <p>This is a test email from <strong>{{ config('app.name') }}</strong>.</p>
    @if(!empty($student))
        <p>Student: {{ $student->first_name ?? $student->name ?? 'N/A' }}</p>
    @endif
    <p>If you received this message, SMTP is configured correctly.</p>
    <p style="color:#6b7280; font-size:12px;">Sent at {{ now()->toDayDateTimeString() }}</p>
</body>
</html>
