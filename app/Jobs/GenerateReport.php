<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\Report;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateReport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reportId)
    {
    }

    public function handle(): void
    {
        $report = Report::find($this->reportId);

        if (! $report) {
            return;
        }

        $report->update(['status' => 'processing']);

        try {
            [$year, $month] = explode('-', $report->month);

            $transactions = Transaction::query()
                ->where('user_id', $report->user_id)
                ->whereYear('transaction_date', (int) $year)
                ->whereMonth('transaction_date', (int) $month)
                ->get(['id', 'type', 'amount', 'description', 'transaction_date', 'account_id', 'category_id']);

            $totalBalance = Account::where('user_id', $report->user_id)
                ->where('is_active', true)
                ->sum('balance');

            $income = $transactions->where('type', 'income')->sum('amount');
            $expense = $transactions->where('type', 'expense')->sum('amount');

            $payload = [
                'report_id' => $report->id,
                'month' => $report->month,
                'format' => $report->format,
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'total_balance' => $totalBalance,
                    'income' => $income,
                    'expense' => $expense,
                    'net' => $income - $expense,
                ],
                'transactions' => $transactions->toArray(),
            ];

            if ($report->format === 'pdf') {
                $filePath = 'reports/'.$report->user_id.'/report-'.$report->id.'-'.$report->month.'.pdf';
                $pdf = Pdf::loadView('reports.monthly-pdf', ['report' => $payload]);
                Storage::disk('local')->put($filePath, $pdf->output());
            } elseif ($report->format === 'xlsx') {
                $filePath = 'reports/'.$report->user_id.'/report-'.$report->id.'-'.$report->month.'.xlsx';
                $this->storeXlsxReport($filePath, $payload);
            } else {
                $filePath = 'reports/'.$report->user_id.'/report-'.$report->id.'-'.$report->month.'.json';
                Storage::disk('local')->put(
                    $filePath,
                    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }

            $report->update([
                'status' => 'ready',
                'file_path' => $filePath,
            ]);
        } catch (\Throwable $e) {
            $report->update([
                'status' => 'failed',
            ]);

            throw $e;
        }
    }

    private function storeXlsxReport(string $filePath, array $payload): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Finance Tracker')
            ->setTitle('Report '.$payload['month'])
            ->setDescription('Monthly finance report export');

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Summary');
        $summarySheet->fromArray([
            ['Field', 'Value'],
            ['Month', $payload['month']],
            ['Generated At', $payload['generated_at']],
            ['Total Balance', (float) $payload['summary']['total_balance']],
            ['Income', (float) $payload['summary']['income']],
            ['Expense', (float) $payload['summary']['expense']],
            ['Net', (float) $payload['summary']['net']],
        ]);

        $transactionsSheet = $spreadsheet->createSheet();
        $transactionsSheet->setTitle('Transactions');
        $transactionsSheet->fromArray([
            ['ID', 'Date', 'Type', 'Description', 'Amount', 'Account ID', 'Category ID'],
        ]);

        $rowNumber = 2;
        foreach ($payload['transactions'] as $transaction) {
            $transactionsSheet->fromArray([
                [
                    $transaction['id'],
                    $transaction['transaction_date'],
                    $transaction['type'],
                    $transaction['description'],
                    (float) $transaction['amount'],
                    $transaction['account_id'],
                    $transaction['category_id'],
                ],
            ], null, 'A'.$rowNumber);

            $rowNumber++;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'report_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        Storage::disk('local')->put($filePath, file_get_contents($tempFile));

        @unlink($tempFile);
    }
}
