<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\User;
use App\Enum\DishVisibility;

final class DesignGapsTest extends ApiTestCase
{
    /**
     * @param list<mixed> $ingredients
     */
    private function dish(User $author, string $title, string $category = 'plat', array $ingredients = ['riz'], bool $vegetarian = false): Dish
    {
        $dish = (new Dish())->setTitle($title)->setDescription('Un bon plat.')->setIngredients($ingredients)
            ->setSteps(['Cuire.'])->setPrice(3000)->setCategory($category)->setAuthor($author)->setVegetarian($vegetarian);
        $this->em()->persist($dish);
        $this->em()->flush();

        return $dish;
    }

    /**
     * @param list<User> $fans
     */
    private function likeBy(Dish $dish, array $fans): void
    {
        foreach ($fans as $fan) {
            $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $fan);
        }
    }

    /**
     * @return list<User>
     */
    private function fans(int $n, string $prefix = 'f'): array
    {
        return array_map(fn (int $i): User => $this->createUser("$prefix$i@test.mg"), range(1, $n));
    }

    public function testStatsExposeTheHeadlineDish(): void
    {
        $mother = $this->createUser('mama@test.mg');
        self::assertNull($this->request('GET', '/api/stats')['topDish']);

        $quiet = $this->dish($mother, 'Kitoza');
        $star = $this->dish($mother, 'Romazava');
        $this->likeBy($star, $this->fans(3));
        $this->likeBy($quiet, $this->fans(1, 'g'));

        self::ensureKernelShutdown(); // the ranking is cached per kernel
        $this->client = static::createClient();
        self::assertSame('Romazava', $this->request('GET', '/api/stats')['topDish']['title']);
    }

    public function testDishRankWithinTodaysTopTen(): void
    {
        $mother = $this->createUser('mama2@test.mg');
        $star = $this->dish($mother, 'Romazava');
        $second = $this->dish($mother, 'Kitoza');
        $unliked = $this->dish($mother, 'Vary');
        $this->likeBy($star, $this->fans(3));
        $this->likeBy($second, $this->fans(1, 'g'));

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        self::assertSame(1, $this->request('GET', '/api/dishes/'.$star->getId().'/rank')['rank']);
        self::assertSame(2, $this->request('GET', '/api/dishes/'.$second->getId().'/rank')['rank']);
        self::assertNull($this->request('GET', '/api/dishes/'.$unliked->getId().'/rank')['rank']);

        $this->changeVisibility($star, DishVisibility::Private);
        $this->request('GET', '/api/dishes/'.$star->getId().'/rank');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTrendComparesTheLastTwoDays(): void
    {
        $mother = $this->createUser('mama3@test.mg');
        $dish = $this->dish($mother, 'Romazava');
        $viewer = $this->createUser('viewer@test.mg');
        $db = $this->em()->getConnection();
        $fans = $this->fans(6);
        $this->likeBy($dish, array_slice($fans, 0, 3)); // 3 likes now
        // 2 likes from the previous day
        foreach (array_slice($fans, 3, 2) as $fan) {
            $db->executeStatement('INSERT INTO dish_like (user_id, dish_id, created_at) VALUES (:u, :d, :t)', ['u' => $fan->getId(), 'd' => $dish->getId(), 't' => (new \DateTimeImmutable('-30 hours'))->format('Y-m-d H:i:s')]);
        }

        $trend = $this->request('GET', '/api/discover/trend', null, $viewer);
        self::assertSame(3, $trend['likes']);
        self::assertSame(50, $trend['percent']); // (3 - 2) / 2
    }

    public function testTrendHasNoPercentWithoutAReference(): void
    {
        $viewer = $this->createUser('viewer2@test.mg');
        self::assertNull($this->request('GET', '/api/discover/trend', null, $viewer)['percent']);
    }

    public function testNewTalentsComeWithTheirSpecialty(): void
    {
        $fisher = $this->createUser('fisher@test.mg');
        $baker = $this->createUser('baker@test.mg');
        $green = $this->createUser('green@test.mg');
        $viewer = $this->createUser('viewer3@test.mg');
        $this->dish($fisher, 'Tilapia frit', 'plat', [['name' => 'Tilapia', 'price' => 6000]]);
        $this->dish($baker, 'Koba', 'dessert');
        $this->dish($green, 'Lasopy', 'entree', ['carotte'], true);

        $talents = $this->request('GET', '/api/discover/mothers', null, $viewer);
        $bySpecialty = array_column($talents, 'specialty', 'displayName');
        self::assertSame('Spéc. poisson', $bySpecialty['Fisher']);
        self::assertSame('Pâtisseries', $bySpecialty['Baker']);
        self::assertSame('Végétarien', $bySpecialty['Green']);

        self::assertCount(1, $this->request('GET', '/api/discover/mothers?limit=1', null, $viewer));
    }

    public function testFavouritesOfAnotherMotherRespectPrivacyAndVisibility(): void
    {
        $author = $this->createUser('author@test.mg');
        $fan = $this->createUser('fan@test.mg');
        $viewer = $this->createUser('viewer4@test.mg');
        $open = $this->dish($author, 'Romazava');
        $secret = $this->dish($author, 'Secret');
        $this->request('POST', '/api/dishes/'.$open->getId().'/bookmark', null, $fan);
        $this->request('POST', '/api/dishes/'.$secret->getId().'/bookmark', null, $fan);
        $this->changeVisibility($secret, DishVisibility::Private); // made private after being bookmarked

        $data = $this->request('GET', '/api/users/'.$fan->getId().'/bookmarks', null, $viewer);
        self::assertFalse($data['isPrivate']);
        self::assertSame(['Romazava'], array_column($data['dishes'], 'title'));

        $this->request('PATCH', '/api/users/'.$fan->getId(), ['favoritesPublic' => false], $fan);
        self::assertResponseIsSuccessful();
        $hidden = $this->request('GET', '/api/users/'.$fan->getId().'/bookmarks', null, $viewer);
        self::assertTrue($hidden['isPrivate']);
        self::assertSame([], $hidden['dishes']);
        self::assertCount(1, $this->request('GET', '/api/users/'.$fan->getId().'/bookmarks', null, $fan)['dishes']);
    }

    public function testCookingNotifiesTheAuthorOncePerDayInTheMarketTab(): void
    {
        $author = $this->createUser('author2@test.mg');
        $cook = $this->createUser('cook@test.mg');
        $dish = $this->dish($author, 'Romazava');

        $this->request('POST', '/api/dishes/'.$dish->getId().'/cook', null, $cook);
        $this->request('POST', '/api/dishes/'.$dish->getId().'/cook', null, $cook);
        $this->request('POST', '/api/dishes/'.$dish->getId().'/cook', null, $author); // cooking your own dish: nothing

        $market = $this->request('GET', '/api/me/notifications?filter=market', null, $author);
        self::assertCount(1, $market['items']);
        self::assertSame('cook', $market['items'][0]['type']);
        self::assertSame([], $this->request('GET', '/api/me/notifications?filter=mentions', null, $author)['items']);
    }

    public function testMentionsTabHoldsReviewsWithTheirCommentAndCollections(): void
    {
        $author = $this->createUser('author3@test.mg');
        $fan = $this->createUser('fan3@test.mg');
        $dish = $this->dish($author, 'Romazava');
        $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$dish->getId(), 'rating' => 5, 'comment' => 'Divin, mes enfants ont adoré !'], $fan);
        $this->request('POST', '/api/dishes/'.$dish->getId().'/like', null, $fan);

        $mentions = $this->request('GET', '/api/me/notifications?filter=mentions', null, $author)['items'];
        self::assertCount(1, $mentions);
        self::assertSame('review', $mentions[0]['type']);
        self::assertSame('Divin, mes enfants ont adoré !', $mentions[0]['label']);
        self::assertCount(2, $this->request('GET', '/api/me/notifications', null, $author)['items']);
    }

    public function testHeartsOnTheSameDishTheSameDayAreGrouped(): void
    {
        $author = $this->createUser('author4@test.mg');
        $dish = $this->dish($author, 'Romazava');
        $other = $this->dish($author, 'Kitoza');
        $this->likeBy($dish, $this->fans(4));
        $this->likeBy($other, $this->fans(1, 'g'));

        $items = $this->request('GET', '/api/me/notifications', null, $author)['items'];
        self::assertCount(2, $items);
        $counts = [];
        foreach ($items as $item) {
            $counts[$item['dish']['title']] = $item['othersCount'];
        }
        self::assertSame(3, $counts['Romazava']);
        self::assertSame(0, $counts['Kitoza']);
    }

    public function testRankingBannerForAnAuthorInTodaysTopTen(): void
    {
        $author = $this->createUser('author5@test.mg');
        $dish = $this->dish($author, 'Romazava');
        $this->likeBy($dish, $this->fans(2));

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $banner = $this->request('GET', '/api/me/notifications', null, $author)['ranking'];
        self::assertSame($dish->getId(), $banner['dishId']);
        self::assertSame(1, $banner['rank']);

        $stranger = $this->createUser('stranger@test.mg');
        self::assertNull($this->request('GET', '/api/me/notifications', null, $stranger)['ranking']);
        self::assertNull($this->request('GET', '/api/me/notifications?filter=market', null, $author)['ranking']);
    }
}
