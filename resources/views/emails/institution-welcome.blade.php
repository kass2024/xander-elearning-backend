<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #1a1a1a; line-height: 1.6; max-width: 600px; margin: 0 auto;">
  <div style="background: linear-gradient(135deg, #254D81, #1D3B66); padding: 28px 24px; border-radius: 12px 12px 0 0;">
    <h1 style="color: #fff; margin: 0; font-size: 22px;">Partner Institution Account</h1>
    <p style="color: rgba(255,255,255,0.85); margin: 8px 0 0;">{{ $institution->name }}</p>
  </div>
  <div style="border: 1px solid #e5e7eb; border-top: none; padding: 24px; border-radius: 0 0 12px 12px;">
    @if($isResend)
      <p>Hello,</p>
      <p>Your partner login credentials for <strong>{{ $institution->name }}</strong> have been reset by the platform administrator.</p>
    @else
      <p>Hello,</p>
      <p>Thank you for registering <strong>{{ $institution->name }}</strong> on our learning platform. Your partner owner account has been created.</p>
    @endif

    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin: 20px 0;">
      <p style="margin: 0 0 8px; font-size: 13px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Login credentials</p>
      <p style="margin: 4px 0;"><strong>Email:</strong> {{ $ownerEmail }}</p>
      <p style="margin: 4px 0;"><strong>Password:</strong> <code style="background:#fff;padding:2px 8px;border-radius:4px;border:1px solid #e2e8f0;">{{ $plainPassword }}</code></p>
    </div>

    <p style="font-size: 14px; color: #475569;">
      @if($institution->status === 'pending_approval')
        Your institution is <strong>pending admin approval</strong>. You will be able to sign in once approved.
      @elseif($institution->payment_status === 'unpaid')
        Please complete your platform payment (check your inbox for a Stripe link) before signing in.
      @else
        You may sign in once your account is fully activated.
      @endif
    </p>

    <p style="margin-top: 24px;">
      <a href="{{ $loginUrl }}" style="background:#012F6B;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:600;display:inline-block;">Sign in to dashboard</a>
    </p>

    <p style="font-size: 12px; color: #94a3b8; margin-top: 28px;">
      For security, change your password after your first login. If you did not request this account, contact platform support.
    </p>
  </div>
</body>
</html>
