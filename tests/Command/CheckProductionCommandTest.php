<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CheckProductionCommand;
use PHPUnit\Framework\TestCase;

final class CheckProductionCommandTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function goodEnv(): array
    {
        return [
            'APP_SECRET' => str_repeat('a1', 20),
            'JWT_PASSPHRASE' => str_repeat('b2', 20),
            'MERCURE_JWT_SECRET' => str_repeat('c3', 20),
            'DATABASE_URL' => 'postgresql://app:Xk29sdjf83hs@database:5432/app?serverVersion=16',
            'MERCURE_PUBLIC_URL' => 'https://mama.example.com/.well-known/mercure',
            'UPLOADS_BASE_URL' => 'https://mama.example.com/uploads',
            'API_DOCS_ENABLED' => '0',
            'REDIS_URL' => 'redis://:secret@redis:6379',
        ];
    }

    /**
     * @param array<string, string> $env
     *
     * @return list<string>
     */
    private function problems(array $env): array
    {
        return (new CheckProductionCommand('prod'))->problems(static fn (string $name): string => $env[$name] ?? '');
    }

    public function testAcceptsAProperConfiguration(): void
    {
        self::assertSame([], $this->problems($this->goodEnv()));
    }

    public function testRejectsMissingWeakAndDevelopmentSecrets(): void
    {
        $env = $this->goodEnv();
        unset($env['APP_SECRET']);
        $env['JWT_PASSPHRASE'] = 'short';
        $env['MERCURE_JWT_SECRET'] = '!ChangeThisMercureHubJWTSecretKey!';

        $problems = $this->problems($env);
        self::assertContains('APP_SECRET is not set.', $problems);
        self::assertContains('JWT_PASSPHRASE must be at least 24 characters long.', $problems);
        self::assertContains('MERCURE_JWT_SECRET still has its development value.', $problems);
    }

    public function testRejectsDevelopmentInfrastructure(): void
    {
        $env = $this->goodEnv();
        $env['DATABASE_URL'] = 'postgresql://app:app@127.0.0.1:5432/app';
        $env['MERCURE_PUBLIC_URL'] = 'http://localhost:8081/.well-known/mercure';
        $env['UPLOADS_BASE_URL'] = 'http://localhost:8080/uploads';
        $env['API_DOCS_ENABLED'] = '1';
        unset($env['REDIS_URL']);

        self::assertCount(5, $this->problems($env));
    }
}
