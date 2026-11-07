<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password WeCare</title>
</head>

<body style="margin:0; padding:0; background-color:#FBF2E7; font-family: Arial, Helvetica, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FBF2E7; padding:32px 0;">
        <tr>
            <td align="center">
                <table width="480" cellpadding="0" cellspacing="0" border="0"
                    style="background-color:#FFFFFF; border-radius:16px; overflow:hidden;">
                    <tr>
                        <td style="background-color:#FEFAF5; padding:24px 32px; border-bottom:1px solid #EEE1CE;">
                            <span style="font-size:20px; font-weight:bold; color:#4A3B2D;">🐣 WeCare</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h2 style="color:#4A3B2D; margin-top:0;">Reset Password Kamu</h2>
                            <p style="color:#8B7458; font-size:14px; line-height:1.6;">
                                Halo <?php echo e($name); ?>, kami terima permintaan reset password buat akun WeCare kamu.
                                Klik tombol di bawah buat bikin password baru.
                            </p>
                            <table cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
                                <tr>
                                    <td style="background-color:#F2A93B; border-radius:999px;">
                                        <a href="<?php echo e($url); ?>"
                                            style="display:inline-block; padding:12px 28px; color:#FFFFFF; font-weight:bold; text-decoration:none; font-size:14px;">
                                            Reset Password
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="color:#B8A48C; font-size:12px; line-height:1.6;">
                                Link ini berlaku selama 60 menit. Kalau kamu gak minta reset password, abaikan aja email
                                ini.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#FEFAF5; padding:16px 32px; border-top:1px solid #EEE1CE;">
                            <p style="color:#B8A48C; font-size:11px; margin:0;">&copy; <?php echo e(date('Y')); ?> WeCare.
                                Berbagi kebaikan, bersama.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
<?php /**PATH /Users/grace/Herd/fai/project/MWF-Proyek/resources/views/mail/reset-password.blade.php ENDPATH**/ ?>