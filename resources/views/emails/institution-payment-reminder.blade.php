<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #1a1a1a;">
  <h2>Payment reminder</h2>
  <p>Hello,</p>
  <p>Your institution <strong>{{ $institution->name }}</strong> has an outstanding platform subscription payment.</p>
  <p><a href="{{ $checkoutUrl }}" style="background:#012F6B;color:#fff;padding:12px 20px;text-decoration:none;border-radius:8px;">Pay with Stripe</a></p>
  <p>If you already paid, you can ignore this email.</p>
</body>
</html>
