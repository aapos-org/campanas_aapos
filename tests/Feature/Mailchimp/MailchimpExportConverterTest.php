<?php

use App\Enums\Role;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\Suppression;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

it('migrates from the CSV export of the Mailchimp dashboard', function () {
    Storage::fake('local');
    $admin = userWithRole(Role::Admin);
    $dir = sys_get_temp_dir().'/mc-export-'.uniqid();
    File::ensureDirectoryExists($dir);

    File::put($dir.'/subscribed_members_export_abc.csv', "\u{FEFF}\"Email Address\",\"First Name\",\"Last Name\",Birthday,OPTIN_TIME,OPTIN_IP,CONFIRM_TIME,CONFIRM_IP,LAST_CHANGED,TAGS\n"
        ."ana@example.com,Ana,López,03/31,\"2024-01-01 10:00:00\",1.1.1.1,\"2024-01-02 10:00:00\",2.2.2.2,\"2024-01-02 10:00:00\",\"\"\"Socio\"\",\"\"VIP\"\"\"\n");
    File::put($dir.'/unsubscribed_members_export_abc.csv', "\"Email Address\",\"First Name\",UNSUB_TIME,UNSUB_REASON\nbeto@example.com,Beto,\"2024-03-01 10:00:00\",\"No longer interested\"\n");
    File::put($dir.'/cleaned_members_export_abc.csv', "\"Email Address\",\"First Name\",CLEAN_TIME\ncarla@example.com,Carla,\"2024-03-01 10:00:00\"\n");

    $this->artisan('mailchimp:import-export', ['path' => $dir, '--audience' => 'Boletín CSV'])->assertSuccessful();
    $this->artisan('mailchimp:load', ['--snapshot' => 'csv', '--dry-run' => true, '--organization' => $admin->organization->slug])->assertSuccessful();
    $this->artisan('mailchimp:load', ['--snapshot' => 'csv', '--force' => true, '--organization' => $admin->organization->slug, '--reports-dir' => $dir.'/docs'])->assertSuccessful();

    actAsOrganization($admin->organization);
    $ana = Contact::query()->where('email', 'ana@example.com')->sole();

    expect($ana->last_name)->toBe('López')
        ->and($ana->tags->pluck('name')->sort()->values()->all())->toBe(['Socio', 'VIP'])
        ->and(Subscription::query()->where('contact_id', $ana->id)->sole()->consent_ip)->toBe('2.2.2.2')
        ->and(Suppression::query()->where('email', 'beto@example.com')->exists())->toBeTrue()
        ->and(Suppression::query()->where('email', 'carla@example.com')->whereNull('audience_id')->exists())->toBeTrue();

    File::deleteDirectory($dir);
});
