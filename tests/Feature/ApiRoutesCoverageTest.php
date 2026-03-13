<?php

namespace Tests\Feature;

use App\Models\LoginCheck;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionLevel;
use App\Services\MinioService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiRoutesCoverageTest extends TestCase
{
    use RefreshDatabase;

    private static ?array $cachedApiRoutes = null;

    private string $adminEmail = 'apitest-admin@example.com';
    private string $adminPassword = '123456';
    private string $accessToken = '';

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite extension is required for this test suite.');
        }

        parent::setUp();
        Mail::fake();

        config([
            'filesystems.disks.minio.endpoint' => 'http://localhost:9000',
            'filesystems.disks.minio.bucket' => 'test-bucket',
        ]);

        $this->seedAdminUser();
        $this->accessToken = $this->loginAndGetAccessToken();
    }

    #[DataProvider('publicRoutesProvider')]
    public function test_public_api_routes_are_reachable(string $method, string $uri): void
    {
        $response = $this->json($method, $uri, []);
        $status = $response->getStatusCode();

        $this->assertNotSame(404, $status, "Public route not found: [{$method}] {$uri}");
        $this->assertNotSame(405, $status, "Method not allowed for route: [{$method}] {$uri}");
        $this->assertTrue($status < 500, "Public route returned server error {$status}: [{$method}] {$uri}");
    }

    #[DataProvider('protectedRoutesProvider')]
    public function test_protected_api_routes_reject_unauthenticated_request(string $method, string $uri): void
    {
        $response = $this->json($method, $uri, []);
        $status = $response->getStatusCode();

        $this->assertSame(
            401,
            $status,
            "Protected route should return 401 when unauthenticated: [{$method}] {$uri}"
        );
    }

    #[DataProvider('protectedRoutesProvider')]
    public function test_protected_api_routes_are_reachable_with_authentication(string $method, string $uri): void
    {
        $response = $this->withToken($this->accessToken)->json($method, $uri, []);
        $status = $response->getStatusCode();

        $this->assertNotSame(401, $status, "Authenticated route still returns 401: [{$method}] {$uri}");
        $this->assertNotSame(405, $status, "Method not allowed for route: [{$method}] {$uri}");
        $this->assertTrue($status < 500, "Authenticated route returned server error {$status}: [{$method}] {$uri}");
    }

    public function test_users_create_and_get_detail_functional(): void
    {
        $storePayload = [
            'FullName' => 'API Test User',
            'Email' => 'apitest-user@example.com',
            'Password' => '123456',
            'UTCCode' => '+07:00',
        ];

        $storeResponse = $this->withToken($this->accessToken)->postJson('/api/users', $storePayload);
        $storeResponse->assertStatus(201);
        $storeResponse->assertJson([
            'success' => true,
            'message' => 'User created successfully',
        ]);

        $createdUserId = data_get($storeResponse->json(), 'data.UserID');
        $this->assertNotEmpty($createdUserId, 'Created UserID is empty.');

        $showResponse = $this->withToken($this->accessToken)->getJson('/api/users/' . $createdUserId);
        $showResponse->assertStatus(200);
        $showResponse->assertJson([
            'success' => true,
            'message' => 'User retrieved successfully',
        ]);
        $showResponse->assertJsonPath('data.UserID', (string) $createdUserId);
        $showResponse->assertJsonPath('data.Email', 'apitest-user@example.com');
    }

    public function test_users_create_duplicate_email_returns_validation_error(): void
    {
        $payload = [
            'FullName' => 'Duplicated Mail User',
            'Email' => 'duplicate-user@example.com',
            'Password' => '123456',
            'UTCCode' => '+07:00',
        ];

        $first = $this->withToken($this->accessToken)->postJson('/api/users', $payload);
        $first->assertStatus(201);

        $second = $this->withToken($this->accessToken)->postJson('/api/users', $payload);
        $second->assertStatus(422);
        $second->assertJsonPath('success', false);
        $second->assertJsonStructure(['errors' => ['Email']]);
    }

    public function test_employees_create_and_get_detail_functional(): void
    {
        $organization = $this->createOrganization(61001, 'Org API Test');
        $positionLevel = $this->createPositionLevel(62001, 'Supervisor');
        $position = $this->createPosition(
            63001,
            $organization->OrganizationID,
            $positionLevel->PositionLevelID,
            'Supervisor QA'
        );

        $payload = [
            'FullName' => 'Employee API Test',
            'Email' => 'employee-apitest@example.com',
            'EmployeeID' => 64001,
            'OrganizationID' => $organization->OrganizationID,
            'GenderCode' => 'M',
            'JoinDate' => '2026-01-01',
            'Positions' => [
                [
                    'PositionID' => $position->PositionID,
                    'PositionName' => $position->PositionName,
                    'PositionLevelID' => $positionLevel->PositionLevelID,
                    'StartDate' => '2026-01-01',
                ],
            ],
        ];

        $store = $this->withToken($this->accessToken)->postJson('/api/employees', $payload);
        $store->assertStatus(200);
        $store->assertJsonPath('success', true);

        $show = $this->withToken($this->accessToken)->getJson('/api/employees/64001');
        $show->assertStatus(200);
        $show->assertJsonPath('success', true);
        $show->assertJsonPath('data.EmployeeID', 64001);
        $show->assertJsonPath('data.User.Email', 'employee-apitest@example.com');
    }

    public function test_employees_create_duplicate_returns_validation_error(): void
    {
        $organization = $this->createOrganization(65001, 'Org Duplicate Employee');
        $positionLevel = $this->createPositionLevel(66001, 'Staff');
        $position = $this->createPosition(
            67001,
            $organization->OrganizationID,
            $positionLevel->PositionLevelID,
            'Staff IT'
        );

        $payload = [
            'FullName' => 'Duplicate Employee',
            'Email' => 'duplicate-employee@example.com',
            'EmployeeID' => 68001,
            'OrganizationID' => $organization->OrganizationID,
            'GenderCode' => 'F',
            'JoinDate' => '2026-01-01',
            'Positions' => [
                [
                    'PositionID' => $position->PositionID,
                    'PositionName' => $position->PositionName,
                    'PositionLevelID' => $positionLevel->PositionLevelID,
                    'StartDate' => '2026-01-01',
                ],
            ],
        ];

        $first = $this->withToken($this->accessToken)->postJson('/api/employees', $payload);
        $first->assertStatus(200);

        $second = $this->withToken($this->accessToken)->postJson('/api/employees', $payload);
        $second->assertStatus(422);
        $second->assertJsonPath('success', false);
        $second->assertJsonStructure(['errors' => ['Email', 'EmployeeID']]);
    }

    public function test_projects_create_list_show_and_owner_validation(): void
    {
        $member = $this->createBasicUser(69001, 'project-member@example.com', 'Project Member');

        $validPayload = [
            'project' => [
                'LevelNo' => 1,
                'IsChild' => false,
                'ProjectName' => 'Project Feature API Test',
                'ProjectDescription' => 'Project untuk pengujian API',
                'CurrencyCode' => 'IDR',
                'BudgetAmount' => 1000000,
                'StartDate' => '2026-01-01',
                'EndDate' => '2026-03-01',
                'PriorityCode' => 2,
            ],
            'status' => [
                'ProjectStatusCode' => '10',
            ],
            'members' => [
                [
                    'UserID' => 900001,
                    'IsOwner' => true,
                    'Title' => 'Owner',
                ],
                [
                    'UserID' => $member->UserID,
                    'IsOwner' => false,
                    'Title' => 'Member',
                ],
            ],
        ];

        $store = $this->withToken($this->accessToken)->postJson('/api/projects', $validPayload);
        $store->assertStatus(201);
        $store->assertJsonPath('success', true);
        $projectId = (string) data_get($store->json(), 'data.ProjectID');
        $this->assertNotSame('', $projectId);

        $list = $this->withToken($this->accessToken)->getJson('/api/projects');
        $list->assertStatus(200);
        $list->assertJsonPath('success', true);

        $show = $this->withToken($this->accessToken)->getJson("/api/projects/{$projectId}?include=members");
        $show->assertStatus(200);
        $show->assertJsonPath('success', true);
        $show->assertJsonPath('data.ProjectID', (int) $projectId);

        $invalidOwnerPayload = $validPayload;
        $invalidOwnerPayload['members'][1]['IsOwner'] = true;

        $invalid = $this->withToken($this->accessToken)->postJson('/api/projects', $invalidOwnerPayload);
        $invalid->assertStatus(422);
        $invalid->assertJsonPath('success', false);
        $invalid->assertJsonPath('message', 'Project must have exactly ONE owner');
    }

    public function test_documents_create_get_list_and_duplicate_name(): void
    {
        $ownerOrg = $this->createOrganization(70001, 'Org Document Owner');
        $accessOrg = $this->createOrganization(70002, 'Org Document Access');
        $this->mockMinioService();

        $payload = [
            'document_name' => 'SOP Pengujian API',
            'document_type' => 'SOP',
            'description' => 'Dokumen pengujian',
            'organization_id' => $ownerOrg->OrganizationID,
            'filename' => 'sop-pengujian.pdf',
            'content_type' => 'application/pdf',
            'file_size' => 12345,
            'access_organization_ids' => [$accessOrg->OrganizationID],
            'is_download' => true,
            'is_comment' => true,
        ];

        $store = $this->withToken($this->accessToken)->postJson('/api/document-management/create', $payload);
        $store->assertStatus(201);
        $store->assertJsonPath('success', true);
        $documentId = (int) data_get($store->json(), 'data.document_id');
        $this->assertTrue($documentId > 0);

        $show = $this->withToken($this->accessToken)->getJson("/api/document-management/{$documentId}");
        $show->assertStatus(200);
        $show->assertJsonPath('success', true);
        $show->assertJsonPath('data.DocumentManagementID', $documentId);

        $list = $this->withToken($this->accessToken)->postJson('/api/document-management/list', [
            'organization_id' => $ownerOrg->OrganizationID,
        ]);
        $list->assertStatus(200);
        $list->assertJsonPath('success', true);

        $duplicate = $this->withToken($this->accessToken)->postJson('/api/document-management/create', $payload);
        $duplicate->assertStatus(422);
        $duplicate->assertJsonPath('success', false);
        $duplicate->assertJsonStructure(['errors' => ['document_name']]);
    }

    public static function publicRoutesProvider(): array
    {
        $routes = self::collectApiRouteMatrix();
        $public = array_filter($routes, static fn(array $item) => !$item['protected']);

        $dataset = [];
        foreach ($public as $item) {
            $dataset["{$item['method']} {$item['uri']}"] = [$item['method'], $item['uri']];
        }

        return $dataset;
    }

    public static function protectedRoutesProvider(): array
    {
        $routes = self::collectApiRouteMatrix();
        $protected = array_filter($routes, static fn(array $item) => $item['protected']);

        $dataset = [];
        foreach ($protected as $item) {
            $dataset["{$item['method']} {$item['uri']}"] = [$item['method'], $item['uri']];
        }

        return $dataset;
    }

    private static function collectApiRouteMatrix(): array
    {
        if (self::$cachedApiRoutes !== null) {
            return self::$cachedApiRoutes;
        }

        $filePath = dirname(__DIR__, 2) . '/routes/api.php';
        $content = file_get_contents($filePath) ?: '';
        $lines = preg_split('/\R/', $content) ?: [];

        $groupStack = [
            ['prefix' => '/api', 'protected' => false],
        ];
        $routes = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^Route::middleware\(\[(.*?)\]\)->group\(function \(\) \{$/', $trimmed, $m)) {
                $parent = end($groupStack);
                $isProtected = str_contains($m[1], "'jwt.auth'") || str_contains($m[1], '"jwt.auth"');
                $groupStack[] = [
                    'prefix' => $parent['prefix'],
                    'protected' => $parent['protected'] || $isProtected,
                ];
                continue;
            }

            if (preg_match('/^Route::prefix\(\'([^\']+)\'\)->group\(function \(\) \{$/', $trimmed, $m)) {
                $parent = end($groupStack);
                $prefix = rtrim($parent['prefix'], '/') . '/' . trim($m[1], '/');
                $groupStack[] = [
                    'prefix' => $prefix,
                    'protected' => $parent['protected'],
                ];
                continue;
            }

            if (preg_match('/^\\}\\);$/', $trimmed) && count($groupStack) > 1) {
                array_pop($groupStack);
                continue;
            }

            if (preg_match('/^Route::(get|post|put|patch|delete)\(\'([^\']+)\'/', $trimmed, $m)) {
                $ctx = end($groupStack);
                $method = strtoupper($m[1]);
                $uri = $ctx['prefix'] . '/' . ltrim($m[2], '/');
                $uri = preg_replace('#/+#', '/', $uri) ?? $uri;

                $routes[] = [
                    'method' => $method,
                    'uri' => self::resolveUriParametersStatic($uri),
                    'protected' => (bool) $ctx['protected'],
                ];
            }
        }

        self::$cachedApiRoutes = $routes;
        return $routes;
    }

    private static function resolveUriParametersStatic(string $uri): string
    {
        return preg_replace('/\{[^}]+\??\}/', '1', $uri) ?? $uri;
    }

    private function seedAdminUser(): void
    {
        $timestamp = Carbon::now()->timestamp;
        $salt = Str::uuid()->toString();

        User::create([
            'UserID' => 900001,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 900001,
            'OperationCode' => 'I',
            'IsAdministrator' => true,
            'FullName' => 'API Test Admin',
            'Email' => $this->adminEmail,
            'Password' => Hash::make($this->adminPassword . $salt),
            'UTCCode' => '+07:00',
        ]);

        LoginCheck::create([
            'UserID' => 900001,
            'UserStatusCode' => '99',
            'IsChangePassword' => false,
            'Salt' => $salt,
            'LastLoginTimeStamp' => null,
            'LastLoginLocationJSON' => null,
            'LastLoginAttemptCounter' => 0,
        ]);
    }

    private function loginAndGetAccessToken(): string
    {
        $response = $this->postJson('/api/auth/login', [
            'Email' => $this->adminEmail,
            'Password' => $this->adminPassword,
        ]);

        $response->assertStatus(200);
        $token = data_get($response->json(), 'data.access_token');
        $this->assertNotEmpty($token, 'Access token from login is empty.');

        return (string) $token;
    }

    private function createBasicUser(int $userId, string $email, string $name, bool $isAdministrator = false): User
    {
        $timestamp = Carbon::now()->timestamp;
        $salt = Str::uuid()->toString();

        $user = User::create([
            'UserID' => $userId,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 900001,
            'OperationCode' => 'I',
            'IsAdministrator' => $isAdministrator,
            'FullName' => $name,
            'Email' => $email,
            'Password' => Hash::make('123456' . $salt),
            'UTCCode' => '+07:00',
        ]);

        LoginCheck::create([
            'UserID' => $userId,
            'UserStatusCode' => '99',
            'IsChangePassword' => false,
            'Salt' => $salt,
            'LastLoginTimeStamp' => null,
            'LastLoginLocationJSON' => null,
            'LastLoginAttemptCounter' => 0,
        ]);

        return $user;
    }

    private function createOrganization(int $organizationId, string $name): Organization
    {
        $timestamp = Carbon::now()->timestamp;

        return Organization::create([
            'OrganizationID' => $organizationId,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 900001,
            'OperationCode' => 'I',
            'ParentOrganizationID' => null,
            'LevelNo' => 1,
            'IsChild' => false,
            'OrganizationName' => $name,
            'IsActive' => true,
            'IsDelete' => false,
        ]);
    }

    private function createPositionLevel(int $positionLevelId, string $name): PositionLevel
    {
        $timestamp = Carbon::now()->timestamp;

        return PositionLevel::create([
            'PositionLevelID' => $positionLevelId,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 900001,
            'OperationCode' => 'I',
            'PositionLevelName' => $name,
        ]);
    }

    private function createPosition(
        int $positionId,
        int $organizationId,
        int $positionLevelId,
        string $name
    ): Position {
        $timestamp = Carbon::now()->timestamp;

        return Position::create([
            'PositionID' => $positionId,
            'AtTimeStamp' => $timestamp,
            'ByUserID' => 900001,
            'OperationCode' => 'I',
            'OrganizationID' => $organizationId,
            'ParentPositionID' => $positionId,
            'LevelNo' => 1,
            'IsChild' => false,
            'PositionName' => $name,
            'PositionLevelID' => $positionLevelId,
            'RequirementQuantity' => 1,
            'IsActive' => true,
            'IsDelete' => false,
        ]);
    }

    private function mockMinioService(): void
    {
        $this->mock(MinioService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generatePresignedUploadUrl')
                ->andReturnUsing(function (string $moduleName, string $moduleNameId, string $filename) {
                    return [
                        'upload_url' => "https://mock-minio.local/{$moduleName}/{$moduleNameId}/{$filename}",
                        'file_info' => [
                            'id' => 'mock-id',
                            'path' => "{$moduleName}/{$moduleNameId}/{$filename}",
                            'filename' => $filename,
                            'original_filename' => $filename,
                        ],
                        'expires_in' => 3600,
                        'content_type' => 'application/pdf',
                    ];
                });

            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('generatePresignedViewUrl')->andReturn('https://mock-minio.local/view-url');
            $mock->shouldReceive('generatePresignedDownloadUrl')->andReturn('https://mock-minio.local/download-url');
        });
    }
}
