<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode Verifikasi Reset Password - TAMENG SOC</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #0b1320;
            color: #d1d5db;
            margin: 0;
            padding: 24px;
        }
        .email-card {
            max-width: 540px;
            margin: 0 auto;
            background-color: #151f30;
            border: 1px solid #1f2e46;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
        }
        .header {
            background-color: #0f172a;
            padding: 28px 24px;
            text-align: center;
            border-bottom: 2px solid #00d2ff;
        }
        .header h1 {
            color: #00d2ff;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 3px;
            margin: 0;
        }
        .header p {
            color: #94a3b8;
            font-size: 12px;
            margin: 6px 0 0 0;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .body-content {
            padding: 32px 28px;
        }
        .greeting {
            font-size: 16px;
            color: #f8fafc;
            margin-bottom: 16px;
        }
        .message {
            font-size: 14px;
            line-height: 1.6;
            color: #cbd5e1;
            margin-bottom: 24px;
        }
        .code-box {
            background-color: #0c1524;
            border: 2px dashed #00d2ff;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 28px 0;
        }
        .code-label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }
        .code-value {
            font-family: 'Courier New', Courier, monospace;
            font-size: 34px;
            font-weight: 900;
            letter-spacing: 10px;
            color: #00d2ff;
            margin: 0;
        }
        .expiry-notice {
            font-size: 13px;
            color: #f59e0b;
            background-color: rgba(245, 158, 11, 0.1);
            border-left: 3px solid #f59e0b;
            padding: 10px 14px;
            border-radius: 4px;
            margin-bottom: 24px;
        }
        .security-warning {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
            border-top: 1px solid #1f2e46;
            padding-top: 18px;
        }
        .footer {
            background-color: #0c1524;
            padding: 20px 24px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            border-top: 1px solid #1f2e46;
        }
    </style>
</head>
<body>
    <div class="email-card">
        <div class="header">
            <h1>TAMENG</h1>
            <p>Security Operations Center</p>
        </div>
        <div class="body-content">
            <div class="greeting">
                Halo, <strong>{{ $user->name }}</strong>
            </div>
            <div class="message">
                Kami menerima permintaan untuk mereset kata sandi akun Anda di portal keamanan <strong>TAMENG</strong>. Silakan gunakan kode verifikasi di bawah ini untuk mengonfirmasi perubahan kata sandi:
            </div>
            <div class="code-box">
                <div class="code-label">Kode Verifikasi Reset Password</div>
                <div class="code-value">{{ $resetCode }}</div>
            </div>
            <div class="expiry-notice">
                ⏰ Kode verifikasi ini hanya berlaku selama <strong>30 menit</strong>.
            </div>
            <div class="security-warning">
                <strong>Peringatan Keamanan:</strong> Demi melindungi akun Anda dari penyalahgunaan, jangan pernah membagikan kode ini kepada siapapun. Tim TAMENG tidak pernah meminta kode sandi atau token Anda. Jika Anda tidak merasa meminta reset password ini, Anda dapat mengabaikan email ini.
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} TAMENG SOC. Sistem Manajemen & Audit Kerentanan Keamanan Siber.
        </div>
    </div>
</body>
</html>
