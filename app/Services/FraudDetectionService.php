<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\UserProfile;
use App\Repositories\AlertRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserProfileRepository;
use Illuminate\Support\Str;

class FraudDetectionService
{
    public function __construct(
        private RiskScoringService $riskScoringService,
        private ExplainabilityService $explainabilityService,
        private AzureOpenAIService $azureOpenAIService,
        private RecommendationService $recommendationService,
        private TransactionRepository $transactionRepository,
        private UserProfileRepository $userProfileRepository,
        private AlertRepository $alertRepository,
        private AzureInsightsService $azureInsightsService,
    ) {}

    /**
     * Full fraud detection pipeline (Hybrid AI):
     * 1. Create/retrieve user profile
     * 2. Calculate rule-based risk score
     * 3. Call Azure OpenAI for AI risk assessment (scoring + classification)
     * 4. Blend scores: final = (rule * 0.6) + (ai * 0.4)
     * 5. Generate explanations
     * 6. Get recommendation
     * 7. Store transaction
     * 8. Create alert if flagged
     * 9. Update user profile
     */
    public function analyzeTransaction(array $data): array
    {
        // Step 1: Get or create user profile
        $profile = $this->userProfileRepository->findOrCreate($data['user_id']);

        // Step 2: Count recent transactions for velocity check
        $recentTxnCount = $this->transactionRepository->countRecentByUser($data['user_id']);

        // Step 3: Calculate rule-based risk score
        $riskResult = $this->riskScoringService->calculateRisk(
            amount: (float) $data['amount'],
            location: $data['location'] ?? null,
            device: $data['device'] ?? null,
            timestamp: $data['timestamp'] ?? null,
            profile: $profile,
            recentTxnCount: $recentTxnCount,
        );

        $ruleScore = $riskResult['risk_score'];
        $riskFactors = $riskResult['risk_factors'];

        // Step 4: AI-Assisted Risk Assessment (NEW — LLM participates in scoring)
        $transaction = new Transaction($data);
        $aiAssessment = $this->azureOpenAIService->assessRisk($transaction, $riskFactors, $profile);

        $aiRiskScore = $aiAssessment['ai_risk_score'];
        $aiConfidence = $aiAssessment['confidence'];
        $fraudType = $aiAssessment['fraud_type'];
        $aiReasoning = $aiAssessment['reasoning'];

        // Step 5: Blend scores — AI influences the final decision
        if ($aiRiskScore !== null) {
            // Hybrid scoring: 60% rule-based + 40% AI
            $finalScore = min(100, round(($ruleScore * 0.6) + ($aiRiskScore * 0.4), 2));
            $explanationSource = 'AZURE_OPENAI';
        } else {
            // Fallback: pure rule-based
            $finalScore = $ruleScore;
            $explanationSource = 'RULE_BASED';
        }

        $riskLevel = $this->getRiskLevel($finalScore);

        // Step 6: Generate explanations
        $explanations = $this->explainabilityService->generateExplanations($riskFactors, $transaction, $profile);

        if ($explanationSource === 'AZURE_OPENAI') {
            $aiExplanation = $aiReasoning;
        } else {
            $aiExplanation = $this->explainabilityService->generateSummary($explanations, $finalScore, $riskLevel);
        }

        // Step 7: Get recommendation
        $threshold = (float) env('FRAUD_RISK_THRESHOLD', 70);
        $isFlagged = $finalScore >= $threshold;
        $recommendation = $this->recommendationService->getRecommendation($finalScore);

        // Step 8: Store transaction
        $transactionData = [
            'transaction_id' => $data['transaction_id'] ?? 'TXN-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
            'user_id' => $data['user_id'],
            'receiver_id' => $data['receiver_id'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'transaction_type' => $data['transaction_type'] ?? 'TRANSFER',
            'location' => $data['location'] ?? null,
            'device' => $data['device'] ?? null,
            'device_type' => $data['device_type'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'risk_score' => $finalScore,
            'ai_risk_score' => $aiRiskScore,
            'ai_confidence' => $aiConfidence,
            'fraud_type' => $fraudType,
            'risk_level' => $riskLevel,
            'is_flagged' => $isFlagged,
            'is_simulated' => $data['is_simulated'] ?? false,
            'status' => $isFlagged ? 'FLAGGED' : 'SCORED',
            'recommended_action' => $recommendation['action'],
            'ai_explanation' => $aiExplanation,
            'explanation_source' => $explanationSource,
        ];

        $savedTransaction = $this->transactionRepository->create($transactionData);

        // Step 9: Create alert if flagged
        $alert = null;
        if ($isFlagged) {
            $alert = $this->alertRepository->create([
                'transaction_id' => $savedTransaction->id,
                'risk_score' => $finalScore,
                'risk_level' => $riskLevel,
                'explanations' => $explanations,
                'risk_factors' => $riskFactors,
                'alert_status' => 'NEW',
            ]);
        }

        // Step 10: Update user profile with this transaction's data
        $this->userProfileRepository->updateWithTransaction(
            $profile,
            (float) $data['amount'],
            $data['location'] ?? null,
            $data['device'] ?? null,
        );

        // Step 11: Track Insights Event
        if ($isFlagged) {
            $this->azureInsightsService->trackEvent('fraud_detected', [
                'transaction_id' => $savedTransaction->id,
                'amount' => $data['amount'],
                'user_id' => $data['user_id']
            ], $finalScore, $riskLevel);
        } else {
            $this->azureInsightsService->trackEvent('normal_transaction', [
                'transaction_id' => $savedTransaction->id,
                'amount' => $data['amount'],
            ], $finalScore, $riskLevel);
        }

        // Return result
        return [
            'transaction' => $savedTransaction,
            'risk_score' => $finalScore,
            'rule_score' => $ruleScore,
            'ai_risk_score' => $aiRiskScore,
            'ai_confidence' => $aiConfidence,
            'fraud_type' => $fraudType,
            'risk_level' => $riskLevel,
            'is_flagged' => $isFlagged,
            'recommended_action' => $recommendation['action'],
            'recommendation_reason' => $recommendation['reason'],
            'ai_explanation' => $aiExplanation,
            'explanation_source' => $explanationSource,
            'explanations' => $explanations,
            'risk_factors' => $riskFactors,
            'scoring_details' => $riskResult['scoring_details'],
            'alert' => $alert,
        ];
    }

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
