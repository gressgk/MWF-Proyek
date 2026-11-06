<?php

namespace Database\Seeders;

use App\Models\BroadcastNotification;
use App\Models\Campaign;
use App\Models\CampaignReport;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Donation;
use App\Models\ItemCategory;
use App\Models\Message;
use App\Models\PenerimaProfile;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. akun admin
        $admin = User::create([
            'name' => 'Admin WeCare',
            'email' => 'admin@wecare.id',
            'phone' => '081200000001',
            'role' => 'admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // 2. kategori campaign
        $kategori = collect([
            ['nama' => 'Bencana Alam', 'icon' => 'bi-tsunami'],
            ['nama' => 'Panti Asuhan', 'icon' => 'bi-house-heart'],
            ['nama' => 'Pendidikan', 'icon' => 'bi-mortarboard'],
            ['nama' => 'Kesehatan', 'icon' => 'bi-heart-pulse'],
            ['nama' => 'Lingkungan', 'icon' => 'bi-tree'],
            ['nama' => 'Panti Jompo', 'icon' => 'bi-person-heart'],
        ])->map(fn ($k) => Category::create([
            'nama' => $k['nama'],
            'slug' => Str::slug($k['nama']),
            'icon' => $k['icon'],
        ]));

        // 3. kategori barang (tidak termasuk makanan)
        $itemKategori = collect(['Pakaian', 'Perlengkapan Bayi', 'Elektronik', 'Perabotan Rumah', 'Mainan Anak', 'Buku & Alat Tulis', 'Obat-obatan', 'Lainnya'])
            ->map(fn ($n) => ItemCategory::create(['nama' => $n, 'slug' => Str::slug($n)]));

        // 4. akun penerima (mitra)
        $mitraData = [
            ['nama' => 'Yayasan Kasih Bunda', 'pic' => 'Siti Rahayu', 'email' => 'kasihbunda@wecare.id', 'jenis' => 'yayasan', 'status' => 'approved'],
            ['nama' => 'Panti Asuhan Cahaya Harapan', 'pic' => 'Budi Santoso', 'email' => 'cahayaharapan@wecare.id', 'jenis' => 'yayasan', 'status' => 'approved'],
            ['nama' => 'Komunitas Peduli Bencana Jogja', 'pic' => 'Andi Wijaya', 'email' => 'peduli.jogja@wecare.id', 'jenis' => 'komunitas', 'status' => 'approved'],
            ['nama' => 'Rumah Singgah Anak Bangsa', 'pic' => 'Maria Agustina', 'email' => 'rumahsinggah@wecare.id', 'jenis' => 'yayasan', 'status' => 'pending'],
        ];

        $mitra = collect($mitraData)->map(function ($m) {
            $user = User::create([
                'name' => $m['pic'],
                'email' => $m['email'],
                'phone' => fake()->numerify('08##########'),
                'role' => 'penerima',
                'status_verifikasi' => $m['status'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);

            PenerimaProfile::create([
                'user_id' => $user->id,
                'nama_organisasi' => $m['nama'],
                'jenis_organisasi' => $m['jenis'],
                'alamat' => fake()->address(),
                'nomor_rekening' => fake()->numerify('##########'),
                'dokumen_ktp' => 'dokumen/ktp-contoh.jpg',
                'dokumen_legalitas' => $m['jenis'] == 'yayasan' ? 'dokumen/sk-yayasan-contoh.pdf' : null,
                'alasan_ditolak' => null,
            ]);

            return $user;
        });

        // 5. akun donatur
        $donaturData = [
            ['Dewi Lestari', 'dewi@wecare.id'],
            ['Raka Pratama', 'raka@wecare.id'],
            ['Nadia Putri', 'nadia@wecare.id'],
            ['Fajar Nugroho', 'fajar@wecare.id'],
            ['Indah Permata', 'indah@wecare.id'],
        ];

        $donatur = collect($donaturData)->map(fn ($d) => User::create([
            'name' => $d[0],
            'email' => $d[1],
            'phone' => fake()->numerify('08##########'),
            'role' => 'donatur',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]));

        $donatur->push(User::factory()->donatur()->count(8)->create())->flatten();
        $semuaDonatur = User::where('role', 'donatur')->get();

        // 6. campaign
        $campaignData = [
            ['judul' => 'Bantu Korban Banjir Bandang Sukabumi', 'kategori' => 'Bencana Alam', 'tipe' => 'keduanya', 'urgensi' => 'mendesak', 'penerima' => 2, 'target_nominal' => 75000000, 'target_barang' => 300, 'satuan' => 'paket sembako non-makanan'],
            ['judul' => 'Renovasi Atap Panti Asuhan Cahaya Harapan', 'kategori' => 'Panti Asuhan', 'tipe' => 'uang', 'urgensi' => 'biasa', 'penerima' => 1, 'target_nominal' => 45000000, 'target_barang' => null, 'satuan' => null],
            ['judul' => 'Seragam & Alat Tulis untuk 100 Anak', 'kategori' => 'Pendidikan', 'tipe' => 'barang', 'urgensi' => 'terjadwal', 'penerima' => 1, 'target_nominal' => null, 'target_barang' => 200, 'satuan' => 'paket'],
            ['judul' => 'Dana Operasi Jantung Adik Reva', 'kategori' => 'Kesehatan', 'tipe' => 'uang', 'urgensi' => 'mendesak', 'penerima' => 0, 'target_nominal' => 120000000, 'target_barang' => null, 'satuan' => null],
            ['judul' => 'Selimut & Pakaian Hangat untuk Pengungsi', 'kategori' => 'Bencana Alam', 'tipe' => 'barang', 'urgensi' => 'mendesak', 'penerima' => 3, 'target_nominal' => null, 'target_barang' => 400, 'satuan' => 'pcs'],
            ['judul' => 'Dukung Operasional Panti Jompo Wening Asih', 'kategori' => 'Panti Jompo', 'tipe' => 'keduanya', 'urgensi' => 'biasa', 'penerima' => 0, 'target_nominal' => 30000000, 'target_barang' => 100, 'satuan' => 'paket kebutuhan harian'],
            ['judul' => 'Penghijauan Lahan Kritis Gunung Kidul', 'kategori' => 'Lingkungan', 'tipe' => 'uang', 'urgensi' => 'terjadwal', 'penerima' => 2, 'target_nominal' => 20000000, 'target_barang' => null, 'satuan' => null],
            ['judul' => 'Renovasi Perpustakaan Rumah Singgah', 'kategori' => 'Pendidikan', 'tipe' => 'keduanya', 'urgensi' => 'biasa', 'penerima' => 1, 'target_nominal' => 15000000, 'target_barang' => 150, 'satuan' => 'buku'],
        ];

        $campaigns = collect($campaignData)->map(function ($c, $i) use ($kategori, $mitra) {
            $kat = $kategori->firstWhere('nama', $c['kategori']);
            $pemilik = $mitra[$c['penerima']];

            return Campaign::create([
                'user_id' => $pemilik->id,
                'category_id' => $kat->id,
                'judul' => $c['judul'],
                'slug' => Str::slug($c['judul']),
                'deskripsi' => "Campaign ini dibuat untuk membantu sesama yang membutuhkan uluran tangan. Setiap donasi yang masuk, baik uang maupun barang, akan disalurkan secara transparan dan dilaporkan secara berkala di halaman ini. Mari bersama-sama meringankan beban saudara kita.\n\nDana dan barang yang terkumpul akan digunakan sesuai kebutuhan di lapangan, dengan laporan penggunaan yang bisa dipantau langsung oleh donatur.",
                'tipe_bantuan' => $c['tipe'],
                'target_nominal' => $c['target_nominal'],
                'terkumpul_nominal' => 0,
                'target_barang' => $c['target_barang'],
                'satuan_barang' => $c['satuan'],
                'terkumpul_barang' => 0,
                'urgensi' => $c['urgensi'],
                'tanggal_mulai' => now()->subDays(rand(5, 30)),
                'tanggal_selesai' => now()->addDays(rand(10, 45)),
                'status' => $i == 7 ? 'pending_approval' : 'aktif',
            ]);
        });

        Campaign::factory()->count(4)->create();

        // 7. donasi uang + barang + shipment
        $campaignAktif = Campaign::where('status', 'aktif')->get();

        foreach ($campaignAktif as $campaign) {
            $jumlahDonasi = rand(2, 6);

            for ($i = 0; $i < $jumlahDonasi; $i++) {
                $donaturDipilih = $semuaDonatur->random();

                if ($campaign->tipe_bantuan == 'barang' || ($campaign->tipe_bantuan == 'keduanya' && rand(0, 1))) {
                    $itemKat = $itemKategori->random();
                    $jumlah = rand(5, 30);
                    $statusKirim = fake()->randomElement(['dikirim', 'dalam_perjalanan', 'diterima']);

                    $donasi = Donation::create([
                        'campaign_id' => $campaign->id,
                        'user_id' => $donaturDipilih->id,
                        'jenis' => 'barang',
                        'is_anonim' => rand(0, 4) == 0,
                        'item_category_id' => $itemKat->id,
                        'nama_barang' => $itemKat->nama,
                        'jumlah_barang' => $jumlah,
                        'satuan_barang' => $campaign->satuan_barang ?? 'pcs',
                        'catatan' => 'Barang dalam kondisi baik dan siap pakai.',
                        'metode_pengiriman' => fake()->randomElement(['dikirim_sendiri', 'diantar_langsung']),
                        'status' => $statusKirim == 'diterima' ? 'diterima' : 'terverifikasi',
                        'tanggal_diterima' => $statusKirim == 'diterima' ? now()->subDays(rand(1, 10)) : null,
                    ]);

                    Shipment::create([
                        'donation_id' => $donasi->id,
                        'ekspedisi' => fake()->randomElement(['JNE', 'J&T Express', 'SiCepat']),
                        'no_resi' => strtoupper(Str::random(12)),
                        'estimasi_ongkir' => rand(15, 60) * 1000,
                        'status' => $statusKirim,
                        'last_tracked_at' => now()->subHours(rand(1, 48)),
                    ]);

                    $campaign->increment('terkumpul_barang', $jumlah);
                } else {
                    $nominal = fake()->randomElement([50000, 100000, 150000, 250000, 500000, 1000000]);
                    $rutin = rand(0, 4) == 0;

                    Donation::create([
                        'campaign_id' => $campaign->id,
                        'user_id' => $donaturDipilih->id,
                        'jenis' => 'uang',
                        'is_anonim' => rand(0, 4) == 0,
                        'nominal' => $nominal,
                        'is_recurring' => $rutin,
                        'frequency' => $rutin ? fake()->randomElement(['mingguan', 'bulanan']) : null,
                        'order_id' => 'WECARE-' . strtoupper(Str::random(10)),
                        'status' => 'terverifikasi',
                    ]);

                    $campaign->increment('terkumpul_nominal', $nominal);
                }
            }
        }

        // 8. laporan penggunaan
        foreach ($campaignAktif->take(3) as $campaign) {
            CampaignReport::create([
                'campaign_id' => $campaign->id,
                'judul' => 'Update penyaluran bantuan tahap 1',
                'isi' => 'Alhamdulillah, bantuan tahap pertama sudah kami salurkan kepada penerima manfaat. Terima kasih atas kepercayaan dan dukungan para donatur.',
                'nominal_terpakai' => $campaign->tipe_bantuan != 'barang' ? $campaign->terkumpul_nominal * 0.4 : null,
            ]);
        }

        // 9. chat
        foreach ($campaignAktif->take(3) as $campaign) {
            $conv = Conversation::create([
                'campaign_id' => $campaign->id,
                'donatur_id' => $semuaDonatur->random()->id,
            ]);

            Message::create([
                'conversation_id' => $conv->id,
                'sender_id' => $conv->donatur_id,
                'isi' => 'Halo, mau tanya apakah bantuan ini masih dibutuhkan sampai sekarang?',
                'read_at' => now(),
            ]);

            Message::create([
                'conversation_id' => $conv->id,
                'sender_id' => $campaign->user_id,
                'isi' => 'Halo, terima kasih sudah bertanya. Masih sangat dibutuhkan ya, donasi apapun sangat berarti bagi kami.',
                'read_at' => null,
            ]);
        }

        // 10. broadcast notifikasi
        BroadcastNotification::create([
            'admin_id' => $admin->id,
            'judul' => 'Selamat Datang di WeCare',
            'isi' => 'Terima kasih sudah bergabung bersama WeCare. Mari bersama berbagi kebaikan.',
            'target_role' => 'semua',
        ]);

        BroadcastNotification::create([
            'admin_id' => $admin->id,
            'judul' => 'Pengingat Verifikasi Dokumen',
            'isi' => 'Mohon lengkapi dokumen legalitas organisasi agar campaign dapat segera disetujui.',
            'target_role' => 'penerima',
        ]);
    }
}
