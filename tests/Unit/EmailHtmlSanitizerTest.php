<?php

use App\Services\Campaigns\EmailHtmlSanitizer;

it('removes scripts, event handlers and dangerous URLs but keeps email markup', function () {
    $html = <<<'HTML'
        <html><head><style>@media (max-width:600px){.c{width:100%}} p{color:red}</style><script>alert(1)</script></head>
        <body onload="x()">
          <table class="c" style="width:600px"><tr><td background="https://example.org/bg.png">
            <a href="javascript:alert(1)">mal</a>
            <a href="java&#09;script:alert(1)">mal2</a>
            <a href="https://example.org/ok?a=1&amp;b=2" onclick="steal()">bien</a>
            <a href="{{ enlace_baja }}">baja</a>
            <img src="data:image/png;base64,AAAA" alt="ok"><img src="data:text/html,<script>x</script>">
            <iframe src="https://evil"></iframe><form action="https://evil"><input name="x"></form>
            <div style="background:url(javascript:alert(1))">x</div>
            <svg onload="alert(1)"></svg>
          </td></tr></table>
        </body></html>
        HTML;

    $clean = (new EmailHtmlSanitizer)->sanitize($html);

    expect($clean)
        ->not->toContain('<script')->not->toContain('alert(1)')->not->toContain('onclick')->not->toContain('onload')
        ->not->toContain('<iframe')->not->toContain('<form')->not->toContain('<svg')->not->toContain('data:text/html')
        ->toContain('@media (max-width:600px)')
        ->toContain('href="https://example.org/ok?a=1&amp;b=2"')
        ->toContain('{{ enlace_baja }}')
        ->toContain('data:image/png;base64,AAAA')
        ->toContain('background="https://example.org/bg.png"')
        ->toContain('<table');
});
