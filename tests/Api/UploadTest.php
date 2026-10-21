<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Dish;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadTest extends ApiTestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadDir = static::getContainer()->getParameter('kernel.project_dir').'/var/uploads_test';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->uploadDir);
        parent::tearDown();
    }

    private function dish(User $author): Dish
    {
        $dish = (new Dish())->setTitle('Koba')->setDescription('Gâteau.')->setIngredients(['riz'])
            ->setSteps(['Cuire.'])->setPrice(1500)->setCategory('dessert')->setAuthor($author);
        $this->em()->persist($dish);
        $this->em()->flush();

        return $dish;
    }

    private function image(string $type = 'png', int $size = 2400): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        $img = imagecreatetruecolor($size, $size / 2);
        'png' === $type ? imagepng($img, $path) : file_put_contents($path, 'not an image at all');

        return new UploadedFile($path, 'photo.'.$type, 'png' === $type ? 'image/png' : 'text/plain', null, true);
    }

    private function upload(string $uri, UploadedFile $file, ?User $as): void
    {
        $server = [];
        if (null !== $as) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.static::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
        }
        $this->client->request('POST', $uri, files: ['file' => $file], server: $server + ['HTTP_ACCEPT' => 'application/json']);
    }

    public function testAuthorUploadsDishPhotoAsResizedWebp(): void
    {
        $author = $this->createUser('up@test.mg');
        $dish = $this->dish($author);

        $this->upload('/api/dishes/'.$dish->getId().'/photo', $this->image(), $author);

        self::assertResponseStatusCodeSame(201);
        $url = json_decode($this->client->getResponse()->getContent(), true)['photoUrl'];
        self::assertMatchesRegularExpression('#/uploads/dishes/[0-9a-f]{32}\.webp$#', $url);

        $stored = $this->uploadDir.'/'.substr($url, strpos($url, 'dishes/'));
        self::assertFileExists($stored);
        [$width] = getimagesize($stored);
        self::assertSame(1600, $width);
    }

    public function testReplacingPhotoDeletesPreviousFile(): void
    {
        $author = $this->createUser('replace@test.mg');
        $dish = $this->dish($author);
        $uri = '/api/dishes/'.$dish->getId().'/photo';

        $this->upload($uri, $this->image(), $author);
        $first = json_decode($this->client->getResponse()->getContent(), true)['photoUrl'];
        $this->upload($uri, $this->image(), $author);

        self::assertFileDoesNotExist($this->uploadDir.'/'.substr($first, strpos($first, 'dishes/')));
    }

    public function testNonAuthorCannotUploadDishPhoto(): void
    {
        $dish = $this->dish($this->createUser('owner2@test.mg'));
        $this->upload('/api/dishes/'.$dish->getId().'/photo', $this->image(), $this->createUser('thief@test.mg'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotUpload(): void
    {
        $this->upload('/api/me/avatar', $this->image(), null);
        self::assertResponseStatusCodeSame(401);
    }

    public function testNonImageIsRejected(): void
    {
        $user = $this->createUser('bad@test.mg');
        $this->upload('/api/me/avatar', $this->image('txt'), $user);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAvatarIsStoredAndLinkedToUser(): void
    {
        $user = $this->createUser('avatar@test.mg');
        $this->upload('/api/me/avatar', $this->image(), $user);

        self::assertResponseStatusCodeSame(201);
        $this->em()->refresh($user);
        self::assertStringContainsString('/uploads/avatars/', (string) $user->getAvatarUrl());
    }
}
