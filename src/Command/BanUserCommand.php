<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:ban', description: 'Bans an account (no login, content hidden), or lifts the ban with --lift')]
final class BanUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail of the account')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason kept in the audit trail')
            ->addOption('lift', null, InputOption::VALUE_NONE, 'Lift the ban');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim((string) $input->getArgument('email')))]);
        if (null === $user) {
            $io->error('No user with this e-mail.');

            return Command::FAILURE;
        }

        if ($input->getOption('lift')) {
            $this->moderation->unban($user);
            $io->success($user->getEmail().' is no longer banned.');

            return Command::SUCCESS;
        }
        try {
            $this->moderation->ban($user, $input->getOption('reason'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success($user->getEmail().' is banned.');

        return Command::SUCCESS;
    }
}
