<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    public function uploadCsv(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'has_header' => 'sometimes|boolean',
        ]);

        $hasHeader = $request->boolean('has_header', true);
        $file = $validated['file'];
        $storedPath = $file->storeAs(
            'imports/'.$request->user()->id,
            now()->format('YmdHis').'-'.Str::random(8).'-'.$file->getClientOriginalName(),
            'local'
        );

        $analysis = $this->analyzeCsv($storedPath, $hasHeader);

        $import = Import::create([
            'user_id' => $request->user()->id,
            'filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => 'uploaded',
            'rows_total' => $analysis['rows_total'],
            'rows_processed' => 0,
            'errors' => [],
            'metadata' => [
                'has_header' => $hasHeader,
                'headers' => $analysis['headers'],
                'preview' => $analysis['preview'],
            ],
        ]);

        return response()->json([
            'data' => [
                'import_id' => $import->id,
                'status' => $import->status,
                'filename' => $import->filename,
                'rows_total' => $import->rows_total,
                'headers' => $analysis['headers'],
                'preview' => $analysis['preview'],
            ],
        ], 201);
    }

    public function status(Request $request, Import $import)
    {
        if ($import->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $import]);
    }

    public function map(Request $request, Import $import)
    {
        if ($import->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'column_mappings' => 'required|array',
            'column_mappings.amount' => 'required',
            'column_mappings.date' => 'required',
            'column_mappings.type' => 'nullable',
            'column_mappings.category' => 'nullable',
            'column_mappings.description' => 'nullable',
            'column_mappings.notes' => 'nullable',
            'date_format' => 'nullable|string|max:50',
            'has_header' => 'sometimes|boolean',
        ]);

        $account = Account::where('user_id', $request->user()->id)->find($validated['account_id']);
        if (! $account) {
            return response()->json(['error' => 'Account not found.'], 404);
        }

        $hasHeader = array_key_exists('has_header', $validated)
            ? (bool) $validated['has_header']
            : (bool) ($import->metadata['has_header'] ?? true);

        $import->update([
            'status' => 'processing',
            'rows_processed' => 0,
            'errors' => [],
            'metadata' => array_merge($import->metadata ?? [], [
                'has_header' => $hasHeader,
                'column_mappings' => $validated['column_mappings'],
                'date_format' => $validated['date_format'] ?? null,
            ]),
        ]);

        [$successCount, $errors, $warnings] = $this->processImport(
            $request->user()->id,
            $import,
            $account,
            $validated['column_mappings'],
            $validated['date_format'] ?? null,
            $hasHeader
        );

        $import->refresh();

        return response()->json([
            'data' => [
                'import_id' => $import->id,
                'status' => $import->status,
                'rows_total' => $import->rows_total,
                'rows_processed' => $import->rows_processed,
                'success_count' => $successCount,
                'errors' => $errors,
                'warnings' => $warnings,
            ],
        ]);
    }

    private function processImport(
        int $userId,
        Import $import,
        Account $account,
        array $mappings,
        ?string $dateFormat,
        bool $hasHeader
    ): array {
        $stream = Storage::disk('local')->readStream($import->stored_path);
        if (! $stream) {
            $import->update([
                'status' => 'failed',
                'errors' => [['message' => 'Unable to read import file.']],
            ]);

            return [0, [['message' => 'Unable to read import file.']], []];
        }

        $headers = [];
        $rowNumber = 0;
        $processed = 0;
        $success = 0;
        $errors = [];
        $warnings = [];

        $expenseCache = $this->buildCategoryCache($userId, 'expense');
        $incomeCache  = $this->buildCategoryCache($userId, 'income');

        try {
            if ($hasHeader) {
                $headers = fgetcsv($stream) ?: [];
            }

            while (($row = fgetcsv($stream)) !== false) {
                $rowNumber++;
                $rowData = $this->normalizeRow($row, $headers, $hasHeader);

                try {
                    $type = $this->resolveType($rowData, $mappings);
                    $amount = $this->parseAmount($this->mappedValue($rowData, $mappings['amount'] ?? null));
                    $dateValue = $this->mappedValue($rowData, $mappings['date'] ?? null);
                    $transactionDate = $this->parseDate($dateValue, $dateFormat);
                    if ($type === 'transfer') {
                        $category = null;
                    } else {
                        $cache = $type === 'income' ? $incomeCache : $expenseCache;
                        $category = $this->resolveOrCreateCategory(
                            $userId,
                            $type,
                            $this->mappedValue($rowData, $mappings['category'] ?? null),
                            $cache,
                            $warnings,
                            $rowNumber
                        );
                    }

                    DB::transaction(function () use ($userId, $account, $mappings, $rowData, $type, $amount, $transactionDate, $category) {
                        Transaction::create([
                            'user_id' => $userId,
                            'account_id' => $account->id,
                            'category_id' => $category?->id,
                            'type' => $type,
                            'amount' => $amount,
                            'description' => $this->mappedValue($rowData, $mappings['description'] ?? null),
                            'transaction_date' => $transactionDate->toDateString(),
                            'notes' => $this->mappedValue($rowData, $mappings['notes'] ?? null),
                        ]);

                        $this->applyBalanceDelta($account, $type, $amount);
                    });

                    $success++;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                        'raw' => $row,
                    ];
                }

                $processed++;
            }
        } finally {
            fclose($stream);
        }

        $import->update([
            'status' => empty($errors) ? 'completed' : ($success > 0 ? 'completed' : 'failed'),
            'rows_processed' => $processed,
            'errors' => $errors,
            'metadata' => array_merge($import->metadata ?? [], [
                'warnings' => $warnings,
            ]),
        ]);

        return [$success, $errors, $warnings];
    }

    private function analyzeCsv(string $storedPath, bool $hasHeader): array
    {
        $stream = Storage::disk('local')->readStream($storedPath);
        $headers = [];
        $preview = [];
        $rowsTotal = 0;

        if (! $stream) {
            return ['headers' => [], 'preview' => [], 'rows_total' => 0];
        }

        try {
            if ($hasHeader) {
                $headers = fgetcsv($stream) ?: [];
            }

            while (($row = fgetcsv($stream)) !== false) {
                $rowsTotal++;

                if (count($preview) < 10) {
                    $preview[] = $this->normalizeRow($row, $headers, $hasHeader);
                }
            }
        } finally {
            fclose($stream);
        }

        return [
            'headers' => $headers,
            'preview' => $preview,
            'rows_total' => $rowsTotal,
        ];
    }

    private function normalizeRow(array $row, array $headers, bool $hasHeader): array
    {
        if (! $hasHeader || empty($headers)) {
            return $row;
        }

        $normalized = [];
        foreach ($row as $index => $value) {
            $header = $headers[$index] ?? (string) $index;
            $normalized[$header] = $value;
        }

        return $normalized;
    }

    private function mappedValue(array $rowData, mixed $mapping): mixed
    {
        if ($mapping === null || $mapping === '') {
            return null;
        }

        if (array_key_exists($mapping, $rowData)) {
            return $rowData[$mapping];
        }

        if (is_numeric($mapping)) {
            return $rowData[(int) $mapping] ?? null;
        }

        return null;
    }

    private function resolveType(array $rowData, array $mappings): string
    {
        $mappedType = $this->mappedValue($rowData, $mappings['type'] ?? null);
        if ($mappedType !== null && $mappedType !== '') {
            $normalized = Str::lower(trim((string) $mappedType));

            return match ($normalized) {
                'income', 'credit', 'pemasukan' => 'income',
                'expense', 'debit', 'pengeluaran' => 'expense',
                'transfer' => 'transfer',
                default => throw new \RuntimeException('Invalid transaction type mapping.'),
            };
        }

        $amount = $this->parseSignedAmount($this->mappedValue($rowData, $mappings['amount'] ?? null));

        return $amount < 0 ? 'expense' : 'income';
    }

    private function normalizeString(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        $value = preg_replace('/[^\w\s]/u', '', $value);
        return $value;
    }

    private function buildCategoryCache(int $userId, string $type): Collection
    {
        return Category::where('user_id', $userId)
            ->where('type', $type)
            ->get()
            ->keyBy(fn(Category $cat) => $this->normalizeString($cat->name));
    }

    private function resolveOrCreateCategory(
        int        $userId,
        string     $type,
        mixed      $rawValue,
        Collection &$cache,
        array      &$warnings,
        int        $rowNumber
    ): ?Category {
        if ($rawValue === null || trim((string) $rawValue) === '') {
            return null;
        }

        $normalized = $this->normalizeString((string) $rawValue);

        if ($cache->has($normalized)) {
            return $cache->get($normalized);
        }

        $slug = Str::slug($normalized);
        $slugMatch = $cache->first(
            fn(Category $cat) => Str::slug($this->normalizeString($cat->name)) === $slug
        );
        if ($slugMatch) {
            return $slugMatch;
        }

        $containsMatch = $cache->first(function (Category $cat) use ($normalized) {
            $catNorm = $this->normalizeString($cat->name);
            return str_contains($catNorm, $normalized) || str_contains($normalized, $catNorm);
        });
        if ($containsMatch) {
            $warnings[] = [
                'row'     => $rowNumber,
                'message' => "Category '{$rawValue}' loosely matched to '{$containsMatch->name}'.",
            ];
            return $containsMatch;
        }

        $bestMatch = null;
        $bestScore = 0;
        $threshold = 70;

        foreach ($cache as $catNorm => $category) {
            similar_text($normalized, $catNorm, $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $bestMatch = $category;
            }
        }

        if ($bestScore >= $threshold && $bestMatch !== null) {
            $warnings[] = [
                'row'     => $rowNumber,
                'message' => "Category '{$rawValue}' fuzzy-matched to '{$bestMatch->name}' ({$bestScore}% similarity).",
            ];
            return $bestMatch;
        }

        $newCategory = Category::create([
            'user_id' => $userId,
            'type'    => $type,
            'name'    => trim((string) $rawValue),
        ]);

        $cache->put($normalized, $newCategory);

        $warnings[] = [
            'row'     => $rowNumber,
            'message' => "Category '{$rawValue}' did not exist and was auto-created.",
        ];

        return $newCategory;
    }

    private function parseAmount(mixed $value): float
    {
        return abs($this->parseSignedAmount($value));
    }

    private function parseSignedAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            throw new \RuntimeException('Amount is required.');
        }

        $normalized = preg_replace('/[^0-9,\.-]/', '', (string) $value) ?? '';

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (! is_numeric($normalized)) {
            throw new \RuntimeException('Amount format is invalid.');
        }

        return (float) $normalized;
    }

    private function parseDate(mixed $value, ?string $dateFormat): Carbon
    {
        if ($value === null || $value === '') {
            throw new \RuntimeException('Date is required.');
        }

        $stringValue = trim((string) $value);

        try {
            if ($dateFormat) {
                return Carbon::createFromFormat($dateFormat, $stringValue);
            }

            if (str_contains($stringValue, '/')) {
                return Carbon::createFromFormat('d/m/Y', $stringValue);
            }

            return Carbon::parse($stringValue);
        } catch (\Exception $e) {
            throw new \RuntimeException("Could not parse date '{$stringValue}'. Format mismatch.");
        }
    }

    private function applyBalanceDelta(Account $account, string $type, float $amount): void
    {
        $delta = match ($type) {
            'income' => $amount,
            'expense', 'transfer' => -1 * $amount,
        };

        $account->balance = (float) $account->balance + $delta;
        $account->save();
    }
}
