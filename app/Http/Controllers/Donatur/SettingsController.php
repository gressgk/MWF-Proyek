<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\SubscriptionScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SettingsController extends Controller
{
    public function index(SubscriptionScheduleService $scheduleService)
    {
        $user = getAuthUser();

        $subscriptions = Subscription::where('user_id', $user->id)
            ->with(['campaign:id,judul,slug'])
            ->orderByDesc('id')
            ->get();

        $subscriptionItems = $subscriptions->map(function (Subscription $subscription) use ($scheduleService): array {
            $scheduleData = $scheduleService->serializeForApi($subscription);
            $nominal = (int) $subscription->nominal;
            $frequency = strtolower((string) $subscription->frequency);

            return [
                'id' => $subscription->id,
                'campaign_title' => $subscription->campaign?->judul ?? 'Campaign tidak tersedia',
                'campaign_slug' => $subscription->campaign?->slug,
                'nominal' => $nominal,
                'nominal_formatted' => 'Rp' . number_format($nominal, 0, ',', '.'),
                'frequency' => $subscription->frequency,
                'frequency_label' => $frequency === 'mingguan' ? 'Mingguan' : 'Bulanan',
                'status' => $subscription->status,
                'management_state' => $subscription->management_state,
                'operation_in_progress' => $subscription->isOperationInProgress(),
                'next_execution_at' => $scheduleData['next_execution_at'],
                'schedule' => $scheduleData['schedule'],
                'endpoints' => [
                    'pause' => route('donatur.subscriptions.pause', ['subscription' => $subscription->id]),
                    'resume' => route('donatur.subscriptions.resume', ['subscription' => $subscription->id]),
                    'cancel' => route('donatur.subscriptions.cancel', ['subscription' => $subscription->id]),
                    'reconcile' => route('donatur.subscriptions.reconcile', ['subscription' => $subscription->id]),
                ],
            ];
        })->values()->all();

        $subscriptionsIndexUrl = route('donatur.subscriptions.index');

        return view('donatur.settings', compact('user', 'subscriptionItems', 'subscriptionsIndexUrl'));
    }

    // Update profil
    public function update(Request $request)
    {
        $user = getAuthUser();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $fotoLama = $user->avatar;
        $fotoBaru = null;

        try {
            // Upload foto
            if ($request->hasFile('avatar')) {
                $fotoBaru = $request->file('avatar')
                    ->store('avatars', 'public');

                if (! $fotoBaru) {
                    throw new RuntimeException('Upload foto gagal.');
                }
            }

            // Simpan profil
            $data = [
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
            ];

            if ($fotoBaru) {
                $data['avatar'] = $fotoBaru;
            }

            $user->update($data);

        } catch (\Throwable $e) {
            if ($fotoBaru) {
                Storage::disk('public')->delete($fotoBaru);
            }

            report($e);

            return back()->withInput()
                ->with('pesan', 'Profil gagal diperbarui.');
        }

        // Hapus foto lama
        if ($fotoBaru && $fotoLama) {
            try {
                Storage::disk('public')->delete($fotoLama);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('donatur.settings')
            ->with('pesan', 'Profil berhasil diperbarui.');
    }

    // Ganti password
    public function gantiPassword(Request $request)
    {
        $user = getAuthUser();

        $validated = $request->validate([
            'password_lama' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($validated['password_lama'], $user->password)) {
            return back()->withErrors([
                'password_lama' => 'Password lama tidak sesuai.',
            ]);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Password baru harus berbeda dari password lama.',
            ]);
        }

        $user->password = Hash::make($validated['password']);
        $user->remember_token = Str::random(60);
        $user->save();

        return redirect()->route('donatur.settings')
            ->with('pesan', 'Password berhasil diubah.');
    }
}
