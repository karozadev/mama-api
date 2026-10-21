<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\User;
use App\Enum\DishVisibility;

final class CollectionTest extends ApiTestCase
{
    private function dish(User $author, string $title, DishVisibility $visibility = DishVisibility::Public): Dish
    {
        $dish = (new Dish())->setTitle($title)->setDescription('Un bon plat.')->setIngredients(['riz'])
            ->setSteps(['Cuire.'])->setPrice(2000)->setCategory('plat')->setAuthor($author)->setVisibility($visibility);
        $this->em()->persist($dish);
        $this->em()->flush();

        return $dish;
    }

    public function testCreateFillAndShowACollection(): void
    {
        $owner = $this->createUser('owner@test.mg');
        $author = $this->createUser('author@test.mg');
        $romazava = $this->dish($author, 'Romazava');
        $kitoza = $this->dish($author, 'Kitoza');

        $collection = $this->request('POST', '/api/collections', ['name' => '  Classiques malgaches '], $owner);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Classiques malgaches', $collection['name']);
        $id = $collection['id'];

        $this->request('POST', "/api/collections/$id/dishes/".$romazava->getId(), null, $owner);
        $this->request('POST', "/api/collections/$id/dishes/".$romazava->getId(), null, $owner); // idempotent
        $this->request('POST', "/api/collections/$id/dishes/".$kitoza->getId(), null, $owner);

        $mine = $this->request('GET', '/api/me/collections?dish='.$romazava->getId(), null, $owner);
        self::assertSame(2, $mine[0]['dishCount']);
        self::assertTrue($mine[0]['containsDish']);
        self::assertCount(2, $mine[0]['covers']);

        $shown = $this->request('GET', "/api/collections/$id", null, $owner);
        self::assertSame(['Kitoza', 'Romazava'], array_column($shown['dishes'], 'title'));
        self::assertTrue($shown['isMine']);

        $this->request('DELETE', "/api/collections/$id/dishes/".$kitoza->getId(), null, $owner);
        self::assertSame(1, $this->request('GET', '/api/me/collections', null, $owner)[0]['dishCount']);
    }

    public function testAddingToAPublicCollectionNotifiesTheDishAuthorOnce(): void
    {
        $owner = $this->createUser('owner2@test.mg');
        $author = $this->createUser('author2@test.mg');
        $dish = $this->dish($author, 'Romazava');
        $id = $this->request('POST', '/api/collections', ['name' => 'Favoris du dimanche'], $owner)['id'];

        $this->request('POST', "/api/collections/$id/dishes/".$dish->getId(), null, $owner);
        $this->request('POST', "/api/collections/$id/dishes/".$dish->getId(), null, $owner);

        $items = $this->request('GET', '/api/me/notifications', null, $author)['items'];
        self::assertCount(1, $items);
        self::assertSame('collection', $items[0]['type']);
        self::assertSame('Favoris du dimanche', $items[0]['label']);
    }

    public function testPrivateCollectionIsOnlyVisibleToItsOwner(): void
    {
        $owner = $this->createUser('owner3@test.mg');
        $visitor = $this->createUser('visitor@test.mg');
        $private = $this->request('POST', '/api/collections', ['name' => 'Secret', 'isPublic' => false], $owner)['id'];
        $public = $this->request('POST', '/api/collections', ['name' => 'Ouverte'], $owner)['id'];

        $this->request('GET', "/api/collections/$private", null, $visitor);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['Ouverte'], array_column($this->request('GET', '/api/users/'.$owner->getId().'/collections', null, $visitor), 'name'));
        self::assertCount(2, $this->request('GET', '/api/users/'.$owner->getId().'/collections', null, $owner));

        $this->request('GET', "/api/collections/$public", null, $visitor);
        self::assertResponseIsSuccessful();
    }

    public function testOnlyTheOwnerCanEdit(): void
    {
        $owner = $this->createUser('owner4@test.mg');
        $other = $this->createUser('other4@test.mg');
        $id = $this->request('POST', '/api/collections', ['name' => 'Mine'], $owner)['id'];

        $this->request('PATCH', "/api/collections/$id", ['name' => 'Volée'], $other);
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', "/api/collections/$id", null, $other);
        self::assertResponseStatusCodeSame(404);

        $renamed = $this->request('PATCH', "/api/collections/$id", ['name' => 'Renommée', 'isPublic' => false], $owner);
        self::assertSame('Renommée', $renamed['name']);
        self::assertFalse($renamed['isPublic']);
        $this->request('DELETE', "/api/collections/$id", null, $owner);
        self::assertResponseStatusCodeSame(204);
    }

    public function testInvalidNamesAndHiddenDishesAreRejected(): void
    {
        $owner = $this->createUser('owner5@test.mg');
        $author = $this->createUser('author5@test.mg');
        $secret = $this->dish($author, 'Secret', DishVisibility::Private);

        $this->request('POST', '/api/collections', ['name' => '   '], $owner);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/collections', ['name' => str_repeat('a', 81)], $owner);
        self::assertResponseStatusCodeSame(422);

        $id = $this->request('POST', '/api/collections', ['name' => 'Ok'], $owner)['id'];
        $this->request('POST', "/api/collections/$id/dishes/".$secret->getId(), null, $owner);
        self::assertResponseStatusCodeSame(404);
    }

    public function testHiddenDishesDisappearFromACollection(): void
    {
        $owner = $this->createUser('owner6@test.mg');
        $author = $this->createUser('author6@test.mg');
        $dish = $this->dish($author, 'Romazava');
        $id = $this->request('POST', '/api/collections', ['name' => 'Ok'], $owner)['id'];
        $this->request('POST', "/api/collections/$id/dishes/".$dish->getId(), null, $owner);

        $this->changeVisibility($dish, DishVisibility::Private);

        self::assertSame([], $this->request('GET', "/api/collections/$id", null, $owner)['dishes']);
        self::assertSame(0, $this->request('GET', '/api/me/collections', null, $owner)[0]['dishCount']);
    }

    public function testChefOfTheWeekNeedsAtLeastThreeHearts(): void
    {
        $mother = $this->createUser('chef@test.mg');
        $dish = $this->dish($mother, 'Romazava');
        $viewer = $this->createUser('viewer@test.mg');
        self::assertNull($this->request('GET', '/api/discover/chef-of-the-week', null, $viewer)['id'] ?? null);

        foreach (['a', 'b', 'c'] as $name) {
            $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $this->createUser($name.'@test.mg'));
        }
        // The result is cached for an hour: a fresh kernel (new cache) sees the likes.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $chef = $this->request('GET', '/api/discover/chef-of-the-week', null, $viewer);
        self::assertSame($mother->getId(), $chef['id']);
        self::assertSame(3, $chef['likes']);
    }
}
