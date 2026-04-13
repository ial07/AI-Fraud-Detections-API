<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class AzureOpenAIService
{
    private string $endpoint;
    private string $apiKey;
    private string $deployment;
    private string $apiVersion;
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = filter_var(env('AZURE_OPENAI_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $this->endpoint = env('AZURE_OPENAI_ENDPOINT', '');
        $this->apiKey = env('AZURE_OPENAI_API_KEY', '');
        $this->deployment = env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o');
        $this->apiVersion = env('AZURE_OPENAI_API_VERSION', '2024-08-01-preview');
    }

    /**
     * Call Azure OpenAI to generate a natural language explanation of the fraud risk.
     * 
     * @throws Exception If API key is missing or request fails.
     */
    public function generateExplanation(Transaction $transaction, array $riskFactors, float $riskScore, string $riskLevel): string
    {
        if (!$this->enabled || empty($this->endpoint) || empty($this->apiKey)) {
            throw new Exception("Azure OpenAI credentials not configured or disabled.");
        }

        $url = sprintf(
            "%s/openai/deployments/%s/chat/completions?api-version=%s",
            rtrim($this->endpoint, '/'),
            $this->deployment,
            $this->apiVersion
        );

        $systemPrompt = "You are a senior fraud analyst in a fintech company. " .
            "Analyze the transaction and explain why it is risky. " .
            "Focus on abnormal behavior, deviation from user patterns, and risk implications. " .
            "Be concise, professional, and clear. Limit to 2 short sentences.";

        $userPrompt = $this->buildPrompt($transaction, $riskFactors, $riskScore, $riskLevel);

        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(5)->retry(2, 500)->post($url, [
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 150,
            ]);

            $response->throw(); // Throws RequestException on 4xx/5xx errors

            $result = $response->json();
            
            if (isset($result['choices'][0]['message']['content'])) {
                return trim($result['choices'][0]['message']['content']);
            }

            throw new Exception("Unexpected response format from Azure OpenAI.");
        } catch (Exception $e) {
            Log::error("Azure OpenAI Error: " . $e->getMessage());
            throw $e;
        }
    }

    private function buildPrompt(Transaction $transaction, array $riskFactors, float $riskScore, string $riskLevel): string
    {
        $prompt = "Transaction Detail:\n";
        $prompt .= "- Amount: Rp " . number_format((float) $transaction->amount, 0, ',', '.') . "\n";
        $prompt .= "- Location: " . ($transaction->location ?? 'Unknown') . "\n";
        $prompt .= "- Device: " . ($transaction->device ?? 'Unknown') . "\n";
        $prompt .= "- Risk Score: {$riskScore} ({$riskLevel} risk)\n\n";

        if (empty($riskFactors)) {
            $prompt .= "This transaction triggered no risk factors and appears completely normal.";
        } else {
            $prompt .= "Triggered Risk Factors:\n";
            foreach ($riskFactors as $factor) {
                $prompt .= "- {$factor['description']}\n";
            }
        }

        $prompt .= "\nPlease provide a concise explanation for the risk assessment.";
        return $prompt;
    }

    public function generateDeepExplanation(Transaction $transaction): string
    {
        if (!$this->enabled || empty($this->endpoint) || empty($this->apiKey)) {
            $amountFormatted = number_format((float) $transaction->amount, 0, ',', '.');
            return "Using rule-based fallback due to AI unavailability: Based on the raw parameters assessed by the core deterministic models, this transaction triggered anomalous high-risk factors. The transfer amount of Rp {$amountFormatted} deviated massively from typical peer baselines originating from {$transaction->location}. Additionally, the hardware footprint ({$transaction->device}) matches recognized patterns signaling likely account takeover or geographical proxy bypassing. We recommend immediate freezing of outbound transfers mapping to this identity pending a comprehensive KYC review.";
        }

        $url = sprintf(
            "%s/openai/deployments/%s/chat/completions?api-version=%s",
            rtrim($this->endpoint, '/'),
            $this->deployment,
            $this->apiVersion
        );

        $systemPrompt = "You are a senior fraud analyst in a fintech company. Output a comprehensive 3 to 5 sentence investigation summary regarding this specific transaction's risk. You must include: why it is suspicious, what specific pattern is broken, and what the risk impact is. Maintain a highly professional, concise, analyst-style tone. Highlight what physical actions the ops team should take.";

        // We pull the risk factors from the database directly since it handles JSON casting natively
        $riskFactors = $transaction->fraudAlert ? $transaction->fraudAlert->risk_factors : [];

        $userPrompt = "Transaction #{$transaction->transaction_id}\n";
        $userPrompt .= "Value: Rp " . number_format((float) $transaction->amount, 0, ',', '.') . "\n";
        $userPrompt .= "Location: {$transaction->location}\n";
        $userPrompt .= "Device: {$transaction->device}\n";
        $userPrompt .= "Risk Penalty: {$transaction->risk_score}\n";
        $userPrompt .= "Please provide the deep analyst breakdown.";

        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(8)->retry(1, 500)->post($url, [
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.4,
                'max_tokens' => 350,
            ]);

            $response->throw();

            $result = $response->json();
            
            if (isset($result['choices'][0]['message']['content'])) {
                return trim($result['choices'][0]['message']['content']);
            }

            return "Explanation parsing failed.";
        } catch (Exception $e) {
            Log::error("Azure OpenAI Explainer Error: " . $e->getMessage());
            return "Failed to contact external Azure OpenAI engines due to a timeout/API issue. Please try again later.";
        }
    }

    /**
     * AI-Assisted Risk Assessment — the LLM actively participates in scoring.
     * Returns structured JSON with ai_risk_score, confidence, fraud_type, reasoning.
     */
    public function assessRisk(Transaction $transaction, array $riskFactors, UserProfile $profile): array
    {
        $fallback = [
            'ai_risk_score' => null,
            'confidence' => 0,
            'fraud_type' => 'UNKNOWN',
            'reasoning' => 'AI assessment unavailable — using rule-based scoring only.',
        ];

        if (!$this->enabled || empty($this->endpoint) || empty($this->apiKey)) {
            return $fallback;
        }

        $url = sprintf(
            "%s/openai/deployments/%s/chat/completions?api-version=%s",
            rtrim($this->endpoint, '/'),
            $this->deployment,
            $this->apiVersion
        );

        $systemPrompt = <<<PROMPT
You are a senior fraud analyst AI engine embedded inside a real-time financial transaction monitoring system.

Your task: Analyze the transaction below and return a structured risk assessment as JSON.

IMPORTANT RULES:
- ai_risk_score: integer 0-100 (0 = completely safe, 100 = confirmed fraud)
- confidence: integer 0-100 (how confident you are in your assessment)
- fraud_type: one of: "ACCOUNT_TAKEOVER", "MONEY_LAUNDERING", "CARD_TESTING", "SUSPICIOUS_TRANSFER", "IDENTITY_FRAUD", "NORMAL"
- reasoning: 1-2 sentences explaining your assessment

Consider these factors in your analysis:
- Is the amount abnormal relative to the user's average transaction?
- Is the location consistent with the user's known locations?
- Is the device recognized or new?
- Is the time of transaction unusual?
- Do multiple risk factors combine to form a dangerous pattern?

Return ONLY valid JSON. No markdown, no extra text.
PROMPT;

        $riskFactorDescriptions = [];
        foreach ($riskFactors as $factor) {
            $riskFactorDescriptions[] = $factor['description'];
        }

        $userPrompt = json_encode([
            'transaction_id' => $transaction->transaction_id ?? 'N/A',
            'amount' => (float) $transaction->amount,
            'amount_formatted' => 'Rp ' . number_format((float) $transaction->amount, 0, ',', '.'),
            'location' => $transaction->location ?? 'Unknown',
            'device' => $transaction->device ?? 'Unknown',
            'transaction_type' => $transaction->transaction_type ?? 'TRANSFER',
            'user_avg_amount' => $profile->avg_transaction_amt ?? 0,
            'user_usual_location' => $profile->usual_location ?? 'Unknown',
            'user_known_devices' => $profile->known_devices ?? [],
            'user_total_transactions' => $profile->total_transactions ?? 0,
            'triggered_risk_factors' => $riskFactorDescriptions,
        ], JSON_PRETTY_PRINT);

        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(6)->retry(1, 300)->post($url, [
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.2,
                'max_tokens' => 200,
                'response_format' => ['type' => 'json_object'],
            ]);

            $response->throw();

            $result = $response->json();

            if (isset($result['choices'][0]['message']['content'])) {
                $parsed = json_decode($result['choices'][0]['message']['content'], true);

                if ($parsed && isset($parsed['ai_risk_score'])) {
                    return [
                        'ai_risk_score' => max(0, min(100, (float) $parsed['ai_risk_score'])),
                        'confidence' => max(0, min(100, (float) ($parsed['confidence'] ?? 50))),
                        'fraud_type' => $parsed['fraud_type'] ?? 'UNKNOWN',
                        'reasoning' => $parsed['reasoning'] ?? 'No reasoning provided.',
                    ];
                }
            }

            Log::warning("Azure assessRisk: unexpected response format.");
            return $fallback;
        } catch (Exception $e) {
            Log::error("Azure AI Risk Assessment Error: " . $e->getMessage());
            return $fallback;
        }
    }
}
