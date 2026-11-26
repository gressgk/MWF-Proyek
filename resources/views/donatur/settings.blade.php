@extends('layout.donatur')

@section('judul', 'Settings - WeCare')

@section('konten')

    <h4 class="fw-bold mb-4">Settings</h4>

    @if (session('pesan'))
        <div class="alert alert-info">
            {{ session('pesan') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="row g-4">

        <div class="col-lg-7">
            <div class="wc-card p-4 mb-4">
                <h6 class="fw-bold mb-3">Edit Profil</h6>

                <form method="POST" action="{{ route('donatur.settings') }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">
                            Nama Lengkap
                        </label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}"
                            maxlength="255" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">
                            No. HP
                        </label>
                        <input type="text" name="phone" class="form-control" value="{{ old('phone', $user->phone) }}"
                            maxlength="20">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold small">
                            Foto Profil
                        </label>

                        @if ($user->avatar)
                            <div class="mb-3">
                                <img src="{{ Storage::disk('public')->url($user->avatar) }}" alt="Foto Profil"
                                    class="rounded-circle" style="width: 90px; height: 90px; object-fit: cover;">
                            </div>
                        @endif

                        <input type="file" name="avatar" class="form-control" accept=".jpg,.jpeg,.png,.webp">

                        <small class="text-secondary">
                            Format JPG, PNG, atau WebP. Maksimal 2 MB.
                        </small>
                    </div>

                    <button type="submit" class="wc-btn-primary">
                        Simpan Perubahan
                    </button>
                </form>
            </div>

            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Ganti Password</h6>

                <form method="POST" action="{{ route('donatur.settings.password') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <input type="password" name="password_lama" class="form-control" placeholder="Password lama"
                            autocomplete="current-password" required>
                    </div>

                    <div class="mb-3">
                        <input type="password" name="password" class="form-control" placeholder="Password baru"
                            autocomplete="new-password" minlength="8" required>
                    </div>

                    <div class="mb-4">
                        <input type="password" name="password_confirmation" class="form-control"
                            placeholder="Konfirmasi password baru" autocomplete="new-password" minlength="8" required>
                    </div>

                    <button type="submit" class="wc-btn-outline">
                        Ganti Password
                    </button>
                </form>
            </div>
        </div>


        <div class="col-lg-5">
            <div class="wc-card p-4 mb-4" id="donasiRutinCard">
                <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                    <h6 class="fw-bold mb-0">Donasi Rutin</h6>
                    <button type="button" class="btn btn-sm wc-btn-outline" id="refreshSubscriptionsBtn">
                        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Muat Ulang
                    </button>
                </div>

                <div id="subscriptionFeedback" class="alert d-none small mb-3" role="status" aria-live="polite"></div>

                <div id="subscriptionList">
                    @forelse ($subscriptionItems as $item)
                        @php
                            $localStatus = strtolower(trim((string) ($item['status'] ?? '')));
                            $mgmtState = $item['management_state'] ?? null;
                            $opInProgress = !empty($item['operation_in_progress']);
                            $sched = is_array($item['schedule'] ?? null) ? $item['schedule'] : [];
                            $schedStatus = $sched['status'] ?? 'unavailable';

                            if ($mgmtState === 'cancelled' || $localStatus === 'cancelled') {
                                $uiLabel = 'Dibatalkan';
                                $badgeClass = 'wc-badge wc-badge-cancelled';
                                $actions = [];
                                $scheduleText = 'Tidak ada penagihan otomatis berikutnya.';
                            } elseif ($mgmtState === 'reconciliation') {
                                $uiLabel = 'Menunggu Verifikasi';
                                $badgeClass = 'wc-badge wc-badge-biasa';
                                $actions = ['reconcile'];
                                $scheduleText = 'Jadwal menunggu verifikasi.';
                            } elseif ($localStatus === 'inactive' && $mgmtState === 'paused') {
                                $uiLabel = 'Dijeda';
                                $badgeClass = 'wc-badge wc-badge-paused';
                                $actions = ['resume', 'cancel'];
                                $scheduleText = 'Donasi rutin sedang dijeda.';
                            } elseif ($localStatus === 'active' && in_array($mgmtState, [null, 'active'], true)) {
                                $uiLabel = 'Aktif';
                                $badgeClass = 'wc-badge wc-badge-terjadwal';
                                $actions = ['pause', 'cancel'];
                                if ($schedStatus === 'confirmed' && !empty($sched['is_displayable_upcoming']) && !empty($sched['display_wib'])) {
                                    $scheduleText = 'Jadwal terakhir tercatat: ' . $sched['display_wib'];
                                } elseif ($schedStatus === 'historical_unverified') {
                                    $scheduleText = 'Jadwal berikutnya belum terverifikasi.';
                                } elseif (in_array($schedStatus, ['past_awaiting_refresh', 'stale_preserved', 'awaiting_verification'], true)) {
                                    $scheduleText = 'Jadwal menunggu verifikasi.';
                                } else {
                                    $scheduleText = 'Jadwal belum dapat dipastikan.';
                                }
                            } elseif ($localStatus === 'inactive' && $mgmtState === null) {
                                $uiLabel = 'Status Belum Dipastikan';
                                $badgeClass = 'wc-badge wc-badge-neutral';
                                $actions = ['reconcile'];
                                $scheduleText = 'Jadwal belum dapat dipastikan.';
                            } else {
                                $uiLabel = 'Status Belum Dipastikan';
                                $badgeClass = 'wc-badge wc-badge-neutral';
                                $actions = [];
                                $scheduleText = 'Jadwal belum dapat dipastikan.';
                            }
                        @endphp
                        <div class="wc-sub-item border-bottom pb-3 mb-3" data-subscription-id="{{ $item['id'] }}">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                <p class="fw-semibold mb-0">{{ $item['campaign_title'] }}</p>
                                <span class="{{ $badgeClass }} text-nowrap" data-role="status-badge">{{ $uiLabel }}</span>
                            </div>
                            <p class="small text-secondary mb-1">
                                {{ $item['nominal_formatted'] }} &middot; {{ $item['frequency_label'] }}
                            </p>
                            <p class="small text-secondary mb-2" data-role="schedule-text">{{ $scheduleText }}</p>

                            @if ($opInProgress)
                                <p class="small wc-notice-warning mb-2" data-role="guard-notice">Operasi lain sedang diproses di server. Gunakan Muat Ulang untuk memeriksa kembali.</p>
                            @endif

                            @if (!empty($actions))
                                <div class="d-flex flex-wrap gap-2" data-role="actions">
                                    @if (in_array('pause', $actions, true))
                                        <button type="button" class="btn btn-sm wc-btn-outline" data-sub-action="pause" data-subscription-id="{{ $item['id'] }}" @if($opInProgress) disabled @endif>
                                            Jeda
                                        </button>
                                    @endif
                                    @if (in_array('resume', $actions, true))
                                        <button type="button" class="btn btn-sm wc-btn-primary" data-sub-action="resume" data-subscription-id="{{ $item['id'] }}" @if($opInProgress) disabled @endif>
                                            Lanjutkan
                                        </button>
                                    @endif
                                    @if (in_array('reconcile', $actions, true))
                                        <button type="button" class="btn btn-sm wc-btn-outline" data-sub-action="reconcile" data-subscription-id="{{ $item['id'] }}" @if($opInProgress) disabled @endif>
                                            Periksa Status
                                        </button>
                                    @endif
                                    @if (in_array('cancel', $actions, true))
                                        <button type="button" class="btn btn-sm wc-btn-danger-outline" data-sub-action="cancel" data-subscription-id="{{ $item['id'] }}" @if($opInProgress) disabled @endif>
                                            Batalkan
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="small text-secondary mb-0" data-role="empty-state">Belum ada donasi rutin terdaftar.</p>
                    @endforelse
                </div>
            </div>

            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Preferensi Notifikasi</h6>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" checked>
                    <label class="form-check-label small">Email saat status donasi berubah</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" checked>
                    <label class="form-check-label small">Email pengingat donasi rutin</label>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="subscriptionConfirmModal" tabindex="-1" aria-labelledby="subscriptionConfirmTitle" aria-describedby="subscriptionConfirmBody" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 p-3">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold" id="subscriptionConfirmTitle">Konfirmasi Aksi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" id="subscriptionConfirmBody"></p>
                    <p class="small text-secondary mb-0" id="subscriptionConfirmContext"></p>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="wc-btn-outline" data-bs-dismiss="modal" id="subscriptionConfirmCancelBtn">Kembali</button>
                    <button type="button" class="wc-btn-primary" id="subscriptionConfirmProceedBtn">Konfirmasi</button>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="subscriptionInitialData">@json(['items' => $subscriptionItems, 'indexUrl' => $subscriptionsIndexUrl])</script>

@endsection

@push('scripts')
    <script src="{{ asset('js/donatur-subscriptions.js') }}"></script>
@endpush
