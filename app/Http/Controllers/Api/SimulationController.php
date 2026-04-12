<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Simulation", description="Simulation engine for demo purposes")
 */
class SimulationController extends Controller
{
    public function __construct(
        private SimulationService $simulationService
    ) {}

    /**
     * @OA\Post(
     *   path="/api/simulation",
     *   tags={"Simulation"},
     *   summary="Run transaction simulation",
     *   description="Generates random realistic transactions including fraud scenarios.",
     *   @OA\RequestBody(
     *     @OA\JsonContent(
     *       @OA\Property(property="count", type="integer", example=15),
     *       @OA\Property(property="fraud_percentage", type="integer", example=20)
     *     )
     *   ),
     *   @OA\Response(response=200, description="Simulation completed successfully")
     * )
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'count' => 'integer|min:1|max:100',
            'fraud_percentage' => 'integer|min:0|max:100',
        ]);

        $count = $validated['count'] ?? 10;
        $fraudPercentage = $validated['fraud_percentage'] ?? 20;

        $results = $this->simulationService->run($count, $fraudPercentage);

        return response()->json([
            'status' => 'success',
            'message' => 'Simulation completed',
            'data' => $results
        ]);
    }
}
