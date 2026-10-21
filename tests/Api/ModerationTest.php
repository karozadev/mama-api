<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\DishVisibility;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ModerationTest extends ApiTestCase
{
    /** Mothers who follow each other can talk. */
    private function friends(User $a, User $b): void
    {
        $this->request('POST', '/api/users/'.$b->getId().'/follow', null, $a);
        $this->request('POST', '/api/users/'.$a->getId().'/follow', null, $b);
    }

    private function say(User $from, User $to, string $body = 'Salut'): int
    {
        $data = $this->request('POST', '/api/conversations/'.$to->getId().'/messages', ['body' => $body], $from);

        return (int) ($data['id'] ?? 0);
    }

    public function testBlockingUnfollowsAndStopsMessagingBothWays(): void
    {
        $a = $this->createUser('a@test.mg');
        $b = $this->createUser('b@test.mg');
        $this->friends($a, $b);

        $this->request('POST', '/api/users/'.$b->getId().'/block', null, $a);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->request('GET', '/api/me/following', null, $a)['ids']);
        self::assertSame([], $this->request('GET', '/api/me/following', null, $b)['ids']);

        $this->say($b, $a);
        self::assertResponseStatusCodeSame(403);
        $this->say($a, $b);
        self::assertResponseStatusCodeSame(403);
        $this->request('POST', '/api/users/'.$a->getId().'/follow', null, $b);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBlockedMotherCannotRefollowAndBlockCanBeLifted(): void
    {
        $a = $this->createUser('a2@test.mg');
        $b = $this->createUser('b2@test.mg');
        $this->request('POST', '/api/users/'.$b->getId().'/block', null, $a);
        self::assertSame([$b->getId()], array_column($this->request('GET', '/api/me/blocks', null, $a), 'id'));

        $this->request('DELETE', '/api/users/'.$b->getId().'/block', null, $a);
        self::assertSame([], $this->request('GET', '/api/me/blocks', null, $a));
        $this->request('POST', '/api/users/'.$b->getId().'/follow', null, $a);
        self::assertResponseIsSuccessful();
    }

    public function testCannotBlockYourself(): void
    {
        $a = $this->createUser('a3@test.mg');
        $this->request('POST', '/api/users/'.$a->getId().'/block', null, $a);
        self::assertResponseStatusCodeSame(422);
    }

    public function testBlockerNoLongerSeesTheThreadNorItsUnreadCount(): void
    {
        $a = $this->createUser('a4@test.mg');
        $b = $this->createUser('b4@test.mg');
        $this->friends($a, $b);
        $this->say($b, $a, 'Bonjour');
        self::assertSame(1, $this->request('GET', '/api/me', null, $a)['unreadMessages']);

        $this->request('POST', '/api/users/'.$b->getId().'/block', null, $a);
        self::assertSame(0, $this->request('GET', '/api/me', null, $a)['unreadMessages']);
        self::assertSame([], $this->request('GET', '/api/me/conversations', null, $a));
        $history = $this->request('GET', '/api/conversations/'.$b->getId().'/messages', null, $a);
        self::assertTrue($history['blockedByMe']);
        self::assertFalse($history['canMessage']);
    }

    public function testBlockedMotherDoesNotNotifyTheBlocker(): void
    {
        $mother = $this->createUser('mama5@test.mg');
        $pest = $this->createUser('pest@test.mg');
        $dish = (new Dish())->setTitle('Romazava')->setDescription('Bouillon.')->setIngredients(['bœuf'])
            ->setSteps(['Cuire.'])->setPrice(4500)->setCategory('plat')->setAuthor($mother);
        $this->em()->persist($dish);
        $this->em()->flush();

        $this->request('POST', '/api/users/'.$pest->getId().'/block', null, $mother);
        $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $pest);
        $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$dish->getId(), 'rating' => 1], $pest);

        self::assertSame(0, $this->request('GET', '/api/me/notifications', null, $mother)['unread']);
    }

    public function testReportAUserAndTheDuplicateIsIgnored(): void
    {
        $reporter = $this->createUser('r@test.mg');
        $bad = $this->createUser('bad@test.mg');

        $data = $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $bad->getId(), 'reason' => 'harassment', 'details' => ' Insultes répétées '], $reporter);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($data['duplicate']);

        $again = $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $bad->getId(), 'reason' => 'spam'], $reporter);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($again['duplicate']);
        self::assertSame(1, $this->em()->getRepository(Report::class)->count([]));
    }

    public function testReportValidation(): void
    {
        $me = $this->createUser('r2@test.mg');
        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $me->getId(), 'reason' => 'spam'], $me);
        self::assertResponseStatusCodeSame(422); // yourself
        $this->request('POST', '/api/reports', ['type' => 'nonsense', 'id' => 1, 'reason' => 'spam'], $me);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => 1, 'reason' => 'gossip'], $me);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => 99999999, 'reason' => 'spam'], $me);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => 5, 'reason' => 'spam']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOnlyTheRecipientCanReportAMessageAndASnapshotIsKept(): void
    {
        $a = $this->createUser('a6@test.mg');
        $b = $this->createUser('b6@test.mg');
        $outsider = $this->createUser('out@test.mg');
        $this->friends($a, $b);
        $this->say($a, $b, 'Premier');
        $id = $this->say($a, $b, 'Message abusif');

        $this->request('POST', '/api/reports', ['type' => 'message', 'id' => $id, 'reason' => 'harassment'], $outsider);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/api/reports', ['type' => 'message', 'id' => $id, 'reason' => 'harassment'], $a);
        self::assertResponseStatusCodeSame(404); // the sender cannot report their own message

        $this->request('POST', '/api/reports', ['type' => 'message', 'id' => $id, 'reason' => 'harassment'], $b);
        self::assertResponseStatusCodeSame(201);

        $report = $this->em()->getRepository(Report::class)->findOneBy(['targetId' => $id]);
        self::assertSame($a->getId(), $report?->getReportedUser()->getId());
        self::assertSame(['Premier', 'Message abusif'], array_column($report?->getContext()['messages'] ?? [], 'body'));
    }

    public function testHiddenDishCannotBeReported(): void
    {
        $author = $this->createUser('au@test.mg');
        $stranger = $this->createUser('st@test.mg');
        $dish = (new Dish())->setTitle('Secret')->setDescription('x')->setIngredients(['riz'])->setSteps(['a'])->setPrice(1)
            ->setCategory('plat')->setAuthor($author)->setVisibility(DishVisibility::Private);
        $this->em()->persist($dish);
        $this->em()->flush();

        $this->request('POST', '/api/reports', ['type' => 'dish', 'id' => $dish->getId(), 'reason' => 'spam'], $stranger);
        self::assertResponseStatusCodeSame(404);
    }

    public function testThreeDistinctReportersSuspendMessagingAutomatically(): void
    {
        $bad = $this->createUser('bad2@test.mg');
        $victim = $this->createUser('victim@test.mg');
        $this->friends($bad, $victim);
        self::assertGreaterThan(0, $this->say($bad, $victim));

        foreach (['x', 'y'] as $n) {
            $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $bad->getId(), 'reason' => 'spam'], $this->createUser($n.'@test.mg'));
        }
        self::assertGreaterThan(0, $this->say($bad, $victim)); // two reports: still allowed

        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $bad->getId(), 'reason' => 'scam'], $this->createUser('z@test.mg'));
        $this->say($bad, $victim);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->request('GET', '/api/me', null, $bad)['messagingSuspendedUntil']);
    }

    public function testAdminListsAndResolvesReports(): void
    {
        $admin = $this->createUser('admin@test.mg', ['ROLE_ADMIN']);
        $reporter = $this->createUser('rep@test.mg');
        $bad = $this->createUser('bad3@test.mg');
        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $bad->getId(), 'reason' => 'inappropriate', 'details' => 'Photos choquantes'], $reporter);

        $this->request('GET', '/api/admin/reports', null, $reporter);
        self::assertResponseStatusCodeSame(403);

        $list = $this->request('GET', '/api/admin/reports', null, $admin);
        self::assertCount(1, $list);
        self::assertSame('Photos choquantes', $list[0]['details']);
        self::assertSame($bad->getId(), $list[0]['reportedUser']['id']);

        $this->request('POST', '/api/admin/reports/'.$list[0]['id'].'/resolve', ['action' => 'explode'], $admin);
        self::assertResponseStatusCodeSame(422);
        $done = $this->request('POST', '/api/admin/reports/'.$list[0]['id'].'/resolve', ['action' => 'suspend', 'note' => 'Récidive'], $admin);
        self::assertSame('actioned', $done['status']);
        self::assertCount(0, $this->request('GET', '/api/admin/reports', null, $admin));
        self::assertNotNull($this->request('GET', '/api/me', null, $bad)['messagingSuspendedUntil']);
    }

    public function testConsoleCommandListsAndDismisses(): void
    {
        $reporter = $this->createUser('rep2@test.mg');
        $bad = $this->createUser('bad4@test.mg');
        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $bad->getId(), 'reason' => 'spam'], $reporter);
        $report = $this->em()->getRepository(Report::class)->findOneBy([]);

        $application = new Application(static::getContainer()->get('kernel'));
        $command = new CommandTester($application->find('app:reports'));
        $command->execute([]);
        self::assertStringContainsString('spam', $command->getDisplay());

        $command->execute(['--resolve' => $report?->getId(), '--dismiss' => true, '--note' => 'RAS']);
        self::assertStringContainsString('dismissed', $command->getDisplay());
        $command->execute(['--resolve' => $report?->getId()]);
        self::assertSame(2, $command->getStatusCode()); // neither --suspend nor --dismiss
    }
}
