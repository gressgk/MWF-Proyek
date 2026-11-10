<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LaporanController extends Controller
{
    public function index(Request $request)
    {
        $user = getAuthUser();

        // Get all campaigns belonging to the logged-in Penerima
        $campaigns = Campaign::where('user_id', $user->id)
            ->latest()
            ->get();

        // Use the selected campaign, or choose a default
        $campaign = $campaigns->firstWhere('id', $request->campaign_id);

        if (!$campaign) {
            $campaign = $campaigns->first(function ($item) {
                return $item->Reports()->exists();
            }) ?? $campaigns->first();
        }

        // Get reports for the selected campaign
        $laporan = $campaign
            ? $campaign->Reports()->latest()->get()
            : collect();

        return view('penerima.laporan', compact(
            'campaign',
            'campaigns',
            'laporan'
        ));
    }
    public function store(Request $request)
    {
        $user = getAuthUser();

        $validated = $request->validate([
            'campaign_id' => 'required|exists:campaigns,id',
            'judul' => 'required|string|max:255',
            'isi' => 'required|string',
            'dokumen_bukti' => 'required_without:foto_bukti|file|mimes:pdf|max:10240',
            'foto_bukti' => 'required_without:dokumen_bukti|array|min:1',
            'foto_bukti.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'nominal_terpakai' => 'nullable|numeric|min:0',
        ]);

        // Ensure the campaign belongs to the logged-in Penerima
        $campaign = Campaign::where('user_id', $user->id)
            ->findOrFail($validated['campaign_id']);

        // Store the PDF document
        $dokumenPath = null;

        if ($request->hasFile('dokumen_bukti')) {
            $dokumenPath = $request->file('dokumen_bukti')
                ->store('reports/documents', 'public');
        }

        // Create the main report
        $report = CampaignReport::create([
            'campaign_id' => $campaign->id,
            'judul' => $validated['judul'],
            'isi' => $validated['isi'],
            'dokumen_bukti' => $dokumenPath,
            'nominal_terpakai' => $validated['nominal_terpakai'] ?? null,
        ]);

        // Store multiple supporting photos
        if ($request->hasFile('foto_bukti')) {
            foreach ($request->file('foto_bukti') as $photo) {
                $fotoPath = $photo->store('reports/photos', 'public');

                $report->Photos()->create([
                    'foto_path' => $fotoPath,
                ]);
            }
        }

        return redirect()
            ->route('mitra.laporan', ['campaign_id' => $campaign->id])
            ->with('success', 'Laporan berhasil dipublikasikan.');
    }

    public function show($id)
    {
        $user = getAuthUser();

        // Find the report and load its campaign and photos
        $report = CampaignReport::with(['Campaign', 'Photos'])
            ->whereHas('Campaign', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->findOrFail($id);

        return view('penerima.laporan-detail', compact('report'));
    }
}
