<?php

namespace App\Services\Forms;

use App\Models\Audience;
use App\Models\Contact;
use App\Models\Form;
use App\Models\Subscription;
use Illuminate\Support\Facades\URL;

/**
 * Signed (HMAC-SHA256 with APP_KEY) public links. They include a hash of the email,
 * so they stop working if the contact's address changes.
 */
class PublicLinks
{
    public const int CONFIRMATION_DAYS = 7;

    public static function emailHash(string $email): string
    {
        return substr(hash_hmac('sha256', mb_strtolower($email), (string) config('app.key')), 0, 32);
    }

    public function confirmation(Subscription $subscription, Form $form, Contact $contact): string
    {
        return URL::temporarySignedRoute('optin.confirm', now()->addDays(self::CONFIRMATION_DAYS), [
            'subscription' => $subscription->id,
            'form' => $form->id,
            'hash' => self::emailHash($contact->email),
        ]);
    }

    /** Preferences page (no expiry: it is linked from every email). */
    public function preferences(Contact $contact): string
    {
        return URL::signedRoute('preferences.show', ['contact' => $contact->id, 'hash' => self::emailHash($contact->email)]);
    }

    /** One-click unsubscribe (RFC 8058) for one audience. */
    public function unsubscribe(Contact $contact, Audience $audience): string
    {
        return URL::signedRoute('unsubscribe.show', [
            'contact' => $contact->id,
            'audience' => $audience->id,
            'hash' => self::emailHash($contact->email),
        ]);
    }
}
