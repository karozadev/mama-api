<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:role', description: 'Adds or removes a role (ROLE_ADMIN for moderators, ROLE_CHEF for verified cooks)')]
final class UserRoleCommand extends Command
{
    private const ALLOWED = ['ROLE_ADMIN', 'ROLE_CHEF'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail of the user')
            ->addOption('add', null, InputOption::VALUE_REQUIRED, 'Role to add')
            ->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Role to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim((string) $input->getArgument('email')))]);
        if (null === $user) {
            $io->error('No user with this e-mail.');

            return Command::FAILURE;
        }

        $add = $input->getOption('add');
        $remove = $input->getOption('remove');
        foreach ([$add, $remove] as $role) {
            if (null !== $role && !\in_array($role, self::ALLOWED, true)) {
                $io->error('Role must be one of: '.implode(', ', self::ALLOWED));

                return Command::INVALID;
            }
        }

        $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER']));
        if (null !== $add) {
            $roles[] = $add;
        }
        if (null !== $remove) {
            $roles = array_values(array_diff($roles, [$remove]));
        }
        $user->setRoles(array_values(array_unique($roles)));
        $this->em->flush();

        $io->success(\sprintf('%s now has: %s', $user->getEmail(), implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
