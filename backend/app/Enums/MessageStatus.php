<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Assigned = 'assigned';
    case AcceptedByDevice = 'accepted_by_device';
    case Sending = 'sending';
    case SentToNetwork = 'sent_to_network';
    case SendFailed = 'send_failed';
    case DeliveryReported = 'delivery_reported';
    case DeliveryFailed = 'delivery_failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Queued => [self::Assigned, self::Cancelled],
            self::Assigned => [self::AcceptedByDevice, self::Unknown, self::Cancelled],
            // A carrier delivery callback is stronger evidence than the sent
            // callback and can arrive before it (or when Android's sent result
            // callback is inconsistent). Accept the final carrier result from
            // the device-accepted state so reports are not rejected and left
            // stuck on the backend.
            self::AcceptedByDevice => [self::Sending, self::SentToNetwork, self::SendFailed, self::DeliveryReported, self::DeliveryFailed, self::Unknown, self::Cancelled],
            self::Sending => [self::SentToNetwork, self::SendFailed, self::DeliveryReported, self::DeliveryFailed, self::Unknown],
            self::SentToNetwork => [self::DeliveryReported, self::DeliveryFailed, self::Unknown],
            self::Unknown => [self::SentToNetwork, self::SendFailed, self::DeliveryReported, self::DeliveryFailed],
            self::SendFailed, self::DeliveryReported, self::DeliveryFailed, self::Cancelled => [],
        }, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::SendFailed, self::DeliveryReported, self::DeliveryFailed, self::Cancelled], true);
    }
}
