<?php

namespace App\Http\Controllers\Tenancy;

use App\Contracts\ObjectStorage;
use App\Domains\Tenancy\Models\FileObject;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\ObjectStorageFailure;
use App\Support\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

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

    public function uploadLogo(Request $request, Team $current_team, ObjectStorage $storage): JsonResponse
    {
        $branding = $this->brandingFor($current_team);

        $this->authorize('update', $branding);

        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $key = "branding/{$current_team->id}/".$file->hashName();

        try {
            $storage->put($key, (string) $file->get());
        } catch (Throwable $e) {
            if (! ObjectStorageFailure::matches($e)) {
                throw $e;
            }

            SystemLog::degraded('tenancy.branding.logo_upload_failed', reason: 'storage_unavailable', input: [
                'team_id' => $current_team->id,
            ], error: $e);

            // 503 with a readable message on the `logo` field instead of a raw
            // 500: nothing was persisted, the previous logo stays and the
            // tenant can simply retry.
            $message = 'No se pudo subir el logo. '.ObjectStorageFailure::USER_MESSAGE;

            return response()->json([
                'message' => $message,
                'errors' => ['logo' => [$message]],
            ], 503);
        }

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
        $branding = TenantBranding::query()
            ->firstOrNew(['team_id' => $current_team->id]);

        $branding->team_id = $current_team->id;

        return $branding;
    }
}
