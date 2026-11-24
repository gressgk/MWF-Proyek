<?php

use App\Http\Controllers\Admin\ApproveCampaignController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\KategoriController;
use App\Http\Controllers\Admin\MonitoringDonasiController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\VerifikasiMitraController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterDonaturController;
use App\Http\Controllers\Auth\RegisterMitraController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Donatur\BerandaController;
use App\Http\Controllers\Donatur\CampaignController as DonaturCampaignController;
use App\Http\Controllers\Donatur\ChatController as DonaturChatController;
use App\Http\Controllers\Donatur\ChatDetailController as DonaturChatDetailController;
use App\Http\Controllers\Donatur\DonasiBarangController;
use App\Http\Controllers\Donatur\DonasiUangController;
use App\Http\Controllers\Donatur\RiwayatController;
use App\Http\Controllers\Donatur\SettingsController as DonaturSettingsController;
use App\Http\Controllers\Donatur\SubscriptionManagementController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MidtransWebhookController;
use App\Http\Controllers\MidtransRecurringWebhookController;
use App\Http\Controllers\Penerima\CampaignController as PenerimaCampaignController;
use App\Http\Controllers\Penerima\CampaignFormController;
use App\Http\Controllers\Penerima\ChatController as PenerimaChatController;
use App\Http\Controllers\Penerima\ChatDetailController as PenerimaChatDetailController;
use App\Http\Controllers\Penerima\DashboardController as PenerimaDashboardController;
use App\Http\Controllers\Penerima\DonasiMasukController;
use App\Http\Controllers\Penerima\LaporanController;
use App\Http\Controllers\Penerima\SettingsController as PenerimaSettingsController;
use App\Http\Middleware\CekMitraApproved;
use Illuminate\Support\Facades\Route;

// -------------------------------------------------------------------------
// Webhook Midtrans — CSRF dikecualikan via bootstrap/app.php untuk path ini.
// Endpoint ini menerima POST dari server Midtrans, bukan dari browser Donatur.
// Tidak memerlukan autentikasi user.
// -------------------------------------------------------------------------
Route::post('midtrans/webhook', [MidtransWebhookController::class, 'handle'])
    ->name('midtrans.webhook');

// Webhook siklus Donasi Rutin (Subscription API) — CSRF juga dikecualikan.
// Midtrans mengirim notifikasi per siklus pembayaran berulang ke endpoint ini.
Route::post('midtrans/webhook/subscription', [MidtransRecurringWebhookController::class, 'handle'])
    ->name('midtrans.webhook.subscription');

// halaman utama
Route::get('/', [HomeController::class, 'index'])->name('home');

// autentikasi
Route::prefix('auth')->group(function () {
    Route::get('register', [RegisterDonaturController::class, 'index'])->name('register');
    Route::post('register', [RegisterDonaturController::class, 'store']);

    Route::get('register-mitra', [RegisterMitraController::class, 'index'])->name('register.mitra');
    Route::post('register-mitra/akun', [RegisterMitraController::class, 'simpanAkun'])->name('register.mitra.akun');
    Route::post('register-mitra', [RegisterMitraController::class, 'store'])->name('register.mitra.store');

    Route::get('login', [LoginController::class, 'index'])->name('login');
    Route::post('login', [LoginController::class, 'doLogin']);
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('lupa-password', [ForgotPasswordController::class, 'index'])->name('password.request');
    Route::post('lupa-password', [ForgotPasswordController::class, 'kirimLink']);

    Route::get('reset-password/{token}', [ResetPasswordController::class, 'index'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'simpan'])->name('password.update');
});

// Public campaign pages (no login required)
Route::prefix('donatur')->name('donatur.')->group(function () {
    Route::get('campaign/{campaign:slug}', [DonaturCampaignController::class, 'index'])
        ->name('campaign.show');

    Route::get('campaign/{campaign:slug}/laporan/{report}', [DonaturCampaignController::class, 'showReport'])
        ->name('campaign.report.show');

    Route::get('campaign/{campaign:slug}/laporan-pdf', [DonaturCampaignController::class, 'exportLaporan'])
        ->name('campaign.laporan-pdf');
});

