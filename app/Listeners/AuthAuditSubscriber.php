<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Records authentication-related events in the audit trail.
 * Registered through Laravel's event discovery (typed handle* methods); do not
 * also register it manually or every event is recorded twice.
 * Credentials are never logged; failed attempts only store the (normalized) email.
 */
class AuthAuditSubscriber
{
    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;

        // The password was just verified: don't ask for it again on protected pages
        // (e.g. an administrator sent straight to the security settings to enable 2FA).
        if (request()->hasSession()) {
            request()->session()->passwordConfirmed();
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();

        Audit::record('auth.login', 'Inicio de sesión', $user, causer: $user, organizationId: $user->organization_id);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('auth.logout', 'Cierre de sesión', $event->user, causer: $event->user, organizationId: $event->user->organization_id);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $email = is_string($event->credentials['email'] ?? null) ? mb_strtolower(trim($event->credentials['email'])) : null;
        $user = $event->user instanceof User ? $event->user : null;

        Audit::record(
            'auth.failed',
            'Intento de inicio de sesión fallido',
            $user,
            ['email' => $email],
            organizationId: $user?->organization_id,
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        Audit::record('auth.lockout', 'Bloqueo temporal por demasiados intentos', properties: [
            'email' => is_string($email) ? mb_strtolower(trim($email)) : null,
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('auth.password_reset', 'Contraseña restablecida', $event->user, causer: $event->user, organizationId: $event->user->organization_id);
        }
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->recordTwoFactor($event->user, 'auth.2fa_enabled', 'Autenticación en dos pasos activada');
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->recordTwoFactor($event->user, 'auth.2fa_disabled', 'Autenticación en dos pasos desactivada');
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->recordTwoFactor($event->user, 'auth.2fa_failed', 'Código de verificación en dos pasos incorrecto');
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->recordTwoFactor($event->user, 'auth.2fa_recovery_codes', 'Códigos de recuperación regenerados');
    }

    private function recordTwoFactor(mixed $user, string $event, string $description): void
    {
        if ($user instanceof User) {
            Audit::record($event, $description, $user, causer: $user, organizationId: $user->organization_id);
        }
    }
}
