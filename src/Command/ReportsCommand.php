<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Report;
use App\Enum\ReportStatus;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:reports', description: 'Lists open reports, or resolves one (--resolve=ID with --dismiss, --suspend, --hide or --ban)')]
final class ReportsCommand extends Command
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
            ->addArgument('status', InputArgument::OPTIONAL, 'open | dismissed | actioned', 'open')
            ->addOption('resolve', null, InputOption::VALUE_REQUIRED, 'Report id to resolve')
            ->addOption('dismiss', null, InputOption::VALUE_NONE, 'Dismiss the report without action')
            ->addOption('suspend', null, InputOption::VALUE_NONE, 'Suspend the reported mother\'s messaging for 7 days')
            ->addOption('hide', null, InputOption::VALUE_NONE, 'Hide the reported dish / delete the reported review')
            ->addOption('ban', null, InputOption::VALUE_NONE, 'Ban the reported mother (no login, content hidden)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Resolution note');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null !== $input->getOption('resolve')) {
            $report = $this->em->find(Report::class, (int) $input->getOption('resolve'));
            if (null === $report) {
                $io->error('Report not found.');

                return Command::FAILURE;
            }
            $chosen = array_values(array_filter(ModerationService::RESOLUTIONS, static fn (string $a): bool => (bool) $input->getOption($a)));
            if (1 !== \count($chosen)) {
                $io->error('Choose exactly one of --dismiss, --suspend, --hide or --ban.');

                return Command::INVALID;
            }
            try {
                $this->moderation->resolve($report, $chosen[0], $input->getOption('note'));
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success(\sprintf('Report #%d %s.', $report->getId(), $report->getStatus()->value));

            return Command::SUCCESS;
        }

        $status = ReportStatus::tryFrom((string) $input->getArgument('status')) ?? ReportStatus::Open;
        $reports = $this->em->getRepository(Report::class)->findBy(['status' => $status], ['createdAt' => 'ASC'], 100);
        $io->table(
            ['#', 'When', 'Type', 'Reason', 'Reported', 'By', 'Details'],
            array_map(static fn (Report $r): array => [
                $r->getId(),
                $r->getCreatedAt()->format('Y-m-d H:i'),
                $r->getTargetType()->value.'#'.$r->getTargetId(),
                $r->getReason()->value,
                $r->getReportedUser()->getDisplayName().' (#'.$r->getReportedUser()->getId().')',
                $r->getReporter()->getDisplayName(),
                mb_strimwidth((string) $r->getDetails(), 0, 40, '…'),
            ], $reports),
        );

        return Command::SUCCESS;
    }
}
