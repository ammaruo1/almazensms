<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OtpChallenge;
use App\Models\Project;
use App\Services\MessageIntake;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    public function store(Request $request, OtpService $otp): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('apiProject');
        $data = $request->validate([
            'to' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'purpose' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,32}$/'],
        ]);
        $result = $otp->request(
            $project,
            (int) $request->attributes->get('apiKeyId'),
            MessageIntake::idempotencyKey($request),
            $data['to'],
            $data['purpose'] ?? 'login',
        );

        return response()->json([
            'data' => $this->resource($result['challenge']),
            'duplicate' => $result['duplicate'],
        ], $result['duplicate'] ? 200 : 202);
    }

    public function cancel(Request $request, string $challengeId, OtpService $otp): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('apiProject');
        $result = $otp->cancel($project, $challengeId);

        return response()->json(['data' => $result['data']], $result['http_status']);
    }

    public function verify(Request $request, OtpService $otp): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('apiProject');
        $data = $request->validate([
            'to' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'code' => ['required', 'string', 'regex:/^[0-9]{1,12}$/'],
            'purpose' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,32}$/'],
        ]);
        $code = preg_replace('/\D+/', '', (string) $data['code']);
        abort_unless(strlen($code) === 6, 422, 'OTP code must be 6 digits.');
        $result = $otp->verify($project, $data['to'], $code, $data['purpose'] ?? 'login');

        return response()->json(['data' => $result], $result['verified'] ? 200 : 422);
    }

    /**
     * @return array{id: string, status: string, purpose: string, expires_at: string|null, message_id: string}
     */
    private function resource(OtpChallenge $challenge): array
    {
        return [
            'id' => $challenge->id,
            'status' => $challenge->status,
            'purpose' => $challenge->purpose,
            'expires_at' => $challenge->expires_at?->toISOString(),
            'message_id' => $challenge->message_id,
        ];
    }
}
