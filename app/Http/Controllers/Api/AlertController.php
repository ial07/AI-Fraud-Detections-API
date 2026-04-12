<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AlertRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Alerts", description="Fraud alert management")
 */
class AlertController extends Controller
{
    public function __construct(
        private AlertRepository $alertRepository,
    ) {}

    /**
     * @OA\Get(
     *   path="/api/alerts",
     *   tags={"Alerts"},
     *   summary="List fraud alerts",
     *   @OA\Parameter(name="alert_status", in="query", required=false, @OA\Schema(type="string", enum={"NEW","INVESTIGATING","RESOLVED"})),
     *   @OA\Parameter(name="risk_level", in="query", required=false, @OA\Schema(type="string", enum={"HIGH","CRITICAL"})),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *   @OA\Response(response=200, description="List of alerts")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['alert_status', 'risk_level']);
        $perPage = (int) $request->get('per_page', 15);

        $alerts = $this->alertRepository->paginate($filters, $perPage);

        return response()->json([
            'status' => 'success',
            'data' => $alerts->items(),
            'pagination' => [
                'current_page' => $alerts->currentPage(),
                'per_page' => $alerts->perPage(),
                'total' => $alerts->total(),
                'last_page' => $alerts->lastPage(),
            ],
        ]);
    }

    /**
     * @OA\Patch(
     *   path="/api/alerts/{id}",
     *   tags={"Alerts"},
     *   summary="Update alert status",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(
     *     @OA\JsonContent(
     *       @OA\Property(property="alert_status", type="string", enum={"INVESTIGATING","RESOLVED"}),
     *       @OA\Property(property="resolution", type="string", enum={"CONFIRMED_FRAUD","FALSE_POSITIVE"})
     *     )
     *   ),
     *   @OA\Response(response=200, description="Alert updated"),
     *   @OA\Response(response=404, description="Alert not found")
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $alert = $this->alertRepository->findById($id);

        if (!$alert) {
            return response()->json([
                'status' => 'error',
                'message' => 'Alert not found',
            ], 404);
        }

        $validated = $request->validate([
            'alert_status' => 'sometimes|string|in:INVESTIGATING,RESOLVED',
            'resolution' => 'sometimes|nullable|string|in:CONFIRMED_FRAUD,FALSE_POSITIVE',
        ]);

        $updatedAlert = $this->alertRepository->update($alert, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Alert updated successfully',
            'data' => $updatedAlert->load('transaction'),
        ]);
    }
}
