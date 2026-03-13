<?php

namespace Database\Seeders;

use App\Models\SystemReference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SystemReferenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;
        $userId = User::query()->value('UserID') ?? 1;
        $nextIdBase = $timestamp;
        $counter = 0;

        $references = [
            [
                'ReferenceName' => 'Organization',
                'FieldName' => 'OrganizationLOV',
                'FieldValue' => json_encode([
                    'ListOfValue' => [
                        ['LevelNo' => 1, 'OrganizationLabel' => 'Division'],
                        ['LevelNo' => 2, 'OrganizationLabel' => 'Department'],
                    ],
                ]),
            ],
            [
                'ReferenceName' => 'User',
                'FieldName' => 'OTPExpiry',
                'FieldValue' => '60',
            ],
            [
                'ReferenceName' => 'System',
                'FieldName' => 'AuditLogDuration',
                'FieldValue' => '360',
            ],
            [
                'ReferenceName' => 'System',
                'FieldName' => 'LoginLogDuration',
                'FieldValue' => '360',
            ],
            [
                'ReferenceName' => 'System',
                'FieldName' => 'MaximumLoginAttemptCounter',
                'FieldValue' => '5',
            ],
            [
                'ReferenceName' => 'System',
                'FieldName' => 'NonEmployee',
                'FieldValue' => '1000000',
            ],

            // =======================================
            // Project module email templates (HTML)
            // =======================================
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Project',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Pemberitahuan Project Baru',
                    '<p>Dengan hormat,</p>
                    <p>Anda telah terdaftar pada project <strong>{{project_name}}</strong>.</p>
                    <p>Project ID: <strong>{{project_id}}</strong>.</p>
                    <p>Mohon menindaklanjuti kebutuhan project sesuai peran Anda.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Update Project Status',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Pembaruan Status Project',
                    '<p>Dengan hormat,</p>
                    <p>Status project <strong>{{project_name}}</strong> telah diperbarui.</p>
                    <p>Project ID: <strong>{{project_id}}</strong>.</p>
                    <p>Status: <strong>{{status_name}}</strong> ({{status_code}}).</p>
                    <p>Alasan: {{reason}}</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Task Assignment',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Penugasan Task Baru',
                    '<p>Dengan hormat,</p>
                    <p>Anda telah ditugaskan pada task berikut:</p>
                    <ul>
                        <li>Project: <strong>{{project_name}}</strong> ({{project_id}})</li>
                        <li>Task ID: <strong>{{task_id}}</strong></li>
                        <li>Deskripsi Task: <strong>{{task_description}}</strong></li>
                    </ul>
                    <p>Mohon tindak lanjut sesuai target waktu yang ditentukan.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Rejected',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Informasi Penolakan Task',
                    '<p>Dengan hormat,</p>
                    <p>Task berikut dikembalikan oleh owner untuk perbaikan:</p>
                    <ul>
                        <li>Project: <strong>{{project_name}}</strong> ({{project_id}})</li>
                        <li>Task ID: <strong>{{task_id}}</strong></li>
                        <li>Deskripsi Task: <strong>{{task_description}}</strong></li>
                    </ul>
                    <p>Mohon lakukan penyesuaian dan update progres kembali.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Approval Needed',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Permintaan Approval Task',
                    '<p>Dengan hormat,</p>
                    <p>Task berikut membutuhkan pengecekan/approval owner:</p>
                    <ul>
                        <li>Project: <strong>{{project_name}}</strong> ({{project_id}})</li>
                        <li>Task ID: <strong>{{task_id}}</strong></li>
                        <li>Deskripsi Task: <strong>{{task_description}}</strong></li>
                    </ul>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Project Ready To Complete',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Project Siap Diselesaikan',
                    '<p>Dengan hormat,</p>
                    <p>Project berikut telah siap untuk diselesaikan:</p>
                    <ul>
                        <li>Project: <strong>{{project_name}}</strong></li>
                        <li>Project ID: <strong>{{project_id}}</strong></li>
                    </ul>
                    <p>Seluruh task aktif telah mencapai progres 100% dan sudah terverifikasi.</p>
                    <p>Silakan lanjutkan proses <em>complete project</em>.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Due Date Reminder',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Reminder Due Date Task',
                    '<p>Dengan hormat,</p>
                    <p>Ini adalah pengingat due date task pada project:</p>
                    <ul>
                        <li>Project: <strong>{{project_name}}</strong> ({{project_id}})</li>
                        <li>Task ID: <strong>{{task_id}}</strong></li>
                        <li>Deskripsi Task: <strong>{{task_description}}</strong></li>
                        <li>Due Date: <strong>{{due_date}}</strong></li>
                        <li>Reminder: <strong>{{reminder_day}}</strong></li>
                    </ul>
                    <p>Mohon tindak lanjut penyelesaian task sesuai jadwal.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Member',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Pemberitahuan Keanggotaan Project',
                    '<p>Dengan hormat,</p>
                    <p>Kami informasikan bahwa Anda telah terdaftar sebagai anggota pada project <strong>{{project_name}}</strong>.</p>
                    <p>Project ID: <strong>{{project_id}}</strong>.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Remove Member',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Pemberitahuan Perubahan Keanggotaan',
                    '<p>Dengan hormat,</p>
                    <p>Kami informasikan bahwa keanggotaan Anda pada project <strong>{{project_name}}</strong> telah dinonaktifkan.</p>
                    <p>Project ID: <strong>{{project_id}}</strong>.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Expense',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Pencatatan Expense Project',
                    '<p>Dengan hormat,</p>
                    <p>Expense project telah ditambahkan pada project <strong>{{project_name}}</strong> ({{project_id}}).</p>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Update Expense',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Pembaruan Expense Project',
                    '<p>Dengan hormat,</p>
                    <p>Data expense pada project <strong>{{project_name}}</strong> ({{project_id}}) telah diperbarui.</p>'
                ),
            ],

            // =======================================
            // Document module email templates (HTML)
            // =======================================
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Submission Request',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Permintaan Dokumen Baru',
                    '<p>Dengan hormat,</p>
                    <p>Terdapat permintaan submission dokumen baru dari pengguna.</p>
                    <p>Silakan lakukan peninjauan sesuai prosedur.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Submission Approved',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Pengajuan Dokumen Disetujui',
                    '<p>Dengan hormat,</p>
                    <p>Pengajuan dokumen Anda telah disetujui oleh administrator.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Submission Declined',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Pengajuan Dokumen Ditolak',
                    '<p>Dengan hormat,</p>
                    <p>Pengajuan dokumen Anda belum dapat disetujui.</p>
                    <p>Mohon periksa catatan admin untuk tindak lanjut.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Request',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Permintaan Revisi Dokumen',
                    '<p>Dengan hormat,</p>
                    <p>Terdapat permintaan revisi pada dokumen yang memerlukan peninjauan admin.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Approved',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Revisi Dokumen Disetujui',
                    '<p>Dengan hormat,</p>
                    <p>Permintaan revisi dokumen Anda telah disetujui oleh administrator.</p>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Declined',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Revisi Dokumen Ditolak',
                    '<p>Dengan hormat,</p>
                    <p>Permintaan revisi dokumen Anda belum dapat disetujui.</p>
                    <p>Mohon periksa catatan admin untuk detail penolakan.</p>'
                ),
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

            SystemReference::create([
                'SystemReferenceID' => (string) ((int) $nextIdBase + random_numbersu(5)),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ReferenceName' => $reference['ReferenceName'],
                'FieldName' => $reference['FieldName'],
                'FieldValue' => $reference['FieldValue'],
            ]);
        }
    }

    private function buildHtmlTemplate(string $title, string $content): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($title) . '</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fa;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;">
    <div style="max-width:600px;margin:32px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <div style="background:linear-gradient(135deg,#0f766e 0%,#115e59 100%);padding:24px;color:#ffffff;text-align:center;">
            <h1 style="margin:0;font-size:22px;">' . e($title) . '</h1>
        </div>
        <div style="padding:28px 24px;color:#1f2937;font-size:14px;line-height:1.7;">
            ' . $content . '
            <p style="margin-top:20px;">Terima kasih.</p>
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
