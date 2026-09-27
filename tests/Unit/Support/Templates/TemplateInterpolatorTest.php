<?php

namespace Tests\Unit\Support\Templates;

use App\Support\Templates\TemplateInterpolator;
use PHPUnit\Framework\TestCase;

class TemplateInterpolatorTest extends TestCase
{
    private function render(string $template, array $variables = []): string
    {
        return (new TemplateInterpolator)->render($template, $variables);
    }

    public function test_it_interpolates_blade_style_and_bare_variables(): void
    {
        $this->assertSame(
            'Unidad T-7 · Ana — ok',
            $this->render('Unidad {{ $asset_name }} · {{driver_name}} — {{ status }}', [
                'asset_name' => 'T-7',
                'driver_name' => 'Ana',
                'status' => 'ok',
            ]),
        );
    }

    public function test_it_resolves_dotted_paths(): void
    {
        $this->assertSame(
            'Zona: Norte (3)',
            $this->render('Zona: {{ location.zone }} ({{ $location.count }})', [
                'location' => ['zone' => 'Norte', 'count' => 3],
            ]),
        );
    }

    public function test_missing_variables_render_empty_and_booleans_as_words(): void
    {
        $this->assertSame('a= b=sí c=no', $this->render('a={{ missing }} b={{ yes }} c={{ nope }}', [
            'yes' => true,
            'nope' => false,
        ]));
    }

    public function test_values_are_not_html_escaped_unless_requested(): void
    {
        $this->assertSame('A & B <x>', $this->render('{{ v }}', ['v' => 'A & B <x>']));
        $this->assertSame('A &amp; B &lt;x&gt;', (new TemplateInterpolator)->render('{{ v }}', ['v' => 'A & B <x>'], escapeHtml: true));
    }

    public function test_php_expressions_are_never_evaluated(): void
    {
        $out = $this->render('x{{ \App\Models\User::count() }}y{{ system("id") }}z{!! phpinfo() !!}', []);

        $this->assertSame('xyz', $out);
    }

    public function test_blade_directives_are_left_as_inert_text(): void
    {
        $template = "@php echo 'pwned'; @endphp @if(true) si @endif";

        $this->assertSame($template, $this->render($template));
    }

    public function test_interpolated_values_are_not_reinterpreted_as_templates(): void
    {
        $this->assertSame('{{ secret }}', $this->render('{{ v }}', ['v' => '{{ secret }}', 'secret' => 'leak']));
    }
}
