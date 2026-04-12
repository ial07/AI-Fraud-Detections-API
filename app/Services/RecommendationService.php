<?php

namespace App\Services;

class RecommendationService
{
    /**
     * Generate a recommended action based on the risk score.
     */
    public function getRecommendation(float $riskScore): array
    {
        return match (true) {
            $riskScore >= 86 => [
                'action' => 'BLOCK',
                'reason' => 'Critical risk level — transaction should be blocked immediately pending identity verification.',
                'urgency' => 'IMMEDIATE',
            ],
            $riskScore >= 61 => [
                'action' => 'REQUIRE_VERIFICATION',
                'reason' => 'High risk level — additional verification recommended before processing.',
                'urgency' => 'HIGH',
            ],
            $riskScore >= 31 => [
                'action' => 'APPROVE',
                'reason' => 'Moderate risk — within acceptable range, monitor for follow-up activity.',
                'urgency' => 'LOW',
            ],
            default => [
                'action' => 'APPROVE',
                'reason' => 'Low risk — transaction consistent with user history.',
                'urgency' => 'NONE',
            ],
        };
    }
}
