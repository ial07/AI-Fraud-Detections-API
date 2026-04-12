<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\UserProfile;

class ExplainabilityService
{
    /**
     * Generate human-readable explanations based on risk factors.
     * This is the rule-based fallback when Azure OpenAI is not available.
     */
    public function generateExplanations(array $riskFactors, Transaction $transaction, UserProfile $profile): array
    {
        $explanations = [];

        foreach ($riskFactors as $key => $factor) {
            $explanations[] = $factor['description'];
        }

        // If no specific factors triggered, provide a default
        if (empty($explanations)) {
            $explanations[] = 'Transaction appears within normal parameters.';
        }

        return $explanations;
    }

    /**
     * Generate a natural language summary from all explanations.
     */
    public function generateSummary(array $explanations, float $riskScore, string $riskLevel): string
    {
        if ($riskLevel === 'LOW') {
            return 'This transaction appears normal. No suspicious indicators detected.';
        }

        $summary = sprintf(
            'This transaction has been flagged as %s risk (score: %.1f/100). ',
            $riskLevel,
            $riskScore
        );

        $summary .= 'Key concerns: ' . implode('; ', array_slice($explanations, 0, 3)) . '.';

        return $summary;
    }
}
