    @extends('layout.penerima')

    @section('judul', 'Laporan Penggunaan')

    @section('konten')

        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Please check the following fields:</strong>
                <ul class="mb-0 mt-2">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <h5 class="fw-bold mb-4">Laporan Penggunaan &mdash; {{ $campaign->judul ?? '' }}</h5>

        @if ($campaigns->isNotEmpty())
            <div class="wc-card p-4 w-100">
                <form method="GET" action="{{ route('mitra.laporan') }}">
                    <div class="row align-items-end g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Pilih Campaign</label>
                            <select name="campaign_id" class="form-select">
                                @foreach ($campaigns as $item)
                                    <option value="{{ $item->id }}"
                                        {{ $campaign && $campaign->id == $item->id ? 'selected' : '' }}>
                                        {{ $item->judul }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="wc-btn-primary w-100">
                                Lihat Laporan
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        @endif
        <div class="row g-4" style="margin-top: 0.3rem;">
            <div class="col-lg-5">
                @if ($campaign)
                    <div class="wc-card p-4 w-100">
                        <h6 class="fw-bold mb-3">Tambah Update Laporan</h6>

                        <form method="POST" action="{{ route('mitra.laporan') }}" enctype="multipart/form-data">
                            @csrf

                            <input type="hidden" name="campaign_id" value="{{ $campaign->id }}">

                            <div class="mb-3">
                                <label class="form-label">Judul Update</label>
                                <input type="text" name="judul" class="form-control" value="{{ old('judul') }}"
                                    required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Isi Laporan</label>
                                <textarea name="isi" class="form-control" rows="4" required>{{ old('isi') }}</textarea>
                            </div>

                            {{-- PDF Document --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Dokumen Bukti (PDF)</label>
                                <input type="file" name="dokumen_bukti" class="form-control"
                                    accept=".pdf,application/pdf">
                                <small class="text-muted">
                                    Upload invoices, receipts, or distribution reports. Maximum size: 10 MB.
                                </small>
                            </div>

                            {{-- Supporting Photos --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Foto Bukti</label>
                                <input type="file" name="foto_bukti[]" class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp" multiple>
                                <small class="text-muted">
                                    You can upload multiple photos. JPG, PNG, or WEBP. Maximum size: 2 MB per photo.
                                </small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nominal Terpakai (Opsional)</label>
                                <input type="number" name="nominal_terpakai" class="form-control" placeholder="Rp"
                                    min="0" step="0.01" value="{{ old('nominal_terpakai') }}">
                            </div>

                            <button type="submit" class="wc-btn-primary w-100">
                                Publikasikan Update
                            </button>
                        </form>
                    </div>
                @else
                    <div class="wc-card p-4">
                        <h6 class="fw-bold mb-2">Belum Ada Campaign</h6>
                        <p class="small text-secondary mb-3">
                            You need to create a campaign before publishing a usage report.
                        </p>
                        <a href="{{ route('mitra.campaign.create') }}" class="wc-btn-primary">
                            Buat Campaign
                        </a>
                    </div>
                @endif
            </div>


            <div class="col-lg-7">
                <div class="wc-card p-4">
                    <h6 class="fw-bold mb-3">Riwayat Update</h6>

                    @forelse ($laporan as $update)
                        <a href="{{ route('mitra.laporan.show', $update->id) }}" class="text-decoration-none text-dark">

                            <div class="border-bottom pb-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <p class="fw-semibold mb-1">
                                        {{ $update->judul }}
                                    </p>

                                    <i class="bi bi-chevron-right text-muted"></i>
                                </div>

                                <p class="small text-muted mb-2">
                                    <i class="bi bi-calendar me-1"></i>
                                    {{ $update->created_at->format('d M Y, H:i') }}
                                </p>

                                <p class="small text-secondary mb-1">
                                    {{ \Illuminate\Support\Str::limit($update->isi, 120) }}
                                </p>

                                @if ($update->dokumen_bukti || $update->Photos->isNotEmpty() || $update->foto_bukti)
                                    <span class="small text-primary">
                                        <i class="bi bi-paperclip me-1"></i>
                                        Supporting attachments available
                                    </span>
                                @endif

                                @if ($update->nominal_terpakai)
                                    <p class="small mb-0 mt-2">
                                        Amount spent:
                                        Rp{{ number_format($update->nominal_terpakai, 0, ',', '.') }}
                                    </p>
                                @endif
                            </div>
                        </a>
                    @empty
                        <p class="small text-secondary mb-0">
                            Belum ada update laporan untuk campaign ini.
                        </p>
                    @endforelse
                </div>
            </div>

        </div>

    @endsection
