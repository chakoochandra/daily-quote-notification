# Daily Quote Notification

Script PHP standalone untuk mengirim quote motivasi harian (Inggris + terjemahan Indonesia) secara otomatis ke WhatsApp lewat [DialogWA](https://dialogwa.com), dijadwalkan via cronjob.

## Fitur

- Kirim quote random harian ke satu atau banyak nomor/grup WhatsApp
- Bisa dijalankan manual untuk testing (`test`, `send`) maupun otomatis lewat cron
- Mendaftarkan jadwal cron langsung dari PHP, tanpa edit crontab manual
- Konfigurasi lewat `.env`, zero-dependency (gak butuh `composer install`)
- Bisa di-include ke framework lain (CodeIgniter, Laravel, dll)

## Requirement

- PHP 5.6+ (CLI)
- Akses ke `crontab` di server (untuk fitur `setup-cron`)
- Akun & token aktif di Whatsapp API Gateway [DialogWA](https://dialogwa.com)

## Instalasi

1. Clone/copy project ini ke server kamu.
2. Copy `_env.sample` menjadi `.env`:
   ```bash
   cp _env.sample .env
   ```
3. Edit `.env`, isi sesuai akun gateway kamu:
   ```env
   CRONJOB_TIME=30 6 * * *
   DIALOGWA_API_URL=https://dialogwa.com/api
   DIALOGWA_SESSION=nama-session-kamu
   DIALOGWA_TOKEN=token-api-kamu
   WA_TARGETS=6281234567890,6289876543210
   ```
   - `CRONJOB_TIME` — jadwal cron, format standar `menit jam tanggal bulan hari`
   - `WA_TARGETS` — daftar nomor/grup tujuan, dipisah koma (nomor grup pakai format `...@g.us`)
   - `SKIP_SSL_VERIFY=true` (opsional) — lewati verifikasi SSL, dipakai kalau testing di environment dengan self-signed certificate. Jangan diaktifkan di production.
4. (Opsional) Edit `quotes.json` untuk menambah/mengganti koleksi quote. Formatnya:
   ```json
   [
     { "en": "Quote dalam Bahasa Inggris", "id": "Terjemahan Bahasa Indonesia" }
   ]
   ```

## Penggunaan

| Command | Keterangan |
|---|---|
| `php index.php test` | Kirim quote test ke semua `WA_TARGETS`, tampilkan hasil di terminal |
| `php index.php send` | Kirim quote sekali (dipakai juga oleh cronjob) |
| `php index.php setup-cron` | Daftarkan/perbarui jadwal cron sesuai `CRONJOB_TIME` di `.env` |
| `php index.php list-cron` | Tampilkan isi crontab saat ini |

Urutan setup yang disarankan:

```bash
php index.php test          # pastikan gateway & target sudah benar
php index.php setup-cron    # daftarkan jadwal otomatis
php index.php list-cron     # verifikasi entry cron sudah masuk
```

### Integrasi ke framework lain

```php
require_once 'index.php';
(new QuoteSender())->sendDaily();
```

## Struktur Project

```
quote-bot/
├── index.php            # logic utama + CLI handler
├── DialogWaGateway.php  # class komunikasi ke WhatsApp gateway
├── .env                 # konfigurasi & credential (jangan commit!)
├── _env.sample          # contoh isi .env
└── quotes.json          # koleksi quote
```

## Keamanan

- **Jangan commit file `.env`** — pastikan sudah masuk `.gitignore`. File ini menyimpan token API gateway WhatsApp kamu.
- Jika `index.php` diakses lewat web (bukan CLI), script hanya menampilkan pesan debug dan tidak mengirim apapun. Tetap disarankan membatasi akses publik ke file ini di server production.
- Gunakan `SKIP_SSL_VERIFY` hanya di environment development/staging.

## Lisensi

Bebas digunakan dan dimodifikasi untuk keperluan pribadi maupun komersial.
