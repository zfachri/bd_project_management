# How To Test

## Prerequisites
- Ensure dependencies are installed:
```bash
composer install
```
- Ensure PHP SQLite extension is installed (for test DB `sqlite :memory:`):
```bash
php -m | grep -i sqlite
```
If empty, install `pdo_sqlite` / `sqlite3` extension on your machine first.

## Run All Tests
```bash
php artisan test
```

## Run Only API Routes Coverage Test
This test checks all endpoints registered from `routes/api.php`:
- Public routes: must be reachable (not `404/405`, and not `5xx`)
- Protected routes (`jwt.auth`): must reject unauthenticated request with `401`
- Protected routes are also tested with valid authentication token (per-endpoint data provider)
- Includes functional module flows with dummy data:
  - User: create/get + duplicate email validation
  - Employee: create/get + duplicate validation
  - Project: create/list/detail + owner validation
  - Document Management: create/get/list + duplicate name validation (Minio mocked)

```bash
php artisan test --filter=ApiRoutesCoverageTest
```

## Run Specific Module Test Methods
```bash
php artisan test --filter=users_create_and_get_detail_functional
php artisan test --filter=employees_create_and_get_detail_functional
php artisan test --filter=projects_create_list_show_and_owner_validation
php artisan test --filter=documents_create_get_list_and_duplicate_name
```

## Run Feature Tests Only
```bash
php artisan test --testsuite=Feature
```

## Run Unit Tests Only
```bash
php artisan test --testsuite=Unit
```
