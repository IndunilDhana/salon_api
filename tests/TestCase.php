<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    private string $testDatabase;

    protected function setUp(): void
    {
        $this->testDatabase = tempnam(sys_get_temp_dir(), 'salon-tests-');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE='.$this->testDatabase);
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $this->testDatabase;
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        DB::purge('central');
        DB::purge('tenant');
        parent::tearDown();
        if (is_file($this->testDatabase)) {
            unlink($this->testDatabase);
        }
    }

    public function registerOwner(string $email = 'owner@example.test', string $slug = 'studio-test', bool $completeBranchSetup = true): array
    {
        $session = $this->postJson('/api/v1/register', ['name' => 'Salon Owner', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password', 'salon_name' => str_replace('-', ' ', $slug), 'phone' => '077'.str_pad((string) (crc32($email) % 10000000), 7, '0', STR_PAD_LEFT)])->assertCreated()->json();
        if ($completeBranchSetup) {
            $headers = $this->headers($session['token'], $slug);
            $this->postJson('/api/v1/branches', ['name' => 'Main Branch', 'code' => 'MAIN'], $headers)->assertCreated();
            $session = [...$session, ...$this->getJson('/api/v1/me', $headers)->assertOk()->json()];
        }

        return $session;
    }

    public function headers(string $token, string $slug): array
    {
        return ['Authorization' => 'Bearer '.$token, 'X-Tenant' => $slug];
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // Independent bearer requests must not inherit a cached guard user in this shared test process.
        Auth::forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
