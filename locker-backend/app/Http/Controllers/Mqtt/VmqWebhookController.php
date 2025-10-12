<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mqtt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vmq\AuthOnPublishRequest;
use App\Http\Requests\Vmq\AuthOnRegisterRequest;
use App\Http\Requests\Vmq\AuthOnSubscribeRequest;
use App\Models\MqttUser;
use App\Services\VmqAclService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class VmqWebhookController extends Controller
{
    public function __construct(private readonly VmqAclService $vmqAclService) {}

    /**
     * Build a successful webhook response, optionally with modifiers.
     */
    private function ok(array $modifiers = []): JsonResponse
    {
        Log::info('Ok', ['modifiers' => $modifiers]);

        return empty($modifiers)
            ? response()->json(['result' => 'ok'])
            : response()->json(['modifiers' => $modifiers, 'result' => 'ok']);
    }

    /**
     * Build a deny response following VerneMQ webhook schema.
     */
    private function notAllowed(string $error = 'not_allowed'): JsonResponse
    {
        Log::info('Not allowed', ['error' => $error]);

        return response()->json(['result' => 'error', 'error' => $error]);
    }

    /**
     * Handle VerneMQ auth_on_register.
     */
    public function authOnRegister(AuthOnRegisterRequest $request): JsonResponse
    {
        $username = (string) $request->input('username');
        $password = (string) $request->input('password', '');

        $provisioningUsername = config('mqtt-client.system.provisioning_username');
        $provisioningPassword = config('mqtt-client.system.provisioning_password');

        $backendUsername = config('mqtt-client.system.backend_username');
        $backendPassword = config('mqtt-client.system.backend_password');

        // Provisioning user: allow any client_id with strict credentials
        if ($username === $provisioningUsername &&
            hash_equals((string) $provisioningPassword, $password)) {
            return $this->ok();
        }

        // Backend user
        if ($username === $backendUsername &&
            hash_equals((string) $backendPassword, $password)) {
            return $this->ok();
        }

        // Device users: validate against mqtt_users
        $user = MqttUser::where('username', $username)->where('enabled', true)->first();
        if ($user !== null && \Illuminate\Support\Facades\Hash::check($password, $user->password_hash)) {
            return response()->json(['result' => 'ok']);
        }

        return $this->notAllowed('unauthorized');
    }

    /**
     * Handle VerneMQ auth_on_subscribe.
     */
    public function authOnSubscribe(AuthOnSubscribeRequest $request): JsonResponse
    {
        $username = (string) $request->input('username');
        $clientId = (string) $request->input('client_id');
        $topics = (array) $request->input('topics', []);

        $provisioningUsername = config('mqtt-client.system.provisioning_username');
        $backendUsername = config('mqtt-client.system.backend_username');

        // Provisioning user may only subscribe to locker/provisioning/reply/%c
        if ($username === $provisioningUsername) {
            foreach ($topics as $sub) {
                $topic = (string) ($sub['topic'] ?? '');
                if (! $this->vmqAclService->topicMatches('locker/provisioning/reply/%c', $topic, $username, $clientId)) {
                    return $this->notAllowed();
                }
            }

            return $this->ok();
        }

        // Backend user: full access to locker/# and server/status
        if ($username === $backendUsername) {
            foreach ($topics as $sub) {
                $topic = (string) ($sub['topic'] ?? '');
                if (! ($this->vmqAclService->topicMatches('locker/#', $topic, $username, $clientId)
                    || $this->vmqAclService->topicMatches('server/status', $topic, $username, $clientId))) {
                    return $this->notAllowed();
                }
            }

            return $this->ok();
        }

        // Device users: authorize each topic against hardcoded ACLs bound to username
        $user = MqttUser::where('username', $username)->where('enabled', true)->first();
        if ($user !== null) {
            foreach ($topics as $sub) {
                $topic = (string) ($sub['topic'] ?? '');
                $allowed = $this->vmqAclService->topicMatches('locker/%u/command', $topic, $username, $clientId);
                if (! $allowed) {
                    return $this->notAllowed();
                }
            }

            return $this->ok();
        }

        return $this->notAllowed();
    }

    /**
     * Handle VerneMQ auth_on_publish.
     */
    public function authOnPublish(AuthOnPublishRequest $request): JsonResponse
    {
        $username = (string) $request->input('username');
        $clientId = (string) $request->input('client_id');
        $topic = (string) $request->input('topic');

        $provisioningUsername = config('mqtt-client.system.provisioning_username');
        $backendUsername = config('mqtt-client.system.backend_username');

        // Provisioning user may only publish to locker/register/+
        if ($username === $provisioningUsername) {
            return $this->vmqAclService->topicMatches('locker/register/+', $topic, $username, $clientId)
                ? $this->ok()
                : $this->notAllowed();
        }

        // Backend user: publish to locker/# and server/status (Last Will)
        if ($username === $backendUsername) {
            return ($this->vmqAclService->topicMatches('locker/#', $topic, $username, $clientId)
                || $this->vmqAclService->topicMatches('server/status', $topic, $username, $clientId))
                ? $this->ok()
                : $this->notAllowed();
        }

        // Device users: authorize publish against hardcoded ACLs bound to username
        $user = MqttUser::where('username', $username)->where('enabled', true)->first();
        if ($user !== null) {
            if ($this->vmqAclService->topicMatches('locker/%u/state', $topic, $username, $clientId)
                || $this->vmqAclService->topicMatches('locker/%u/status', $topic, $username, $clientId)) {
                return $this->ok();
            }
        }

        return $this->notAllowed();
    }
}
