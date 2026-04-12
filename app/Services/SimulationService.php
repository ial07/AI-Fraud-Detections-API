<?php

namespace App\Services;

use Illuminate\Support\Str;

class SimulationService
{
    private array $users = ['USR-001', 'USR-002', 'USR-003', 'USR-004', 'USR-005'];
    
    private array $locations = [
        'normal' => ['Jakarta, Indonesia', 'Bandung, Indonesia', 'Surabaya, Indonesia', 'Yogyakarta, Indonesia'],
        'fraud' => ['Lagos, Nigeria', 'Moscow, Russia', 'Phnom Penh, Cambodia', 'Unknown Proxy']
    ];

    private array $devices = [
        'normal' => ['iPhone-13', 'Samsung-A52', 'MacBook-Pro-M1', 'Windows-11-Chrome'],
        'fraud' => ['Unknown-Android', 'Tor-Browser', 'Linux-Firefox-Spoofed']
    ];

    public function __construct(
        private FraudDetectionService $fraudDetectionService,
        private AzureInsightsService $azureInsightsService
    ) {}

    /**
     * Run simulation creating $count transactions.
     */
    public function run(int $count = 10, int $fraudPercentage = 20): array
    {
        $results = [
            'total_generated' => $count,
            'fraud_simulated' => 0,
            'normal_simulated' => 0,
            'transactions' => []
        ];

        for ($i = 0; $i < $count; $i++) {
            // Phase 11 Guarantee: Always seed exactly one devastating fraud anomaly on index 0
            if ($i === 0) {
                $isFraudScenario = true;
                $transactionData = [
                    'user_id' => 'USR-ANOMALY-999',
                    'receiver_id' => 'CRYPTO-MIXER-EXCHANGE',
                    'amount' => 950000000, // 950 Million IDR
                    'currency' => 'IDR',
                    'transaction_type' => 'TRANSFER',
                    'location' => 'Moscow, Russia',
                    'device' => 'Tor-Browser-Spoofed',
                    'device_type' => 'DESKTOP',
                    'ip_address' => '192.168.1.1',
                    'timestamp' => now()->setTime(3, 14)->format('Y-m-d H:i:s'),
                    'is_simulated' => true,
                ];
            } else {
                $isFraudScenario = (rand(1, 100) <= $fraudPercentage);
                $transactionData = $isFraudScenario ? $this->generateFraudTransaction() : $this->generateNormalTransaction();
            }

            // Run through the detection pipeline
            $analysisResult = $this->fraudDetectionService->analyzeTransaction($transactionData);
            
            if ($isFraudScenario) {
                $results['fraud_simulated']++;
            } else {
                $results['normal_simulated']++;
            }

            $results['transactions'][] = [
                'transaction_id' => $analysisResult['transaction']->transaction_id,
                'is_simulated_fraud' => $isFraudScenario,
                'risk_score' => $analysisResult['risk_score'],
                'risk_level' => $analysisResult['risk_level'],
                'is_flagged' => $analysisResult['is_flagged'],
            ];
            
            // Add slight sleep to simulate real-world time gaps and avoid same-second timestamps
            usleep(100000); // 100ms
        }

        $this->azureInsightsService->trackEvent('simulation_run', [
            'total_generated' => $count,
            'fraud_simulated' => $results['fraud_simulated'],
            'normal_simulated' => $results['normal_simulated'],
        ], null, null, 'SIMULATION');

        return $results;
    }

    private function generateNormalTransaction(): array
    {
        return [
            'user_id' => $this->getRandom($this->users),
            'receiver_id' => 'MERCHANT-' . rand(100, 999),
            'amount' => rand(50000, 2500000), // Normal IDR amounts (50K - 2.5M)
            'currency' => 'IDR',
            'transaction_type' => 'PAYMENT',
            'location' => $this->getRandom($this->locations['normal']),
            'device' => $this->getRandom($this->devices['normal']),
            'device_type' => 'MOBILE',
            'ip_address' => '103.' . rand(10, 255) . '.' . rand(1, 255) . '.' . rand(1, 255),
            'timestamp' => now()->subMinutes(rand(1, 60))->format('Y-m-d H:i:s'), // Normal hours generally handled by rule
            'is_simulated' => true,
        ];
    }

    private function generateFraudTransaction(): array
    {
        return [
            'user_id' => $this->getRandom($this->users),
            'receiver_id' => 'CRYPTO-EXCHANGE-' . rand(10, 99),
            'amount' => rand(15000000, 100000000), // Massive IDR amounts (15M - 100M)
            'currency' => 'IDR',
            'transaction_type' => 'TRANSFER',
            'location' => $this->getRandom($this->locations['fraud']), // Unusual location
            'device' => $this->getRandom($this->devices['fraud']), // New device
            'device_type' => 'DESKTOP',
            'ip_address' => rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255),
            'timestamp' => now()->setTime(rand(1, 4), rand(0, 59))->format('Y-m-d H:i:s'), // Anomalous time (1 AM - 4 AM)
            'is_simulated' => true,
        ];
    }

    private function getRandom(array $array): mixed
    {
        return $array[array_rand($array)];
    }
}
