<?php

use App\Http\Controllers\Api\ProjectController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Http\Request;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('project:send-due-date-reminders {--date=} {--project_id=*}', function () {
    $date = $this->option('date');
    $projectIds = array_values(array_filter((array) $this->option('project_id')));

    $payload = [];
    if (!empty($date)) {
        $payload['RunDate'] = $date;
    }
    if (!empty($projectIds)) {
        $payload['ProjectID'] = array_map(static fn($id) => (int) $id, $projectIds);
    }

    $request = Request::create('/internal/projects/reminders/due-date', 'POST', $payload);
    $request->auth_user = (object) [
        'UserID' => 0,
        'IsAdministrator' => true,
    ];
    $request->merge(['auth_user_id' => 0]);

    $response = app(ProjectController::class)->sendDueDateReminders($request);
    $statusCode = $response->getStatusCode();
    $responseData = json_decode($response->getContent(), true);

    if ($statusCode >= 400) {
        $this->error($responseData['message'] ?? 'Failed to send due date reminders');
        return SymfonyCommand::FAILURE;
    }

    $data = $responseData['data'] ?? [];
    $this->info('Due date reminders processed successfully.');
    $this->line('RunDate: ' . ($data['RunDate'] ?? '-'));
    $this->line('TotalTasksMatched: ' . ($data['TotalTasksMatched'] ?? 0));
    $this->line('TotalEmailsSent: ' . ($data['TotalEmailsSent'] ?? 0));

    return SymfonyCommand::SUCCESS;
})->purpose('Send project task due date reminders (D-30,15,7,3,2,1,H0)');

Schedule::command('project:send-due-date-reminders')
    ->dailyAt('08:00')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();
