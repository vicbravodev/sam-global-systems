<?php

namespace App\Http\Controllers;

use App\Domains\Tenancy\Actions\SubmitDemoRequest;
use App\Domains\Tenancy\Models\DemoRequest;
use App\Support\SystemLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulario público "Pedir una demo". Sin cuenta: lo protege el throttle de
 * la ruta y un campo trampa (`website`) que una persona nunca ve ni llena.
 */
class DemoRequestController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('demo-request', [
            'fleetSizes' => DemoRequest::FLEET_SIZES,
        ]);
    }

    public function store(Request $request, SubmitDemoRequest $submit): RedirectResponse
    {
        // Un bot llenó el campo trampa: se responde igual que a una persona
        // (sin pista de que se detectó) y no se guarda nada.
        if (filled($request->input('website'))) {
            SystemLog::skipped('tenancy.demo_request.rejected', reason: 'honeypot');

            return back();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{7,40}$/'],
            'fleet_size' => ['required', 'string', Rule::in(DemoRequest::FLEET_SIZES)],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'phone.regex' => 'Escribe un teléfono válido (sólo números, espacios y +).',
            'fleet_size.in' => 'Elige el tamaño de tu flota.',
        ], [
            'name' => 'nombre',
            'company' => 'empresa',
            'email' => 'correo',
            'phone' => 'teléfono',
            'fleet_size' => 'tamaño de flota',
            'message' => 'mensaje',
        ]);

        $submit->execute([
            'name' => trim($data['name']),
            'company' => trim($data['company']),
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'fleet_size' => $data['fleet_size'],
            'message' => filled($data['message'] ?? null) ? trim($data['message']) : null,
        ], $request->ip());

        return back();
    }
}
