<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #111827;
        }

        .wrapper {
            width: 100%;
            padding: 24px 0;
            background-color: #f3f4f6;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #f9fafb;
            padding: 20px 28px;
        }

        .header-title {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .header-subtitle {
            margin-top: 8px;
            font-size: 14px;
            color: #e5e7eb;
        }

        .body {
            padding: 24px 28px 28px;
        }

        .greeting {
            font-size: 16px;
            margin-bottom: 16px;
        }

        .paragraph {
            font-size: 14px;
            line-height: 1.6;
            margin: 0 0 12px;
            color: #374151;
        }

        .credentials {
            margin: 20px 0 22px;
            padding: 16px 18px;
            border-radius: 10px;
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
        }

        .credentials-title {
            font-size: 14px;
            font-weight: 600;
            margin: 0 0 10px;
            color: #111827;
        }

        .cred-row {
            font-size: 13px;
            margin-bottom: 6px;
        }

        .cred-label {
            font-weight: 600;
            color: #4b5563;
        }

        .cred-value {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
            background-color: #ffffff;
            color: #111827;
            padding: 3px 6px;
            border-radius: 4px;
            border: 1px solid #2563eb;
            display: inline-block;
        }

        .button-wrapper {
            margin: 22px 0 10px;
            text-align: left;
        }

        .primary-button {
            display: inline-block;
            padding: 9px 22px;
            border-radius: 999px;
            background-color: #2563eb;
            color: #f9fafb !important;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .note {
            font-size: 11px;
            color: #6b7280;
            margin-top: 8px;
        }

        .footer {
            padding: 14px 28px 18px;
            border-top: 1px solid #e5e7eb;
            background-color: #f9fafb;
            font-size: 11px;
            color: #9ca3af;
            text-align: center;
        }

        .footer a {
            color: #6b7280;
            text-decoration: none;
        }

        @media (max-width: 600px) {
            .container {
                border-radius: 0;
            }

            .header,
            .body,
            .footer {
                padding-left: 20px;
                padding-right: 20px;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="container">
        <div class="header">
            <div class="header-title">{{ config('app.name') }}</div>
            <div class="header-subtitle">Your learner account is now created</div>
        </div>

        <div class="body">
            <p class="greeting">
                Dear {{ $student->first_name ?? $student->name ?? 'Student' }},
            </p>

            <p class="paragraph">
                Your learner account at <strong>{{ config('app.name') }}</strong> has been created successfully.
                Below you will find your login details. Please keep this information in a safe place.
                Your course application has also been received and is now <strong>waiting approval from our team</strong>.
            </p>

            <div class="credentials">
                <p class="credentials-title">Login credentials</p>

                <p class="cred-row">
                    <span class="cred-label">Email:&nbsp;</span>
                    <span class="cred-value">{{ $student->email }}</span>
                </p>

                @isset($password)
                    <p class="cred-row">
                        <span class="cred-label">Password:&nbsp;</span>
                        <span class="cred-value">{{ $password }}</span>
                    </p>
                @endisset

                <p class="paragraph" style="font-size: 12px; margin-top: 12px;">
                    For your security, we recommend that you log in and change this password after your first login.
                </p>
            </div>

            @if(!empty($selectedCourses))
                <div class="credentials" style="margin-top: 8px;">
                    <p class="credentials-title">Courses you applied for</p>
                    <ul style="padding-left: 18px; margin: 8px 0 0; font-size: 13px; color: #374151;">
                        @foreach($selectedCourses as $courseTitle)
                            @if(!empty($courseTitle))
                                <li style="margin-bottom: 4px;">{{ $courseTitle }}</li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="button-wrapper">
                @php
                    $loginUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/login';
                @endphp
                <a href="{{ $loginUrl }}" class="primary-button" target="_blank">
                    Sign in to your learner dashboard
                </a>
                <p class="note">
                    If the button does not work, copy and paste this link into your browser:<br>
                    <span>{{ $loginUrl }}</span>
                </p>
            </div>

            <p class="paragraph">
                If you did not expect this email or believe it was sent in error, please contact our support team.
                Your course application will be reviewed and you will be notified once it is approved.
            </p>

            <p class="paragraph" style="margin-top: 20px;">
                Best regards,<br>
                <strong>{{ config('app.name') }}</strong>
            </p>
        </div>

        <div class="footer">
            <p>
                You are receiving this email because a learner account was created for you at {{ config('app.name') }}.
            </p>
            <p>
                For assistance, contact
                <a href="mailto:info@xanderglobalscholars.com">info@xanderglobalscholars.com</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
