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
     * Full fraud detection pipeline:
     * 1. Create/retrieve user profile
     * 2. Calculate risk score
     * 3. Generate explanations
     * 4. Get recommendation
     * 5. Store transaction
     * 6. Create alert if flagged
     * 7. Update user profile
     */
    public function analyzeTransaction(array $data): array
    {
        // Step 1: Get or create user profile
        $profile = $this->userProfileRepository->findOrCreate($data['user_id']);

        // Step 2: Count recent transactions for velocity check
        $recentTxnCount = $this->transactionRepository->countRecentByUser($data['user_id']);

        // Step 3: Calculate risk score
        $riskResult = $this->riskScoringService->calculateRisk(
            amount: (float) $data['amount'],
            location: $data['location'] ?? null,
            device: $data['device'] ?? null,
            timestamp: $data['timestamp'] ?? null,
            profile: $profile,
            recentTxnCount: $recentTxnCount,
        );

        $riskScore = $riskResult['risk_score'];
        $riskLevel = $riskResult['risk_level'];
        $riskFactors = $riskResult['risk_factors'];

        // Step 4: Generate explanations
        $transaction = new Transaction($data); // Temp model for explanation context
        $explanations = $this->explainabilityService->generateExplanations($riskFactors, $transaction, $profile);
        
        $azureEnabled = env('AZURE_OPENAI_ENABLED', false);
        $explanationSource = 'RULE_BASED';

        if ($azureEnabled) {
            try {
                $aiExplanation = $this->azureOpenAIService->generateExplanation($transaction, $riskFactors, $riskScore, $riskLevel);
                $explanationSource = 'AZURE_OPENAI';
            } catch (\Exception $e) {
                // Fallback to rules if Azure fails
                $aiExplanation = $this->explainabilityService->generateSummary($explanations, $riskScore, $riskLevel);
            }
        } else {
            $aiExplanation = $this->explainabilityService->generateSummary($explanations, $riskScore, $riskLevel);
        }

        // Step 5: Get recommendation
        $threshold = (float) env('FRAUD_RISK_THRESHOLD', 70);
        $isFlagged = $riskScore >= $threshold;
        $recommendation = $this->recommendationService->getRecommendation($riskScore);

        // Step 6: Store transaction
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
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'is_flagged' => $isFlagged,
            'is_simulated' => $data['is_simulated'] ?? false,
            'status' => $isFlagged ? 'FLAGGED' : 'SCORED',
            'recommended_action' => $recommendation['action'],
            'ai_explanation' => $aiExplanation,
            'explanation_source' => $explanationSource,
        ];

        $savedTransaction = $this->transactionRepository->create($transactionData);

        // Step 7: Create alert if flagged
        $alert = null;
        if ($isFlagged) {
            $alert = $this->alertRepository->create([
                'transaction_id' => $savedTransaction->id,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'explanations' => $explanations,
                'risk_factors' => $riskFactors,
                'alert_status' => 'NEW',
            ]);
        }

        // Step 8: Update user profile with this transaction's data
        $this->userProfileRepository->updateWithTransaction(
            $profile,
            (float) $data['amount'],
            $data['location'] ?? null,
            $data['device'] ?? null,
        );

        // Step 9: Track Insights Event
        if ($isFlagged) {
            $this->azureInsightsService->trackEvent('fraud_detected', [
                'transaction_id' => $savedTransaction->id,
                'amount' => $data['amount'],
                'user_id' => $data['user_id']
            ], $riskScore, $riskLevel);
        } else {
            $this->azureInsightsService->trackEvent('normal_transaction', [
                'transaction_id' => $savedTransaction->id,
                'amount' => $data['amount'],
            ], $riskScore, $riskLevel);
        }

        // Return result
        return [
            'transaction' => $savedTransaction,
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'is_flagged' => $isFlagged,
            'recommended_action' => $recommendation['action'],
            'recommendation_reason' => $recommendation['reason'],
            'ai_explanation' => $aiExplanation,
            'explanation_source' => $explanationSource,
            'explanations' => $explanations,
            'risk_factors' => $riskFactors,
            'alert' => $alert,
        ];
    }
}