// modul donatur
Route::prefix('donatur')->middleware(['CekRole:donatur'])->name('donatur.')->group(function () {
    Route::get('beranda', [BerandaController::class, 'index'])->name('beranda');
    // Route::get('campaign/{campaign:slug}', [DonaturCampaignController::class, 'index'])->name('campaign.show');

    // Route::get('campaign/{campaign:slug}/laporan/{report}', [DonaturCampaignController::class, 'showReport'])
    //     ->name('campaign.report.show');

    // Route::get('campaign/{campaign:slug}/laporan-pdf', [DonaturCampaignController::class, 'exportLaporan'])
    //     ->name('campaign.laporan-pdf');

    # Biar bisa lihat sebelum login
    Route::get('donasi/{campaign:slug}/uang', [DonasiUangController::class, 'index'])->name('donasi.uang');
    Route::post('donasi/{campaign:slug}/uang', [DonasiUangController::class, 'store']);
    Route::post('donasi/verify-activation/{orderId}', [DonasiUangController::class, 'verifyActivation'])->name('donasi.verify-activation');

    Route::get('donasi/{campaign:slug}/barang', [DonasiBarangController::class, 'index'])->name('donasi.barang');
    Route::post('donasi/{campaign:slug}/barang', [DonasiBarangController::class, 'store']);

    Route::get('riwayat', [RiwayatController::class, 'index'])->name('riwayat');

    Route::get('chat', [DonaturChatController::class, 'index'])->name('chat');
    Route::post('campaign/{campaign:slug}/chat', [DonaturChatController::class, 'mulai'])->middleware('throttle:20,1')->name('chat.start');
    Route::get('chat/{conversation}', [DonaturChatDetailController::class, 'index'])->name('chat.show');
    Route::get('chat/{conversation}/pesan', [DonaturChatDetailController::class, 'pesan'])->name('chat.pesan');
    Route::post('chat/{conversation}/kirim', [DonaturChatDetailController::class, 'kirimPesan'])->middleware('throttle:20,1')->name('chat.kirim');

    Route::get('settings', [DonaturSettingsController::class, 'index'])->name('settings');
    Route::get('subscriptions', [SubscriptionManagementController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions/{subscription}/pause', [SubscriptionManagementController::class, 'pause'])->name('subscriptions.pause');
    Route::post('subscriptions/{subscription}/resume', [SubscriptionManagementController::class, 'resume'])->name('subscriptions.resume');
    Route::post('subscriptions/{subscription}/cancel', [SubscriptionManagementController::class, 'cancel'])->name('subscriptions.cancel');
    Route::post('subscriptions/{subscription}/reconcile', [SubscriptionManagementController::class, 'reconcile'])->name('subscriptions.reconcile');
});

// modul penerima (mitra)
Route::prefix('mitra')->middleware(['CekRole:penerima'])->name('mitra.')->group(function () {
    Route::get('dashboard', [PenerimaDashboardController::class, 'index'])->name('dashboard');

    Route::get('campaign', [PenerimaCampaignController::class, 'index'])->name('campaign.index');
    Route::get('campaign/tambah', [CampaignFormController::class, 'index'])->middleware(CekMitraApproved::class)->name('campaign.create');
    Route::post('campaign', [CampaignFormController::class, 'store'])->middleware(CekMitraApproved::class)->name('campaign.store');
    Route::get('campaign/{campaign}/edit', [CampaignFormController::class, 'edit'])->name('campaign.edit');
    Route::put('campaign/{campaign}', [CampaignFormController::class, 'update'])->name('campaign.update');
    Route::delete('campaign/{campaign}', [CampaignFormController::class, 'destroy'])->name('campaign.destroy');

    Route::get('donasi-masuk', [DonasiMasukController::class, 'index'])->name('donasi-masuk');
    Route::put('donasi-masuk/{donation}/konfirmasi', [DonasiMasukController::class, 'konfirmasiDiterima']);

    Route::get('laporan', [LaporanController::class, 'index'])->name('laporan');
    Route::post('laporan', [LaporanController::class, 'store']);
    Route::get('laporan/{report}', [LaporanController::class, 'show'])
        ->name('laporan.show');

    Route::get('chat', [PenerimaChatController::class, 'index'])->name('chat');
    Route::get('chat/{conversation}', [PenerimaChatDetailController::class, 'index'])->name('chat.show');
    Route::get('chat/{conversation}/pesan', [PenerimaChatDetailController::class, 'pesan'])->name('chat.pesan');
    Route::post('chat/{conversation}/kirim', [PenerimaChatDetailController::class, 'kirimPesan'])->middleware('throttle:20,1')->name('chat.kirim');

    Route::get('settings', [PenerimaSettingsController::class, 'index'])->name('settings');
    Route::put('settings', [PenerimaSettingsController::class, 'update'])->name('settings.update');
    Route::get('settings/dokumen-legalitas', [PenerimaSettingsController::class, 'previewLegalitas'])
        ->name('settings.preview-legalitas');
});

// modul admin
Route::prefix('admin')->middleware(['CekRole:admin'])->name('admin.')->group(function () {
    Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('verifikasi-mitra', [VerifikasiMitraController::class, 'index'])->name('verifikasi-mitra');
    Route::put('verifikasi-mitra/{user}/approve', [VerifikasiMitraController::class, 'approve'])
        ->name('verifikasi-mitra.approve');
    Route::put('verifikasi-mitra/{user}/reject', [VerifikasiMitraController::class, 'reject'])
        ->name('verifikasi-mitra.reject');
    Route::get('verifikasi-mitra/dokumen', [VerifikasiMitraController::class, 'download'])->name('verifikasi-mitra.dokumen');

    Route::get('approve-campaign', [ApproveCampaignController::class, 'index'])->name('approve-campaign');
    Route::put('approve-campaign/{campaign}/approve', [ApproveCampaignController::class, 'approve']);
    Route::put('approve-campaign/{campaign}/reject', [ApproveCampaignController::class, 'reject']);

    Route::get('kategori', [KategoriController::class, 'index'])->name('kategori');
    Route::post('kategori/campaign', [KategoriController::class, 'storeCampaignKategori']);
    Route::put('kategori/campaign/{category}', [KategoriController::class, 'updateCampaignKategori']);
    Route::delete('kategori/campaign/{category}', [KategoriController::class, 'destroyCampaignKategori']);

    Route::post('kategori/barang', [KategoriController::class, 'storeItemKategori']);
    Route::put('kategori/barang/{itemCategory}', [KategoriController::class, 'updateItemKategori']);
    Route::delete('kategori/barang/{itemCategory}', [KategoriController::class, 'destroyItemKategori']);
    Route::get('monitoring-donasi', [MonitoringDonasiController::class, 'index'])->name('monitoring-donasi');

    Route::get('user', [AdminUserController::class, 'index'])->name('user');
    Route::delete('user/{user}', [AdminUserController::class, 'suspend']);

    Route::get('broadcast', [BroadcastController::class, 'index'])->name('broadcast');
    Route::post('broadcast', [BroadcastController::class, 'store']);

    Route::get('settings', [AdminSettingsController::class, 'index'])->name('settings');
    Route::put('settings', [AdminSettingsController::class, 'update']);
});
