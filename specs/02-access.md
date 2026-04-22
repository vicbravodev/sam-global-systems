# Access (COMPLETADO)

## 1. Purpose

Manage granular roles, permissions, and authorization for users within tenants. Extends the existing `TeamRole` enum and `Membership` model to support a full RBAC system where permissions are evaluated in tenant context, roles are assignable per-team, and subscription status can further restrict access.

## 2. Responsibilities

- Define a catalog of permissions grouped by module
- Define roles (global and tenant-scoped) with assigned permission sets
- Authorize user actions against permission codes within the current team context
- Allow users to hold different roles in different teams
- Integrate with Tenancy module so subscription status and tenant features can restrict permissions
- Provide `super_admin` global role that bypasses all tenant-level authorization checks
- Store per-user, per-team preferences

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| Middleware | Current user + current team | Request context |
| Tenancy module | Subscription status, tenant features | Eloquent relationships / service call |
| Admin | Role and permission assignments | Inertia pages / API |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Policies / Gates | Authorization decisions (bool) | `AuthorizeAction` service |
| Controllers | Permission-guarded endpoints | Middleware / policy checks |
| Frontend | User permissions for current team | Shared Inertia props |

## 4. Entities

### 4.1 Roles (`roles`)

Defines named roles with associated permission sets. Roles can be global (super_admin) or tenant-scoped.

```php
Schema::create('roles', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('code')->unique();
    $table->text('description')->nullable();
    $table->string('scope'); // global, tenant
    $table->boolean('is_system')->default(false);
    $table->timestamps();
});
```

**Enum `RoleScope`**: `Global`, `Tenant`

### 4.2 Permissions (`permissions`)

Granular capabilities grouped by module. Each permission is a single action a user can perform.

```php
Schema::create('permissions', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('module');
    $table->timestamps();
});
```

### 4.3 Role-Permission Pivot (`role_permission`)

Many-to-many relationship between roles and permissions.

```php
Schema::create('role_permission', function (Blueprint $table) {
    $table->foreignId('role_id')->constrained()->cascadeOnDelete();
    $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

    $table->primary(['role_id', 'permission_id']);
});
```

### 4.4 User Preferences (`user_preferences`)

Stores user-specific settings, optionally scoped to a team. When `team_id` is null, preferences are global for the user.

```php
Schema::create('user_preferences', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->jsonb('preferences_json');
    $table->timestamps();

    $table->unique(['user_id', 'team_id']);
});
```

### 4.5 Extend Existing Membership (separate migration)

Add a nullable foreign key to the `roles` table for future granular role assignment. The existing `role` string column (from `TeamRole` enum) is kept for backward compatibility.

```php
Schema::table('memberships', function (Blueprint $table) {
    $table->foreignId('role_id')->nullable()->after('role')->constrained()->nullOnDelete();
});
```

### 4.6 Extend Existing User (separate migration)

