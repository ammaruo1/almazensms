<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Message;
use App\Services\MessageLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceMessageController extends Controller
{
    public function claim(Request $request, MessageLifecycle $lifecycle): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('gatewayDevice');
        $message = DB::transaction(function () use ($device, $lifecycle): ?Message {
            $message = Message::query()
                ->where('project_id', $device->project_id)
                ->where(function ($query) use ($device): void {
                    $query->where('status', MessageStatus::Queued->value)
                        ->orWhere(function ($assigned) use ($device): void {
                            $assigned->where('status', MessageStatus::Assigned->value)
                                ->where('device_id', $device->id);
                        });
                })
                ->orderBy('queued_at')
                ->lockForUpdate()
                ->first();
            if (! $message) {
                return null;
            }

            if ($message->status === MessageStatus::Queued) {
                $message->forceFill(['device_id' => $device->id])->save();
                $message = $lifecycle->transition($message, MessageStatus::Assigned, 'message.assigned', 'device', $device->id);
            }

            return $message;
        }, attempts: 3);

        if (! $message) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id' => $message->id,
            'to' => $message->destination,
            'body' => $message->body,
            'status' => $message->status->value,
            'created_at' => $message->queued_at?->toISOString(),
        ]]);
    }

    public function acknowledge(Request $request, string $messageId, MessageLifecycle $lifecycle): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('gatewayDevice');
        $message = Message::query()->where('project_id', $device->project_id)->where('device_id', $device->id)->findOrFail($messageId);
        $message = $lifecycle->transition($message, MessageStatus::AcceptedByDevice, 'device.accepted', 'device', $device->id);

        return response()->json(['data' => ['id' => $message->id, 'status' => $message->status->value]]);
    }

    public function report(Request $request, string $messageId, MessageLifecycle $lifecycle): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('gatewayDevice');
        $data = $request->validate([
            'status' => ['required', 'in:sending,sent_to_network,send_failed,delivery_reported,delivery_failed,unknown'],
            'detail_code' => ['nullable', 'string', 'max:48'],
        ]);
        $message = Message::query()->where('project_id', $device->project_id)->where('device_id', $device->id)->findOrFail($messageId);
        $status = MessageStatus::from($data['status']);
        $message = $lifecycle->transition($message, $status, 'device.status_reported', 'device', $device->id, array_filter(['detail_code' => $data['detail_code'] ?? null]));

        return response()->json(['data' => ['id' => $message->id, 'status' => $message->status->value]]);
    }
}
