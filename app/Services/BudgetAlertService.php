<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

class BudgetAlertService
{
    const THRESHOLDS = [50, 75, 90, 100];

    public function checkAndNotify(Budget $budget): void
    {
        // Tidak perlu cek jika budget tidak aktif atau amount = 0
        if (! $budget->is_active || (float) $budget->amount <= 0) {
            return;
        }

        $percentage = ((float) $budget->spent / (float) $budget->amount) * 100;

        // Kumpulkan threshold yang sudah terlewati
        $triggeredThresholds = array_filter(
            self::THRESHOLDS,
            fn (int $t) => $percentage >= $t
        );

        if (empty($triggeredThresholds)) {
            // Jika tidak ada threshold yang terlewati, hapus semua alert lama untuk budget ini
            BudgetAlert::where('budget_id', $budget->id)->delete();
            return;
        }

        // Reset threshold lock jika pengeluaran turun (e.g. transaksi dihapus)
        BudgetAlert::where('budget_id', $budget->id)
            ->whereNotIn('threshold', $triggeredThresholds)
            ->delete();

        // Ambil threshold yang sudah pernah di-alert untuk budget ini
        $existingThresholds = BudgetAlert::where('budget_id', $budget->id)
            ->pluck('threshold')
            ->toArray();

        // Hanya proses threshold yang BELUM pernah di-alert
        $newThresholds = array_filter(
            $triggeredThresholds,
            fn (int $t) => ! in_array($t, $existingThresholds)
        );

        if (empty($newThresholds)) {
            return;
        }

        DB::transaction(function () use ($budget, $newThresholds, $percentage) {
            foreach ($newThresholds as $threshold) {
                // 1. Catat alert ke tabel budget_alerts
                BudgetAlert::create([
                    'budget_id' => $budget->id,
                    'threshold' => $threshold,
                ]);

                // 2. Kirim notifikasi ke user
                Notification::create([
                    'user_id' => $budget->user_id,
                    'title'   => $this->buildTitle($threshold),
                    'message' => $this->buildMessage($budget, $threshold, $percentage),
                    'type'    => $threshold >= 100 ? 'error' : ($threshold >= 90 ? 'warning' : 'info'),
                    'data'    => [
                        'budget_id'       => $budget->id,
                        'budget_name'     => $budget->name,
                        'threshold'       => $threshold,
                        'percentage_used' => round($percentage, 1),
                        'spent_amount'    => (float) $budget->spent,
                        'budget_amount'   => (float) $budget->amount,
                        'severity'        => $threshold >= 100 ? 'critical' : ($threshold >= 90 ? 'warning' : 'info'),
                    ],
                ]);
            }
        });
    }

    // ── Private Helpers ──────────────────────────────────────────────────────

    private function buildTitle(int $threshold): string
    {
        return match (true) {
            $threshold >= 100 => '🚨 Anggaran Habis!',
            $threshold >= 90  => '⚠️ Anggaran Hampir Habis',
            $threshold >= 75  => '📊 Peringatan Anggaran',
            default           => '💡 Info Anggaran',
        };
    }

    private function buildMessage(Budget $budget, int $threshold, float $percentage): string
    {
        $spent    = number_format((float) $budget->spent, 0, ',', '.');
        $amount   = number_format((float) $budget->amount, 0, ',', '.');
        $pctLabel = number_format($percentage, 1);

        return match (true) {
            $threshold >= 100 => "Anggaran \"{$budget->name}\" sudah habis! "
                               . "Pengeluaran Rp{$spent} telah mencapai 100% dari limit Rp{$amount}.",
            $threshold >= 90  => "Anggaran \"{$budget->name}\" hampir habis ({$pctLabel}%). "
                               . "Sisa anggaran sangat sedikit dari Rp{$amount}.",
            default           => "Pengeluaran anggaran \"{$budget->name}\" telah mencapai {$threshold}% "
                               . "(Rp{$spent} dari Rp{$amount}).",
        };
    }
}
