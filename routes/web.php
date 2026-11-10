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
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Penerima\CampaignController as PenerimaCampaignController;
use App\Http\Controllers\Penerima\CampaignFormController;
use App\Http\Controllers\Penerima\ChatController as PenerimaChatController;
use App\Http\Controllers\Penerima\ChatDetailController as PenerimaChatDetailController;
use App\Http\Controllers\Penerima\DashboardController as PenerimaDashboardController;
use App\Http\Controllers\Penerima\DonasiMasukController;
use App\Http\Controllers\Penerima\LaporanController;
use App\Http\Controllers\Penerima\SettingsController as PenerimaSettingsController;
use Illuminate\Support\Facades\Route;

// halaman utama
Route::get('/', [HomeController::class, 'index'])->name('home');

// autentikasi
Route::prefix('auth')->group(function () {
    Route::get('register', [RegisterDonaturController::class, 'index'])->name('register');
    Route::post('register', [RegisterDonaturController::class, 'store']);

    Route::get('register-mitra', [RegisterMitraController::class, 'index'])->name('register.mitra');
    Route::post('register-mitra', [RegisterMitraController::class, 'store'])->name('register.mitra.store');

    Route::get('login', [LoginController::class, 'index'])->name('login');
    Route::post('login', [LoginController::class, 'doLogin']);
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('lupa-password', [ForgotPasswordController::class, 'index'])->name('password.request');
    Route::post('lupa-password', [ForgotPasswordController::class, 'kirimLink']);

    Route::get('reset-password/{token}', [ResetPasswordController::class, 'index'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'simpan'])->name('password.update');
});

// modul donatur
Route::prefix('donatur')->middleware(['CekRole:donatur'])->name('donatur.')->group(function () {
    Route::get('beranda', [BerandaController::class, 'index'])->name('beranda');
    Route::get('campaign/{campaign:slug}', [DonaturCampaignController::class, 'index'])->name('campaign.show');

    Route::get('campaign/{campaign:slug}/laporan/{report}', [DonaturCampaignController::class, 'showReport'])
        ->name('campaign.report.show');

    Route::get('campaign/{campaign:slug}/laporan-pdf', [DonaturCampaignController::class, 'exportLaporan'])
        ->name('campaign.laporan-pdf');
    Route::get('donasi/{campaign:slug}/uang', [DonasiUangController::class, 'index'])->name('donasi.uang');
    Route::post('donasi/{campaign:slug}/uang', [DonasiUangController::class, 'store']);

    Route::get('donasi/{campaign:slug}/barang', [DonasiBarangController::class, 'index'])->name('donasi.barang');
    Route::post('donasi/{campaign:slug}/barang', [DonasiBarangController::class, 'store']);

    Route::get('riwayat', [RiwayatController::class, 'index'])->name('riwayat');

    Route::get('chat', [DonaturChatController::class, 'index'])->name('chat');
    Route::get('chat/{conversation}', [DonaturChatDetailController::class, 'index'])->name('chat.show');
    Route::post('chat/{conversation}/kirim', [DonaturChatDetailController::class, 'kirimPesan']);

    Route::get('settings', [DonaturSettingsController::class, 'index'])->name('settings');
    Route::put('settings', [DonaturSettingsController::class, 'update']);
    Route::put('settings/donasi-rutin/{donation}/pause', [DonaturSettingsController::class, 'pauseRutin']);
    Route::delete('settings/donasi-rutin/{donation}', [DonaturSettingsController::class, 'batalkanRutin']);
});

// modul penerima (mitra)
Route::prefix('mitra')->middleware(['CekRole:penerima'])->name('mitra.')->group(function () {
    Route::get('dashboard', [PenerimaDashboardController::class, 'index'])->name('dashboard');

    Route::get('campaign', [PenerimaCampaignController::class, 'index'])->name('campaign.index');
    Route::get('campaign/tambah', [CampaignFormController::class, 'index'])->name('campaign.create');
    Route::post('campaign', [CampaignFormController::class, 'store'])->name('campaign.store');
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
    Route::post('chat/{conversation}/kirim', [PenerimaChatDetailController::class, 'kirimPesan']);

    Route::get('settings', [PenerimaSettingsController::class, 'index'])->name('settings');
    Route::put('settings', [PenerimaSettingsController::class, 'update']);
});

// modul admin
Route::prefix('admin')->middleware(['CekRole:admin'])->name('admin.')->group(function () {
    Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('verifikasi-mitra', [VerifikasiMitraController::class, 'index'])->name('verifikasi-mitra');
    Route::put('verifikasi-mitra/{user}/approve', [VerifikasiMitraController::class, 'approve']);
    Route::put('verifikasi-mitra/{user}/reject', [VerifikasiMitraController::class, 'reject']);
    Route::get('verifikasi-mitra/dokumen', [VerifikasiMitraController::class, 'download'])->name('verifikasi-mitra.dokumen');

    Route::get('approve-campaign', [ApproveCampaignController::class, 'index'])->name('approve-campaign');
    Route::put('approve-campaign/{campaign}/approve', [ApproveCampaignController::class, 'approve']);
    Route::put('approve-campaign/{campaign}/reject', [ApproveCampaignController::class, 'reject']);

    Route::get('kategori', [KategoriController::class, 'index'])->name('kategori');
    Route::post('kategori/campaign', [KategoriController::class, 'storeCampaignKategori']);
    Route::delete('kategori/campaign/{category}', [KategoriController::class, 'destroyCampaignKategori']);
    Route::post('kategori/barang', [KategoriController::class, 'storeItemKategori']);
    Route::delete('kategori/barang/{itemCategory}', [KategoriController::class, 'destroyItemKategori']);

    Route::get('monitoring-donasi', [MonitoringDonasiController::class, 'index'])->name('monitoring-donasi');

    Route::get('user', [AdminUserController::class, 'index'])->name('user');
    Route::delete('user/{user}', [AdminUserController::class, 'suspend']);

    Route::get('broadcast', [BroadcastController::class, 'index'])->name('broadcast');
    Route::post('broadcast', [BroadcastController::class, 'store']);

    Route::get('settings', [AdminSettingsController::class, 'index'])->name('settings');
    Route::put('settings', [AdminSettingsController::class, 'update']);
});
