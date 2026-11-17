<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DonasiBarangController extends Controller
{
    public function index(Campaign $campaign)
    {
        abort_unless(
            $campaign->status === 'aktif' &&
                in_array($campaign->tipe_bantuan, ['barang', 'keduanya']),
            404
        );

        $itemKategori = ItemCategory::all();

        return view('donatur.donasi-barang', compact(
            'campaign',
            'itemKategori'
        ));
    }

    public function store(Request $request, Campaign $campaign)
    {
        $validated = $request->validate([
            'nama_barang' => 'required|string|max:255',
            'item_category_id' => 'required|exists:item_categories,id',
            'jumlah_barang' => 'required|integer|min:1',
            'foto_barang' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'catatan' => 'nullable|string',
            'is_anonim' => 'nullable|boolean',
            'metode_pengiriman' => 'required|in:dikirim_sendiri,diantar_langsung',
            'ekspedisi' => 'required_if:metode_pengiriman,dikirim_sendiri|nullable|in:JNE,J\&T Express,SiCepat',
            'no_resi' => 'required_if:metode_pengiriman,dikirim_sendiri|nullable|string|max:255',
        ]);

        // Find an active campaign that accepts goods donations
        abort_unless(
            $campaign->status === 'aktif' &&
                in_array($campaign->tipe_bantuan, ['barang', 'keduanya']),
            404
        );

        // Verify the selected item category
        $itemKategori = ItemCategory::findOrFail(
            $validated['item_category_id']
        );

        if (strtolower($itemKategori->slug) === 'makanan') {
            return back()
                ->withErrors(['item_category_id' => 'Donasi makanan tidak diterima.'])
                ->withInput();
        }

        // Store the uploaded item photo
        $fotoPath = $request->file('foto_barang')
            ->store('donations/items', 'public');

        try {
            DB::transaction(function () use (
                $validated,
                $campaign,
                $fotoPath,
                $request
            ) {
                // Create donation record
                $donation = Donation::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => getAuthUser()->id,
                    'jenis' => 'barang',
                    'is_anonim' => $request->boolean('is_anonim'),
                    'item_category_id' => $validated['item_category_id'],
                    'nama_barang' => $validated['nama_barang'],
                    'jumlah_barang' => $validated['jumlah_barang'],
                    'satuan_barang' => $campaign->satuan_barang ?? 'pcs',
                    'foto_barang' => $fotoPath,
                    'catatan' => $validated['catatan'] ?? null,
                    'metode_pengiriman' => $validated['metode_pengiriman'],
                    'status' => 'menunggu_konfirmasi',
                ]);

                // Create shipment record for self-delivery
                if ($validated['metode_pengiriman'] === 'dikirim_sendiri') {
                    $donation->Shipment()->create([
                        'ekspedisi' => $validated['ekspedisi'],
                        'no_resi' => $validated['no_resi'],
                        'status' => 'dikirim',
                    ]);
                }
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($fotoPath);

            throw $e;
        }

        return redirect()
            ->route('donatur.campaign.show', $campaign->slug)
            ->with('success', 'Donasi barang berhasil dikirim!');
    }
}
