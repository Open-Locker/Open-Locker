<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mqtt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mosq\AclRequest;
use App\Http\Requests\Mosq\AuthRequest;
use App\Models\MqttUser;
use App\Services\MqttAclService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class MosquittoAuthController extends Controller
{
    private const ACC_READ = 1;

    private const ACC_WRITE = 2;

    private const ACC_READWRITE = 3;

    private const ACC_SUBSCRIBE = 4;

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

    // Superuser check is disabled in mosquitto.conf
    // public function superuser(...)

    /**
     * HTTP ACL check: authorize publish/subscribe per topic.
     */
    public function acl(AclRequest $request): JsonResponse
    {
        $username = (string) $request->input('username');
        $clientId = (string) $request->input('clientid');
        $topic = (string) $request->input('topic');
        $acc = (int) $request->input('acc'); // 1=subscribe, 2=publish

        Log::info("ACL Check: User={$username}, Topic={$topic}, Acc={$acc}");

        $provisioningUsername = config('mqtt-client.system.provisioning_username');
        $backendUsername = config('mqtt-client.system.backend_username');

        // Backend user - NOW handled in ACL since superuser is disabled
        if ($username === $backendUsername) {
            // Allow backend to do everything (like a superuser, but scoped via ACL)
            $allow = $this->acl->topicMatches('#', $topic, $username, $clientId); // Allow everything
            Log::info('ACL Backend: '.($allow ? 'Allowed' : 'Denied'));

            return response()->json([
                'allow' => $allow,
                'ok' => $allow,
            ], $allow ? 200 : 403);
        }

        // Provisioning user
        if ($username === $provisioningUsername) {
            $isWriteAcc = in_array($acc, [self::ACC_WRITE, self::ACC_READWRITE], true);
            $isReadAcc = in_array($acc, [self::ACC_READ, self::ACC_READWRITE, self::ACC_SUBSCRIBE], true);

            if ($isWriteAcc) { // publish (registration requests)
                $allowed = $this->acl->topicMatches('locker/register/+', $topic, $username, $clientId);
                Log::info('ACL Provisioning Publish: '.($allowed ? 'Allowed' : 'Denied'));

                return response()->json([
                    'allow' => $allowed,
                    'ok' => $allowed,
                ], $allowed ? 200 : 403);
            }

            // NOTE: Some backends use acc=1 for subscribe, others acc=4 or other values.
            // For provisioning user, anything that is not an explicit publish (acc===2)
            // is treated as a subscribe/other read operation.
            if ($isReadAcc && ! $isWriteAcc) { // subscribe / unsubscribe / other read-style access
                $allowed = $this->acl->topicMatches('locker/provisioning/reply/%c', $topic, $username, $clientId);
                Log::info('ACL Provisioning Subscribe: '.($allowed ? 'Allowed' : 'Denied'));

                return response()->json([
                    'allow' => $allowed,
                    'ok' => $allowed,
                ], $allowed ? 200 : 403);
            }

            Log::info('ACL Provisioning: Denied (Unknown acc or fallback)');

            return response()->json(['allow' => false, 'ok' => false], 403); // Explicitly deny
        }

        // Device users
        $user = MqttUser::where('username', $username)->where('enabled', true)->first();
        if ($user !== null) {
            $isWriteAcc = in_array($acc, [self::ACC_WRITE, self::ACC_READWRITE], true);
            $isReadAcc = in_array($acc, [self::ACC_READ, self::ACC_READWRITE, self::ACC_SUBSCRIBE], true);

            if ($isWriteAcc) { // publish (device -> backend)
                $allow = $this->acl->topicMatches('locker/%u/state', $topic, $username, $clientId)
                    || $this->acl->topicMatches('locker/%u/status', $topic, $username, $clientId);

                return response()->json([
                    'allow' => $allow,
                    'ok' => $allow,
                ], $allow ? 200 : 403);
            }

            // For device users, treat read-style acc values as subscribe-like (without double-counting readwrite).
            if ($isReadAcc && ! $isWriteAcc) { // subscribe / unsubscribe / other read-style access
                $allow = $this->acl->topicMatches('locker/%u/command', $topic, $username, $clientId);

                return response()->json([
                    'allow' => $allow,
                    'ok' => $allow,
                ], $allow ? 200 : 403);
            }
        }

        Log::info('ACL Default: Denied');

        return response()->json(['allow' => false, 'ok' => false], 403);
    }
}
