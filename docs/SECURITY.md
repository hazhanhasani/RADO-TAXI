# Security Baseline

- OTP attempts are rate-limited by phone, IP and device.
- Access tokens are short-lived; refresh tokens are rotated.
- Driver/admin permissions are separate from passenger permissions.
- Every privileged admin action is audited.
- Trip state transitions use server-side authorization and idempotency keys.
- Driver location updates are accepted only for authenticated, eligible drivers.
- Financial ledger rows are immutable; corrections use compensating entries.
- Payment callbacks must be signature-verified and idempotent.
- Secrets never ship inside mobile apps.
- PII is minimized in logs.
