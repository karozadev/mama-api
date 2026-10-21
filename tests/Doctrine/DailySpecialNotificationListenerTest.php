<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\Dish;
use App\Entity\User;
use App\Message\NotifyFollowersBatch;
use App\Message\SendDailySpecialNotification;
use App\Service\Notification\FollowerNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class DailySpecialNotificationListenerTest extends KernelTestCase
{
    private function makeDish(User $author, bool $special): Dish
    {
        return (new Dish())->setTitle('Romazava')->setDescription('Bouillon.')->setIngredients(['bœuf'])
            ->setSteps(['Cuire.'])->setPrice(12000)->setCategory('plat')->setIsDailySpecial($special)->setAuthor($author);
    }

    private function makeUser(string $email): User
    {
        return (new User())->setEmail($email)->setDisplayName($email)->setPassword('x');
    }

    public function testDailySpecialQueuesNotification(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $mother = $this->makeUser('m@test.mg');
        $em->persist($mother);
        $dish = $this->makeDish($mother, true);
        $em->persist($dish);
        $em->flush();

        $sent = array_map(static fn ($e) => $e->getMessage(), $transport->getSent());
        self::assertCount(1, $sent);
        self::assertInstanceOf(SendDailySpecialNotification::class, $sent[0]);
        self::assertSame($dish->getId(), $sent[0]->dishId);
    }

    public function testRegularDishDoesNotNotify(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $mother = $this->makeUser('m2@test.mg');
        $em->persist($mother);
        $em->persist($this->makeDish($mother, false));
        $em->flush();

        self::assertCount(0, $transport->getSent());
    }

    public function testFollowersAreNotifiedInBatches(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $mother = $this->makeUser('m3@test.mg');
        $follower = $this->makeUser('f@test.mg');
        $follower->follow($mother);
        $em->persist($mother);
        $em->persist($follower);
        $dish = $this->makeDish($mother, true);
        $em->persist($dish);
        $em->flush();

        $notifier = new class () implements FollowerNotifier {
            /** @var list<int> */
            public array $notified = [];

            public function notifyDailySpecial(Dish $dish, array $followerIds): void
            {
                $this->notified = $followerIds;
            }
        };
        $c->set(FollowerNotifier::class, $notifier);

        $transport = $c->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $c->get(\App\MessageHandler\SendDailySpecialNotificationHandler::class)(new SendDailySpecialNotification($dish->getId()));
        $batches = array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $transport->getSent()),
            static fn ($m) => $m instanceof NotifyFollowersBatch,
        ));

        self::assertCount(1, $batches);
        self::assertSame([$follower->getId()], $batches[0]->followerIds);
    }
}
