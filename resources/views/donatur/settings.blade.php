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
            <div class="wc-card p-4 mb-4">
                <h6 class="fw-bold mb-3">Donasi Rutin Aktif</h6>
                @forelse ($donasiRutin as $rutin)
                    <div class="border-bottom pb-3 mb-3">
                        <p class="fw-semibold mb-0">{{ $rutin->Campaign->judul }}</p>
                        <p class="small text-secondary mb-2">Rp{{ number_format($rutin->nominal, 0, ',', '.') }} &middot;
                            {{ ucfirst($rutin->frequency) }}</p>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm wc-btn-outline">Pause</button>
                            <button class="btn btn-sm btn-outline-danger">Batalkan</button>
                        </div>
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Belum ada donasi rutin yang aktif.</p>
                @endforelse
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

@endsection
