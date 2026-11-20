<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current): Response
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $current->get();

        $alerts = [];

        if ($organization !== null && blank($organization->physical_address)) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Falta la dirección física',
                'message' => 'Es obligatoria en el pie de todas las campañas. No se podrán enviar campañas hasta configurarla.',
            ];
        }

        if (! $user->hasConfirmedTwoFactor()) {
            $alerts[] = [
                'level' => 'info',
                'title' => 'Protege tu cuenta',
                'message' => 'Activa la autenticación en dos pasos desde Configuración → Seguridad.',
            ];
        }

        return Inertia::render('Dashboard', [
            'alerts' => $alerts,
            'stats' => [
                'users' => $user->can(Permission::UsersManage->value) ? User::query()->where('is_active', true)->count() : null,
            ],
        ]);
    }
}