Add a global role column for system-wide roles like `super_admin`.

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('global_role')->nullable()->after('email');
});
```

### Default Roles (Seeded)

| Code | Scope | System | Description |
|------|-------|--------|-------------|
| `super_admin` | global | true | Full system access, bypasses all tenant checks |
| `tenant_admin` | tenant | true | Full tenant management including billing and users |
| `supervisor` | tenant | true | Operational oversight: incidents, assets, drivers, reports |
| `monitorista` | tenant | true | Real-time monitoring: incidents view/manage/resolve, assets view |
| `analyst` | tenant | true | Read-only analytics: reports, AI analysis, audit logs |
| `billing_manager` | tenant | true | Billing and subscription management |
| `viewer` | tenant | true | Read-only access across all tenant modules |

### Default Permissions (Seeded by Module)

| Module | Permission Code | Description |
|--------|----------------|-------------|
| tenancy | `tenancy.manage` | Manage tenant settings, branding, features |
| tenancy | `tenancy.billing.view` | View billing dashboard, invoices, usage |
| tenancy | `tenancy.billing.manage` | Manage subscriptions, payment methods |
| incidents | `incidents.view` | View incidents and their details |
| incidents | `incidents.manage` | Create and update incidents |
| incidents | `incidents.resolve` | Resolve open incidents |
| incidents | `incidents.close` | Close resolved incidents |
| assets | `assets.view` | View assets and their status |
| assets | `assets.manage` | Create, update, delete assets |
| drivers | `drivers.view` | View driver profiles and assignments |
| drivers | `drivers.manage` | Create, update, delete drivers |
| reports | `reports.view` | View generated reports |
| reports | `reports.export` | Export reports to file |
| ai | `ai.analysis.view` | View AI analysis results |
| ai | `ai.analysis.execute` | Trigger AI analysis |
| config | `config.view` | View tenant configuration |
| config | `config.manage` | Modify tenant configuration |
| users | `users.view` | View team members |
| users | `users.manage` | Update member roles and details |
| users | `users.invite` | Invite new members to the team |
| audit | `audit.view` | View audit logs |

### Default Role-Permission Assignments

| Role | Permissions |
|------|------------|
| `super_admin` | All permissions (bypass — not explicitly assigned, checked at gate level) |
| `tenant_admin` | All permissions |
| `supervisor` | `incidents.*`, `assets.*`, `drivers.*`, `reports.*`, `ai.analysis.view`, `config.view`, `users.view`, `audit.view` |
| `monitorista` | `incidents.view`, `incidents.manage`, `incidents.resolve`, `assets.view`, `drivers.view` |
| `analyst` | `reports.*`, `ai.analysis.*`, `audit.view`, `assets.view`, `incidents.view` |
| `billing_manager` | `tenancy.billing.*`, `tenancy.manage` |
| `viewer` | All `*.view` permissions |

## 5. Services / Actions

### 5.1 `AuthorizeAction`

**Path**: `app/Domains/Access/Actions/AuthorizeAction.php`

```php
public function execute(
    User $user,
    string $permissionCode,
    ?Team $team = null,
): bool
```

Authorization evaluation order:

1. If `$user->global_role === 'super_admin'`, return `true` (bypass all checks)
2. Resolve `$team` from current context if not provided
3. Load the user's `Membership` for the team
4. Load the role (via `membership.role_id`, falling back to mapping from `membership.role` string)
5. Check if the role has the requested `$permissionCode` via `role_permission` pivot
6. If permission is granted, check subscription status:
   - If subscription is `suspended` and permission is in an operational module (`incidents`, `assets`, `drivers`, `ai`, `automation`), return `false`
   - Otherwise, proceed
7. If permission is granted, check tenant features:
   - If the feature corresponding to the permission's module is disabled, return `false`
8. Return the final boolean result

### 5.2 `AssignRoleToMember`

**Path**: `app/Domains/Access/Actions/AssignRoleToMember.php`

```php
public function execute(
    Membership $membership,
    string $roleCode,
): void
```

- Resolve the `Role` by `$roleCode`
- Validate the role's scope matches `tenant` (cannot assign `global` roles via this action)
- Update `membership.role_id` to the role's ID
- Optionally sync `membership.role` string column for backward compat mapping

### 5.3 `SyncRolePermissions`

**Path**: `app/Domains/Access/Actions/SyncRolePermissions.php`

```php
public function execute(
    Role $role,
    array $permissionCodes,
): void
```

- Resolve permission IDs from the `$permissionCodes` array
- Sync the `role_permission` pivot (detach removed, attach new)
- Clear cached permissions for all users with this role

## 6. Jobs

No dedicated jobs for this module. Permission lookups are synchronous and cached.

## 7. Domain Events

| Event | Payload | Dispatched When |
|-------|---------|-----------------|
| `RoleAssigned` | `Membership $membership, Role $role` | A role is assigned to a team member |
| `PermissionsSynced` | `Role $role, array $permissionCodes` | A role's permission set is updated |

## 8. Broadcasting Events

No broadcasting events. Permission changes take effect on the next request; no real-time push needed.

## 9. APIs / Endpoints

| Method | URI | Controller | Purpose |
|--------|-----|------------|---------|
| GET | `/{current_team}/settings/roles` | `RoleController@index` | List roles and their permissions |
| POST | `/{current_team}/settings/roles` | `RoleController@store` | Create a custom role |
| PUT | `/{current_team}/settings/roles/{role}` | `RoleController@update` | Update role permissions |
| DELETE | `/{current_team}/settings/roles/{role}` | `RoleController@destroy` | Delete a non-system role |
| PUT | `/{current_team}/settings/members/{membership}/role` | `MemberRoleController@update` | Assign role to team member |

## 10. Business Rules

1. Permissions are always evaluated within the context of the current team. There is no "teamless" permission check for tenant-scoped roles.
2. A user can have different roles in different teams via separate `Membership` records.
3. The role assigned to a membership defines which permissions the user has in that team.
4. Subscription status and tenant features can further restrict permissions — a granted permission can be denied if the subscription is `suspended` or the corresponding feature is disabled.
5. `super_admin` (global role on `users.global_role`) bypasses all tenant-level permission checks entirely.
6. Every sensitive action must be authorizable via a Laravel policy or gate that delegates to `AuthorizeAction`.
7. System roles (`is_system = true`) cannot be deleted or have their code changed. Their permissions can be customized per deployment via seeder.
8. Custom roles (non-system) can be created per tenant for tailored access control.
9. The `role` string column on `Membership` remains the source of truth for the legacy `TeamRole` enum. The new `role_id` FK is used for granular RBAC and takes precedence when present.

## 11. Integration with Other Modules

| Module | Integration Point |
|--------|-------------------|
| **Tenancy** | Subscription status (`suspended`, `canceled`, `expired`) restricts operational permissions. Tenant features can disable entire modules. |
| **Incidents** | Policies check `incidents.view`, `incidents.manage`, `incidents.resolve`, `incidents.close` |
| **Assets** | Policies check `assets.view`, `assets.manage` |
| **Drivers** | Policies check `drivers.view`, `drivers.manage` |
| **AI** | Policies check `ai.analysis.view`, `ai.analysis.execute` |
| **Analytics** | Policies check `reports.view`, `reports.export` |
| **Audit** | Policies check `audit.view`. Role and permission changes are logged by the Audit module. |
| **Tenant Config** | Policies check `config.view`, `config.manage` |
| **Notifications** | Permission context used to determine notification recipients |

## 12. Usage Metering

This module does not emit usage events directly. Authorization checks are not metered.

## 13. Technical Considerations

### Caching Strategy

- Cache each user's resolved permissions per team in Valkey with key `access:perms:{userId}:{teamId}` and 5-minute TTL.
- Invalidate on: role assignment change, role permission sync, membership update.
- `super_admin` check is done before cache lookup (no cache needed for super admins).

### Middleware Integration

Register a `can` middleware alias that delegates to `AuthorizeAction`:

```php
// Usage in routes
Route::middleware('can:incidents.manage')->group(function () {
    // ...
});
```

### Shared Inertia Props

Share the current user's permissions for the active team via `HandleInertiaRequests` middleware:

```php
'auth' => [
    'user' => $request->user(),
    'permissions' => $request->user()
        ? app(AuthorizeAction::class)->resolvePermissions($request->user(), currentTeam())
        : [],
],
```

This allows frontend components to conditionally render based on `usePage().props.auth.permissions`.

### Backward Compatibility

The existing `TeamRole` enum (`owner`, `admin`, `member`) continues to work via the `membership.role` string column. The new `role_id` FK provides granular RBAC on top of it:

- If `role_id` is set, use the role's permissions from the `role_permission` pivot.
- If `role_id` is null, fall back to a mapping from the `TeamRole` enum to a default role:
  - `owner` → `tenant_admin`
  - `admin` → `supervisor`
  - `member` → `viewer`

### Policy Pattern

Each domain module should define a policy that delegates to `AuthorizeAction`:

```php
class IncidentPolicy
{
    public function view(User $user, Incident $incident): bool
    {
        return app(AuthorizeAction::class)->execute($user, 'incidents.view', $incident->team);
    }

