<?php

namespace App\Http\Controllers;

use App\Services\AiInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function __construct(
        private AiInsightService $insightService
    ) {}

    /**
     * GET /api/insights
     * Mengembalikan data prediksi, anomali, dan rekomendasi finansial.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $predictions     = $this->insightService->getPredictions($user);
        $anomalies       = $this->insightService->getAnomalies($user);
        $recommendations = $this->insightService->getRecommendations($user, $anomalies);

        return response()->json([
            'data' => [
                'generated_at'    => now()->toISOString(),
                'predictions'     => $predictions,
                'anomalies'       => $anomalies,
                'recommendations' => $recommendations,
            ],
            'meta' => [
                'anomaly_count'        => count($anomalies),
                'recommendation_count' => count($recommendations),
                'lookback_months'      => 3,
            ],
        ]);
    }
}
