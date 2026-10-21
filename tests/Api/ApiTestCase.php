<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\User;
use App\Enum\DishVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Changes a dish's visibility straight in the database (entities of an earlier request belong to a previous kernel).
     */
    protected function changeVisibility(Dish $dish, DishVisibility $visibility): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE dish SET visibility = :v WHERE id = :id', ['v' => $visibility->value, 'id' => $dish->getId()]);
    }

    /**
     * @param list<string> $roles
     */
    protected function createUser(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setDisplayName(ucfirst(strstr($email, '@', true)))->setRoles($roles);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    protected function request(string $method, string $uri, ?array $body = null, ?User $as = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $as) {
            $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if ('PATCH' === $method) {
            $server['CONTENT_TYPE'] = 'application/merge-patch+json';
        }

        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $content = $this->client->getResponse()->getContent();

        return $content ? (array) json_decode($content, true) : [];
    }
}
