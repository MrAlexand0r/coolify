<?php

namespace App\Http\Controllers\Api;

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Service\StartService;
use App\Http\Controllers\Controller;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\Tag;
use Illuminate\Http\Request;
use Visus\Cuid2\Cuid2;

class DeployController extends Controller
{
    private function removeSensitiveData($deployment)
    {
        $token = auth()->user()->currentAccessToken();
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($deployment);
        }

        $deployment->makeHidden([
            'logs',
        ]);

        return serializeApiResponse($deployment);
    }

    public function deployments(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $servers = Server::whereTeamId($teamId)->get();
        $deployments_per_server = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->whereIn('server_id', $servers->pluck('id'))->get([
            'id',
            'application_id',
            'application_name',
            'deployment_url',
            'pull_request_id',
            'server_name',
            'server_id',
            'status',
        ])->sortBy('id')->toArray();

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($deployments_per_server),
        ]);
    }

    public function deployment_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['success' => false, 'message' => 'UUID is required.'], 400);
        }
        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->first();
        if (! $deployment) {
            return response()->json(['success' => false, 'message' => 'Deployment not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->removeSensitiveData($deployment),
        ]);
    }

    public function deploy(Request $request)
    {
        $teamId = getTeamIdFromToken();
        $uuids = $request->query->get('uuid');
        $tags = $request->query->get('tag');
        $force = $request->query->get('force') ?? false;

        if ($uuids && $tags) {
            return response()->json(['success' => false, 'message' => 'You can only use uuid or tag, not both.', 'docs' => 'https://coolify.io/docs/api-reference/deploy-webhook'], 400);
        }
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($tags) {
            return $this->by_tags($tags, $teamId, $force);
        } elseif ($uuids) {
            return $this->by_uuids($uuids, $teamId, $force);
        }

        return response()->json(['success' => false, 'message' => 'You must provide uuid or tag.', 'docs' => 'https://coolify.io/docs/api-reference/deploy-webhook'], 400);
    }

    private function by_uuids(string $uuid, int $teamId, bool $force = false)
    {
        $uuids = explode(',', $uuid);
        $uuids = collect(array_filter($uuids));

        if (count($uuids) === 0) {
            return response()->json(['success' => false, 'message' => 'No UUIDs provided.', 'docs' => 'https://coolify.io/docs/api-reference/deploy-webhook'], 400);
        }
        $deployments = collect();
        $payload = collect();
        foreach ($uuids as $uuid) {
            $resource = getResourceByUuid($uuid, $teamId);
            if ($resource) {
                ['message' => $return_message, 'deployment_uuid' => $deployment_uuid] = $this->deploy_resource($resource, $force);
                if ($deployment_uuid) {
                    $deployments->push(['success' => true, 'message' => $return_message, 'resource_uuid' => $uuid, 'deployment_uuid' => $deployment_uuid->toString()]);
                } else {
                    $deployments->push(['success' => true, 'message' => $return_message, 'resource_uuid' => $uuid]);
                }
            }
        }
        if ($deployments->count() > 0) {
            $payload->put('deployments', $deployments->toArray());

            return response()->json([
                'success' => true,
                'data' => serializeApiResponse($payload->toArray()),
            ]);
        }

        return response()->json(['success' => false, 'message' => 'No resources found.', 'docs' => 'https://coolify.io/docs/api-reference/deploy-webhook'], 404);
    }

    public function by_tags(string $tags, int $team_id, bool $force = false)
    {
        $tags = explode(',', $tags);
        $tags = collect(array_filter($tags));

        if (count($tags) === 0) {
            return response()->json(['success' => false, 'message' => 'No TAGs provided.', 'docs' => 'https://coolify.io/docs/api-reference/deploy-webhook'], 400);
        }
        $message = collect([]);
        $deployments = collect();
        $payload = collect();
        foreach ($tags as $tag) {
            $found_tag = Tag::where(['name' => $tag, 'team_id' => $team_id])->first();
            if (! $found_tag) {
                // $message->push("Tag {$tag} not found.");
                continue;
            }
            $applications = $found_tag->applications()->get();
            $services = $found_tag->services()->get();
            if ($applications->count() === 0 && $services->count() === 0) {
                $message->push("No resources found for tag {$tag}.");

                continue;
            }
            foreach ($applications as $resource) {
                ['message' => $return_message, 'deployment_uuid' => $deployment_uuid] = $this->deploy_resource($resource, $force);
                if ($deployment_uuid) {
                    $deployments->push(['resource_uuid' => $resource->uuid, 'deployment_uuid' => $deployment_uuid->toString()]);
                }
                $message = $message->merge($return_message);
            }
            foreach ($services as $resource) {
                ['message' => $return_message] = $this->deploy_resource($resource, $force);
                $message = $message->merge($return_message);
            }
        }
        if ($message->count() > 0) {
            $payload->put('message', $message->toArray());
            if ($deployments->count() > 0) {
                $payload->put('details', $deployments->toArray());
            }

            return response()->json([
                'success' => true,
                'data' => serializeApiResponse($payload->toArray()),
            ]);
        }

        return response()->json(['success' => false, 'message' => 'No resources found with this tag.', 'docs' => 'https://coolify.io/docs/api-reference/deploy-webhook'], 404);
    }

    public function deploy_resource($resource, bool $force = false): array
    {
        $message = null;
        $deployment_uuid = null;
        if (gettype($resource) !== 'object') {
            return ['success' => false, 'message' => "Resource ($resource) not found.", 'deployment_uuid' => $deployment_uuid];
        }
        $type = $resource?->getMorphClass();
        if ($type === 'App\Models\Application') {
            $deployment_uuid = new Cuid2(7);
            queue_application_deployment(
                application: $resource,
                deployment_uuid: $deployment_uuid,
                force_rebuild: $force,
            );
            $message = "Application {$resource->name} deployment queued.";
        } elseif ($type === 'App\Models\StandalonePostgresql') {
            StartPostgresql::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message = "Database {$resource->name} started.";
        } elseif ($type === 'App\Models\StandaloneRedis') {
            StartRedis::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message = "Database {$resource->name} started.";
        } elseif ($type === 'App\Models\StandaloneKeydb') {
            StartKeydb::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message = "Database {$resource->name} started.";
        } elseif ($type === 'App\Models\StandaloneDragonfly') {
            StartDragonfly::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message = "Database {$resource->name} started.";
        } elseif ($type === 'App\Models\StandaloneClickhouse') {
            StartClickhouse::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message = "Database {$resource->name} started.";
        } elseif ($type === 'App\Models\StandaloneMongodb') {
            StartMongodb::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message = "Database {$resource->name} started.";
        } elseif ($type === 'App\Models\StandaloneMysql') {
            StartMysql::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message = "Database {$resource->name} started.";
        } elseif ($type === 'App\Models\StandaloneMariadb') {
            StartMariadb::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message = "Database {$resource->name} started.";
        } elseif ($type === 'App\Models\Service') {
            StartService::run($resource);
            $message = "Service {$resource->name} started. It could take a while, be patient.";
        }

        return ['success' => true, 'message' => $message, 'deployment_uuid' => $deployment_uuid];
    }
}
