<?php

namespace App\Services;

use App\Models\UserProfile;

class RiskScoringService
{
    /**
     * Calculate risk score using hybrid approach:
     * - Rule-based scoring (30% weight)
     * - Statistical anomaly scoring (70% weight)
     *
     * Returns: ['risk_score', 'risk_level', 'risk_factors']
     */
    public function calculateRisk(
        float $amount,
        ?string $location,
        ?string $device,
        ?string $timestamp,
        UserProfile $profile,
        int $recentTxnCount = 0
    ): array {
        $riskFactors = [];

        // ── Rule-Based Scoring ──────────────────────────────────────────
        $ruleScore = 0;

        // Rule 1: Amount deviation
        $amountDeviation = $this->calculateAmountDeviation($amount, $profile);
        if ($amountDeviation > 3) {
            $penalty = 40;
            $ruleScore += $penalty;
            $riskFactors['amount_deviation'] = [
                'value' => round($amountDeviation, 1),
                'penalty' => $penalty,
                'contribution' => 0,
                'description' => sprintf(
                    'Amount (Rp %s) is %sx higher than 30-day average (Rp %s)',
                    number_format($amount, 0, ',', '.'),
                    round($amountDeviation, 1),
                    number_format($profile->avg_transaction_amt, 0, ',', '.')
                ),
            ];
        }

        // Rule 2: New device detection
        $isNewDevice = $this->isNewDevice($device, $profile);
        if ($isNewDevice) {
            $penalty = 30;
            $ruleScore += $penalty;
            $riskFactors['new_device'] = [
                'value' => true,
                'penalty' => $penalty,
                'contribution' => 0,
                'description' => 'New device detected — not seen in the last 90 days',
            ];
        }

        // Rule 3: Unusual location
        $isUnusualLocation = $this->isUnusualLocation($location, $profile);
        if ($isUnusualLocation) {
            $penalty = 30;
            $ruleScore += $penalty;
            $riskFactors['location_mismatch'] = [
                'value' => true,
                'penalty' => $penalty,
                'contribution' => 0,
                'description' => sprintf(
                    'Unusual location: usual activity from %s, this transaction from %s',
                    $profile->usual_location ?? 'Unknown',
                    $location ?? 'Unknown'
                ),
            ];
        }

        // Rule 4: Unusual time (00:00 - 05:00)
        $isUnusualTime = $this->isUnusualTime($timestamp);
        if ($isUnusualTime) {
            $penalty = 20;
            $ruleScore += $penalty;
            $hour = $timestamp ? date('H:i', strtotime($timestamp)) : 'unknown';
            $riskFactors['unusual_time'] = [
                'value' => true,
                'penalty' => $penalty,
                'contribution' => 0,
                'description' => sprintf('Transaction at unusual hour (%s)', $hour),
            ];
        }

        // Rule 5: High velocity
        if ($recentTxnCount > 3) {
            $penalty = 15;
            $ruleScore += $penalty;
            $riskFactors['high_velocity'] = [
                'value' => $recentTxnCount,
                'penalty' => $penalty,
                'contribution' => 0,
                'description' => sprintf(
                    'High transaction velocity: %d transactions in the last hour',
                    $recentTxnCount
                ),
            ];
        }

        $ruleScore = min(100, $ruleScore);

        // ── Statistical Anomaly Scoring ─────────────────────────────────
        $anomalyScore = $this->calculateAnomalyScore($amount, $profile, $isNewDevice, $isUnusualLocation, $isUnusualTime);

        // ── Combined Score ──────────────────────────────────────────────
        $finalScore = min(100, round(0.7 * $anomalyScore + 0.3 * $ruleScore, 2));

        // Calculate contribution percentages
        $totalPenalty = array_sum(array_column($riskFactors, 'penalty'));
        if ($totalPenalty > 0) {
            foreach ($riskFactors as $key => &$factor) {
                $factor['contribution'] = round(($factor['penalty'] / $totalPenalty) * 100, 0);
            }
        }

        return [
            'risk_score' => $finalScore,
            'risk_level' => $this->getRiskLevel($finalScore),
            'risk_factors' => $riskFactors,
            'scoring_details' => [
                'rule_score' => $ruleScore,
                'anomaly_score' => round($anomalyScore, 2),
                'rule_weight' => 0.3,
                'anomaly_weight' => 0.7,
            ],
        ];
    }

    /**
     * Calculate how many times the amount exceeds the user's average.
     */
    private function calculateAmountDeviation(float $amount, UserProfile $profile): float
    {
        if ($profile->avg_transaction_amt <= 0) {
            // New user — use sensible default
            return $amount > 1000 ? 4.0 : 1.0;
        }

        return $amount / $profile->avg_transaction_amt;
    }

    /**
     * Check if the device is not in the user's known devices.
     */
    private function isNewDevice(?string $device, UserProfile $profile): bool
    {
        if (!$device) {
            return false;
        }

        $knownDevices = $profile->known_devices ?? [];
        return !empty($knownDevices) && !in_array($device, $knownDevices);
    }

    /**
     * Check if location differs from user's usual location.
     */
    private function isUnusualLocation(?string $location, UserProfile $profile): bool
    {
        if (!$location || !$profile->usual_location) {
            return false;
        }

        return strtolower(trim($location)) !== strtolower(trim($profile->usual_location));
    }

    /**
     * Check if transaction occurred during unusual hours (00:00 - 05:00).
     */
    private function isUnusualTime(?string $timestamp): bool
    {
        if (!$timestamp) {
            $hour = (int) date('H');
        } else {
            $hour = (int) date('H', strtotime($timestamp));
        }

        return $hour >= 0 && $hour < 5;
    }

    /**
     * Calculate statistical anomaly score (Z-score inspired).
     */
    private function calculateAnomalyScore(
        float $amount,
        UserProfile $profile,
        bool $isNewDevice,
        bool $isUnusualLocation,
        bool $isUnusualTime
    ): float {
        $score = 0;

        // Amount anomaly (Z-score based)
        if ($profile->avg_transaction_amt > 0) {
            $deviation = $amount / $profile->avg_transaction_amt;
            if ($deviation > 5) {
                $score += 50;
            } elseif ($deviation > 3) {
                $score += 35;
            } elseif ($deviation > 2) {
                $score += 20;
            } elseif ($deviation > 1.5) {
                $score += 10;
            }
        } elseif ($amount > 2000) {
            // New user with high first transaction
            $score += 30;
        }

        // Device anomaly
        if ($isNewDevice) {
            $score += 25;
        }

        // Location anomaly
        if ($isUnusualLocation) {
            $score += 25;
        }

        // Time anomaly
        if ($isUnusualTime) {
            $score += 15;
        }

        return min(100, $score);
    }

    /**
     * Map score to risk level.
     */
    private function getRiskLevel(float $score): string
    {
        return match (true) {
            $score >= 86 => 'CRITICAL',
            $score >= 61 => 'HIGH',
            $score >= 31 => 'MEDIUM',
            default => 'LOW',
        };
    }
}
