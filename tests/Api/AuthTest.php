<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;

final class AuthTest extends ApiTestCase
{
    public function testRegisterLoginAndFetchProfile(): void
    {
        $this->request('POST', '/api/users', [
            'email' => 'nouvelle@test.mg', 'plainPassword' => 'motdepasse123', 'displayName' => 'Mama Nouvelle',
        ]);
        self::assertResponseStatusCodeSame(201);

        $login = $this->request('POST', '/api/login_check', ['email' => 'nouvelle@test.mg', 'password' => 'motdepasse123']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $login);

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['token']]);
        self::assertResponseIsSuccessful();
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->request('POST', '/api/users', [
            'email' => 'x@test.mg', 'plainPassword' => 'motdepasse123', 'displayName' => 'X',
        ]);
        $this->request('POST', '/api/login_check', ['email' => 'x@test.mg', 'password' => 'nope']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRolesCannotBeSetAtRegistration(): void
    {
        $data = $this->request('POST', '/api/users', [
            'email' => 'hack@test.mg', 'plainPassword' => 'motdepasse123', 'displayName' => 'Hack', 'roles' => ['ROLE_ADMIN'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNotContains('ROLE_ADMIN', $data['roles']);
    }

    public function testDeletingTheAccountRequiresThePasswordAndRemovesEverything(): void
    {
        $this->request('POST', '/api/users', ['email' => 'bye@test.mg', 'plainPassword' => 'motdepasse123', 'displayName' => 'Mama Bye']);
        $login = $this->request('POST', '/api/login_check', ['email' => 'bye@test.mg', 'password' => 'motdepasse123']);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$login['token'], 'CONTENT_TYPE' => 'application/json'];

        $this->client->request('DELETE', '/api/me', server: $auth, content: json_encode(['password' => 'mauvais'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('DELETE', '/api/me', server: $auth);
        self::assertResponseStatusCodeSame(403);

        $dish = $this->request('POST', '/api/dishes', [
            'title' => 'Vary', 'description' => 'Riz.', 'ingredients' => ['riz'], 'steps' => ['Cuire.'], 'price' => 800, 'category' => 'plat',
        ], $this->em()->getRepository(User::class)->findOneBy(['email' => 'bye@test.mg']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/me', server: $auth, content: json_encode(['password' => 'motdepasse123'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'bye@test.mg']));
        $this->request('GET', '/api/dishes/'.$dish['id']);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/api/login_check', ['email' => 'bye@test.mg', 'password' => 'motdepasse123']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testHeadRequestsWorkOnPublicRoutes(): void
    {
        $this->client->request('HEAD', '/api/stats');
        self::assertResponseIsSuccessful();
        $this->client->request('HEAD', '/api/dishes', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
    }
}
