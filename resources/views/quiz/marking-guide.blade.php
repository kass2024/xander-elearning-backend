<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Marking guide — {{ $quiz_title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1a1a1a; margin: 24px; font-size: 12px; line-height: 1.45; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .meta { color: #555; margin-bottom: 16px; }
        .score-box { border: 2px solid #ddd; border-radius: 8px; padding: 14px 16px; margin: 16px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .score-box .pct { font-size: 32px; font-weight: 700; }
        .passed { color: #15803d; border-color: #86efac; background: #f0fdf4; }
        .failed { color: #b45309; border-color: #fcd34d; background: #fffbeb; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 11px; letter-spacing: 0.04em; }
        .badge.pass { background: #15803d; color: #fff; }
        .badge.fail { background: #b45309; color: #fff; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ddd; padding: 8px; vertical-align: top; text-align: left; }
        th { background: #f5f5f5; font-size: 11px; }
        .ok { color: #15803d; font-weight: 700; }
        .bad { color: #b91c1c; font-weight: 700; }
        .pending { color: #a16207; font-weight: 700; }
        .footnote { margin-top: 20px; color: #666; font-size: 10px; }
        @media print {
            body { margin: 12mm; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 12px;">
        <button onclick="window.print()" style="padding:8px 14px;cursor:pointer;">Print / Save as PDF</button>
    </div>

    <h1>Quiz marking guide</h1>
    <div class="meta">
        <div><strong>{{ $quiz_title }}</strong>@if($topic) — {{ $topic }}@endif</div>
        <div>Learner: {{ $student_name }} · Attempt #{{ $attempt_id }}</div>
        <div>Submitted: {{ $submitted_at ?: '—' }} · Marked: {{ $marked_at ?: '—' }}</div>
        <div>Audience: {{ ucfirst($audience) }} · Generated: {{ $generated_at }}</div>
    </div>

    <div class="score-box {{ $passed ? 'passed' : 'failed' }}">
        <div>
            <div class="pct">{{ number_format($percentage, 1) }}%</div>
            <div>Score {{ $score }} / {{ $max_score }} · Pass mark {{ $passing_score }}%</div>
            <div style="margin-top:6px;">Marked by {{ $marking_provider ?: 'auto' }}</div>
        </div>
        <span class="badge {{ $passed ? 'pass' : 'fail' }}">{{ $pass_label }}</span>
    </div>

    @if($feedback)
        <h2>Overall feedback</h2>
        <p>{{ $feedback }}</p>
    @endif

    <h2>Question breakdown</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Question</th>
                <th>Your answer</th>
                <th>Correct answer</th>
                <th>Result</th>
                <th>Points</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['number'] }}</td>
                    <td>
                        <div>{{ $row['question'] }}</div>
                        @if($row['explanation'])
                            <div style="margin-top:4px;color:#555;font-size:10px;"><em>{{ $row['explanation'] }}</em></div>
                        @endif
                        @if($row['feedback'])
                            <div style="margin-top:4px;color:#555;font-size:10px;">Feedback: {{ $row['feedback'] }}</div>
                        @endif
                    </td>
                    <td>{{ $row['student_answer'] }}</td>
                    <td>{{ $row['correct_answer'] }}</td>
                    <td>
                        @if($row['pending_review'])
                            <span class="pending">Pending review</span>
                        @elseif($row['correct'] === true)
                            <span class="ok">Correct</span>
                        @elseif($row['correct'] === false)
                            <span class="bad">Incorrect</span>
                        @else
                            <span class="pending">—</span>
                        @endif
                    </td>
                    <td>{{ $row['score'] }}/{{ $row['max_score'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footnote">Xander Learning Hub — automated marking guide. Use your browser’s Print dialog and choose “Save as PDF” to download this report.</p>
</body>
</html>
