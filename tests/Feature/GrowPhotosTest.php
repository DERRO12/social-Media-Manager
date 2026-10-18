<?php

namespace Tests\Feature;

use App\Jobs\SubmitToIndexNow;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Seo\ImageOptimizer;
use App\Services\Seo\IndexNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GrowPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * A tiny but structurally valid JPEG of the given size, optionally with an EXIF block holding
     * GPS-like data (the bytes are what matter: the test checks they are removed).
     */
    private function jpeg(int $width = 1600, int $height = 900, bool $withGps = true): string
    {
        $app0 = "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $exif = "Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08GPSLatitude=0.3476,GPSLongitude=32.5825,Model=SecretPhone";
        $sof = "\x08".pack('n', $height).pack('n', $width)."\x01\x01\x11\x00";
        $sos = "\x01\x01\x00\x00\x3F\x00";

        return "\xFF\xD8"
            ."\xFF\xE0".pack('n', strlen($app0) + 2).$app0
            .($withGps ? "\xFF\xE1".pack('n', strlen($exif) + 2).$exif : '')
            ."\xFF\xC0".pack('n', strlen($sof) + 2).$sof
            ."\xFF\xDA".pack('n', strlen($sos) + 2).$sos
            ."\x12\x34\x56\x78"
            ."\xFF\xD9";
    }

    private function upload(string $name = 'Bridal_braids-2.jpg', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? $this->jpeg());
    }

    private function liveClient(array $profile = []): User
    {
        $client = User::factory()->create(['name' => 'Amara Studio']);
        $client->publicProfile()->create($profile + ['handle' => 'amara', 'is_public' => true]);

        return $client;
    }

    // ---------- Image preparation ----------

    public function test_jpeg_location_and_camera_details_are_removed_but_rotation_is_kept(): void
    {
        $clean = ImageOptimizer::stripJpeg($this->jpeg(), orientation: 6);

        $this->assertStringNotContainsString('GPSLatitude', $clean);
        $this->assertStringNotContainsString('SecretPhone', $clean);
        $this->assertStringContainsString("Exif\x00\x00MM", $clean); // the orientation-only block
        $this->assertStringContainsString("\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06", $clean);
        $this->assertStringEndsWith("\x12\x34\x56\x78\xFF\xD9", $clean); // image data untouched

        $file = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($file, $clean);
        $this->assertSame([1600, 900], array_slice(getimagesize($file), 0, 2));
        unlink($file);
    }

    public function test_png_text_and_exif_chunks_are_removed(): void
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NN', 1, 1)."\x08\x02\x00\x00\x00")
            .$chunk('tEXt', "Comment\x00Taken at home, Kololo").$chunk('eXIf', 'MM*GPS').$chunk('IDAT', 'x').$chunk('IEND', '');

        $clean = ImageOptimizer::stripPng($png);

        $this->assertStringNotContainsString('Kololo', $clean);
        $this->assertStringNotContainsString('eXIf', $clean);
        $this->assertStringContainsString('IHDR', $clean);
        $this->assertStringContainsString('IEND', $clean);
    }

    // ---------- Photos page ----------

    public function test_client_uploads_photos_with_search_friendly_names_and_no_gps(): void
    {
        $client = $this->liveClient();

        // The good photo is added; the broken file is reported by name.
        $component = Volt::actingAs($client)->test('grow.photos')
            ->set('uploads', [$this->upload(), $this->upload('IMG_2041.png', 'not really a photo')])
            ->call('upload')
            ->assertHasErrors('uploads')
            ->assertSee('IMG_2041.png');

        $photo = ProfilePhoto::sole();
        $this->assertSame('Bridal braids 2', $photo->title);
        $this->assertMatchesRegularExpression('#^photos/'.$client->id.'/bridal-braids-2-[a-z0-9]{6}\.(jpg|webp)$#', $photo->path);
        $this->assertSame(1600, $photo->width);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertStringNotContainsString('GPSLatitude', Storage::disk('public')->get($photo->path));
        $component->assertSet('editing', $photo->id)->assertSee('Describe this photo for Google');
    }

    public function test_describing_a_photo_raises_its_score_and_tells_search_engines(): void
    {
        config(['services.indexnow.enabled' => true]);
        Queue::fake();
        $client = $this->liveClient();
        $photo = $client->photos()->create(['path' => 'photos/x.jpg', 'thumb_path' => 'photos/x.jpg', 'width' => 1600, 'height' => 900, 'title' => 'Photo']);
        $this->assertLessThan(50, $photo->seoChecks()['score']);

        Volt::actingAs($client)->test('grow.photos')
            ->call('edit', $photo->id)
            ->set('title', 'Bridal braids in Kampala')
            ->set('alt', 'Bride with long knotless braids and gold beads')
            ->set('caption', 'Knotless bridal braids with gold cuffs, finished in four hours at our Ntinda studio.')
            ->set('keywords', 'bridal braids, wedding hair Kampala')
            ->set('location', 'Kampala, Uganda')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', null);

        $this->assertSame(100, $photo->fresh()->seoChecks()['score']);
        Queue::assertPushed(SubmitToIndexNow::class, fn ($job) => in_array($photo->fresh()->pageUrl($client->publicProfile), $job->urls, true));
    }

    public function test_clients_can_only_change_their_own_photos(): void
    {
        $owner = $this->liveClient();
        Storage::disk('public')->put('photos/1/a.jpg', 'x');
        $photo = $owner->photos()->create(['path' => 'photos/1/a.jpg', 'thumb_path' => 'photos/1/a.jpg', 'title' => 'Mine']);
        $intruder = User::factory()->create();

        Volt::actingAs($intruder)->test('grow.photos')->call('edit', $photo->id)->assertNotFound();
        Volt::actingAs($intruder)->test('grow.photos')->call('delete', $photo->id)->assertNotFound();
        $this->assertModelExists($photo);

        Volt::actingAs($owner)->test('grow.photos')->call('delete', $photo->id);
        $this->assertModelMissing($photo);
        Storage::disk('public')->assertMissing('photos/1/a.jpg');
    }

    // ---------- What Google sees ----------

    public function test_each_photo_has_a_page_google_images_can_index(): void
    {
        $client = $this->liveClient();
        $photo = $client->photos()->create([
            'path' => 'photos/1/bridal-braids-abc123.webp', 'thumb_path' => 'photos/1/bridal-braids-abc123-thumb.webp',
            'width' => 2000, 'height' => 1333, 'mime' => 'image/webp',
            'title' => 'Bridal braids in Kampala', 'alt' => 'Bride with knotless braids', 'caption' => 'Finished in four hours.',
            'keywords' => 'bridal braids, wedding hair', 'location' => 'Kampala',
        ]);
        $hidden = $client->photos()->create(['path' => 'p/h.jpg', 'thumb_path' => 'p/h.jpg', 'title' => 'Private', 'is_public' => false]);
        $others = User::factory()->create()->photos()->create(['path' => 'p/o.jpg', 'thumb_path' => 'p/o.jpg', 'title' => 'Not hers']);

        $this->get("/p/amara/photos/{$photo->id}")->assertRedirect("/p/amara/photos/{$photo->id}/bridal-braids-in-kampala");

        $this->get("/p/amara/photos/{$photo->id}/bridal-braids-in-kampala")
            ->assertOk()
            ->assertSee('<title>Bridal braids in Kampala | Amara Studio', false)
            ->assertSee('"@type":"ImageObject"', false)
            ->assertSee('"contentUrl":"'.Storage::disk('public')->url($photo->path).'"', false)
            ->assertSee('"creditText":"Amara Studio"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('<meta property="og:image" content="'.Storage::disk('public')->url($photo->path).'">', false)
            ->assertSee('max-image-preview:large', false)
            ->assertSee('alt="Bride with knotless braids"', false);

        $this->get("/p/amara/photos/{$hidden->id}/private")->assertNotFound();
        $this->get("/p/amara/photos/{$others->id}/not-hers")->assertNotFound();

        $this->get('/p/amara')->assertSee('Bridal braids in Kampala')->assertDontSee('Private');

        $this->get('/sitemap.xml')
            ->assertSee('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', false)
            ->assertSee("/p/amara/photos/{$photo->id}/bridal-braids-in-kampala", false)
            ->assertSee('<image:loc>'.Storage::disk('public')->url($photo->path).'</image:loc>', false)
            ->assertDontSee('p/h.jpg', false);
    }

    public function test_public_page_uses_the_clients_google_title_and_shows_business_details(): void
    {
        $client = $this->liveClient(['seo_title' => 'Amara Studio – Bridal braids in Kampala', 'seo_description' => 'Book bridal braids in Ntinda.', 'show_business' => true]);
        $client->businessProfile()->create([
            'name' => 'Amara Studio', 'primary_category' => 'Hair salon', 'city' => 'Kampala', 'address' => 'Plot 5, Ntinda Road',
            'phone' => '+256 700 123456', 'services' => ['Bridal braids'], 'place_id' => 'ChIJabc',
            'hours' => ['monday' => ['open' => true, 'from' => '08:00', 'to' => '18:00'], 'sunday' => ['open' => false, 'from' => '08:00', 'to' => '18:00']],
        ]);

        $this->get('/p/amara')
            ->assertOk()
            ->assertSee('<title>Amara Studio – Bridal braids in Kampala', false)
            ->assertSee('<meta name="description" content="Book bridal braids in Ntinda.">', false)
            ->assertSee('"@type":"LocalBusiness"', false)
            ->assertSee('"telephone":"+256 700 123456"', false)
            ->assertSee('"dayOfWeek":"https://schema.org/Monday"', false)
            ->assertSee('href="tel:+256700123456"', false)
            ->assertSee('query_place_id=ChIJabc', false)
            ->assertSee('Review us on Google');

        $client->publicProfile->update(['show_business' => false]);
        $this->get('/p/amara')->assertDontSee('LocalBusiness')->assertDontSee('+256 700 123456')->assertSee('"@type":"Person"', false);
    }

    public function test_page_settings_save_search_appearance_and_logo(): void
    {
        $client = $this->liveClient();

        Volt::actingAs($client)->test('grow.page')
            ->set('seoTitle', 'Amara Studio – Bridal braids')
            ->set('seoDescription', str_repeat('a', 171))
            ->call('save')
            ->assertHasErrors('seoDescription')
            ->set('seoDescription', 'Book bridal braids in Ntinda.')
            ->set('avatar', $this->upload('Logo.jpg'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Rich results test');

        $profile = $client->publicProfile->fresh();
        $this->assertSame('Amara Studio – Bridal braids', $profile->seo_title);
        $this->assertNotNull($profile->avatar_path);
        Storage::disk('public')->assertExists($profile->avatar_path);
        $this->get('/p/amara')->assertSee('<meta property="og:image" content="'.Storage::disk('public')->url($profile->avatar_path).'">', false);
    }

    // ---------- IndexNow ----------

    public function test_indexnow_key_file_and_submission(): void
    {
        $this->get('/indexnow.txt')->assertNotFound(); // off outside production

        config(['services.indexnow.enabled' => true, 'app.url' => 'https://social.example']);
        $key = app(IndexNow::class)->key();
        $this->get('/indexnow.txt')->assertOk()->assertSeeText($key);

        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
        app(IndexNow::class)->submit(['https://social.example/p/amara', 'https://evil.example/x']);

        Http::assertSent(fn ($request) => $request['host'] === 'social.example'
            && $request['key'] === $key
            && $request['keyLocation'] === 'https://social.example/indexnow.txt'
            && $request['urlList'] === ['https://social.example/p/amara']);
    }
}