    public function manage(User $user, Incident $incident): bool
    {
        return app(AuthorizeAction::class)->execute($user, 'incidents.manage', $incident->team);
    }
}
```

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_user_with_viewer_role_cannot_manage_incidents` | User assigned `viewer` role is denied `incidents.manage` permission |
| `test_user_with_supervisor_role_can_resolve_incidents` | User assigned `supervisor` role is granted `incidents.resolve` permission |
| `test_super_admin_bypasses_all_tenant_checks` | User with `global_role = super_admin` passes all permission checks regardless of team membership |
| `test_user_has_different_roles_in_different_teams` | Same user has `supervisor` in Team A and `viewer` in Team B; permissions differ per team |
| `test_suspended_subscription_restricts_operational_permissions` | User with `supervisor` role in a team with `suspended` subscription is denied `incidents.manage` |
| `test_permission_check_respects_tenant_features` | Disabling a tenant feature blocks the corresponding module's permissions even for authorized roles |
| `test_assign_role_to_member_updates_membership` | `AssignRoleToMember` sets `role_id` on the membership record |
| `test_sync_role_permissions_updates_pivot` | `SyncRolePermissions` correctly attaches and detaches permissions |
| `test_system_roles_cannot_be_deleted` | Attempting to delete a role with `is_system = true` throws an exception |
| `test_custom_role_can_be_created_per_tenant` | A non-system role can be created and assigned within a team |
| `test_fallback_to_team_role_enum_when_role_id_is_null` | Membership without `role_id` falls back to mapped permissions from `TeamRole` enum |
| `test_permission_cache_is_invalidated_on_role_change` | After `AssignRoleToMember`, the Valkey cache key for the user+team is cleared |
| `test_shared_inertia_props_include_permissions` | Authenticated requests include `auth.permissions` in Inertia shared data |
