<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\DemoRequestStatus;
use App\Domains\Tenancy\Models\DemoRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\DemoRequestReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class DemoRequestTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ana Prospecto',
            'company' => 'Transportes del Norte',
            'email' => 'Ana@TransNorte.mx',
            'phone' => '+52 81 1234 5678',
            'fleet_size' => '51-200',
            'message' => 'Queremos ver botones de pánico.',
            ...$overrides,
        ];
    }

    public function test_public_page_renders_with_fleet_sizes(): void
    {
        $this->get(route('demo-request.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('demo-request')
                ->where('fleetSizes', DemoRequest::FLEET_SIZES));
    }

    public function test_submission_is_stored_and_emailed_to_every_super_admin(): void
    {
        Notification::fake();
        $adminA = User::factory()->superAdmin()->create();
        $adminB = User::factory()->superAdmin()->create();
        $regular = User::factory()->create();

        $this->from(route('demo-request.create'))
            ->post(route('demo-request.store'), $this->payload())
            ->assertRedirect(route('demo-request.create'))
            ->assertSessionHasNoErrors();

        $demo = DemoRequest::query()->sole();
        $this->assertSame('Transportes del Norte', $demo->company);
        $this->assertSame('ana@transnorte.mx', $demo->email);
        $this->assertSame(DemoRequestStatus::New, $demo->status);
        $this->assertSame(2, $demo->notified_recipients);
        $this->assertNotNull($demo->notified_at);

        Notification::assertSentTo([$adminA, $adminB], DemoRequestReceived::class,
            fn (DemoRequestReceived $n) => $n->demoRequest->is($demo));
        Notification::assertNotSentTo($regular, DemoRequestReceived::class);

        $this->assertSystemLogged('tenancy.demo_request.received', fn ($ctx) => $ctx['input']['demo_request_id'] === $demo->id
            && $ctx['calc']['has_phone'] === true);
        $this->assertSystemLogged('tenancy.demo_request.notified', fn ($ctx) => $ctx['result']['recipients'] === 2);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_mail_contains_contact_details_and_replies_to_prospect(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $demo = DemoRequest::factory()->create([
            'name' => 'Ana Prospecto',
            'company' => 'Transportes del Norte',
            'email' => 'ana@transnorte.mx',
        ]);

        $mail = (new DemoRequestReceived($demo))->toMail($admin);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString('Transportes del Norte', (string) $mail->subject);
        $this->assertSame([['ana@transnorte.mx', 'Ana Prospecto']], $mail->replyTo);
        $this->assertSame('ana@transnorte.mx', $mail->detailRows()['Correo'] ?? null);
        $this->assertStringContainsString('ana@transnorte.mx', (string) $mail->render());
        $this->assertSame(route('admin.demo-requests.index'), $mail->actionUrl);
    }

    public function test_optional_fields_can_be_omitted(): void
    {
        Notification::fake();
        User::factory()->superAdmin()->create();

        $this->post(route('demo-request.store'), $this->payload(['phone' => '', 'message' => '']))
            ->assertSessionHasNoErrors();

        $demo = DemoRequest::query()->sole();
        $this->assertNull($demo->phone);
        $this->assertNull($demo->message);
    }

    public function test_invalid_submission_is_rejected_without_storing(): void
    {
        Notification::fake();

        $this->post(route('demo-request.store'), $this->payload([
            'email' => 'no-es-correo',
            'fleet_size' => '9999',
            'company' => '',
            'phone' => 'llámame',
        ]))->assertSessionHasErrors(['email', 'fleet_size', 'company', 'phone']);

        $this->assertSame(0, DemoRequest::query()->count());
        Notification::assertNothingSent();
    }

    public function test_honeypot_submission_is_silently_dropped(): void
    {
        Notification::fake();
        User::factory()->superAdmin()->create();

        $this->post(route('demo-request.store'), $this->payload(['website' => 'http://spam.example']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DemoRequest::query()->count());
        Notification::assertNothingSent();
        $this->assertSystemLogged('tenancy.demo_request.rejected', fn ($ctx) => $ctx['reason'] === 'honeypot');
    }

    public function test_submissions_are_throttled(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('demo-request.store'), $this->payload(['email' => "p{$i}@example.com"]))
                ->assertRedirect();
        }

        $this->post(route('demo-request.store'), $this->payload())->assertTooManyRequests();
        $this->assertSame(5, DemoRequest::query()->count());
    }

    public function test_request_is_kept_when_the_mail_fails(): void
    {
        User::factory()->superAdmin()->create();
        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('smtp down'));

        $this->post(route('demo-request.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $demo = DemoRequest::query()->sole();
        $this->assertNull($demo->notified_at);
        $this->assertSame(0, $demo->notified_recipients);
        $this->assertSystemLogged('tenancy.demo_request.notify_failed', fn ($ctx) => $ctx['reason'] === 'send_failed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_without_super_admins_the_request_is_still_stored(): void
    {
        Notification::fake();

        $this->post(route('demo-request.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, DemoRequest::query()->count());
        Notification::assertNothingSent();
        $this->assertSystemLogged('tenancy.demo_request.notified', fn ($ctx) => $ctx['reason'] === 'no_recipients');
    }

    public function test_console_lists_requests_with_counts_and_status_filter(): void
    {
        $admin = User::factory()->superAdmin()->create();
        DemoRequest::factory()->count(2)->create();
        DemoRequest::factory()->contacted()->create(['company' => 'Ya Contactada']);

        $this->actingAs($admin)
            ->get(route('admin.demo-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/demo-requests/index')
                ->has('requests', 3)
                ->where('counts.new', 2)
                ->where('counts.contacted', 1)
                ->where('counts.closed', 0)
                ->where('adminBadges.demoRequestsNew', 2));

        $this->actingAs($admin)
            ->get(route('admin.demo-requests.index', ['status' => 'contacted']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('requests', 1)
                ->where('requests.0.company', 'Ya Contactada')
                ->where('filters.status', 'contacted'));
    }

    public function test_super_admin_moves_the_follow_up_status(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $demo = DemoRequest::factory()->create();

        $this->actingAs($admin)
            ->from(route('admin.demo-requests.index'))
            ->put(route('admin.demo-requests.update', $demo), ['status' => 'contacted'])
            ->assertRedirect(route('admin.demo-requests.index'));

        $demo->refresh();
        $this->assertSame(DemoRequestStatus::Contacted, $demo->status);
        $this->assertSame($admin->id, $demo->handled_by_user_id);
        $this->assertNotNull($demo->status_changed_at);
        $this->assertSystemLogged('tenancy.demo_request.status_changed', fn ($ctx) => $ctx['result']['previous_status'] === 'new'
            && $ctx['result']['status'] === 'contacted');

        $this->actingAs($admin)
            ->put(route('admin.demo-requests.update', $demo), ['status' => 'contacted']);
        $this->assertSystemLogged('tenancy.demo_request.status_changed', fn ($ctx) => ($ctx['reason'] ?? null) === 'same_status');

        $this->actingAs($admin)
            ->put(route('admin.demo-requests.update', $demo), ['status' => 'archivada'])
            ->assertSessionHasErrors('status');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_tenant_members_cannot_see_or_touch_demo_requests(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $owner = User::factory()->create();
        $team->members()->attach($owner, ['role' => 'owner']);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $demo = DemoRequest::factory()->create();

        $this->actingAs($owner)->get(route('admin.demo-requests.index'))->assertForbidden();
        $this->actingAs($owner)
            ->put(route('admin.demo-requests.update', $demo), ['status' => 'closed'])
            ->assertForbidden();
        $this->assertSame(DemoRequestStatus::New, $demo->fresh()->status);

        $this->actingAs($owner)
            ->get(route('dashboard', $team))
            ->assertInertia(fn (Assert $page) => $page->where('adminBadges', null));
    }

    public function test_guests_are_sent_to_login_from_the_console(): void
    {
        $this->get(route('admin.demo-requests.index'))->assertRedirect(route('login'));
    }
}
