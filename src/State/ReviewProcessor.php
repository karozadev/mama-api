<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\ReviewRepository;
use App\Service\DishAccess;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Assigns the author and keeps the denormalised rating stats of the dish up to date.
 *
 * @implements ProcessorInterface<Review, Review|null>
 */
final class ReviewProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $persistProcessor
     * @param ProcessorInterface<mixed, mixed> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly Security $security,
        private readonly ReviewRepository $reviews,
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
        private readonly DishAccess $access,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?Review
    {
        $dish = $data->getDish();

        if ($operation instanceof DeleteOperationInterface) {
            $this->removeProcessor->process($data, $operation, $uriVariables, $context);
            $this->refreshStats($data);

            return null;
        }

        $user = $this->security->getUser();
        if (null !== $dish) {
            $this->access->assertCanView($dish, $user instanceof User ? $user : null);
        }
        if (null === $data->getAuthor() && $user instanceof User) {
            if ($dish?->getAuthor()?->getId() === $user->getId()) {
                throw new UnprocessableEntityHttpException('Vous ne pouvez pas noter votre propre plat.');
            }
            $data->setAuthor($user);
        }

        $isCreation = null === $data->getId();
        /** @var Review $result */
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        $this->refreshStats($result);
        if ($isCreation && null !== $result->getDish()?->getAuthor()) {
            $this->notifications->record($result->getDish()->getAuthor(), NotificationType::Review, $result->getAuthor(), $result->getDish(), null === $result->getComment() ? null : mb_strimwidth($result->getComment(), 0, 117, '…'));
        }

        return $result;
    }

    private function refreshStats(Review $review): void
    {
        $dish = $review->getDish();
        if (null === $dish) {
            return;
        }

        $stats = $this->reviews->computeStats($dish);
        $dish->updateRatingStats($stats['average'], $stats['count']);
        $this->em->flush();
    }
}
