<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mqtt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mosq\AclRequest;
use App\Http\Requests\Mosq\AuthRequest;
use App\Http\Requests\Mosq\SuperuserRequest;
use App\Models\MqttUser;
use App\Services\MqttAclService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class MosquittoAuthController extends Controller
{
    public function __construct(private readonly MqttAclService $acl) {}

    /**
     * HTTP auth: validate user credentials.
     */
    public function auth(AuthRequest $request): JsonResponse
    {
        $username = (string) $request->input('username');
        $password = (string) $request->input('password', '');

        $provisioningUsername = config('mqtt-client.system.provisioning_username');
        $provisioningPassword = config('mqtt-client.system.provisioning_password');

        $backendUsername = config('mqtt-client.system.backend_username');
        $backendPassword = config('mqtt-client.system.backend_password');

        if ($username === $provisioningUsername && hash_equals((string) $provisioningPassword, $password)) {
            return response()->json(['allow' => true, 'ok' => true]);
        }

        if ($username === $backendUsername && hash_equals((string) $backendPassword, $password)) {
            return response()->json(['allow' => true, 'ok' => true]);
        }

        $user = MqttUser::where('username', $username)->where('enabled', true)->first();
        if ($user !== null && Hash::check($password, $user->password_hash)) {
            return response()->json(['allow' => true, 'ok' => true]);
        }

        return response()->json(['allow' => false, 'ok' => false]);
    }

    /**
     * HTTP superuser check.
     */
    public function superuser(SuperuserRequest $request): JsonResponse
    {
        $username = (string) $request->input('username');
        $backendUsername = config('mqtt-client.system.backend_username');
        // TODO: checken ob es wirklich reicht nur den username zu überprüfen

        return response()->json(['allow' => $username === $backendUsername, 'ok' => $username === $backendUsername]);
    }

    /**
     * HTTP ACL check: authorize publish/subscribe per topic.
     */
    public function acl(AclRequest $request): JsonResponse
    {
        $username = (string) $request->input('username');
        $clientId = (string) $request->input('clientid');
        $topic = (string) $request->input('topic');
        $acc = (int) $request->input('acc'); // 1=subscribe, 2=publish

        $provisioningUsername = config('mqtt-client.system.provisioning_username');
        $backendUsername = config('mqtt-client.system.backend_username');

        // Provisioning user
        if ($username === $provisioningUsername) {
            if ($acc === 2) { // publish
                return response()->json([
                    'allow' => $this->acl->topicMatches('locker/register/+', $topic, $username, $clientId),
                    'ok' => $this->acl->topicMatches('locker/register/+', $topic, $username, $clientId),
                ]);
            }
            if ($acc === 1) { // subscribe
                return response()->json([
                    'allow' => $this->acl->topicMatches('locker/provisioning/reply/%c', $topic, $username, $clientId),
                    'ok' => $this->acl->topicMatches('locker/provisioning/reply/%c', $topic, $username, $clientId),
                ]);
            }

            return response()->json(['allow' => false]);
        }

        // Backend user
        if ($username === $backendUsername) {
            $allow = $this->acl->topicMatches('locker/#', $topic, $username, $clientId)
                || $this->acl->topicMatches('server/status', $topic, $username, $clientId);

            return response()->json(['allow' => $allow, 'ok' => $allow]);
        }

        // Device users
        $user = MqttUser::where('username', $username)->where('enabled', true)->first();
        if ($user !== null) {
            if ($acc === 2) { // publish
                $allow = $this->acl->topicMatches('locker/%u/state', $topic, $username, $clientId)
                    || $this->acl->topicMatches('locker/%u/status', $topic, $username, $clientId);

                return response()->json(['allow' => $allow, 'ok' => $allow]);
            }
            if ($acc === 1) { // subscribe
                $allow = $this->acl->topicMatches('locker/%u/command', $topic, $username, $clientId);

                return response()->json(['allow' => $allow, 'ok' => $allow]);
            }
        }

        return response()->json(['allow' => false, 'ok' => false]);
    }
}
