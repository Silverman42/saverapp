<?php

use App\Enums\AdminPermission;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\BusinessSettingsReadiness;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function logoManager(): User
{
    $user = User::factory()->admin()->withTwoFactor()->create();
    $user->givePermissionTo(AdminPermission::BusinessSettingsManage);

    return $user;
}

function logoUpload(int $width, int $height, string $trailingBytes = ''): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);
    $bytes = ob_get_clean().$trailingBytes;
    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'logo.jpg', 'image/jpeg', null, true);
}

beforeEach(function (): void {
    Storage::fake();
});

test('a manager uploads a re-encoded logo, publishes it, and other users can view it', function (): void {
    $actor = logoManager();
    $response = $this->actingAs($actor)->post(route('admin.business-settings.logo.store'), ['logo' => logoUpload(256, 256, '<?php echo 1; ?>')])
        ->assertOk();
    $reference = $response->json('reference');

    $stored = Storage::disk()->get('business-logos/'.$reference.'.png');
    expect($stored)->toStartWith("\x89PNG")->not->toContain('<?php')
        ->and(hash('sha256', $stored))->toBe($reference)
        ->and(app(BusinessSettingsReadiness::class)->checks()['logo']['state'])->toBe('Ready to enable');
    $this->assertDatabaseHas('audit_events', ['event_type' => 'business_settings.logo_uploaded', 'target_reference' => $reference]);

    $settings = app(BusinessSettings::class);
    $settings->import();
    $draft = $settings->saveDraft($actor, ['logo_reference' => $reference], BusinessProfile::current()->version, (string) Str::uuid());
    $preview = $settings->preview($actor, $draft['draft_id'], $draft['revision'], null);
    $request = Request::create('/admin/business-settings', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $settings->publish($actor, $draft['draft_id'], $draft['revision'], $preview['reference'], 'New logo', (string) Str::uuid(), $request);

    expect($settings->resolve()['values']['logo_reference'])->toBe($reference);
    $this->actingAs(User::factory()->customer()->create())->get(route('business-logo.show', $reference))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('invalid logo uploads are rejected without storing a file', function (UploadedFile $file): void {
    $this->actingAs(logoManager())->post(route('admin.business-settings.logo.store'), ['logo' => $file])
        ->assertSessionHasErrors('logo');

    expect(Storage::disk()->allFiles())->toBe([]);
})->with([
    'too small' => fn () => logoUpload(64, 64),
    'too large in dimensions' => fn () => logoUpload(2100, 200),
    'not an image' => fn () => UploadedFile::fake()->createWithContent('logo.png', 'not an image'),
    'over 2 MB' => fn () => UploadedFile::fake()->create('logo.png', 2049, 'image/png'),
]);

test('only settings managers can upload and guests cannot view logos', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.business-settings.logo.store'), ['logo' => logoUpload(256, 256)])->assertForbidden();
    auth()->logout();

    $this->get(route('business-logo.show', str_repeat('a', 64)))->assertRedirect();
});

test('a draft cannot reference a logo asset that was never uploaded', function (): void {
    $settings = app(BusinessSettings::class);
    $settings->import();

    expect(fn () => $settings->saveDraft(logoManager(), ['logo_reference' => str_repeat('b', 64)], BusinessProfile::current()->version, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});
