<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\User;

final class ExploreTest extends ApiTestCase
{
    /**
     * @param list<mixed> $ingredients
     */
    private function dish(User $author, string $title, array $ingredients = ['riz'], bool $vegetarian = false, int $minutes = 60): Dish
    {
        $dish = (new Dish())->setTitle($title)->setDescription('Un bon plat.')->setIngredients($ingredients)
            ->setSteps(['Cuire.'])->setPrice(2000)->setCategory('plat')->setAuthor($author)
            ->setVegetarian($vegetarian)->setPrepTimeMinutes($minutes);
        $this->em()->persist($dish);
        $this->em()->flush();

        return $dish;
    }

    public function testPlainStringIngredientsAreNormalisedToObjects(): void
    {
        $mother = $this->createUser('mama@test.mg');
        $data = $this->request('POST', '/api/dishes', [
            'title' => 'Vary', 'description' => 'Riz.', 'steps' => ['Cuire.'], 'price' => 800, 'category' => 'plat',
            'ingredients' => ['Riz', ['name' => 'Brèdes', 'quantity' => '1 botte', 'price' => 1500, 'aisle' => 'legumes']],
        ], $mother);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['name' => 'Riz', 'quantity' => null, 'price' => null, 'aisle' => 'autre'], $data['ingredients'][0]);
        self::assertSame(1500.0, $data['ingredients'][1]['price']);
        self::assertSame('legumes', $data['ingredients'][1]['aisle']);
    }

    public function testInvalidIngredientsAreRejected(): void
    {
        $mother = $this->createUser('mama2@test.mg');
        $this->request('POST', '/api/dishes', [
            'title' => 'Vary', 'description' => 'Riz.', 'steps' => ['Cuire.'], 'price' => 800, 'category' => 'plat',
            'ingredients' => [['name' => '', 'price' => -5, 'aisle' => 'cave']],
        ], $mother);

        self::assertResponseStatusCodeSame(422);
    }

    public function testBookmarksAreIdempotentAndListedNewestFirst(): void
    {
        $mother = $this->createUser('mama3@test.mg');
        $fan = $this->createUser('fan@test.mg');
        $first = $this->dish($mother, 'Romazava');
        $second = $this->dish($mother, 'Kitoza');

        $this->request('POST', '/api/dishes/'.$first->getId().'/bookmark', null, $fan);
        $this->request('POST', '/api/dishes/'.$first->getId().'/bookmark', null, $fan);
        $this->request('POST', '/api/dishes/'.$second->getId().'/bookmark', null, $fan);

        self::assertSame([$second->getId(), $first->getId()], $this->request('GET', '/api/me/bookmarks', null, $fan)['ids']);
        $dishes = $this->request('GET', '/api/me/bookmarks/dishes', null, $fan);
        self::assertSame(['Kitoza', 'Romazava'], array_column($dishes, 'title'));
        self::assertSame('riz', $dishes[0]['ingredients'][0]['name']);

        $this->request('DELETE', '/api/dishes/'.$second->getId().'/bookmark', null, $fan);
        self::assertSame([$first->getId()], $this->request('GET', '/api/me/bookmarks', null, $fan)['ids']);
    }

    public function testSearchFindsDishesByTitleIngredientAndMothersByName(): void
    {
        $mother = $this->createUser('lova@test.mg');
        $mother->setDisplayName('Mama Lova');
        $this->em()->flush();
        $this->dish($mother, 'Romazava', [['name' => 'Brèdes mafana', 'quantity' => '1 botte', 'price' => 1500, 'aisle' => 'legumes']]);
        $this->dish($mother, 'Koba', ['cacahuètes']);

        $viewer = $this->createUser('viewer@test.mg');
        self::assertSame(['Romazava'], array_column($this->request('GET', '/api/search?q=roma', null, $viewer)['dishes'], 'title'));
        self::assertSame(['Romazava'], array_column($this->request('GET', '/api/search?q=mafana', null, $viewer)['dishes'], 'title'));
        $data = $this->request('GET', '/api/search?q=lova', null, $viewer);
        self::assertSame('Mama Lova', $data['mothers'][0]['displayName']);
        self::assertSame(2, $data['mothers'][0]['dishCount']);
        self::assertSame([], $this->request('GET', '/api/search?q=a', null, $viewer)['dishes']); // too short
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        $mother = $this->createUser('mama4@test.mg');
        $this->dish($mother, 'Romazava');
        $viewer = $this->createUser('viewer2@test.mg');

        self::assertSame([], $this->request('GET', '/api/search?q=%25%25', null, $viewer)['dishes']);
    }

    public function testDiscoverNewMothersSkipsFollowedAndSelf(): void
    {
        $a = $this->createUser('a@test.mg');
        $b = $this->createUser('b@test.mg');
        $me = $this->createUser('me@test.mg');
        $this->dish($a, 'Romazava');
        $this->dish($b, 'Kitoza');
        $this->dish($me, 'Koba');
        $this->request('POST', '/api/users/'.$a->getId().'/follow', null, $me);

        $ids = array_column($this->request('GET', '/api/discover/mothers', null, $me), 'id');
        self::assertSame([$b->getId()], $ids);
    }

    public function testVegetarianAndQuickFilters(): void
    {
        $mother = $this->createUser('mama5@test.mg');
        $this->dish($mother, 'Lasopy', ['carottes'], true, 25);
        $this->dish($mother, 'Romazava', ['bœuf'], false, 150);

        self::assertSame(['Lasopy'], array_column($this->request('GET', '/api/dishes?vegetarian=true'), 'title'));
        self::assertSame(['Lasopy'], array_column($this->request('GET', '/api/dishes?prepTimeMinutes[lte]=30'), 'title'));
    }
}
