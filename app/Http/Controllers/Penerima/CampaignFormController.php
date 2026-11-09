<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CampaignFormController extends Controller
{
    // Display create campaign form
    public function index()
    {
        $kategoriList = Category::orderBy('nama')->get();

        // No campaign selected when creating a new campaign
        $campaign = null;

        return view('penerima.campaign-form', compact(
            'kategoriList',
            'campaign'
        ));
    }

    // Save a new campaign
    public function store(Request $request)
    {
        $user = getAuthUser();

        // Handle custom category
        if ($request->category_id === 'lainnya') {
            $request->merge(['category_id' => null]);
        }

        $validated = $request->validate([
            'judul' => 'required|string|max:255',
            'deskripsi' => 'required|string',

            'category_id' => 'nullable|exists:categories,id',
            'kategori_lainnya' => 'required_without:category_id|nullable|string|max:255',

            'tipe_bantuan' => [
                'required',
                Rule::in(['uang', 'barang', 'keduanya']),
            ],

            'target_nominal' => 'required_if:tipe_bantuan,uang,keduanya|nullable|numeric|min:1',
            'target_barang' => 'required_if:tipe_bantuan,barang,keduanya|nullable|numeric|min:1',
            'satuan_barang' => 'required_if:tipe_bantuan,barang,keduanya|nullable|string|max:255',

            'urgensi' => [
                'required',
                Rule::in(['mendesak', 'biasa', 'terjadwal']),
            ],

            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',

            'foto_sampul' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Generate unique slug
        $slug = $this->generateUniqueSlug($validated['judul']);

        // Store campaign photo
        $fotoPath = null;

        if ($request->hasFile('foto_sampul')) {
            $fotoPath = $request->file('foto_sampul')
                ->store('campaigns', 'public');
        }

        // Remove unnecessary fields based on donation type
        if ($validated['tipe_bantuan'] === 'uang') {
            $validated['target_barang'] = null;
            $validated['satuan_barang'] = null;
        } elseif ($validated['tipe_bantuan'] === 'barang') {
            $validated['target_nominal'] = null;
        }

        Campaign::create([
            'user_id' => $user->id,
            'category_id' => $validated['category_id'],
            'kategori_lainnya' => $validated['kategori_lainnya'] ?? null,

            'judul' => $validated['judul'],
            'slug' => $slug,
            'deskripsi' => $validated['deskripsi'],

            'tipe_bantuan' => $validated['tipe_bantuan'],

            'target_nominal' => $validated['target_nominal'] ?? null,
            'target_barang' => $validated['target_barang'] ?? null,
            'satuan_barang' => $validated['satuan_barang'] ?? null,

            'urgensi' => $validated['urgensi'],

            'tanggal_mulai' => $validated['tanggal_mulai'],
            'tanggal_selesai' => $validated['tanggal_selesai'],

            'foto_sampul' => $fotoPath,

            'terkumpul_nominal' => 0,
            'terkumpul_barang' => 0,

            'status' => 'pending_approval',
        ]);

        return redirect()
            ->route('mitra.campaign.index')
            ->with('success', 'Campaign berhasil dibuat dan diajukan untuk persetujuan Admin.');
    }

    // Display edit campaign form
    public function edit(Campaign $campaign)
    {
        $user = getAuthUser();

        // Ensure campaign belongs to the logged-in Penerima
        abort_unless((int) $campaign->user_id === (int) $user->id, 403);

        $kategoriList = Category::orderBy('nama')->get();

        return view('penerima.campaign-form', compact(
            'kategoriList',
            'campaign'
        ));
    }

    // Update existing campaign
    public function update(Request $request, Campaign $campaign)
    {
        $user = getAuthUser();

        // Ensure campaign belongs to the logged-in Penerima
        abort_unless((int) $campaign->user_id === (int) $user->id, 403);

        // Handle custom category
        if ($request->category_id === 'lainnya') {
            $request->merge(['category_id' => null]);
        }

        $validated = $request->validate([
            'judul' => 'required|string|max:255',
            'deskripsi' => 'required|string',

            'category_id' => 'nullable|exists:categories,id',
            'kategori_lainnya' => 'required_without:category_id|nullable|string|max:255',

            'tipe_bantuan' => [
                'required',
                Rule::in(['uang', 'barang', 'keduanya']),
            ],

            'target_nominal' => 'required_if:tipe_bantuan,uang,keduanya|nullable|numeric|min:1',
            'target_barang' => 'required_if:tipe_bantuan,barang,keduanya|nullable|numeric|min:1',
            'satuan_barang' => 'required_if:tipe_bantuan,barang,keduanya|nullable|string|max:255',

            'urgensi' => [
                'required',
                Rule::in(['mendesak', 'biasa', 'terjadwal']),
            ],

            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',

            'foto_sampul' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Generate a new slug only when the title changes
        $slug = $campaign->slug;

        if ($campaign->judul !== $validated['judul']) {
            $slug = $this->generateUniqueSlug(
                $validated['judul'],
                $campaign->id
            );
        }

        // Preserve the existing photo unless a new one is uploaded
        $fotoPath = $campaign->foto_sampul;
        $oldFotoPath = $campaign->foto_sampul;

        if ($request->hasFile('foto_sampul')) {
            $fotoPath = $request->file('foto_sampul')
                ->store('campaigns', 'public');
        }

        // Remove unnecessary fields based on donation type
        if ($validated['tipe_bantuan'] === 'uang') {
            $validated['target_barang'] = null;
            $validated['satuan_barang'] = null;
        } elseif ($validated['tipe_bantuan'] === 'barang') {
            $validated['target_nominal'] = null;
        }

        $campaign->update([
            'category_id' => $validated['category_id'],
            'kategori_lainnya' => $validated['kategori_lainnya'] ?? null,

            'judul' => $validated['judul'],
            'slug' => $slug,
            'deskripsi' => $validated['deskripsi'],

            'tipe_bantuan' => $validated['tipe_bantuan'],

            'target_nominal' => $validated['target_nominal'] ?? null,
            'target_barang' => $validated['target_barang'] ?? null,
            'satuan_barang' => $validated['satuan_barang'] ?? null,

            'urgensi' => $validated['urgensi'],

            'tanggal_mulai' => $validated['tanggal_mulai'],
            'tanggal_selesai' => $validated['tanggal_selesai'],

            'foto_sampul' => $fotoPath,

            // Rejected or draft campaigns must be submitted again.
            // Active and completed campaign statuses are preserved.
            'status' => in_array($campaign->status, ['draft', 'ditolak'])
                ? 'pending_approval'
                : $campaign->status,

            'alasan_ditolak' => null,
        ]);

        // Delete the old photo only after the database update succeeds
        if ($request->hasFile('foto_sampul') && $oldFotoPath) {
            Storage::disk('public')->delete($oldFotoPath);
        }

        return redirect()
            ->route('mitra.campaign.index')
            ->with('success', 'Campaign berhasil diperbarui.');
    }

    // Soft delete campaign
    public function destroy(Campaign $campaign)
    {
        $user = getAuthUser();

        // Ensure campaign belongs to the logged-in Penerima
        abort_unless((int) $campaign->user_id === (int) $user->id, 403);

        $campaign->delete();

        return redirect()
            ->route('mitra.campaign.index')
            ->with('success', 'Campaign berhasil dihapus.');
    }

    // Generate unique campaign slug
    private function generateUniqueSlug($judul, $ignoreId = null)
    {
        $baseSlug = Str::slug($judul);

        if ($baseSlug === '') {
            $baseSlug = 'campaign';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Campaign::where('slug', $slug)
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
