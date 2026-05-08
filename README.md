# Finance Tracker API

Backend Laravel untuk aplikasi pencatatan keuangan pribadi.

## Menjalankan Proyek

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

Base URL lokal:

```text
http://127.0.0.1:8000
```

## Autentikasi

Endpoint publik:

- `POST /api/register`
- `POST /api/login`

Endpoint lain memakai token Sanctum:

```http
Accept: application/json
Authorization: Bearer <token>
```

Endpoint auth:

- `GET /api/user`
- `POST /api/logout`

## Ringkasan Endpoint

### Accounts

- `GET /api/accounts`
- `POST /api/accounts`
- `GET /api/accounts/{id}`
- `PUT /api/accounts/{id}`
- `DELETE /api/accounts/{id}`
- `POST /api/accounts/{account}/reconcile`

Contoh create:

```json
{
  "name": "BCA",
  "type": "bank",
  "balance": 0
}
```

Contoh reconcile:

```json
{
  "actual_balance": 150000
}
```

Perilaku endpoint reconcile:

- hanya bisa dipakai untuk akun milik user login
- akun nonaktif akan ditolak
- jika `actual_balance` sama dengan saldo saat ini, response `200` dan tidak ada transaksi baru
- jika `actual_balance` lebih besar, sistem membuat transaksi penyesuaian bertipe `income`
- jika `actual_balance` lebih kecil, sistem membuat transaksi penyesuaian bertipe `expense`
- proses update saldo dan pembuatan transaksi dibungkus `DB::transaction`

### Categories

- `GET /api/categories`
- `POST /api/categories`
- `PUT /api/categories/{id}`
- `DELETE /api/categories/{id}`

Perilaku endpoint:

- `GET /api/categories` mengembalikan kategori aktif milik user dan kategori sistem
- kategori sistem memakai `user_id = null`
- kategori sistem tidak bisa diubah atau dihapus oleh user biasa
- `user_id` tidak dikirim dari request, selalu diambil dari user login
- filter tipe tersedia lewat `?type=income` dan `?type=expense`

Contoh create:

```json
{
  "name": "Jajan Kampus",
  "type": "expense",
  "icon": "coffee",
  "color": "#F59E0B"
}
```

Validasi penting:

- `name`: wajib, string, maks 100 karakter
- `type`: `income` atau `expense`
- `icon`: nullable, string, maks 50 karakter
- `color`: nullable, harus format hex `#RRGGBB`

### Transactions

- `GET /api/transactions`
- `POST /api/transactions`
- `GET /api/transactions/{id}`
- `PUT /api/transactions/{id}`
- `DELETE /api/transactions/{id}`

Filter yang tersedia:

- `account_id`
- `category_id`
- `type`
- `date_from`
- `date_to`

Perilaku endpoint:

- transaksi boleh memakai kategori milik user atau kategori sistem
- `type` transaksi harus cocok dengan `type` kategori
- `tags` disimpan sebagai JSON dan otomatis dikembalikan sebagai array

Contoh create:

```json
{
  "account_id": 3,
  "category_id": 4,
  "type": "expense",
  "amount": 15000,
  "description": "Makan siang",
  "transaction_date": "2026-05-07",
  "reference_number": "TRX-001",
  "notes": "Beli di kantin",
  "tags": ["makan", "kantin"]
}
```

Contoh update:

```json
{
  "notes": "Update catatan transaksi",
  "tags": ["makan", "siang", "kampus"]
}
```

Validasi penting:

- `account_id`: wajib saat create, harus milik user login
- `category_id`: nullable, boleh kategori user atau kategori sistem
- `type`: `income`, `expense`, atau `transfer`
- `amount`: numerik dan lebih dari 0
- `transaction_date`: tanggal valid
- `tags`: nullable array
- `tags.*`: string dengan panjang maksimum 50 karakter

### Budgets

- `GET /api/budgets`
- `POST /api/budgets`
- `GET /api/budgets/{id}`
- `PUT /api/budgets/{id}`
- `DELETE /api/budgets/{id}`

### Fitur Budget Alert

Sistem secara otomatis memantau pengeluaran anggaran dan mengirimkan notifikasi saat mencapai ambang batas tertentu:

- **Threshold**: 50%, 75%, 90%, dan 100%.
- **Logika**: Alert hanya dikirim sekali per threshold untuk setiap anggaran guna menghindari spam.
- **Pemicu**: Otomatis dipicu setiap kali ada transaksi (tambah/ubah/hapus) yang mempengaruhi saldo pengeluaran kategori terkait.
- **Notifikasi**: Muncul di list notifikasi user dengan tipe `info`, `warning`, atau `error` (saat 100%).

