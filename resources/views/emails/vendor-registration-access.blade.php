<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Vendor Portal Registration</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px;">
    <div style="max-width:640px; margin:auto; background:white; border-radius:14px; padding:24px; border:1px solid #e2e8f0;">
        <h2 style="color:#0f2f57; margin-top:0;">Vendor Portal Registration</h2>

        <p>Good day,</p>

        <p>
            You have been invited to register in the Vendor Onboarding Portal.
        </p>

        <table style="width:100%; border-collapse:collapse; margin:20px 0;">
            <tr>
                <td style="padding:8px; font-weight:bold;">Registration No.</td>
                <td style="padding:8px;">{{ $mailData['reg_no'] }}</td>
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

        <p>
            Please click the link below and enter the access key to create your temporary vendor account.
        </p>

        <p>
            <a href="{{ $mailData['registration_link'] }}"
               style="display:inline-block; background:#0f2f57; color:white; padding:12px 18px; border-radius:10px; text-decoration:none; font-weight:bold;">
                Open Vendor Registration
            </a>
        </p>

        <p style="word-break:break-all; color:#475569;">
            {{ $mailData['registration_link'] }}
        </p>

        <p style="margin-top:24px; color:#64748b; font-size:13px;">
            This is a system-generated email. Please do not reply.
        </p>
    </div>
</body>
</html>