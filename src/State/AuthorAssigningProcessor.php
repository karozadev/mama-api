<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuthoredInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sets the connected user as author of a newly created resource.
 *
 * @implements ProcessorInterface<AuthoredInterface, AuthoredInterface>
 */
final class AuthorAssigningProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $user = $this->security->getUser();
        if ($user instanceof User && null === $data->getAuthor()) {
            $data->setAuthor($user);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
