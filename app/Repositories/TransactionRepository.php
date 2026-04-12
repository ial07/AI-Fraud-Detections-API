<?php

namespace App\Repositories;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TransactionRepository
{
    /**
     * Create a new transaction record.
     */
    public function create(array $data): Transaction
    {
        return Transaction::create($data);
    }

    /**
     * Find a transaction by its primary key.
     */
    public function findById(int $id): ?Transaction
    {
        return Transaction::with('alert')->find($id);
    }

    /**
     * Find a transaction by its unique transaction_id.
     */
    public function findByTransactionId(string $transactionId): ?Transaction
    {
        return Transaction::with('alert')->where('transaction_id', $transactionId)->first();
    }

    /**
     * Get paginated transactions with optional filters.
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Transaction::with('alert')->orderBy('created_at', 'desc');

        if (!empty($filters['risk_level'])) {
            $query->where('risk_level', strtoupper($filters['risk_level']));
        }

        if (isset($filters['is_flagged'])) {
            $query->where('is_flagged', filter_var($filters['is_flagged'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['amount_min'])) {
            $query->where('amount', '>=', $filters['amount_min']);
        }

        if (!empty($filters['amount_max'])) {
            $query->where('amount', '<=', $filters['amount_max']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Update a transaction.
     */
    public function update(Transaction $transaction, array $data): Transaction
    {
        $transaction->update($data);
        return $transaction->fresh();
    }

    /**
     * Count transactions by a given user in the last N minutes.
     */
    public function countRecentByUser(string $userId, int $minutes = 60): int
    {
        return Transaction::where('user_id', $userId)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Get summary statistics for the dashboard.
     */
    public function getSummaryStats(): array
    {
        $total = Transaction::count();
        $flagged = Transaction::where('is_flagged', true)->count();
        $criticalAlerts = Transaction::where('risk_level', 'CRITICAL')->where('is_flagged', true)->count();
        $fraudRate = $total > 0 ? round(($flagged / $total) * 100, 2) : 0;

        return [
            'total_transactions' => $total,
            'flagged_transactions' => $flagged,
            'critical_alerts' => $criticalAlerts,
            'fraud_rate' => $fraudRate,
        ];
    }
}
