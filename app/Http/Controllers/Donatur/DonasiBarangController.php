<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DonasiBarangController extends Controller
{
    /**
     * T1.2 (#55) — Form donasi barang.
     * Hanya menampilkan kategori non-makanan yang aktif (tidak soft-deleted).
     */
    public function index(Campaign $campaign)
    {
        abort_unless(
            $campaign->status === 'aktif' &&
                in_array($campaign->tipe_bantuan, ['barang', 'keduanya']),
            404
        );

        $itemKategori = ItemCategory::whereNull('deleted_at')
            ->whereRaw('LOWER(slug) != ?', ['makanan'])
            ->whereRaw('LOWER(nama) != ?', ['makanan'])
            ->get();

        return view('donatur.donasi-barang', compact(
            'campaign',
            'itemKategori'
        ));
    }

    /**
     * T1.2 (#55) & T1.3 (#57) — Simpan donasi barang dan shipment manual.
     */
    public function store(Request $request, Campaign $campaign)
    {
        // Validasi campaign aktif dan menerima barang
        abort_unless(
            $campaign->status === 'aktif' &&
                in_array($campaign->tipe_bantuan, ['barang', 'keduanya']),
            404
        );

        $validated = $request->validate([
            'nama_barang' => ['required', 'string', 'max:255'],
            'item_category_id' => [
                'required',
                Rule::exists('item_categories', 'id')->whereNull('deleted_at'),
            ],
            'jumlah_barang' => ['required', 'integer', 'min:1'],
            'berat_gram' => ['required', 'integer', 'min:1', 'max:1000000'],
            'foto_barang' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'is_anonim' => ['nullable', 'boolean'],
            'metode_pengiriman' => ['required', Rule::in(['dikirim_sendiri', 'diantar_langsung'])],
            'ekspedisi' => [
                Rule::requiredIf($request->input('metode_pengiriman') === 'dikirim_sendiri'),
                'nullable',
                Rule::in(['JNE', 'J&T Express', 'SiCepat']),
            ],
            'no_resi' => [
                Rule::requiredIf($request->input('metode_pengiriman') === 'dikirim_sendiri'),
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        // Verifikasi kategori bukan makanan (pertahanan ganda server-side)
        $itemKategori = ItemCategory::whereNull('deleted_at')
            ->findOrFail($validated['item_category_id']);

        if (strtolower($itemKategori->slug) === 'makanan' || strtolower($itemKategori->nama) === 'makanan') {
            return back()
                ->withErrors(['item_category_id' => 'Donasi makanan tidak diterima.'])
                ->withInput();
        }

        // Simpan foto barang pada storage public
        $fotoPath = $request->file('foto_barang')->store('donations/items', 'public');

        try {
            DB::transaction(function () use (
                $validated,
                $campaign,
                $fotoPath,
                $request
            ) {
                // Simpan record donation
                $donation = Donation::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => getAuthUser()->id,
                    'jenis' => 'barang',
                    'is_anonim' => $request->boolean('is_anonim'),
                    'item_category_id' => $validated['item_category_id'],
                    'nama_barang' => $validated['nama_barang'],
                    'jumlah_barang' => $validated['jumlah_barang'],
                    'satuan_barang' => $campaign->satuan_barang ?? 'pcs',
                    'berat_gram' => $validated['berat_gram'],
                    'foto_barang' => $fotoPath,
                    'catatan' => $validated['catatan'] ?? null,
                    'metode_pengiriman' => $validated['metode_pengiriman'],
                    'status' => 'menunggu_konfirmasi',
                ]);

                // Buat shipment HANYA jika dikirim_sendiri
                if ($validated['metode_pengiriman'] === 'dikirim_sendiri') {
                    $donation->Shipment()->create([
                        'ekspedisi' => trim($validated['ekspedisi']),
                        'no_resi' => trim($validated['no_resi']),
                        'status' => 'dikirim',
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($fotoPath);

            throw $e;
        }

        return redirect()
            ->route('donatur.campaign.show', $campaign->slug)
            ->with('success', 'Donasi barang berhasil dicatat dan menunggu konfirmasi penerima.');
    }
}
