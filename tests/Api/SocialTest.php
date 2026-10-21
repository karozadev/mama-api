<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\User;

final class SocialTest extends ApiTestCase
{
    private function dish(User $author, string $title = 'Romazava'): Dish
    {
        $dish = (new Dish())->setTitle($title)->setDescription('Bouillon.')->setIngredients(['bœuf'])
            ->setSteps(['Cuire.'])->setPrice(4500)->setCategory('plat')->setAuthor($author)
            ->setPrepTimeMinutes(150)->setServings(4);
        $this->em()->persist($dish);
        $this->em()->flush();

        return $dish;
    }

    public function testLikeIsIdempotentAndUnlikeDecrements(): void
    {
        $mother = $this->createUser('mama@test.mg');
        $fan = $this->createUser('fan@test.mg');
        $dish = $this->dish($mother);

        $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $fan);
        $data = $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $fan);
        self::assertSame(1, $data['likeCount']);

        $likes = $this->request('GET', '/api/me/likes', null, $fan);
        self::assertSame([$dish->getId()], $likes['ids']);

        $data = $this->request('DELETE', '/api/dishes/'.$dish->getId().'/like', null, $fan);
        self::assertSame(0, $data['likeCount']);
        self::assertFalse($data['liked']);
    }

    public function testLikeNotifiesTheAuthorOnlyOnce(): void
    {
        $mother = $this->createUser('mama2@test.mg');
        $fan = $this->createUser('fan2@test.mg');
        $dish = $this->dish($mother);

        $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $fan);
        $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $fan);
        $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $mother); // self-like: no notification

        $data = $this->request('GET', '/api/me/notifications', null, $mother);
        self::assertSame(1, $data['unread']);
        self::assertCount(1, $data['items']);
        self::assertSame('like', $data['items'][0]['type']);
        self::assertSame('Fan2', $data['items'][0]['actor']['displayName']);
        self::assertSame($dish->getId(), $data['items'][0]['dish']['id']);

        $this->request('POST', '/api/me/notifications/read', null, $mother);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->request('GET', '/api/me/notifications', null, $mother)['unread']);
    }

    public function testFollowAndReviewNotifyTheMother(): void
    {
        $mother = $this->createUser('mama3@test.mg');
        $fan = $this->createUser('fan3@test.mg');
        $dish = $this->dish($mother);

        $this->request('POST', '/api/users/'.$mother->getId().'/follow', null, $fan);
        $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$dish->getId(), 'rating' => 5, 'comment' => 'Divin'], $fan);

        $types = array_column($this->request('GET', '/api/me/notifications', null, $mother)['items'], 'type');
        sort($types);
        self::assertSame(['follow', 'review'], $types);
    }

    public function testTopOfTheDayRanksByLikesWithinTheNetwork(): void
    {
        $mother = $this->createUser('mama4@test.mg');
        $stranger = $this->createUser('stranger@test.mg');
        $fans = [$this->createUser('f1@test.mg'), $this->createUser('f2@test.mg')];
        $quiet = $this->dish($mother, 'Kitoza');
        $popular = $this->dish($mother, 'Romazava');
        $outsider = $this->dish($stranger, 'Lasopy');
        foreach ($fans as $fan) {
            $this->request('POST', '/api/dishes/'.$popular->getId().'/like', null, $fan);
            $this->request('POST', '/api/dishes/'.$outsider->getId().'/like', null, $fan);
        }
        $this->request('POST', '/api/dishes/'.$quiet->getId().'/like', null, $fans[0]);

        $since = rawurlencode((new \DateTimeImmutable('-1 day'))->format(\DATE_ATOM));
        $data = $this->request('GET', "/api/dishes?author[]=/api/users/{$mother->getId()}&createdAt[after]=$since&order[likeCount]=desc&order[reviewCount]=desc&itemsPerPage=10");

        self::assertSame(['Romazava', 'Kitoza'], array_column($data, 'title'));
        self::assertSame(150, $data[0]['prepTimeMinutes']);
        self::assertSame(4, $data[0]['servings']);
    }

    public function testFollowedMothersFeedTheStoriesRow(): void
    {
        $mother = $this->createUser('mama5@test.mg');
        $fan = $this->createUser('fan5@test.mg');
        $this->dish($mother);
        $this->request('POST', '/api/users/'.$mother->getId().'/follow', null, $fan);

        $data = $this->request('GET', '/api/me/following', null, $fan);
        self::assertSame([$mother->getId()], $data['ids']);
        self::assertSame('Mama5', $data['mothers'][0]['displayName']);
        self::assertNotNull($data['mothers'][0]['latestDishAt']);
    }

    public function testPublicStats(): void
    {
        $this->createUser('someone@test.mg');
        $data = $this->request('GET', '/api/stats');
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $data['mamas']);
    }

    public function testProfileCityAndBioAreEditableByOwner(): void
    {
        $me = $this->createUser('profile@test.mg');
        $this->request('PATCH', '/api/users/'.$me->getId(), ['city' => 'Antananarivo · Analakely', 'bio' => 'Cuisine de grand-mère.'], $me);
        self::assertResponseIsSuccessful();
        $data = $this->request('GET', '/api/me', null, $me);
        self::assertSame('Antananarivo · Analakely', $data['city']);
        self::assertSame(0, $data['dishCount']);
    }
}
