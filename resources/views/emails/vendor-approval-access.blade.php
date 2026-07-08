<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Vendor Accreditation Approved</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px;">
    <div style="max-width:640px; margin:auto; background:white; border-radius:14px; padding:24px; border:1px solid #e2e8f0;">
        <h2 style="color:#0f2f57; margin-top:0;">Vendor Accreditation Approved</h2>

        <p>Good day,</p>

        <p>
            Your vendor accreditation has been approved. You may now access the Vendor Portal using the details below.
        </p>

        <table style="width:100%; border-collapse:collapse; margin:20px 0;">
            <tr>
                <td style="padding:8px; font-weight:bold;">Vendor Code</td>
                <td style="padding:8px;">{{ $mailData['vendor_code'] }}</td>
            </tr>
            <tr>
                <td style="padding:8px; font-weight:bold;">Vendor Name</td>
                <td style="padding:8px;">{{ $mailData['vendor_name'] }}</td>
            </tr>
            <tr>
                <td style="padding:8px; font-weight:bold;">Access Key</td>
                <td style="padding:8px; font-weight:bold; color:#1d4ed8;">
                    {{ $mailData['access_key'] }}
                </td>
            </tr>
        </table>

        @if (!empty($mailData['portal_link']))
            <p>
                Please click the link below and enter your vendor code and access key to access your vendor account.
            </p>

            <p>
                <a href="{{ $mailData['portal_link'] }}"
                   style="display:inline-block; background:#0f2f57; color:white; padding:12px 18px; border-radius:10px; text-decoration:none; font-weight:bold;">
                    Open Vendor Portal
                </a>
            </p>

            <p style="word-break:break-all; color:#475569;">
                {{ $mailData['portal_link'] }}
            </p>
        @endif

        <p style="margin-top:24px; color:#64748b; font-size:13px;">
            This is a system-generated email. Please do not reply.
        </p>
    </div>
</body>
</html>
