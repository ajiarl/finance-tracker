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

        // PROFESSIONAL FIX: Hanya kirim threshold PALING TINGGI yang baru
        // (Misal: langsung lompat ke 100%, lewati 50, 75, 90)
        $highestNewThreshold = max($newThresholds);

        DB::transaction(function () use ($budget, $highestNewThreshold, $percentage) {
            // 1. Catat alert ke tabel budget_alerts (catat semua yang terlewati agar tidak spam nanti)
            $existingAlerts = BudgetAlert::where('budget_id', $budget->id)->pluck('threshold')->toArray();
            $thresholdsToRecord = array_filter(
                self::THRESHOLDS,
                fn ($t) => $percentage >= $t && ! in_array($t, $existingAlerts)
            );

            foreach ($thresholdsToRecord as $t) {
                BudgetAlert::create([
                    'budget_id' => $budget->id,
                    'threshold' => $t,
                ]);
            }

            // 2. Kirim HANYA SATU notifikasi (yang tertinggi)
            Notification::create([
                'user_id' => $budget->user_id,
                'title'   => $this->buildTitle($highestNewThreshold),
                'message' => $this->buildMessage($budget, $highestNewThreshold, $percentage),
                'type'    => $highestNewThreshold >= 100 ? 'error' : ($highestNewThreshold >= 90 ? 'warning' : 'info'),
                'data'    => [
                    'budget_id'       => $budget->id,
                    'budget_name'     => $budget->name,
                    'threshold'       => $highestNewThreshold,
                    'percentage_used' => round($percentage, 1),
                    'spent_amount'    => (float) $budget->spent,
                    'budget_amount'   => (float) $budget->amount,
                    'severity'        => $highestNewThreshold >= 100 ? 'critical' : ($highestNewThreshold >= 90 ? 'warning' : 'info'),
                ],
            ]);
        });
    }

    // ── Private Helpers ──────────────────────────────────────────────────────

    private function buildTitle(int $threshold): string
    {
        return match (true) {
            $threshold >= 100 => 'ANGGARAN HABIS',
            $threshold >= 90  => 'ANGGARAN HAMPIR HABIS',
            $threshold >= 75  => 'PERINGATAN ANGGARAN',
            default           => 'INFO ANGGARAN',
        };
    }

    private function buildMessage(Budget $budget, int $threshold, float $percentage): string
    {
        $spent    = number_format((float) $budget->spent, 0, ',', '.');
        $amount   = number_format((float) $budget->amount, 0, ',', '.');
        $pctLabel = number_format($percentage, 1);

        return match (true) {
            $threshold >= 100 => "ANGGARAN \"{$budget->name}\" SUDAH HABIS. "
                               . "PENGELUARAN RP{$spent} TELAH MENCAPAI 100% DARI LIMIT RP{$amount}.",
            $threshold >= 90  => "ANGGARAN \"{$budget->name}\" HAMPIR HABIS ({$pctLabel}%). "
                               . "SISA ANGGARAN SANGAT SEDIKIT DARI RP{$amount}.",
            default           => "PENGELUARAN ANGGARAN \"{$budget->name}\" TELAH MENCAPAI {$threshold}% "
                               . "(RP{$spent} DARI RP{$amount}).",
        };
    }
}
