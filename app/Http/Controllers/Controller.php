<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Mensaje para el toast global (`useFlashToast` lee `flash.toast`). Un
     * `->with('status', …)` de sesión NO llega a la UI de Inertia.
     *
     * @param  'success'|'error'|'info'|'warning'  $type
     */
    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
