<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class UserRoleCommandTest extends KernelTestCase
{
    public function testAddsAndRemovesARole(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = (new User())->setEmail('mod@test.mg')->setDisplayName('Mod')->setPassword('x');
        $em->persist($user);
        $em->flush();

        $command = new CommandTester((new Application(self::$kernel))->find('app:user:role'));
        $command->execute(['email' => 'MOD@test.mg', '--add' => 'ROLE_ADMIN']);
        self::assertSame(0, $command->getStatusCode());
        self::assertContains('ROLE_ADMIN', $user->getRoles());

        $command->execute(['email' => 'mod@test.mg', '--remove' => 'ROLE_ADMIN']);
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());

        $command->execute(['email' => 'mod@test.mg', '--add' => 'ROLE_SUPER']);
        self::assertSame(2, $command->getStatusCode());
        $command->execute(['email' => 'nobody@test.mg', '--add' => 'ROLE_ADMIN']);
        self::assertSame(1, $command->getStatusCode());
    }
}
