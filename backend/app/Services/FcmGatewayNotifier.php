<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FcmGatewayNotifier
{
    public function notifyProject(int $projectId, string $messageId): void
    {
        $account = $this->account();
        if ($account === null) {
            return;
        }

        $tokens = Device::query()
            ->where('project_id', $projectId)
            ->whereNull('revoked_at')
            ->whereNotNull('fcm_token')
            ->pluck('fcm_token');

        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                continue;
            }
            $this->send($account, $token, $messageId);
        }
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $account
     */
    private function send(array $account, string $token, string $messageId): void
    {
        try {
            $accessToken = $this->accessToken($account);
            if ($accessToken === null) {
                return;
            }

            Http::withToken($accessToken)
                ->timeout(8)
                ->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'data' => ['message_id' => $messageId],
                        'android' => ['priority' => 'HIGH'],
                    ],
                ])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('FCM wake was not delivered.', ['message_id' => $messageId]);
            report($exception);
        }
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $account
     */
    private function accessToken(array $account): ?string
    {
        $cached = Cache::get('fcm.access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $unsigned = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            .'.'.$this->base64Url((string) json_encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

        $signature = '';
        $signed = openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
        if ($signed !== true) {
            return null;
        }

        $response = Http::asForm()->timeout(8)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$this->base64Url($signature),
        ]);
        if (! $response->successful()) {
            return null;
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        Cache::put('fcm.access_token', $token, now()->addMinutes(50));

        return $token;
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string}|null
     */
    private function account(): ?array
    {
        $path = config('services.firebase.credentials');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        $projectId = $decoded['project_id'] ?? null;
        $email = $decoded['client_email'] ?? null;
        $privateKey = $decoded['private_key'] ?? null;
        if (! is_string($projectId) || ! is_string($email) || ! is_string($privateKey)) {
            return null;
        }

        return ['project_id' => $projectId, 'client_email' => $email, 'private_key' => $privateKey];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
