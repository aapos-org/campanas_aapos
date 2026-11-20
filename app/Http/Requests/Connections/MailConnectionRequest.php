<?php

namespace App\Http\Requests\Connections;

use App\Enums\MailDriver;
use App\Models\MailConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route protected by "connections.manage".
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from_email' => mb_strtolower(trim((string) $this->input('from_email'))),
            'reply_to' => $this->filled('reply_to') ? mb_strtolower(trim((string) $this->input('reply_to'))) : null,
            'username' => $this->filled('username') ? mb_strtolower(trim((string) $this->input('username'))) : null,
            'password' => $this->filled('password') ? str_replace(' ', '', (string) $this->input('password')) : null,
            'api_key' => $this->filled('api_key') ? trim((string) $this->input('api_key')) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $connection = $this->route('connection');
        $driver = $connection instanceof MailConnection ? $connection->driver : MailDriver::tryFrom((string) $this->input('driver'));
        $creating = ! $connection instanceof MailConnection;

        return [
            'driver' => $creating ? ['required', Rule::enum(MailDriver::class)] : ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'from_email' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'daily_limit' => $driver?->isGmail()
                ? ['required', 'integer', 'min:1', 'max:2000']
                : ['nullable', 'integer', 'min:1', 'max:10000000'],
            'is_default' => ['sometimes', 'boolean'],
            // Gmail SMTP: account + 16-char app password (required on create, optional on edit).
            'username' => $driver === MailDriver::GmailSmtp ? ['required', 'email:rfc'] : ['nullable'],
            'password' => $driver === MailDriver::GmailSmtp
                ? [$creating ? 'required' : 'nullable', 'string', 'size:16', 'alpha']
                : ['nullable'],
            'api_key' => $driver === MailDriver::ElasticEmail
                ? [$creating ? 'required' : 'nullable', 'string', 'min:20', 'max:255']
                : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.size' => 'La contraseña de aplicación de Google tiene 16 letras (los espacios no cuentan).',
            'password.alpha' => 'La contraseña de aplicación de Google solo contiene letras.',
            'daily_limit.max' => 'Gmail permite como máximo 2,000 envíos diarios (Workspace); deja margen.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'from_email' => 'correo del remitente',
            'from_name' => 'nombre del remitente',
            'reply_to' => 'responder a',
            'daily_limit' => 'límite diario',
            'username' => 'cuenta de Gmail',
            'password' => 'contraseña de aplicación',
            'api_key' => 'clave de API',
        ];
    }
}
