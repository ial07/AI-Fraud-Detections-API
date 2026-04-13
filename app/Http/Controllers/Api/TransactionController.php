<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\TransactionRepository;
use App\Services\FraudDetectionService;
use App\Services\AzureOpenAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Transactions", description="Transaction management and fraud detection")
 */
class TransactionController extends Controller
{
    public function __construct(
        private FraudDetectionService $fraudDetectionService,
        private TransactionRepository $transactionRepository,
        private AzureOpenAIService $azureOpenAIService,
    ) {}

    /**
     * @OA\Post(
     *   path="/api/transactions",
     *   tags={"Transactions"},
     *   summary="Create and analyze a transaction",
     *   description="Submit a new transaction for fraud detection analysis. Returns risk score, explanations, and recommended action.",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"user_id", "amount"},
     *       @OA\Property(property="user_id", type="string", example="USR-001"),
     *       @OA\Property(property="receiver_id", type="string", example="USR-042"),
     *       @OA\Property(property="amount", type="number", format="float", example=5000.00),
     *       @OA\Property(property="currency", type="string", example="USD"),
     *       @OA\Property(property="transaction_type", type="string", example="TRANSFER"),
     *       @OA\Property(property="location", type="string", example="Lagos, Nigeria"),
     *       @OA\Property(property="device", type="string", example="iPhone-15-Pro"),
     *       @OA\Property(property="device_type", type="string", example="MOBILE"),
     *       @OA\Property(property="ip_address", type="string", example="41.190.2.45"),
     *       @OA\Property(property="timestamp", type="string", format="date-time", example="2026-04-10T03:14:00Z")
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Transaction analyzed successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Transaction analyzed successfully"),
     *       @OA\Property(property="data", type="object")
     *     )
     *   ),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|string|max:50',
            'receiver_id' => 'nullable|string|max:50',
            'amount' => 'required|numeric|min:0.01|max:9999999999.99',
            'currency' => 'nullable|string|max:10',
            'transaction_type' => 'nullable|string|max:30',
            'location' => 'nullable|string|max:100',
            'device' => 'nullable|string|max:100',
            'device_type' => 'nullable|string|max:20',
            'ip_address' => 'nullable|string|max:45',
            'timestamp' => 'nullable|date',
        ]);

        $result = $this->fraudDetectionService->analyzeTransaction($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction analyzed successfully',
            'data' => [
                'id' => $result['transaction']->id,
                'transaction_id' => $result['transaction']->transaction_id,
                'user_id' => $result['transaction']->user_id,
                'amount' => (float) $result['transaction']->amount,
                'risk_score' => $result['risk_score'],
                'rule_score' => $result['rule_score'] ?? null,
                'ai_risk_score' => $result['ai_risk_score'] ?? null,
                'ai_confidence' => $result['ai_confidence'] ?? null,
                'fraud_type' => $result['fraud_type'] ?? null,
                'risk_level' => $result['risk_level'],
                'is_flagged' => $result['is_flagged'],
                'recommended_action' => $result['recommended_action'],
                'recommendation_reason' => $result['recommendation_reason'],
                'ai_explanation' => $result['ai_explanation'],
                'explanation_source' => $result['explanation_source'],
                'explanations' => $result['explanations'],
                'risk_factors' => $result['risk_factors'],
                'scoring_details' => $result['scoring_details'] ?? null,
                'status' => $result['transaction']->status,
                'created_at' => $result['transaction']->created_at,
            ],
        ], 201);
    }

    /**
     * @OA\Get(
     *   path="/api/transactions",
     *   tags={"Transactions"},
     *   summary="List all transactions",
     *   description="Get paginated list of transactions with optional filters.",
     *   @OA\Parameter(name="risk_level", in="query", required=false, @OA\Schema(type="string", enum={"LOW","MEDIUM","HIGH","CRITICAL"})),
     *   @OA\Parameter(name="is_flagged", in="query", required=false, @OA\Schema(type="boolean")),
     *   @OA\Parameter(name="user_id", in="query", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="amount_min", in="query", required=false, @OA\Schema(type="number")),
     *   @OA\Parameter(name="amount_max", in="query", required=false, @OA\Schema(type="number")),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *   @OA\Response(response=200, description="List of transactions")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'risk_level', 'is_flagged', 'user_id',
            'date_from', 'date_to', 'amount_min', 'amount_max',
        ]);

        $perPage = (int) $request->get('per_page', 15);
        $transactions = $this->transactionRepository->paginate($filters, $perPage);

        return response()->json([
            'status' => 'success',
            'data' => $transactions->items(),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/transactions/{id}",
     *   tags={"Transactions"},
     *   summary="Get transaction details",
     *   description="Get a single transaction with full fraud analysis details.",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\Response(response=200, description="Transaction details"),
     *   @OA\Response(response=404, description="Transaction not found")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $transaction = $this->transactionRepository->findById($id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $transaction,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/transactions/{id}/explain",
     *     tags={"Transactions"},
     *     summary="Trigger deep analyst explanation for a transaction",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Deep context explanation returned")
     * )
     */
    public function explain(int $id): JsonResponse
    {
        $transaction = $this->transactionRepository->findById($id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found',
            ], 404);
        }

        $deepExplanation = $this->azureOpenAIService->generateDeepExplanation($transaction);

        return response()->json([
            'status' => 'success',
            'data' => [
                'deep_explanation' => $deepExplanation
            ]
        ]);
    }
}
