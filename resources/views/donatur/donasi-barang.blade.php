@extends('layout.donatur')

@section('judul', 'Donasi Barang - WeCare')

@section('konten')

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="wc-card p-4 p-md-5">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <img src="{{ asset('img/mascot/duck-box.png') }}" style="height: 60px;" alt="Maskot WeCare">
                    <div>
                        <h5 class="fw-bold mb-0">Donasi Barang</h5>
                        <p class="text-secondary small mb-0">untuk campaign "{{ $campaign->judul }}"</p>
                    </div>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <strong>Periksa kesalahan berikut:</strong>
                        <ul class="mb-0 mt-2">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (session('success'))
                    <div class="alert alert-success">
                        {{ session('success') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('donatur.donasi.barang', $campaign->slug) }}"
                    enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Barang</label>
                        <input type="text" name="nama_barang" class="form-control @error('nama_barang') is-invalid @enderror"
                            placeholder="Contoh: Selimut hangat, paket buku tulis, dll"
                            value="{{ old('nama_barang') }}" required>
                        @error('nama_barang')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Kategori Barang</label>
                        @if ($itemKategori->isEmpty())
                            <div class="alert alert-warning py-2 mb-0 small">
                                Belum ada kategori barang non-makanan yang tersedia saat ini.
                            </div>
                        @else
                            <select name="item_category_id" class="form-select @error('item_category_id') is-invalid @enderror" required>
                                <option value="" disabled {{ old('item_category_id') ? '' : 'selected' }}>Pilih Kategori...</option>
                                @foreach ($itemKategori as $kat)
                                    <option value="{{ $kat->id }}" {{ old('item_category_id') == $kat->id ? 'selected' : '' }}>
                                        {{ $kat->nama }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                        <p class="small text-secondary mt-1 mb-0">Donasi barang tidak menerima kategori makanan.</p>
                        @error('item_category_id')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Jumlah Barang</label>
                            <div class="input-group">
                                <input type="number" name="jumlah_barang" min="1"
                                    class="form-control @error('jumlah_barang') is-invalid @enderror"
                                    placeholder="Contoh: 10"
                                    value="{{ old('jumlah_barang') }}" required>
                                <span class="input-group-text">{{ $campaign->satuan_barang ?? 'pcs' }}</span>
                            </div>
                            @error('jumlah_barang')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Estimasi Berat Total (gram)</label>
                            <div class="input-group">
                                <input type="number" name="berat_gram" min="1" max="1000000"
                                    class="form-control @error('berat_gram') is-invalid @enderror"
                                    placeholder="Contoh: 1500"
                                    value="{{ old('berat_gram') }}" required>
                                <span class="input-group-text">gram</span>
                            </div>
                            <span class="form-text small text-secondary">1 kg = 1.000 gram</span>
                            @error('berat_gram')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Foto Barang</label>
                        <input type="file" name="foto_barang" accept="image/*"
                            class="form-control @error('foto_barang') is-invalid @enderror" required>
                        <span class="form-text small text-secondary">Format: JPG, JPEG, PNG, WEBP (Maksimal 2MB)</span>
                        @error('foto_barang')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Catatan (Opsional)</label>
                        <textarea name="catatan" class="form-control @error('catatan') is-invalid @enderror" rows="2"
                            placeholder="Kondisi barang, petunjuk penanganan, dll">{{ old('catatan') }}</textarea>
                        @error('catatan')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox" name="is_anonim" id="anonimBarang" value="1" class="form-check-input"
                            {{ old('is_anonim') ? 'checked' : '' }}>
                        <label class="form-check-label" for="anonimBarang">Donasi sebagai anonim</label>
                    </div>

                    {{-- Placeholder jujur sesuai instruksi #55 (tanpa klaim API / estimasi otomatis) --}}
                    <div class="wc-card p-3 mb-4 bg-light border-0" style="background-color: #FDF8F2 !important;">
                        <p class="small fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Informasi Pengiriman</p>
                        <p class="small text-secondary mb-0">Estimasi ongkir otomatis belum tersedia. Pengiriman dan ongkos kirim diatur sendiri oleh Donatur.</p>
                    </div>

                    <label class="form-label fw-semibold">Metode Pengiriman</label>
                    <div class="mb-3">
                        @php
                            $metodeTerpilih = old('metode_pengiriman', 'dikirim_sendiri');
                        @endphp
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="metode_pengiriman" id="kirimSendiri"
                                value="dikirim_sendiri" {{ $metodeTerpilih === 'dikirim_sendiri' ? 'checked' : '' }}
                                onchange="togglePengiriman(this.value)">
                            <label class="form-check-label" for="kirimSendiri">
                                Dikirim sendiri (isi ekspedisi & no. resi)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="metode_pengiriman" id="diantarLangsung"
                                value="diantar_langsung" {{ $metodeTerpilih === 'diantar_langsung' ? 'checked' : '' }}
                                onchange="togglePengiriman(this.value)">
                            <label class="form-check-label" for="diantarLangsung">
                                Diantar langsung ke lokasi penerima
                            </label>
                        </div>
                    </div>

                    <div class="row mb-4 {{ $metodeTerpilih === 'dikirim_sendiri' ? '' : 'd-none' }}" id="resiBox">
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold">Pilih Ekspedisi</label>
                            <select name="ekspedisi" id="inputEkspedisi" class="form-select @error('ekspedisi') is-invalid @enderror">
                                <option value="JNE" {{ old('ekspedisi', 'JNE') === 'JNE' ? 'selected' : '' }}>JNE</option>
                                <option value="J&T Express" {{ old('ekspedisi') === 'J&T Express' ? 'selected' : '' }}>J&T Express</option>
                                <option value="SiCepat" {{ old('ekspedisi') === 'SiCepat' ? 'selected' : '' }}>SiCepat</option>
                            </select>
                            @error('ekspedisi')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold">Nomor Resi</label>
                            <input type="text" name="no_resi" id="inputNoResi"
                                class="form-control @error('no_resi') is-invalid @enderror"
                                placeholder="Masukkan nomor resi pengiriman"
                                value="{{ old('no_resi') }}">
                            @error('no_resi')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <button type="submit" class="wc-btn-primary w-100">Kirim Donasi Barang</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function togglePengiriman(metode) {
            const resiBox = document.getElementById('resiBox');
            const inputNoResi = document.getElementById('inputNoResi');
            const inputEkspedisi = document.getElementById('inputEkspedisi');

            if (metode === 'dikirim_sendiri') {
                resiBox.classList.remove('d-none');
            } else {
                resiBox.classList.add('d-none');
            }
        }
    </script>

@endsection
