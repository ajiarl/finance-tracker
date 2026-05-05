<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReport;
use App\Models\Report;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'format' => ['required', 'in:pdf,xlsx'],
        ]);

        $report = Report::create([
            'user_id' => $request->user()->id,
            'month' => $validated['month'],
            'format' => $validated['format'],
            'status' => 'queued',
        ]);

        if (app()->environment('local')) {
            GenerateReport::dispatchSync($report->id);
            $report->refresh();
        } else {
            GenerateReport::dispatch($report->id);
        }

        return response()->json([
            'data' => [
                'report_id' => $report->id,
                'status' => $report->status,
            ],
        ], 202);
    }

    public function status(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => [
                'report_id' => $report->id,
                'month' => $report->month,
                'format' => $report->format,
                'status' => $report->status,
                'download_url' => $report->file_path ? route('reports.download', $report) : null,
            ],
        ]);
    }

    public function download(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if (! $report->file_path || ! \Storage::disk('local')->exists($report->file_path)) {
            return response()->json(['error' => 'Report file not found.'], 404);
        }

        return \Storage::disk('local')->download($report->file_path);
    }
}
