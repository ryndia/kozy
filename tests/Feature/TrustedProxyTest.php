<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_urls_keep_the_https_scheme_behind_the_tls_terminating_proxy()
    {
        Route::get('/proxy-scheme-probe', fn () => [
            'url' => url('/login'),
            'asset' => asset('build/app.css'),
            'secure' => request()->isSecure(),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.1'])
            ->get('/proxy-scheme-probe', ['X-Forwarded-Proto' => 'https']);

        $response->assertOk();
        $this->assertTrue($response->json('secure'));
        $this->assertStringStartsWith('https://', $response->json('url'));
        $this->assertStringStartsWith('https://', $response->json('asset'));
    }
}
