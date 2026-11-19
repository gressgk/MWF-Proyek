<?php

use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $database = config('database.connections.mysql.database');
    if ($database !== 'wecare_testing') {
        throw new RuntimeException("SAFETY: Chat tests require wecare_testing, current database: {$database}");
    }
});

function makeJejeChatCampaign(User $owner, array $extra = []): Campaign
{
    return Campaign::create(array_merge([
        'user_id' => $owner->id,
        'judul' => 'Bantuan ' . Str::random(8),
        'slug' => 'bantuan-' . strtolower(Str::random(12)),
        'deskripsi' => 'Campaign untuk pengujian chat',
        'tipe_bantuan' => 'uang',
        'target_nominal' => 1000000,
        'terkumpul_nominal' => 0,
        'urgensi' => 'biasa',
        'tanggal_mulai' => now()->subDay()->toDateString(),
        'tanggal_selesai' => now()->addDays(30)->toDateString(),
        'status' => 'aktif',
    ], $extra));
}

test('guest and non-donor cannot create a campaign chat', function () {
    $owner = User::factory()->penerima()->create();
    $campaign = makeJejeChatCampaign($owner);

    $this->post(route('donatur.chat.start', $campaign->slug))
        ->assertRedirect(route('login'));
    $this->actingAs($owner)->post(route('donatur.chat.start', $campaign->slug))
        ->assertForbidden();
});

test('donor creates exactly one conversation per campaign even after repeated request', function () {
    $owner = User::factory()->penerima()->create();
    $donor = User::factory()->donatur()->create();
    $campaign = makeJejeChatCampaign($owner);

    $this->actingAs($donor)->post(route('donatur.chat.start', $campaign->slug))
        ->assertRedirect();
    $this->actingAs($donor)->post(route('donatur.chat.start', $campaign->slug))
        ->assertRedirect();

    expect(Conversation::where('campaign_id', $campaign->id)
        ->where('donatur_id', $donor->id)->count())->toBe(1);
});

test('cannot start chat on inactive campaign', function () {
    $owner = User::factory()->penerima()->create();
    $donor = User::factory()->donatur()->create();
    $campaign = makeJejeChatCampaign($owner, ['status' => 'selesai']);

    $this->actingAs($donor)->post(route('donatur.chat.start', $campaign->slug))
        ->assertNotFound();
});

test('donor only sees their conversations and cannot view, poll or send to another donor conversation', function () {
    $owner = User::factory()->penerima()->create();
    $first = User::factory()->donatur()->create();
    $second = User::factory()->donatur()->create();
    $campaignA = makeJejeChatCampaign($owner, ['judul' => 'Bantuan Rahasia A']);
    $campaignB = makeJejeChatCampaign($owner, ['judul' => 'Bantuan Rahasia B']);
    $chatA = Conversation::create(['campaign_id' => $campaignA->id, 'donatur_id' => $first->id]);
    $chatB = Conversation::create(['campaign_id' => $campaignB->id, 'donatur_id' => $second->id]);

    $this->actingAs($first)->get(route('donatur.chat'))
        ->assertOk()->assertSee('Bantuan Rahasia A')->assertDontSee('Bantuan Rahasia B');
    $this->actingAs($first)->get(route('donatur.chat.show', $chatB))->assertForbidden();
    $this->actingAs($first)->get(route('donatur.chat.pesan', $chatB))->assertForbidden();
    $this->actingAs($first)->post(route('donatur.chat.kirim', $chatB), ['isi' => 'Tidak boleh'])
        ->assertForbidden();

    expect(Message::where('conversation_id', $chatB->id)->count())->toBe(0);
});

test('mitra only sees their own campaign conversations', function () {
    $ownerA = User::factory()->penerima()->create();
    $ownerB = User::factory()->penerima()->create();
    $donor = User::factory()->donatur()->create();
    $campaignA = makeJejeChatCampaign($ownerA, ['judul' => 'Kampanye Pemilik A']);
    $campaignB = makeJejeChatCampaign($ownerB, ['judul' => 'Kampanye Pemilik B']);
    $chatA = Conversation::create(['campaign_id' => $campaignA->id, 'donatur_id' => $donor->id]);
    $chatB = Conversation::create(['campaign_id' => $campaignB->id, 'donatur_id' => $donor->id]);

    $this->actingAs($ownerA)->get(route('mitra.chat'))
        ->assertOk()->assertSee('Kampanye Pemilik A')->assertDontSee('Kampanye Pemilik B');
    $this->actingAs($ownerA)->get(route('mitra.chat.show', $chatB))->assertForbidden();
    $this->actingAs($ownerA)->get(route('mitra.chat.pesan', $chatB))->assertForbidden();
    $this->actingAs($ownerA)->post(route('mitra.chat.kirim', $chatB), ['isi' => 'Tidak boleh'])
        ->assertForbidden();
});

test('donor can send message and mitra can reply in the same conversation', function () {
    $owner = User::factory()->penerima()->create();
    $donor = User::factory()->donatur()->create();
    $campaign = makeJejeChatCampaign($owner);
    $chat = Conversation::create(['campaign_id' => $campaign->id, 'donatur_id' => $donor->id]);

    $this->actingAs($donor)->post(route('donatur.chat.kirim', $chat), ['isi' => 'Halo mitra'])
        ->assertRedirect(route('donatur.chat.show', $chat));
    $this->actingAs($owner)->post(route('mitra.chat.kirim', $chat), ['isi' => 'Halo donatur'])
        ->assertRedirect(route('mitra.chat.show', $chat));

    expect(Message::where('conversation_id', $chat->id)->count())->toBe(2);
    $this->actingAs($donor)->get(route('donatur.chat.pesan', $chat))
        ->assertOk()->assertJsonPath('last_id', (int) Message::where('conversation_id', $chat->id)->max('id'));
});

test('chat content is escaped and invalid message is rejected', function () {
    $owner = User::factory()->penerima()->create();
    $donor = User::factory()->donatur()->create();
    $campaign = makeJejeChatCampaign($owner);
    $chat = Conversation::create(['campaign_id' => $campaign->id, 'donatur_id' => $donor->id]);

    $this->actingAs($donor)->post(route('donatur.chat.kirim', $chat), ['isi' => ''])
        ->assertSessionHasErrors('isi');
    $this->actingAs($donor)->post(route('donatur.chat.kirim', $chat), ['isi' => '<script>alert(1)</script>'])
        ->assertRedirect();
    $this->actingAs($donor)->get(route('donatur.chat.show', $chat))
        ->assertOk()->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});
