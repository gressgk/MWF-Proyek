<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        $judul = fake()->sentence(4);
        $tipe = fake()->randomElement(['uang', 'barang', 'keduanya']);

        return [
            'user_id' => User::factory()->penerima(),
            'category_id' => Category::inRandomOrder()->value('id'),
            'judul' => $judul,
            'slug' => Str::slug($judul) . '-' . fake()->unique()->numberBetween(1, 9999),
            'deskripsi' => fake()->paragraphs(3, true),
            'tipe_bantuan' => $tipe,
            'target_nominal' => $tipe == 'barang' ? null : fake()->numberBetween(5, 100) * 1000000,
            'terkumpul_nominal' => 0,
            'target_barang' => $tipe == 'uang' ? null : fake()->numberBetween(50, 500),
            'satuan_barang' => $tipe == 'uang' ? null : fake()->randomElement(['pcs', 'dus', 'paket']),
            'terkumpul_barang' => 0,
            'urgensi' => fake()->randomElement(['mendesak', 'biasa', 'terjadwal']),
            'tanggal_mulai' => now()->subDays(fake()->numberBetween(5, 40)),
            'tanggal_selesai' => now()->addDays(fake()->numberBetween(10, 60)),
            'status' => 'aktif',
        ];
    }
}
