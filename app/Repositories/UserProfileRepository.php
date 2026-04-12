<?php

namespace App\Repositories;

use App\Models\UserProfile;

class UserProfileRepository
{
    /**
     * Find or create a user profile.
     */
    public function findOrCreate(string $userId): UserProfile
    {
        return UserProfile::firstOrCreate(
            ['user_id' => $userId],
            [
                'avg_transaction_amt' => 0,
                'max_transaction_amt' => 0,
                'total_transactions' => 0,
                'known_devices' => [],
            ]
        );
    }

    /**
     * Update the profile with a new transaction's data.
     */
    public function updateWithTransaction(UserProfile $profile, float $amount, ?string $location, ?string $device): UserProfile
    {
        $totalTxns = $profile->total_transactions + 1;

        // Recalculate rolling average
        $newAvg = (($profile->avg_transaction_amt * $profile->total_transactions) + $amount) / $totalTxns;

        // Update max
        $newMax = max($profile->max_transaction_amt, $amount);

        // Update known devices
        $knownDevices = $profile->known_devices ?? [];
        if ($device && !in_array($device, $knownDevices)) {
            $knownDevices[] = $device;
        }

        // Update usual location (set to first location, or most recent for simplicity)
        $usualLocation = $profile->usual_location ?? $location;

        $profile->update([
            'avg_transaction_amt' => round($newAvg, 2),
            'max_transaction_amt' => round($newMax, 2),
            'total_transactions' => $totalTxns,
            'usual_location' => $usualLocation,
            'known_devices' => $knownDevices,
            'last_active' => now(),
        ]);

        return $profile->fresh();
    }
}
