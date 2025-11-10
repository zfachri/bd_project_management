<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class JobDescriptionSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = Carbon::now()->timestamp;

        DB::transaction(function () use ($timestamp) {
            $jobDescriptions = [
                // General Manager
                [
                    'RecordID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010001,
                    'PositionID' => 200000000010001,
                    'JobDescription' => 'Responsible for overall business operations and strategic direction',
                    'MainTaskDescription' => 'Lead strategic planning, oversee department managers, ensure business growth',
                    'MainTaskMeasurement' => 'Revenue growth, team performance, strategic goal achievement',
                    'InternalRelationshipDescription' => 'All department managers and supervisors',
                    'InternalRelationshipObjective' => 'Coordination and strategic alignment',
                    'ExternalRelationshipDescription' => 'Board of Directors, Stakeholders, Partners',
                    'ExternalRelationshipObjective' => 'Business development and partnership',
                    'TechnicalCompetency' => 'Strategic Management, Business Analysis, Financial Planning',
                    'SoftCompetency' => 'Leadership, Decision Making, Communication',
                    'IsDelete' => 0
                ],

                // Marketing Manager
                [
                    'RecordID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010002,
                    'PositionID' => 200000000010002,
                    'JobDescription' => 'Lead marketing strategies and brand development',
                    'MainTaskDescription' => 'Develop marketing campaigns, manage marketing team, analyze market trends',
                    'MainTaskMeasurement' => 'Brand awareness, campaign ROI, market share growth',
                    'InternalRelationshipDescription' => 'Sales team, Product team, Finance',
                    'InternalRelationshipObjective' => 'Campaign coordination and budget alignment',
                    'ExternalRelationshipDescription' => 'Advertising agencies, Media partners, Customers',
                    'ExternalRelationshipObjective' => 'Brand promotion and customer engagement',
                    'TechnicalCompetency' => 'Digital Marketing, Brand Management, Market Research',
                    'SoftCompetency' => 'Creativity, Team Management, Strategic Thinking',
                    'IsDelete' => 0
                ],

                // Marketing Supervisor
                [
                    'RecordID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010008,
                    'PositionID' => 200000000010003,
                    'JobDescription' => 'Supervise marketing operations and campaign execution',
                    'MainTaskDescription' => 'Manage marketing staff, execute campaigns, monitor performance',
                    'MainTaskMeasurement' => 'Campaign completion rate, team productivity, budget adherence',
                    'InternalRelationshipDescription' => 'Marketing staff, Sales supervisor',
                    'InternalRelationshipObjective' => 'Campaign execution coordination',
                    'ExternalRelationshipDescription' => 'Vendors, Social media platforms',
                    'ExternalRelationshipObjective' => 'Content distribution and promotion',
                    'TechnicalCompetency' => 'Social Media Management, Content Creation, Analytics',
                    'SoftCompetency' => 'Team Coordination, Communication, Problem Solving',
                    'IsDelete' => 0
                ],

                // Sales Manager
                [
                    'RecordID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010004,
                    'PositionID' => 200000000010008,
                    'JobDescription' => 'Drive sales strategy and revenue growth',
                    'MainTaskDescription' => 'Set sales targets, manage sales team, develop sales strategies',
                    'MainTaskMeasurement' => 'Revenue achievement, sales growth, customer acquisition',
                    'InternalRelationshipDescription' => 'Marketing, Finance, Supply Chain',
                    'InternalRelationshipObjective' => 'Sales support and inventory management',
                    'ExternalRelationshipDescription' => 'Key clients, Distributors, Retailers',
                    'ExternalRelationshipObjective' => 'Partnership development and sales growth',
                    'TechnicalCompetency' => 'Sales Strategy, CRM Systems, Negotiation',
                    'SoftCompetency' => 'Leadership, Persuasion, Relationship Building',
                    'IsDelete' => 0
                ],

                // Finance Supervisor
                [
                    'RecordID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => 1000000,
                    'OperationCode' => 'I',
                    'OrganizationID' => 100000000010018,
                    'PositionID' => 200000000010012,
                    'JobDescription' => 'Oversee financial operations and reporting',
                    'MainTaskDescription' => 'Supervise accounting processes, financial reporting, budget monitoring',
                    'MainTaskMeasurement' => 'Report accuracy, timely submissions, compliance adherence',
                    'InternalRelationshipDescription' => 'Finance staff, Department managers',
                    'InternalRelationshipObjective' => 'Financial information and budget control',
                    'ExternalRelationshipDescription' => 'Auditors, Banks, Tax authorities',
                    'ExternalRelationshipObjective' => 'Compliance and financial transactions',
                    'TechnicalCompetency' => 'Accounting Standards, Financial Analysis, ERP Systems',
                    'SoftCompetency' => 'Attention to Detail, Analytical Thinking, Communication',
                    'IsDelete' => 0
                ],
            ];

            DB::table('JobDescription')->insert($jobDescriptions);
        });
    }
}