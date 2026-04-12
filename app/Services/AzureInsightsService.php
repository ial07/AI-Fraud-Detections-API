<?php

namespace App\Services;

use App\Models\InsightsEvent;
use Illuminate\Support\Facades\Log;

class AzureInsightsService
{
    /**
     * Track a custom event. Simulates Azure Application Insights CustomEvents API
     * while storing it locally in the insights_events table.
     */
    public function trackEvent(string $eventName, array $properties = [], ?float $riskScore = null, ?string $riskLevel = null, string $source = 'API'): void
    {
        try {
            InsightsEvent::create([
                'event_type' => $eventName,
                'event_data' => $properties,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'source' => $source,
            ]);

            // Note: If you were to fully integrate the actual Azure App Insights SDK (e.g. app-insights-php),
            // this is exactly where you would call:
            // $telemetryClient->trackEvent($eventName, $properties);
        } catch (\Exception $e) {
            Log::error("Insights Tracking Error: " . $e->getMessage());
        }
    }
}
