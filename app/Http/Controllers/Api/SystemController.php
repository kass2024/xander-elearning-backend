<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DatabaseSchemaService;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function health(DatabaseSchemaService $schema)
    {
        $status = $schema->status();

        $http = ($status['database_connected'] && $status['schema_ready']) ? 200 : 503;

        return response()->json([
            'status' => $status['schema_ready'] ? 'ok' : 'degraded',
            'message' => $status['schema_ready']
                ? 'Database schema is ready.'
                : 'Database connected but schema is incomplete. Run migrations.',
            ...$status,
        ], $http);
    }

    public function migrate(Request $request, DatabaseSchemaService $schema)
    {
        $token = config('app.migrate_token');
        if ($token) {
            $provided = $request->header('X-Migrate-Token') ?? $request->query('token');
            if (!hash_equals((string) $token, (string) $provided)) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        if (!$schema->databaseConnected()) {
            return response()->json([
                'message' => 'Database is not reachable.',
            ], 503);
        }

        $result = $schema->runMigrations();
        $status = $schema->status();

        return response()->json([
            'message' => $result['pending_after'] === 0
                ? 'Migrations complete. Schema is up to date.'
                : 'Migrations ran but some items may still be pending.',
            'migration' => $result,
            'schema_ready' => $status['schema_ready'],
            'schema' => $status['schema'],
        ], $status['schema_ready'] ? 200 : 207);
    }
}
