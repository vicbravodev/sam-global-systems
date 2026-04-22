<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\Tenancy\TenantBrandingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantBranding extends Model
{
    /** @use HasFactory<TenantBrandingFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'logo_url',
        'primary_color',
        'secondary_color',
        'display_name',
        'email_signature',
        'custom_domain',
    ];

    protected static function newFactory(): TenantBrandingFactory
    {
        return TenantBrandingFactory::new();
    }
}
