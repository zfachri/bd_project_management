<?php

namespace Database\Seeders;

use App\Models\SystemReference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SystemReferenceUserCredentialSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;
        $userId = User::query()->value('UserID') ?? 1;
        $baseId = (int) ($timestamp . '30');
        $counter = 0;

        $references = [
            [
                'ReferenceName' => 'System',
                'FieldName' => 'Site Name',
                'FieldValue' => 'https://www.valista.co.id/bd-app/login',
            ],
            [
                'ReferenceName' => 'User',
                'FieldName' => 'Add User',
                'FieldValue' => $this->buildUserCredentialTemplate(),
            ],
        ];

        foreach ($references as $reference) {
            $existing = SystemReference::where('ReferenceName', $reference['ReferenceName'])
                ->where('FieldName', $reference['FieldName'])
                ->first();

            if ($existing) {
                $existing->update([
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $userId,
                    'OperationCode' => 'U',
                    'FieldValue' => $reference['FieldValue'],
                ]);
                continue;
            }

            $counter++;
            SystemReference::create([
                'SystemReferenceID' => (string) ($baseId + $counter),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ReferenceName' => $reference['ReferenceName'],
                'FieldName' => $reference['FieldName'],
                'FieldValue' => $reference['FieldValue'],
            ]);
        }
    }

    private function buildUserCredentialTemplate(): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informasi Akun Pengguna</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f1f5;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:14px;padding:20px;box-sizing:border-box;">
        <div style="background:linear-gradient(90deg,#004aad,#cb6ce6);border-radius:14px;padding:30px 16px;text-align:center;">
            <h1 style="margin:0;font-size:16px;line-height:1.4;color:#ffffff;font-weight:700;">Informasi Akun Pengguna Baru</h1>
        </div>
        <div style="padding:20px 0 10px 0;color:#1f2937;font-size:13px;line-height:1.7;">
            <p>Dengan hormat,</p>
            <p>Berikut kami sampaikan informasi akun Anda:</p>
            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr>
                    <td style="padding:8px;border:1px solid #eceff4;width:35%;"><strong>Nama</strong></td>
                    <td style="padding:8px;border:1px solid #eceff4;">{{full_name}}</td>
                </tr>
                <tr>
                    <td style="padding:8px;border:1px solid #eceff4;"><strong>UserID</strong></td>
                    <td style="padding:8px;border:1px solid #eceff4;">{{user_id}}</td>
                </tr>
                <tr>
                    <td style="padding:8px;border:1px solid #eceff4;"><strong>Password</strong></td>
                    <td style="padding:8px;border:1px solid #eceff4;">{{password}}</td>
                </tr>
                <tr>
                    <td style="padding:8px;border:1px solid #eceff4;"><strong>Link Login</strong></td>
                    <td style="padding:8px;border:1px solid #eceff4;"><a href="{{site_name}}" style="color:#004aad;text-decoration:none;">{{site_name}}</a></td>
                </tr>
            </table>
            <p>Mohon segera login dan lakukan perubahan password setelah pertama kali masuk.</p>
            <p>Terima kasih.</p>
        </div>
        <div style="padding:14px 0 0 0;border-top:1px solid #eceff4;color:#6b7280;font-size:12px;text-align:center;">
            <p style="margin:0 0 8px 0;">Email otomatis dari {{app_name}}. Mohon tidak membalas email ini.</p>
            <p style="margin:0;">&copy; {{year}} {{app_name}}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }
}
