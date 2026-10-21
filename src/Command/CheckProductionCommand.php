<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Refuses to start a production container that still runs on development defaults.
 */
#[AsCommand(name: 'app:check-production', description: 'Fails when production secrets are missing, weak or left at their development value')]
final class CheckProductionCommand extends Command
{
    /** Values shipped in the repository: never acceptable in production. */
    private const KNOWN_DEV_VALUES = [
        '!ChangeThisMercureHubJWTSecretKey!',
        'change-me',
        'abf0ecf3b95a8396e9d8f2fa6580804f8bc565b00d5391242e9a45418b300b96',
        'app',
        '!ChangeMe!',
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run the checks even outside APP_ENV=prod');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' !== $this->environment && !$input->getOption('force')) {
            $io->note(\sprintf('Environment "%s": production checks skipped (use --force to run them).', $this->environment));

            return Command::SUCCESS;
        }
        $problems = $this->problems(static fn (string $name): string => (string) ($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name) ?: ''));

        if ([] === $problems) {
            $io->success('Production configuration looks sane.');

            return Command::SUCCESS;
        }
        $io->error(array_merge(['Unsafe production configuration:'], array_map(static fn (string $p): string => ' - '.$p, $problems)));

        return Command::FAILURE;
    }

    /**
     * @param callable(string): string $env
     *
     * @return list<string>
     */
    public function problems(callable $env): array
    {
        $problems = [];
        foreach (['APP_SECRET', 'JWT_PASSPHRASE', 'MERCURE_JWT_SECRET'] as $name) {
            $value = $env($name);
            if ('' === $value) {
                $problems[] = $name.' is not set.';
            } elseif (\strlen($value) < 24) {
                $problems[] = $name.' must be at least 24 characters long.';
            } elseif (\in_array($value, self::KNOWN_DEV_VALUES, true)) {
                $problems[] = $name.' still has its development value.';
            }
        }

        $database = $env('DATABASE_URL');
        if ('' === $database) {
            $problems[] = 'DATABASE_URL is not set.';
        } elseif (str_contains($database, '127.0.0.1') || str_contains($database, 'localhost') || preg_match('#://[^:]+:(app|!ChangeMe!)@#', $database)) {
            $problems[] = 'DATABASE_URL points to localhost or uses a default password.';
        }

        $publicUrl = $env('MERCURE_PUBLIC_URL');
        if (!str_starts_with($publicUrl, 'https://')) {
            $problems[] = 'MERCURE_PUBLIC_URL must be an https:// URL.';
        }
        if (!str_starts_with($env('UPLOADS_BASE_URL'), 'https://')) {
            $problems[] = 'UPLOADS_BASE_URL must be an https:// URL.';
        }
        if ('1' === $env('API_DOCS_ENABLED') || 'true' === $env('API_DOCS_ENABLED')) {
            $problems[] = 'API_DOCS_ENABLED should be 0 in production.';
        }
        if ('' === $env('REDIS_URL')) {
            $problems[] = 'REDIS_URL is not set.';
        }

        return $problems;
    }
}