### AI Insights

Fitur analisis keuangan berbasis statistik untuk memberikan gambaran kesehatan finansial user:

- `GET /api/insights`

**Ringkasan Algoritma:**

| Method | Teknik | Input Data |
| :--- | :--- | :--- |
| `getPredictions` | Weighted average + tren linear | 3 bulan terakhir per tipe |
| `getAnomalies` | Z-score (threshold ≥ 2.0σ) | Per kategori, bulan ini vs baseline |
| `getRecommendations` | Rule-based scoring | Output anomali + rasio tabungan |

### Settings

- `GET /api/settings`
- `PATCH /api/settings`

Perilaku endpoint:

- mengembalikan preferensi aktif user login
- mengembalikan daftar `supported_locales` dan `supported_currencies`
- hanya field `locale` dan `currency` yang bisa diupdate

Contoh update:

```json
{
  "locale": "en",
  "currency": "USD"
}
```

Validasi penting:

- `locale`: salah satu dari `id`, `en`
- `currency`: salah satu dari `IDR`, `USD`, `EUR`, `SGD`, `MYR`
- payload kosong akan ditolak dengan `422`

### Notifications

- `GET /api/notifications`
- `PATCH /api/notifications/read-all`
- `PATCH /api/notifications/{id}/read`

Perilaku endpoint:

- semua endpoint hanya mengakses notifikasi milik user login
- list notifikasi diurutkan dari yang terbaru
- response list menyertakan `meta.total` dan `meta.unread_count`
- `read-all` harus dideklarasikan sebelum `{id}/read` di route agar tidak tertabrak parameter dinamis

Contoh response list:

```json
{
  "data": [
    {
      "id": 2,
      "title": "Report Ready",
      "message": "Laporan bulanan siap diunduh.",
      "type": "success",
      "is_read": false,
      "read_at": null,
      "created_at": "2026-05-07T00:42:51.000000Z"
    }
  ],
  "meta": {
    "total": 1,
    "unread_count": 1
  }
}
```

Contoh mark satu:

```json
{
  "message": "Notifikasi ditandai sudah dibaca."
}
```

Contoh mark semua:

```json
{
  "message": "Semua notifikasi ditandai sudah dibaca.",
  "updated_count": 1
}
```

### User Data & Account Management

- `GET /api/user/export-data`
- `DELETE /api/user/delete-account`

Perilaku endpoint:

- `export-data` mengembalikan seluruh data finansial user (akun, kategori, transaksi, budget) dalam satu file JSON.
- `delete-account` menghapus seluruh data user secara permanen.
- `delete-account` wajib menyertakan password user untuk validasi keamanan.
- Urutan penghapusan data internal untuk keamanan SQLite: `transactions` -> `accounts` -> `categories` -> `budgets` -> `notifications` -> `tokens` -> `users`.

Contoh delete account:

```json
{
  "password": "password-anda"
}
```

### Dashboard

- `GET /api/dashboard`
- `GET /api/dashboard/charts`

### Imports

- `POST /api/imports/csv`
- `GET /api/imports/{import}/status`
- `POST /api/imports/{import}/map`

### Reports

- `POST /api/reports`
- `GET /api/reports/{report}/status`
- `GET /api/reports/{report}/download`

## Seeder Default

Seeder kategori sistem:

```bash
php artisan db:seed --class=DefaultCategorySeeder
```

Seeder ini membuat kategori default seperti `Makanan & Minuman`, `Transportasi`, `Belanja`, `Gaji`, `Freelance`, dan lainnya dengan `user_id = null`.

## Dokumentasi Tes

Koleksi request manual:

- [transactions-api.http](D:/0. MataKuliah/Semester 4/RPL/finance-tracker/transactions-api.http)

Automated test:

- [AccountControllerTest.php](D:/0. MataKuliah/Semester 4/RPL/finance-tracker/tests/Feature/AccountControllerTest.php)
- [CategoryControllerTest.php](D:/0. MataKuliah/Semester 4/RPL/finance-tracker/tests/Feature/CategoryControllerTest.php)
- [NotificationControllerTest.php](D:/0. MataKuliah/Semester 4/RPL/finance-tracker/tests/Feature/NotificationControllerTest.php)
- [SettingsControllerTest.php](D:/0. MataKuliah/Semester 4/RPL/finance-tracker/tests/Feature/SettingsControllerTest.php)
- [TransactionControllerTest.php](D:/0. MataKuliah/Semester 4/RPL/finance-tracker/tests/Feature/TransactionControllerTest.php)
