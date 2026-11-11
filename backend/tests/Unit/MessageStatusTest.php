<?php

namespace Tests\Unit;

use App\Enums\MessageStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MessageStatusTest extends TestCase
{
    #[DataProvider('validTransitions')]
    public function test_valid_state_transitions_are_explicit(MessageStatus $from, MessageStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    public static function validTransitions(): array
    {
        return [
            'queue to assignment' => [MessageStatus::Queued, MessageStatus::Assigned],
            'assignment to durable receipt' => [MessageStatus::Assigned, MessageStatus::AcceptedByDevice],
            'in progress to network handoff' => [MessageStatus::Sending, MessageStatus::SentToNetwork],
            'unknown can resolve from late evidence' => [MessageStatus::Unknown, MessageStatus::SentToNetwork],
            'delivery evidence after network handoff' => [MessageStatus::SentToNetwork, MessageStatus::DeliveryReported],
        ];
    }

    public function test_unknown_is_not_terminal_and_failure_or_delivery_report_is_terminal(): void
    {
        $this->assertFalse(MessageStatus::Unknown->isTerminal());
        $this->assertTrue(MessageStatus::SendFailed->isTerminal());
        $this->assertTrue(MessageStatus::DeliveryReported->isTerminal());
        $this->assertTrue(MessageStatus::DeliveryFailed->isTerminal());
    }

    public function test_terminal_states_cannot_transition_automatically(): void
    {
        foreach ([MessageStatus::SendFailed, MessageStatus::DeliveryReported, MessageStatus::DeliveryFailed] as $terminal) {
            $this->assertFalse($terminal->canTransitionTo(MessageStatus::Queued));
        }
    }
}
