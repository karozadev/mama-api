<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class MessageTest extends ApiTestCase
{
    public function testStrangersCannotWriteButFollowersCan(): void
    {
        $mother = $this->createUser('mama@test.mg');
        $fan = $this->createUser('fan@test.mg');

        $this->request('POST', '/api/conversations/'.$mother->getId().'/messages', ['body' => 'Bonjour'], $fan);
        self::assertResponseStatusCodeSame(403);

        $this->request('POST', '/api/users/'.$mother->getId().'/follow', null, $fan);
        $data = $this->request('POST', '/api/conversations/'.$mother->getId().'/messages', ['body' => ' Bonjour Mama ! '], $fan);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Bonjour Mama !', $data['body']);
        self::assertTrue($data['mine']);
    }

    public function testTheRecipientCanAnswerAfterBeingContacted(): void
    {
        $mother = $this->createUser('mama2@test.mg');
        $fan = $this->createUser('fan2@test.mg');
        $this->request('POST', '/api/users/'.$mother->getId().'/follow', null, $fan);
        $this->request('POST', '/api/conversations/'.$mother->getId().'/messages', ['body' => 'Salut'], $fan);

        // The mother does not follow the fan, but the fan follows her: she can answer.
        $this->request('POST', '/api/conversations/'.$fan->getId().'/messages', ['body' => 'Salama !'], $mother);
        self::assertResponseStatusCodeSame(201);
    }

    public function testInboxHistoryUnreadAndRead(): void
    {
        $mother = $this->createUser('mama3@test.mg');
        $fan = $this->createUser('fan3@test.mg');
        $this->request('POST', '/api/users/'.$mother->getId().'/follow', null, $fan);
        foreach (['Un', 'Deux', 'Trois'] as $body) {
            $this->request('POST', '/api/conversations/'.$mother->getId().'/messages', ['body' => $body], $fan);
        }

        self::assertSame(3, $this->request('GET', '/api/me', null, $mother)['unreadMessages']);
        $inbox = $this->request('GET', '/api/me/conversations', null, $mother);
        self::assertCount(1, $inbox);
        self::assertSame('Trois', $inbox[0]['lastMessage']);
        self::assertSame(3, $inbox[0]['unread']);
        self::assertSame($fan->getId(), $inbox[0]['user']['id']);
        self::assertFalse($inbox[0]['lastIsMine']);

        $history = $this->request('GET', '/api/conversations/'.$fan->getId().'/messages', null, $mother);
        self::assertSame(['Trois', 'Deux', 'Un'], array_column($history['items'], 'body'));
        self::assertFalse($history['items'][0]['mine']);

        $this->request('POST', '/api/conversations/'.$fan->getId().'/read', null, $mother);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->request('GET', '/api/me', null, $mother)['unreadMessages']);
        self::assertSame(0, $this->request('GET', '/api/me/conversations', null, $mother)[0]['unread']);
    }

    public function testPaginationWithBefore(): void
    {
        $mother = $this->createUser('mama4@test.mg');
        $fan = $this->createUser('fan4@test.mg');
        $this->request('POST', '/api/users/'.$mother->getId().'/follow', null, $fan);
        $last = 0;
        for ($i = 1; $i <= 32; ++$i) {
            $last = $this->request('POST', '/api/conversations/'.$mother->getId().'/messages', ['body' => "m$i"], $fan)['id'];
        }

        $page1 = $this->request('GET', '/api/conversations/'.$fan->getId().'/messages', null, $mother);
        self::assertCount(30, $page1['items']);
        self::assertTrue($page1['hasMore']);
        $page2 = $this->request('GET', '/api/conversations/'.$fan->getId().'/messages?before='.end($page1['items'])['id'], null, $mother);
        self::assertSame(['m2', 'm1'], array_column($page2['items'], 'body'));
        self::assertFalse($page2['hasMore']);
        self::assertSame($last, $page1['items'][0]['id']);
    }

    public function testValidation(): void
    {
        $mother = $this->createUser('mama5@test.mg');
        $fan = $this->createUser('fan5@test.mg');
        $this->request('POST', '/api/users/'.$mother->getId().'/follow', null, $fan);

        $this->request('POST', '/api/conversations/'.$mother->getId().'/messages', ['body' => '   '], $fan);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/conversations/'.$mother->getId().'/messages', ['body' => str_repeat('a', 2001)], $fan);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/conversations/'.$fan->getId().'/messages', ['body' => 'moi'], $fan);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnonymousCannotReadMessages(): void
    {
        $this->request('GET', '/api/me/conversations');
        self::assertResponseStatusCodeSame(401);
    }
}
