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
                'ReferenceName' => 'System',
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
                    'Project Successfully Created',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        A new project, <strong style="color:#1f2937;">{{project_name}}</strong>, has been successfully created.
                        Kindly review the project details for your reference.
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Project -&gt;</a>
                            </td>
                        </tr>
                    </table>'
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
                    'New Task Assignment',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        A new task has been assigned within <strong style="color:#1f2937;">{{project_name}}</strong>.
                        Kindly review the task details and proceed accordingly.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Task -&gt;</a>
                            </td>
                        </tr>
                    </table>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Deleted',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Task Deleted Notification',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        A task, <strong style="color:#1f2937;">{{task_description}}</strong>, within
                        <strong style="color:#1f2937;">{{project_name}}</strong> has been deleted.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        Kindly review the current project task details.
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Project -&gt;</a>
                            </td>
                        </tr>
                    </table>'
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
                'FieldName' => 'Task Approved',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Task Disetujui Owner',
                    '<p>Dengan hormat,</p>
                    <p>Task berikut telah disetujui oleh owner:</p>
                    <ul>
                        <li>Project: <strong>{{project_name}}</strong> ({{project_id}})</li>
                        <li>Task ID: <strong>{{task_id}}</strong></li>
                        <li>Deskripsi Task: <strong>{{task_description}}</strong></li>
                    </ul>
                    <p>Terima kasih atas penyelesaian task yang telah dilakukan.</p>'
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
                    'Project Member Added',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        You have been added to <strong style="color:#1f2937;">{{project_name}}</strong>.
                        Kindly review the project details and the updated team composition.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Project -&gt;</a>
                            </td>
                        </tr>
                    </table>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Remove Member',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Project Member Removed',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        You have been removed from <strong style="color:#1f2937;">{{project_name}}</strong>.
                        You will no longer have access to the project and its associated activities.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Details -&gt;</a>
                            </td>
                        </tr>
                    </table>'
                ),
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Expense',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Project Add Expense',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        Here is the latest project expense information for
                        <strong style="color:#1f2937;">{{project_id}} - {{project_name}}</strong>.
                    </p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        Expense amount:
                        <strong style="color:#1f2937;">{{expense_currency}} {{expense_amount}}</strong>.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For more details, please click the button below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 18px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">Project Expenses -&gt;</a>
                            </td>
                        </tr>
                    </table>'
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
                    'Document Submission Request',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        There is a document submission request for <strong style="color:#1f2937;">{{document_name}}</strong>.
                        Kindly review and submit the required document accordingly.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Document -&gt;</a>
                            </td>
                        </tr>
                    </table>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Submission Approved',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Document Submission Approved',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        The submission for <strong style="color:#1f2937;">{{document_name}}</strong> has been approved.
                        No further action is required at this stage.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Document -&gt;</a>
                            </td>
                        </tr>
                    </table>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Submission Declined',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Document Submission Declined',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        The submission for <strong style="color:#1f2937;">{{document_name}}</strong> has been declined.
                        Kindly review the feedback provided and resubmit the document.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Details -&gt;</a>
                            </td>
                        </tr>
                    </table>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Request',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Document Revision Request',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        There is a document revision request for <strong style="color:#1f2937;">{{document_name}}</strong>.
                        Kindly review the requested changes and proceed accordingly.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">Review Document Revision -&gt;</a>
                            </td>
                        </tr>
                    </table>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Approved',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Document Revision Approved',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        The document revision request for <strong style="color:#1f2937;">{{document_name}}</strong> has been approved.
                        You may proceed with the subsequent steps.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Document -&gt;</a>
                            </td>
                        </tr>
                    </table>'
                ),
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Declined',
                'FieldValue' => $this->buildHtmlTemplate(
                    'Document Revision Declined',
                    '<p style="margin:0 0 12px 0;">Hello, <strong>{{recipient_name}}</strong></p>
                    <p style="margin:0 0 12px 0;color:#737373;">
                        The document revision request for <strong style="color:#1f2937;">{{document_name}}</strong> has been declined.
                        Kindly review the provided feedback and take the necessary action.
                    </p>
                    <p style="margin:0 0 20px 0;color:#737373;">
                        For further details, please click below:
                    </p>
                    <table cellpadding="0" cellspacing="0" border="0" style="margin:26px auto 8px auto;">
                        <tr>
                            <td style="background:linear-gradient(90deg,#c840d4,#e970f7,#f7b5fd);border-radius:12px;">
                                <a href="{{site_name}}" target="_blank" rel="noopener" style="display:inline-block;padding:10px 28px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;">View Details -&gt;</a>
                            </td>
                        </tr>
                    </table>'
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
<body style="margin:0;padding:0;background-color:#f0f1f5;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:14px;padding:20px;box-sizing:border-box;">
        <div style="background:linear-gradient(90deg,#004aad,#cb6ce6);border-radius:14px;padding:30px 16px;text-align:center;">
            <h1 style="margin:0;font-size:16px;line-height:1.4;color:#ffffff;font-weight:700;">' . e($title) . '</h1>
        </div>
        <div style="padding:20px 0 10px 0;color:#1f2937;font-size:13px;line-height:1.7;">
            ' . $content . '
            <p style="margin:20px 0 0 0;color:#737373;">Thank you.</p>
        </div>
        <div style="padding:14px 0 0 0;border-top:1px solid #eceff4;color:#6b7280;font-size:12px;text-align:center;">
            <p style="margin:0 0 8px 0;">Automated email from {{app_name}}. Please do not reply to this message.</p>
            <p style="margin:0;">&copy; {{year}} {{app_name}}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }
}
