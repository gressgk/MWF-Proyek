@extends('layout.donatur')

@section('judul', $campaign->judul . ' - WeCare')

@section('konten')

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="wc-card overflow-hidden mb-4">
                <div style="height: 260px; background: linear-gradient(135deg, #FBDCD3, #FDEFD9);" class="d-flex align-items-center justify-content-center">
                    <i class="bi bi-heart-fill text-white" style="font-size: 4rem;"></i>
                </div>
                <div class="p-4">
                    <div class="d-flex gap-2 mb-3">
                        <span class="wc-badge wc-badge-{{ $campaign->urgensi }}">{{ ucfirst($campaign->urgensi) }}</span>
                        <span class="wc-badge wc-badge-biasa">{{ $campaign->Category->nama ?? $campaign->kategori_lainnya }}</span>
                    </div>
                    <h3 class="fw-bold mb-2">{{ $campaign->judul }}</h3>
                    <p class="text-secondary mb-4">
                        oleh <strong>{{ $campaign->Penerima->PenerimaProfile->nama_organisasi ?? $campaign->Penerima->name }}</strong>
                        &middot; berlangsung sampai {{ \Illuminate\Support\Carbon::parse($campaign->tanggal_selesai)->translatedFormat('d F Y') }}
                    </p>
                    <p style="white-space: pre-line;">{{ $campaign->deskripsi }}</p>
                </div>
            </div>

            <div class="wc-card p-4 mb-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-file-earmark-text me-2"></i>Update Laporan Penggunaan</h5>
                @forelse ($campaign->Reports as $laporan)
                    <div class="border-bottom pb-3 mb-3">
                        <p class="fw-semibold mb-1">{{ $laporan->judul }}</p>
                        <p class="small text-secondary mb-0">{{ $laporan->isi }}</p>
                    </div>
                @empty
                    <p class="text-secondary small mb-0">Belum ada update laporan dari penerima.</p>
                @endforelse
            </div>

            <div class="wc-card p-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-people me-2"></i>Donatur Terbaru</h5>
                @forelse ($campaign->Donations->take(6) as $donasi)
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                        <span>{{ $donasi->nama_tampil }}</span>
                        <span class="small text-secondary">
                            @if ($donasi->jenis == 'uang')
                                Rp{{ number_format($donasi->nominal, 0, ',', '.') }}
                            @else
                                {{ $donasi->jumlah_barang }} {{ $donasi->satuan_barang }} {{ $donasi->nama_barang }}
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="text-secondary small mb-0">Jadilah donatur pertama di campaign ini.</p>
                @endforelse
            </div>
        </div>

        <div class="col-lg-4">
            <div class="wc-card p-4 mb-3" style="position: sticky; top: 90px;">
                @if ($campaign->tipe_bantuan != 'barang')
                    <div class="wc-progress mb-2"><div style="width: {{ $campaign->progres_persen }}%"></div></div>
                    <p class="mb-1"><strong>Rp{{ number_format($campaign->terkumpul_nominal, 0, ',', '.') }}</strong></p>
                    <p class="small text-secondary mb-4">terkumpul dari target Rp{{ number_format($campaign->target_nominal, 0, ',', '.') }}</p>
                @else
                    <div class="wc-progress mb-2"><div style="width: {{ $campaign->progres_persen }}%"></div></div>
                    <p class="mb-1"><strong>{{ $campaign->terkumpul_barang }} {{ $campaign->satuan_barang }}</strong></p>
                    <p class="small text-secondary mb-4">terkumpul dari target {{ $campaign->target_barang }} {{ $campaign->satuan_barang }}</p>
                @endif

                <div class="d-grid gap-2">
                    @if (in_array($campaign->tipe_bantuan, ['uang', 'keduanya']))
                        <a href="{{ route('donatur.donasi.uang', $campaign->slug) }}" class="wc-btn-primary">
                            <i class="bi bi-cash-coin me-1"></i> Donasi Uang
                        </a>
                    @endif
                    @if (in_array($campaign->tipe_bantuan, ['barang', 'keduanya']))
                        <a href="{{ route('donatur.donasi.barang', $campaign->slug) }}" class="wc-btn-outline">
                            <i class="bi bi-box-seam me-1"></i> Donasi Barang
                        </a>
                    @endif
                    <a href="{{ route('donatur.chat') }}" class="btn btn-light border">
                        <i class="bi bi-chat-dots me-1"></i> Chat dengan Penerima
                    </a>
                    <a href="{{ route('donatur.campaign.laporan-pdf', $campaign->slug) }}" class="btn btn-light border">
                        <i class="bi bi-file-earmark-pdf me-1"></i> Export Laporan PDF
                    </a>
                </div>
            </div>

            <div class="wc-card p-4 text-center">
                <p class="small fw-semibold mb-2">QR Code Campaign</p>
                <div class="border rounded p-3 d-inline-block bg-white">
                    <i class="bi bi-qr-code" style="font-size: 4rem;"></i>
                </div>
                <p class="small text-secondary mt-2 mb-0">Scan untuk share campaign ini</p>
            </div>
        </div>
    </div>

@endsection
