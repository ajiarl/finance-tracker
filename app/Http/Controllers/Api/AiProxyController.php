<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiProxyController extends Controller
{
    /**
     * System prompt untuk persona "Pak Hemat".
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
Kamu adalah penasihat keuangan pribadi bernama 'Pak Hemat' — sarkastis, blak-blakan, tapi selalu memberikan satu saran yang benar-benar berguna. ATURAN KERAS: 1. Balas dalam Bahasa Indonesia. 2. Maksimal 2 kalimat. 3. Kalimat pertama: sindiran ringan berdasarkan pengeluaran. Kalimat kedua: satu saran konkret. 4. Jangan sebut angka mentah Rupiah, pakai persentase/kata relatif. 5. Jangan mulai dengan sapaan (Halo, dsb).
PROMPT;

    /**
     * GET /api/ai-insight
     *
     * Mengambil ringkasan keuangan user, mengirimnya ke Gemini,
     * dan mengembalikan insight singkat.
     */
    public function getInsight(Request $request): JsonResponse
    {
        $userId    = $request->user()->id;
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate   = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());

        // ── 1. Hitung summary ────────────────────────────────────────────────
        $baseQuery = Transaction::where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate]);

        $summaryData = (clone $baseQuery)
            ->selectRaw("
                SUM(CASE WHEN transactions.type = 'income'  THEN transactions.amount ELSE 0 END) as total_income,
                SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount ELSE 0 END) as total_expense
            ")
            ->first();

        $totalIncome  = $summaryData ? (float) $summaryData->total_income  : 0;
        $totalExpense = $summaryData ? (float) $summaryData->total_expense : 0;
        $savingsRate  = $totalIncome > 0
            ? round((($totalIncome - $totalExpense) / $totalIncome) * 100, 1)
            : 0;

        // ── 2. Top 3 kategori pengeluaran ────────────────────────────────────
        $topCategories = (clone $baseQuery)
            ->where('transactions.type', 'expense')
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw("
                COALESCE(categories.name, 'Uncategorized') as name,
                SUM(transactions.amount) as amount
            ")
            ->groupBy('name')
            ->orderByDesc('amount')
            ->limit(3)
            ->get()
            ->map(fn ($item) => [
                'name'       => $item->name,
                'amount'     => (float) $item->amount,
                'percentage' => $totalExpense > 0
                    ? round(((float) $item->amount / $totalExpense) * 100, 1)
                    : 0,
            ]);

        // ── 3. Bangun user prompt ────────────────────────────────────────────
        $financialContext = [
            'periode'               => "{$startDate} s/d {$endDate}",
            'total_pemasukan'       => $totalIncome,
            'total_pengeluaran'     => $totalExpense,
            'rasio_tabungan_persen' => $savingsRate,
            'top_3_pengeluaran'     => $topCategories,
        ];

        $userPrompt = 'Berikut data keuangan bulan ini: ' . json_encode($financialContext, JSON_UNESCAPED_UNICODE);

        // ── 4. Panggil Gemini API ────────────────────────────────────────────
        $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

        Log::info('[Pak Hemat] Step 1 — API Key check', [
            'key_present' => ! empty($apiKey),
            'key_length'  => $apiKey ? strlen($apiKey) : 0,
            'key_preview' => $apiKey ? substr($apiKey, 0, 8) . '...' : 'EMPTY',
        ]);

        if (empty($apiKey)) {
            Log::error('[Pak Hemat] ABORT — GEMINI_API_KEY is empty');

            return response()->json([
                'data' => ['insight' => 'Pak Hemat sedang cuti — API key belum dikonfigurasi.'],
            ], 200);
        }

        $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => self::SYSTEM_PROMPT]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0.9,
                'maxOutputTokens' => 200,
            ],
        ];

        Log::info('[Pak Hemat] Step 2 — Sending request to Gemini', [
            'url'          => preg_replace('/key=[^&]+/', 'key=***REDACTED***', $geminiUrl),
            'prompt_chars' => strlen($userPrompt),
        ]);

        try {
            $response = Http::timeout(15)->post($geminiUrl, $payload);

            Log::info('[Pak Hemat] Step 3 — Response received', [
                'status'      => $response->status(),
                'body_length' => strlen($response->body()),
            ]);

            if ($response->failed()) {
                Log::error('[Pak Hemat] GEMINI RETURNED ERROR', [
                    'http_status' => $response->status(),
                    'body'        => $response->body(),
                ]);

                // ── Rate limited (429) — return fallback insight, not error ──
                if ($response->status() === 429) {
                    return response()->json([
                        'data' => [
                            'insight' => 'Pak Hemat sudah terlalu banyak bicara hari ini — kuota API harian habis. '
                                       . 'Coba lagi besok, dan sementara itu, jangan belanja impulsif ya!',
                        ],
                    ]);
                }

                return response()->json([
                    'error'   => 'AI service unavailable',
                    'details' => $response->json() ?: $response->body(),
                    'status'  => $response->status(),
                ], 503);
            }

            $body = $response->json();

            Log::info('[Pak Hemat] Step 4 — Parsing response', [
                'has_candidates' => isset($body['candidates']),
                'candidate_count' => count($body['candidates'] ?? []),
            ]);

            $insight = $body['candidates'][0]['content']['parts'][0]['text']
                ?? null;

            if (empty($insight)) {
                Log::warning('[Pak Hemat] No text in response', [
                    'body_keys'  => array_keys($body),
                    'candidates' => $body['candidates'] ?? 'MISSING',
                ]);

                return response()->json([
                    'data' => ['insight' => 'Pak Hemat kehabisan kata-kata.'],
                ]);
            }

            Log::info('[Pak Hemat] SUCCESS', ['insight_length' => strlen($insight)]);

            return response()->json([
                'data' => ['insight' => trim($insight)],
            ]);

        } catch (\Throwable $e) {
            Log::error('[Pak Hemat] EXCEPTION thrown', [
                'type'    => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json([
                'error'   => 'AI proxy error',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
