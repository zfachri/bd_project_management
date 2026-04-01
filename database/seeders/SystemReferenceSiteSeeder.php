<?php

namespace Database\Seeders;

use App\Models\SystemReference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SystemReferenceSiteSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;
        $userId = User::query()->value('UserID') ?? 1;
        $baseId = (int) ($timestamp . '40');
        $counter = 0;

        $references = [
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Project Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Update Project Status Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Task Assignment Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Deleted Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Rejected Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Approved Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Approval Needed Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Project Ready To Complete Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Task Due Date Reminder Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Member Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Remove Member Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Add Expense Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Project',
                'FieldName' => 'Update Expense Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Submission Request Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Submission Approved Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Submission Declined Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Request Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Approved Site',
                'FieldValue' => '',
            ],
            [
                'ReferenceName' => 'Document',
                'FieldName' => 'Revision Declined Site',
                'FieldValue' => '',
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
}
