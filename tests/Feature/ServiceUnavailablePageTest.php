<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ServiceUnavailablePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_requests_receive_styled_503_blade_page(): void
    {
        $response = $this->get('/__test-503');

        $response->assertStatus(503)
            ->assertSee('503')
            ->assertSee('Service Unavailable')
            ->assertSee('status-page')
            ->assertSee('panel-subtle');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->get('/__test-503', function () {
            throw new HttpException(503, 'Database migration sedang berjalan.');
        });
    }
}
