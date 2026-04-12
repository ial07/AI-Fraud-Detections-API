<?php

namespace App\Repositories;

use App\Models\FraudAlert;
use App\Services\AzureInsightsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AlertRepository
{
    public function __construct(
        private AzureInsightsService $azureInsightsService
    ) {}
    /**
     * Create a new fraud alert.
     */
    public function create(array $data): FraudAlert
    {
        $alert = FraudAlert::create($data);

        $this->azureInsightsService->trackEvent('alert_created', [
            'alert_id' => $alert->id,
            'transaction_id' => $alert->transaction_id,
        ], $alert->risk_score, $alert->risk_level, 'SYSTEM');

        return $alert;
    }

    /**
     * Get paginated alerts with optional filters.
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = FraudAlert::with('transaction')->orderBy('created_at', 'desc');

        if (!empty($filters['alert_status'])) {
            $query->where('alert_status', strtoupper($filters['alert_status']));
        }

        if (!empty($filters['risk_level'])) {
            $query->where('risk_level', strtoupper($filters['risk_level']));
        }

        return $query->paginate($perPage);
    }

    /**
     * Find alert by ID.
     */
    public function findById(int $id): ?FraudAlert
    {
        return FraudAlert::with('transaction')->find($id);
    }

    /**
     * Update an alert's status and resolution.
     */
    public function update(FraudAlert $alert, array $data): FraudAlert
    {
        $alert->update($data);
        return $alert->fresh();
    }
}
