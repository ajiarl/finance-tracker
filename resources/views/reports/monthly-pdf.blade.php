<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Bulanan {{ $report['month'] }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 12px;
            margin: 24px;
        }
        h1, h2 {
            margin: 0 0 12px;
        }
        .meta {
            margin-bottom: 20px;
            color: #4b5563;
        }
        .summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .summary td {
            border: 1px solid #d1d5db;
            padding: 10px;
            width: 25%;
        }
        .label {
            display: block;
            color: #6b7280;
            font-size: 11px;
            margin-bottom: 6px;
        }
        .value {
            font-size: 16px;
            font-weight: bold;
        }
        table.transactions {
            width: 100%;
            border-collapse: collapse;
        }
        table.transactions th,
        table.transactions td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
        }
        table.transactions th {
            background: #f3f4f6;
        }
        .right {
            text-align: right;
        }
    </style>
</head>
<body>
    <h1>Laporan Bulanan</h1>
    <div class="meta">
        Bulan: {{ $report['month'] }}<br>
        Dibuat: {{ $report['generated_at'] }}
    </div>

    <table class="summary">
        <tr>
            <td>
                <span class="label">Total Saldo</span>
                <span class="value">Rp {{ number_format((float) $report['summary']['total_balance'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Pemasukan</span>
                <span class="value">Rp {{ number_format((float) $report['summary']['income'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Pengeluaran</span>
                <span class="value">Rp {{ number_format((float) $report['summary']['expense'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Net</span>
                <span class="value">Rp {{ number_format((float) $report['summary']['net'], 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    <h2>Daftar Transaksi</h2>
    <table class="transactions">
        <thead>
            <tr>
                <th>ID</th>
                <th>Tanggal</th>
                <th>Tipe</th>
                <th>Deskripsi</th>
                <th class="right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['transactions'] as $transaction)
                <tr>
                    <td>{{ $transaction['id'] }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($transaction['transaction_date'])->format('Y-m-d') }}</td>
                    <td>{{ $transaction['type'] }}</td>
                    <td>{{ $transaction['description'] }}</td>
                    <td class="right">Rp {{ number_format((float) $transaction['amount'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Belum ada transaksi untuk bulan ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
