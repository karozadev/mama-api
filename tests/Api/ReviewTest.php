<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class ReviewTest extends ApiTestCase
{
    public function testReviewUpdatesDishAverage(): void
    {
        $mother = $this->createUser('mama@test.mg');
        $a = $this->createUser('a@test.mg');
        $b = $this->createUser('b@test.mg');
        $dish = $this->request('POST', '/api/dishes', [
            'title' => 'Kitoza', 'description' => 'Viande séchée.', 'ingredients' => ['bœuf'],
            'steps' => ['Griller.'], 'price' => 9000, 'category' => 'plat',
        ], $mother);
        $iri = '/api/dishes/'.$dish['id'];

        $this->request('POST', '/api/reviews', ['dish' => $iri, 'rating' => 5], $a);
        self::assertResponseStatusCodeSame(201);
        $this->request('POST', '/api/reviews', ['dish' => $iri, 'rating' => 3], $b);

        $data = $this->request('GET', $iri);
        self::assertSame(4.0, (float) $data['averageRating']);
        self::assertSame(2, $data['reviewCount']);
    }

    public function testRatingMustBeBetweenOneAndFive(): void
    {
        $mother = $this->createUser('mama2@test.mg');
        $user = $this->createUser('u@test.mg');
        $dish = $this->request('POST', '/api/dishes', [
            'title' => 'Koba', 'description' => 'Gâteau.', 'ingredients' => ['riz'], 'steps' => ['Cuire.'], 'price' => 1500, 'category' => 'dessert',
        ], $mother);

        $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$dish['id'], 'rating' => 9], $user);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCannotReviewOwnDish(): void
    {
        $mother = $this->createUser('self@test.mg');
        $dish = $this->request('POST', '/api/dishes', [
            'title' => 'Lasopy', 'description' => 'Soupe.', 'ingredients' => ['carotte'], 'steps' => ['Cuire.'], 'price' => 3500, 'category' => 'entree',
        ], $mother);

        $this->request('POST', '/api/reviews', ['dish' => '/api/dishes/'.$dish['id'], 'rating' => 5], $mother);
        self::assertResponseStatusCodeSame(422);
    }
}
