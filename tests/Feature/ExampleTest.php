<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_root_no_longer_serves_the_welcome_page(): void
    {
        $response = $this->get('/');

        $response
            ->assertNotFound()
            ->assertDontSee('Laravel');
    }
}
