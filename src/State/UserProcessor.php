<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Hashes the plain password and throttles public registrations.
 *
 * @implements ProcessorInterface<User, User>
 */
final class UserProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'limiter.registration')]
        private readonly RateLimiterFactory $registrationLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        if ($operation instanceof HttpOperation && 'POST' === $operation->getMethod()) {
            $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
            if (!$this->registrationLimiter->create($ip)->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException(null, 'Trop de tentatives d\'inscription, réessayez plus tard.');
            }
            if (null === $data->getPlainPassword()) {
                throw new UnprocessableEntityHttpException('Le mot de passe est obligatoire.');
            }
        }

        if (null !== $data->getPlainPassword()) {
            $data->setPassword($this->passwordHasher->hashPassword($data, $data->getPlainPassword()));
            $data->eraseCredentials();
        }

        /** @var User $result */
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        return $result;
    }
}
