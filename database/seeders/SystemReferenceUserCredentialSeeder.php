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
<body style="margin:0;padding:0;background:#f4f7fa;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;">
    <div style="max-width:600px;margin:32px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <div style="background:linear-gradient(135deg,#1d4ed8 0%,#1e40af 100%);padding:24px;color:#ffffff;text-align:center;">
            <h1 style="margin:0;font-size:22px;">Informasi Akun Pengguna Baru</h1>
        </div>
        <div style="padding:28px 24px;color:#1f2937;font-size:14px;line-height:1.7;">
            <p>Dengan hormat,</p>
            <p>Berikut kami sampaikan informasi akun Anda:</p>
            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr>
                    <td style="padding:8px;border:1px solid #e5e7eb;width:35%;"><strong>Nama</strong></td>
                    <td style="padding:8px;border:1px solid #e5e7eb;">{{full_name}}</td>
                </tr>
                <tr>
                    <td style="padding:8px;border:1px solid #e5e7eb;"><strong>UserID</strong></td>
                    <td style="padding:8px;border:1px solid #e5e7eb;">{{user_id}}</td>
                </tr>
                <tr>
                    <td style="padding:8px;border:1px solid #e5e7eb;"><strong>Password</strong></td>
                    <td style="padding:8px;border:1px solid #e5e7eb;">{{password}}</td>
                </tr>
                <tr>
                    <td style="padding:8px;border:1px solid #e5e7eb;"><strong>Link Login</strong></td>
                    <td style="padding:8px;border:1px solid #e5e7eb;"><a href="{{site_name}}">{{site_name}}</a></td>
                </tr>
            </table>
            <p>Mohon segera login dan lakukan perubahan password setelah pertama kali masuk.</p>
            <p>Terima kasih.</p>
        </div>
        <div style="background:#f8fafc;padding:16px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;text-align:center;">
            <p style="margin:0 0 8px 0;">Email otomatis dari {{app_name}}. Mohon tidak membalas email ini.</p>
            <p style="margin:0;">&copy; {{year}} {{app_name}}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }
}
