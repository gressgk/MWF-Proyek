<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KategoriController extends Controller
{
    public function index()
    {
        $kategoriCampaign = Category::withCount('Campaigns')->get();
        $kategoriBarang = ItemCategory::withCount('Donations')->get();

        return view('admin.kategori', compact('kategoriCampaign', 'kategoriBarang'));
    }

    public function storeCampaignKategori(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
        ]);

        Category::create([
            'nama' => $validated['nama'],
            'slug' => Str::slug($validated['nama']),
        ]);

        return redirect()->route('admin.kategori')
            ->with('success', 'Kategori campaign berhasil ditambahkan.');
    }

    public function storeItemKategori(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
        ]);

        ItemCategory::create([
            'nama' => $validated['nama'],
            'slug' => Str::slug($validated['nama']),
        ]);

        return redirect()->route('admin.kategori')
            ->with('success', 'Kategori barang berhasil ditambahkan.');
    }

    public function updateCampaignKategori(Request $request, Category $category)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
        ]);

        $category->update([
            'nama' => $validated['nama'],
            'slug' => Str::slug($validated['nama']),
        ]);

        return redirect()->route('admin.kategori')
            ->with('success', 'Kategori campaign berhasil diperbarui.');
    }

    public function updateItemKategori(Request $request, ItemCategory $itemCategory)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
        ]);

        $itemCategory->update([
            'nama' => $validated['nama'],
            'slug' => Str::slug($validated['nama']),
        ]);

        return redirect()->route('admin.kategori')
            ->with('success', 'Kategori barang berhasil diperbarui.');
    }

    public function destroyCampaignKategori(Category $category)
    {
        $category->delete();

        return redirect()->route('admin.kategori')
            ->with('success', 'Kategori campaign berhasil dihapus.');
    }

    public function destroyItemKategori(ItemCategory $itemCategory)
    {
        $itemCategory->delete();

        return redirect()->route('admin.kategori')
            ->with('success', 'Kategori barang berhasil dihapus.');
    }
}
