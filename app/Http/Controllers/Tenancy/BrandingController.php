<?php

namespace App\Http\Controllers\Tenancy;

use App\Domains\Tenancy\Models\FileObject;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant branding (Roadmap F7): display name, colors and the logo stored as a
 * FileObject in object storage. Lives as the "Marca" tab of the tenant
 * configuration page.
 */
class BrandingController extends Controller
{
    public function update(Request $request, Team $current_team): JsonResponse
    {
        $branding = $this->brandingFor($current_team);

        $this->authorize('update', $branding);

        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'email_signature' => ['nullable', 'string', 'max:2000'],
        ]);

        $branding->fill($validated)->save();

        return response()->json(['data' => $branding->refresh()]);
    }

    public function uploadLogo(Request $request, Team $current_team): JsonResponse
    {
        $branding = $this->brandingFor($current_team);

        $this->authorize('update', $branding);

        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $key = "branding/{$current_team->id}/".$file->hashName();

        Storage::disk('rustfs')->put($key, (string) $file->get());

        FileObject::query()->create([
            'team_id' => $current_team->id,
            'bucket' => (string) config('filesystems.disks.rustfs.bucket', 'sam'),
            'object_key' => $key,
            'original_filename' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
            'content_type' => $file->getMimeType(),
            'visibility' => 'private',
            'category' => 'branding_logo',
        ]);

        $branding->forceFill(['logo_url' => $key])->save();

        return response()->json(['data' => ['logoKey' => $key]], 201);
    }

    private function brandingFor(Team $current_team): TenantBranding
    {
        $branding = TenantBranding::withoutGlobalScopes()
            ->firstOrNew(['team_id' => $current_team->id]);

        $branding->team_id = $current_team->id;

        return $branding;
    }
}
