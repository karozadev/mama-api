<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\User;
use App\Enum\DishVisibility;

final class VisibilityTest extends ApiTestCase
{
    private function dish(User $author, string $title, DishVisibility $visibility): Dish
    {
        $dish = (new Dish())->setTitle($title)->setDescription('Un bon plat.')->setIngredients(['riz'])
            ->setSteps(['Cuire.'])->setPrice(2000)->setCategory('plat')->setAuthor($author)->setVisibility($visibility);
        $this->em()->persist($dish);
        $this->em()->flush();

        return $dish;
    }

    /**
     * @return list<string>
     */
    private function titles(?User $as): array
    {
        $titles = array_column($this->request('GET', '/api/dishes', null, $as), 'title');
        sort($titles); // dishes created within the same millisecond have no stable order

        return $titles;
    }

    public function testListVisibilityDependsOnTheViewer(): void
    {
        $mother = $this->createUser('mama@test.mg');
        $follower = $this->createUser('follower@test.mg');
        $stranger = $this->createUser('stranger@test.mg');
        $this->request('POST', '/api/users/'.$mother->getId().'/follow', null, $follower);
        $this->dish($mother, 'Public', DishVisibility::Public);
        $this->dish($mother, 'Abonnées', DishVisibility::Followers);
        $this->dish($mother, 'Privé', DishVisibility::Private);

        self::assertSame(['Public'], $this->titles(null));
        self::assertSame(['Public'], $this->titles($stranger));
        self::assertSame(['Abonnées', 'Public'], $this->titles($follower));
        self::assertSame(['Abonnées', 'Privé', 'Public'], $this->titles($mother));
    }

    public function testHiddenDishIsNotFoundEverywhere(): void
    {
        $mother = $this->createUser('mama2@test.mg');
        $stranger = $this->createUser('stranger2@test.mg');
        $secret = $this->dish($mother, 'Secret', DishVisibility::Private);
        $id = $secret->getId();

        $this->request('GET', '/api/dishes/'.$id, null, $stranger);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/api/dishes/'.$id.'/like', null, $stranger);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/api/dishes/'.$id.'/bookmark', null, $stranger);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$id, 'rating' => 5], $stranger);
        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 404, 422]);
        self::assertSame([], $this->request('GET', '/api/search?q=secret', null, $stranger)['dishes']);

        $this->request('GET', '/api/dishes/'.$id, null, $mother);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->request('GET', '/api/search?q=secret', null, $mother)['dishes']);
    }

    public function testReviewsOfAHiddenDishAreHidden(): void
    {
        $mother = $this->createUser('mama3@test.mg');
        $fan = $this->createUser('fan@test.mg');
        $stranger = $this->createUser('stranger3@test.mg');
        $dish = $this->dish($mother, 'Kitoza', DishVisibility::Public);
        $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$dish->getId(), 'rating' => 4], $fan);
        self::assertCount(1, $this->request('GET', '/api/reviews', null, $stranger));

        $this->changeVisibility($dish, DishVisibility::Private);
        self::assertCount(0, $this->request('GET', '/api/reviews', null, $stranger));
    }

    public function testProfileCountsAndBookmarksIgnoreHiddenDishes(): void
    {
        $mother = $this->createUser('mama4@test.mg');
        $fan = $this->createUser('fan4@test.mg');
        $dish = $this->dish($mother, 'Romazava', DishVisibility::Public);
        $this->dish($mother, 'Privé', DishVisibility::Private);
        $this->em()->refresh($mother);

        self::assertSame(1, $this->request('GET', '/api/users/'.$mother->getId(), null, $fan)['dishCount']);
        self::assertSame(2, $this->request('GET', '/api/me', null, $mother)['dishCount']);

        $this->request('POST', '/api/dishes/'.$dish->getId().'/bookmark', null, $fan);
        $this->changeVisibility($dish, DishVisibility::Private);
        self::assertSame([], $this->request('GET', '/api/me/bookmarks/dishes', null, $fan));
    }

    public function testVisibilityIsWritableByTheAuthorOnly(): void
    {
        $mother = $this->createUser('mama5@test.mg');
        $other = $this->createUser('other@test.mg');
        $data = $this->request('POST', '/api/dishes', [
            'title' => 'Vary', 'description' => 'Riz.', 'ingredients' => ['riz'], 'steps' => ['Cuire.'], 'price' => 800, 'category' => 'plat', 'visibility' => 'followers',
        ], $mother);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('followers', $data['visibility']);

        $this->request('PATCH', '/api/dishes/'.$data['id'], ['visibility' => 'public'], $other);
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $this->request('PATCH', '/api/dishes/'.$data['id'], ['visibility' => 'private'], $mother);
        self::assertResponseIsSuccessful();
    }
}
