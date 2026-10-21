<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;

final class DishTest extends ApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function dishPayload(float $price = 5000.0, bool $special = false): array
    {
        return [
            'title' => 'Romazava',
            'description' => 'Bouillon de bœuf et de brèdes.',
            'ingredients' => ['bœuf', 'brèdes'],
            'steps' => ['Cuire la viande.', 'Ajouter les brèdes.'],
            'price' => $price,
            'category' => 'plat',
            'isDailySpecial' => $special,
        ];
    }

    public function testAnonymousCannotCreateDish(): void
    {
        $this->request('POST', '/api/dishes', $this->dishPayload());
        self::assertResponseStatusCodeSame(401);
    }

    public function testMotherCreatesDishAndBecomesAuthor(): void
    {
        $mother = $this->createUser('rasoa@test.mg', ['ROLE_CHEF']);
        $data = $this->request('POST', '/api/dishes', $this->dishPayload(), $mother);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(5000.0, (float) $data['price']);
        self::assertArrayHasKey('isDailySpecial', $data);
        self::assertFalse($data['isDailySpecial']);
        self::assertNotNull($this->em()->getRepository(Dish::class)->findOneBy(['author' => $mother]));
    }

    public function testBudgetFilterAndPriceOrdering(): void
    {
        $mother = $this->createUser('soa@test.mg');
        foreach ([3000.0, 8000.0, 15000.0] as $price) {
            $this->request('POST', '/api/dishes', $this->dishPayload($price), $mother);
        }

        $data = $this->request('GET', '/api/dishes?price[lte]=10000&order[price]=desc');

        self::assertResponseIsSuccessful();
        $members = $data['member'] ?? $data['hydra:member'] ?? $data;
        self::assertSame([8000.0, 3000.0], array_map(static fn (array $d): float => (float) $d['price'], $members));
    }

    public function testOnlyAuthorCanEditDish(): void
    {
        $owner = $this->createUser('owner@test.mg');
        $other = $this->createUser('other@test.mg');
        $dish = $this->request('POST', '/api/dishes', $this->dishPayload(), $owner);

        $this->request('PATCH', '/api/dishes/'.$dish['id'], ['title' => 'Piraté'], $other);
        self::assertResponseStatusCodeSame(403);

        $this->request('PATCH', '/api/dishes/'.$dish['id'], ['title' => 'Romazava spécial'], $owner);
        self::assertResponseIsSuccessful();
    }
}
