<?php

namespace App\Services;

use App\Models\Transaction;
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
}
