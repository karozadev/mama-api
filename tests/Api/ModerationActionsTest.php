<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\ModerationAction;
use App\Entity\Report;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ModerationActionsTest extends ApiTestCase
{
    private function dish(User $author, string $title = 'Romazava'): Dish
    {
        $dish = (new Dish())->setTitle($title)->setDescription('Bouillon.')->setIngredients(['bœuf'])
            ->setSteps(['Cuire.'])->setPrice(4500)->setCategory('plat')->setAuthor($author);
        $this->em()->persist($dish);
        $this->em()->flush();

        return $dish;
    }

    private function admin(): User
    {
        return $this->createUser('admin@test.mg', ['ROLE_ADMIN']);
    }

    public function testBanLocksTheAccountAndHidesItsContent(): void
    {
        $admin = $this->admin();
        $bad = $this->createUser('bad@test.mg');
        $viewer = $this->createUser('viewer@test.mg');
        $fan = $this->createUser('fan@test.mg');
        $dish = $this->dish($bad, 'Ravitoto');
        $this->request('POST', '/api/users/'.$bad->getId().'/follow', null, $fan);
        $this->request('POST', '/api/users/'.$fan->getId().'/follow', null, $bad);

        $this->request('POST', '/api/admin/users/'.$bad->getId().'/ban', ['reason' => 'Arnaque répétée'], $admin);
        self::assertResponseIsSuccessful();

        // cannot log in nor use a token
        $this->request('POST', '/api/users', ['email' => 'x@test.mg', 'plainPassword' => 'motdepasse123', 'displayName' => 'X']);
        $this->request('GET', '/api/me', null, $bad);
        self::assertResponseStatusCodeSame(401);

        // content and profile disappear for everyone
        self::assertSame([], $this->request('GET', '/api/dishes', null, $viewer));
        $this->request('GET', '/api/dishes/'.$dish->getId(), null, $viewer);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/users/'.$bad->getId(), null, $viewer);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->request('GET', '/api/search?q=ravitoto', null, $viewer)['dishes']);
        self::assertSame([], $this->request('GET', '/api/search?q=bad', null, $viewer)['mothers']);
        self::assertSame([], $this->request('GET', '/api/me/following', null, $fan)['mothers']);
        self::assertSame([], $this->request('GET', '/api/discover/mothers', null, $viewer));

        // nobody can follow or write to them
        $this->request('POST', '/api/users/'.$bad->getId().'/follow', null, $viewer);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/api/conversations/'.$bad->getId().'/messages', ['body' => 'Salut'], $fan);
        self::assertResponseStatusCodeSame(404);

        // moderators still see the profile
        $this->request('GET', '/api/users/'.$bad->getId(), null, $admin);
        self::assertResponseIsSuccessful();
    }

    public function testBannedLoginIsRefusedWithAClearMessage(): void
    {
        $admin = $this->admin();
        $this->request('POST', '/api/users', ['email' => 'later@test.mg', 'plainPassword' => 'motdepasse123', 'displayName' => 'Mama Later']);
        $later = $this->em()->getRepository(User::class)->findOneBy(['email' => 'later@test.mg']);
        $this->request('POST', '/api/admin/users/'.$later?->getId().'/ban', null, $admin);

        $data = $this->request('POST', '/api/login_check', ['email' => 'later@test.mg', 'password' => 'motdepasse123']);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('suspendu', (string) ($data['message'] ?? ''));
    }

    public function testUnbanRestoresEverything(): void
    {
        $admin = $this->admin();
        $mother = $this->createUser('mama@test.mg');
        $viewer = $this->createUser('viewer2@test.mg');
        $dish = $this->dish($mother);

        $this->request('POST', '/api/admin/users/'.$mother->getId().'/ban', null, $admin);
        $this->request('GET', '/api/dishes/'.$dish->getId(), null, $viewer);
        self::assertResponseStatusCodeSame(404);

        $this->request('DELETE', '/api/admin/users/'.$mother->getId().'/ban', null, $admin);
        $this->request('GET', '/api/dishes/'.$dish->getId(), null, $viewer);
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/me', null, $mother);
        self::assertResponseIsSuccessful();
    }

    public function testAdministratorsAndSelfCannotBeBanned(): void
    {
        $admin = $this->admin();
        $other = $this->createUser('other-admin@test.mg', ['ROLE_ADMIN']);

        $this->request('POST', '/api/admin/users/'.$admin->getId().'/ban', null, $admin);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/admin/users/'.$other->getId().'/ban', null, $admin);
        self::assertResponseStatusCodeSame(422);
    }

    public function testModeratorActionsAreRestrictedToAdmins(): void
    {
        $user = $this->createUser('plain@test.mg');
        $bad = $this->createUser('bad2@test.mg');
        $dish = $this->dish($bad);

        foreach ([
            ['POST', '/api/admin/users/'.$bad->getId().'/ban'],
            ['POST', '/api/admin/dishes/'.$dish->getId().'/hide'],
            ['DELETE', '/api/admin/reviews/1'],
            ['GET', '/api/admin/actions'],
        ] as [$method, $uri]) {
            $this->request($method, $uri, null, $user);
            self::assertResponseStatusCodeSame(403, "$method $uri");
        }
    }

    public function testHidingADishRemovesItEvenForItsAuthor(): void
    {
        $admin = $this->admin();
        $mother = $this->createUser('mama2@test.mg');
        $dish = $this->dish($mother);

        $this->request('POST', '/api/admin/dishes/'.$dish->getId().'/hide', ['reason' => 'Photo inappropriée'], $admin);
        $this->request('GET', '/api/dishes/'.$dish->getId(), null, $mother);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->request('GET', '/api/dishes', null, $mother));

        $this->request('DELETE', '/api/admin/dishes/'.$dish->getId().'/hide', null, $admin);
        $this->request('GET', '/api/dishes/'.$dish->getId(), null, $mother);
        self::assertResponseIsSuccessful();
    }

    public function testDeletingAReviewRecomputesTheRating(): void
    {
        $admin = $this->admin();
        $mother = $this->createUser('mama3@test.mg');
        $dish = $this->dish($mother);
        $a = $this->createUser('a@test.mg');
        $b = $this->createUser('b@test.mg');
        $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$dish->getId(), 'rating' => 5], $a);
        $troll = $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$dish->getId(), 'rating' => 1, 'comment' => 'Nul'], $b);
        self::assertSame(3.0, (float) $this->request('GET', '/api/dishes/'.$dish->getId())['averageRating']);

        $this->request('DELETE', '/api/admin/reviews/'.$troll['id'], ['reason' => 'Insulte'], $admin);
        self::assertResponseStatusCodeSame(204);

        $after = $this->request('GET', '/api/dishes/'.$dish->getId());
        self::assertSame(5.0, (float) $after['averageRating']);
        self::assertSame(1, $after['reviewCount']);
    }

    public function testResolvingReportsWithBanAndHide(): void
    {
        $admin = $this->admin();
        $bad = $this->createUser('bad3@test.mg');
        $reporter = $this->createUser('rep@test.mg');
        $dish = $this->dish($bad);
        $this->request('POST', '/api/reports', ['type' => 'dish', 'id' => $dish->getId(), 'reason' => 'inappropriate'], $reporter);
        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $bad->getId(), 'reason' => 'scam'], $reporter);
        $reports = $this->request('GET', '/api/admin/reports', null, $admin);
        $byType = array_column($reports, 'id', 'type');

        // "hide" does not apply to a report about a user
        $this->request('POST', '/api/admin/reports/'.$byType['user'].'/resolve', ['action' => 'hide'], $admin);
        self::assertResponseStatusCodeSame(422);

        $this->request('POST', '/api/admin/reports/'.$byType['dish'].'/resolve', ['action' => 'hide', 'note' => 'Contenu choquant'], $admin);
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/dishes/'.$dish->getId(), null, $reporter);
        self::assertResponseStatusCodeSame(404);

        $this->request('POST', '/api/admin/reports/'.$byType['user'].'/resolve', ['action' => 'ban'], $admin);
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/me', null, $bad);
        self::assertResponseStatusCodeSame(401);
    }

    public function testEveryModeratorActionIsAudited(): void
    {
        $admin = $this->admin();
        $bad = $this->createUser('bad4@test.mg');
        $dish = $this->dish($bad);
        $this->request('POST', '/api/admin/dishes/'.$dish->getId().'/hide', ['reason' => 'Spam'], $admin);
        $this->request('POST', '/api/admin/users/'.$bad->getId().'/ban', ['reason' => 'Récidive'], $admin);

        $log = $this->request('GET', '/api/admin/actions', null, $admin);
        self::assertSame(['ban', 'hide-dish'], array_column($log, 'action'));
        self::assertSame('Récidive', $log[0]['reason']);
        self::assertSame($admin->getDisplayName(), $log[0]['admin']);
        self::assertSame(2, $this->em()->getRepository(ModerationAction::class)->count([]));
    }

    public function testConsoleCommandsBanAndLift(): void
    {
        $bad = $this->createUser('bad5@test.mg');
        $application = new Application(static::getContainer()->get('kernel'));

        $ban = new CommandTester($application->find('app:user:ban'));
        $ban->execute(['email' => 'BAD5@test.mg', '--reason' => 'Spam']);
        self::assertSame(0, $ban->getStatusCode());
        $this->request('GET', '/api/me', null, $bad);
        self::assertResponseStatusCodeSame(401);

        $ban->execute(['email' => 'bad5@test.mg', '--lift' => true]);
        $this->request('GET', '/api/me', null, $bad);
        self::assertResponseIsSuccessful();

        $ban->execute(['email' => 'nobody@test.mg']);
        self::assertSame(1, $ban->getStatusCode());
    }

    public function testReportsCommandCanBan(): void
    {
        $bad = $this->createUser('bad6@test.mg');
        $reporter = $this->createUser('rep6@test.mg');
        $this->request('POST', '/api/reports', ['type' => 'user', 'id' => $bad->getId(), 'reason' => 'scam'], $reporter);
        $report = $this->em()->getRepository(Report::class)->findOneBy([]);

        $command = new CommandTester((new Application(static::getContainer()->get('kernel')))->find('app:reports'));
        $command->execute(['--resolve' => $report?->getId(), '--ban' => true, '--note' => 'Arnaque']);
        self::assertSame(0, $command->getStatusCode());
        $this->request('GET', '/api/me', null, $bad);
        self::assertResponseStatusCodeSame(401);
    }
}
