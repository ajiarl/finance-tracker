<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AiInsightService
{
    // Berapa bulan ke belakang yang dijadikan baseline
    private const LOOKBACK_MONTHS = 3;
    // Z-score threshold untuk anomali (2.0 = top ~5% outlier)
    private const ANOMALY_Z_THRESHOLD = 2.0;

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    public function getPredictions(User $user): array
    {
        $monthlyExpense = $this->getMonthlyTotals($user->id, 'expense', self::LOOKBACK_MONTHS);
        $monthlyIncome  = $this->getMonthlyTotals($user->id, 'income',  self::LOOKBACK_MONTHS);

        $avgExpense = $this->weightedAverage($monthlyExpense);
        $avgIncome  = $this->weightedAverage($monthlyIncome);

        // Tren: selisih rata-rata bulan terakhir vs rata-rata keseluruhan
        $expenseTrend = $this->calculateTrend($monthlyExpense);
        $incomeTrend  = $this->calculateTrend($monthlyIncome);

        // Prediksi bulan depan = rata-rata tertimbang + proyeksi tren
        $predictedExpense = max(0, $avgExpense + ($avgExpense * $expenseTrend / 100));
        $predictedIncome  = max(0, $avgIncome  + ($avgIncome  * $incomeTrend  / 100));
        $predictedSavings = $predictedIncome - $predictedExpense;

        // Per-kategori: prediksi pengeluaran terbesar bulan depan
        $categoryPredictions = $this->getCategoryPredictions($user->id);

        return [
            'next_month'          => Carbon::now()->addMonth()->format('Y-m'),
            'predicted_expense'   => round($predictedExpense, 2),
            'predicted_income'    => round($predictedIncome,  2),
            'predicted_savings'   => round($predictedSavings, 2),
            'expense_trend_pct'   => round($expenseTrend, 2),
            'income_trend_pct'    => round($incomeTrend,  2),
            'savings_outlook'     => $this->savingsOutlook($predictedSavings, $predictedIncome),
            'top_categories'      => $categoryPredictions,
            'data_months_used'    => count($monthlyExpense),
        ];
    }

    public function getAnomalies(User $user): array
    {
        $anomalies  = [];
        $categories = $this->getCategoriesWithHistory($user->id);

        foreach ($categories as $category) {
            $history = $this->getMonthlyCategoryTotals(
                $user->id,
                $category->category_id,
                self::LOOKBACK_MONTHS + 1   // +1: bulan ini + N bulan baseline
            );

            if (count($history) < 2) {
                continue;
            }

            // Pisahkan bulan ini dari baseline
            $currentMonth   = Carbon::now()->format('Y-m');
            $baseline       = array_filter($history, fn ($h) => $h['month'] !== $currentMonth);
            $currentEntry   = array_values(array_filter($history, fn ($h) => $h['month'] === $currentMonth));

            if (empty($baseline) || empty($currentEntry)) {
                continue;
            }

            $baselineAmounts = array_column(array_values($baseline), 'total');
            $currentAmount   = (float) $currentEntry[0]['total'];

            $mean   = array_sum($baselineAmounts) / count($baselineAmounts);
            $stdDev = $this->standardDeviation($baselineAmounts);

            // Hindari pembagian dengan nol jika semua nilai identik
            if ($stdDev < 0.01) {
                continue;
            }

            $zScore = ($currentAmount - $mean) / $stdDev;

            if ($zScore >= self::ANOMALY_Z_THRESHOLD) {
                $anomalies[] = [
                    'category_id'    => $category->category_id,
                    'category_name'  => $category->category_name,
                    'current_amount' => round($currentAmount, 2),
                    'average_amount' => round($mean, 2),
                    'increase_pct'   => $mean > 0
                                        ? round((($currentAmount - $mean) / $mean) * 100, 2)
                                        : null,
                    'z_score'        => round($zScore, 2),
                    'severity'       => $this->anomalySeverity($zScore),
                ];
            }
        }

        // Urutkan dari yang paling anomali
        usort($anomalies, fn ($a, $b) => $b['z_score'] <=> $a['z_score']);

        return $anomalies;
    }

    public function getRecommendations(User $user, array $anomalies): array
    {
        $recommendations = [];

        // ── 1. Rekomendasi dari anomali ──────────────────────────────────────
        foreach ($anomalies as $anomaly) {
            if ($anomaly['severity'] === 'critical') {
                $recommendations[] = [
                    'type'     => 'anomaly_alert',
                    'priority' => 'high',
                    'category' => $anomaly['category_name'],
                    'message'  => "Pengeluaran \"{$anomaly['category_name']}\" bulan ini "
                                . "melonjak {$anomaly['increase_pct']}% di atas rata-rata. "
                                . "Tinjau kembali transaksi di kategori ini.",
                ];
            } elseif ($anomaly['severity'] === 'warning') {
                $recommendations[] = [
                    'type'     => 'anomaly_alert',
                    'priority' => 'medium',
                    'category' => $anomaly['category_name'],
                    'message'  => "Pengeluaran \"{$anomaly['category_name']}\" lebih tinggi "
                                . "{$anomaly['increase_pct']}% dari biasanya bulan ini.",
                ];
            }
        }

        // ── 2. Rekomendasi rasio tabungan ─────────────────────────────────────
        $savingsRatio = $this->getSavingsRatio($user->id);

        if ($savingsRatio !== null) {
            if ($savingsRatio < 10) {
                $recommendations[] = [
                    'type'     => 'savings_rate',
                    'priority' => 'high',
                    'category' => null,
                    'message'  => "Rasio tabungan Anda hanya {$savingsRatio}%. "
                                . "Target ideal minimal 20% dari pemasukan bulanan.",
                ];
            } elseif ($savingsRatio < 20) {
                $recommendations[] = [
                    'type'     => 'savings_rate',
                    'priority' => 'medium',
                    'category' => null,
                    'message'  => "Rasio tabungan {$savingsRatio}% sudah cukup, "
                                . "namun masih bisa ditingkatkan ke 20% atau lebih.",
                ];
            } else {
                $recommendations[] = [
                    'type'     => 'savings_rate',
                    'priority' => 'low',
                    'category' => null,
                    'message'  => "Rasio tabungan {$savingsRatio}% sangat baik! Pertahankan.",
                ];
            }
        }

        // ── 3. Rekomendasi kategori pengeluaran dominan ───────────────────────
        $dominantCategory = $this->getDominantExpenseCategory($user->id);

        if ($dominantCategory && $dominantCategory->percentage > 40) {
            $pct = round($dominantCategory->percentage, 1);
            $recommendations[] = [
                'type'     => 'dominant_category',
                'priority' => 'medium',
                'category' => $dominantCategory->category_name,
                'message'  => "{$pct}% total pengeluaran Anda berasal dari "
                            . "\"{$dominantCategory->category_name}\". "
                            . "Pertimbangkan untuk mendiversifikasi atau memangkas kategori ini.",
            ];
        }

        // ── 4. Rekomendasi jika tidak ada data cukup ─────────────────────────
        if (empty($recommendations)) {
            $recommendations[] = [
                'type'     => 'general',
                'priority' => 'low',
                'category' => null,
                'message'  => 'Keuangan Anda terlihat stabil. '
                            . 'Terus catat transaksi secara konsisten untuk insight yang lebih akurat.',
            ];
        }

        // Urutkan prioritas: high → medium → low
        $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($recommendations, fn ($a, $b) =>
            $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']]
        );

        return $recommendations;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE QUERY HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Total pengeluaran/pemasukan per bulan selama N bulan terakhir.
     * Menggunakan strftime('%Y-%m') yang kompatibel SQLite.
     */
    private function getMonthlyTotals(int $userId, string $type, int $months): array
    {
        $since = Carbon::now()->subMonths($months)->startOfMonth()->toDateString();

        $rows = DB::table('transactions')
            ->selectRaw("strftime('%Y-%m', transaction_date) as month, SUM(amount) as total")
            ->where('user_id', $userId)
            ->where('type', $type)
            ->whereNull('deleted_at')
            ->where('transaction_date', '>=', $since)
            ->groupByRaw("strftime('%Y-%m', transaction_date)")
            ->orderBy('month')
            ->get();

        return $rows->map(fn ($r) => [
            'month' => $r->month,
            'total' => (float) $r->total,
        ])->toArray();
    }

    private function getMonthlyCategoryTotals(int $userId, int $categoryId, int $months): array
    {
        $since = Carbon::now()->subMonths($months)->startOfMonth()->toDateString();

        $rows = DB::table('transactions')
            ->selectRaw("strftime('%Y-%m', transaction_date) as month, SUM(amount) as total")
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->where('type', 'expense')
            ->whereNull('deleted_at')
            ->where('transaction_date', '>=', $since)
            ->groupByRaw("strftime('%Y-%m', transaction_date)")
            ->orderBy('month')
            ->get();

        return $rows->map(fn ($r) => [
            'month' => $r->month,
            'total' => (float) $r->total,
        ])->toArray();
    }

    private function getCategoriesWithHistory(int $userId)
    {
        $since = Carbon::now()->subMonths(self::LOOKBACK_MONTHS + 1)->startOfMonth()->toDateString();

        return DB::table('transactions')
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw('transactions.category_id, categories.name as category_name, COUNT(DISTINCT strftime(\'%Y-%m\', transaction_date)) as month_count')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereNull('transactions.deleted_at')
            ->whereNotNull('transactions.category_id')
            ->where('transactions.transaction_date', '>=', $since)
            ->groupBy('transactions.category_id', 'categories.name')
            ->having('month_count', '>=', 2) // minimal 2 bulan data agar z-score bermakna
            ->get();
    }

    private function getCategoryPredictions(int $userId): array
    {
        $since = Carbon::now()->subMonths(self::LOOKBACK_MONTHS)->startOfMonth()->toDateString();

        $rows = DB::table('transactions')
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw('categories.name as category_name, AVG(monthly_sum) as avg_monthly')
            ->fromSub(
                DB::table('transactions')
                    ->selectRaw("category_id, strftime('%Y-%m', transaction_date) as month, SUM(amount) as monthly_sum")
                    ->where('user_id', $userId)
                    ->where('type', 'expense')
                    ->whereNull('deleted_at')
                    ->where('transaction_date', '>=', $since)
                    ->groupByRaw("category_id, strftime('%Y-%m', transaction_date)"),
                'monthly_by_cat'
            )
            ->join('categories', 'monthly_by_cat.category_id', '=', 'categories.id')
            ->groupBy('monthly_by_cat.category_id', 'categories.name')
            ->orderByRaw('avg_monthly DESC')
            ->limit(5)
            ->get();

        return $rows->map(fn ($r) => [
            'category_name'     => $r->category_name,
            'predicted_expense' => round((float) $r->avg_monthly, 2),
        ])->toArray();
    }

    private function getSavingsRatio(int $userId): ?float
    {
        $since = Carbon::now()->subMonths(self::LOOKBACK_MONTHS)->startOfMonth()->toDateString();

        $totals = DB::table('transactions')
            ->selectRaw("type, SUM(amount) as total")
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('transaction_date', '>=', $since)
            ->groupBy('type')
            ->pluck('total', 'type');

        $income  = (float) ($totals['income']  ?? 0);
        $expense = (float) ($totals['expense'] ?? 0);

        if ($income <= 0) {
            return null;
        }

        return round((($income - $expense) / $income) * 100, 1);
    }

    private function getDominantExpenseCategory(int $userId): ?object
    {
        $since = Carbon::now()->subMonths(self::LOOKBACK_MONTHS)->startOfMonth()->toDateString();

        $totalExpense = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereNull('deleted_at')
            ->where('transaction_date', '>=', $since)
            ->sum('amount');

        if ($totalExpense <= 0) {
            return null;
        }

        return DB::table('transactions')
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw('categories.name as category_name, SUM(transactions.amount) as cat_total, (SUM(transactions.amount) / ? * 100) as percentage', [$totalExpense])
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereNull('transactions.deleted_at')
            ->whereNotNull('transactions.category_id')
            ->where('transactions.transaction_date', '>=', $since)
            ->groupBy('transactions.category_id', 'categories.name')
            ->orderByRaw('cat_total DESC')
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE MATH HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Rata-rata tertimbang: bulan terakhir bobotnya 2x, sisanya 1x.
     */
    private function weightedAverage(array $monthlyData): float
    {
        if (empty($monthlyData)) {
            return 0.0;
        }

        $totals = array_column($monthlyData, 'total');
        $count  = count($totals);

        if ($count === 1) {
            return $totals[0];
        }

        // Bobot: index terakhir = 2, sisanya = 1
        $weights     = array_fill(0, $count, 1);
        $weights[$count - 1] = 2;

        $weightedSum  = 0;
        $totalWeights = 0;

        foreach ($totals as $i => $value) {
            $weightedSum  += $value * $weights[$i];
            $totalWeights += $weights[$i];
        }

        return $totalWeights > 0 ? $weightedSum / $totalWeights : 0.0;
    }

    /**
     * Hitung tren sebagai persentase perubahan bulan terakhir vs rata-rata sebelumnya.
     */
    private function calculateTrend(array $monthlyData): float
    {
        if (count($monthlyData) < 2) {
            return 0.0;
        }

        $totals   = array_column($monthlyData, 'total');
        $last     = array_pop($totals);
        $prevAvg  = count($totals) > 0 ? array_sum($totals) / count($totals) : $last;

        if ($prevAvg <= 0) {
            return 0.0;
        }

        return (($last - $prevAvg) / $prevAvg) * 100;
    }

    private function standardDeviation(array $values): float
    {
        $count = count($values);

        if ($count < 2) {
            return 0.0;
        }

        $mean     = array_sum($values) / $count;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($count - 1);

        return sqrt($variance);
    }

    private function anomalySeverity(float $zScore): string
    {
        return match (true) {
            $zScore >= 3.0 => 'critical',
            $zScore >= 2.5 => 'warning',
            default        => 'info',
        };
    }

    private function savingsOutlook(float $savings, float $income): string
    {
        if ($income <= 0) {
            return 'insufficient_data';
        }

        $ratio = ($savings / $income) * 100;

        return match (true) {
            $ratio >= 20  => 'excellent',
            $ratio >= 10  => 'good',
            $ratio >= 0   => 'caution',
            default       => 'deficit',
        };
    }
}
