<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dish;
use App\Entity\User;
use App\Security\AuthorVoter;
use App\Service\Upload\ImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_USER')]
final class UploadController
{
    private const AVATAR_MAX_DIMENSION = 512;

    public function __construct(
        private readonly ImageUploader $uploader,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        #[Autowire(service: 'limiter.upload')]
        private readonly RateLimiterFactory $uploadLimiter,
    ) {
    }

    #[Route('/api/dishes/{id<\d+>}/photo', name: 'api_dish_photo', methods: ['POST'])]
    #[IsGranted(AuthorVoter::EDIT, subject: 'dish')]
    public function dishPhoto(Dish $dish, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $this->extractFile($request, $user);
        if ($file instanceof JsonResponse) {
            return $file;
        }
        $previous = $dish->getPhotoUrl();
        $dish->setPhotoUrl($this->uploader->upload($file, 'dishes'));
        $this->em->flush();
        $this->uploader->deleteByUrl($previous);

        return new JsonResponse(['photoUrl' => $dish->getPhotoUrl()], Response::HTTP_CREATED);
    }

    #[Route('/api/me/avatar', name: 'api_me_avatar', methods: ['POST'])]
    public function avatar(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $this->extractFile($request, $user);
        if ($file instanceof JsonResponse) {
            return $file;
        }
        $previous = $user->getAvatarUrl();
        $user->setAvatarUrl($this->uploader->upload($file, 'avatars', self::AVATAR_MAX_DIMENSION));
        $this->em->flush();
        $this->uploader->deleteByUrl($previous);

        return new JsonResponse(['avatarUrl' => $user->getAvatarUrl()], Response::HTTP_CREATED);
    }

    private function extractFile(Request $request, User $user): UploadedFile|JsonResponse
    {
        if (!$this->uploadLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->error('Trop d\'envois, réessayez plus tard.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->error('Le champ multipart "file" est obligatoire.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $violations = $this->validator->validate($file, new Assert\Image(
            maxSize: '5M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            maxPixels: 25_000_000,
            maxSizeMessage: 'L\'image est trop lourde ({{ size }} {{ suffix }}). Maximum : {{ limit }} {{ suffix }}.',
            mimeTypesMessage: 'Format non supporté. Formats acceptés : JPEG, PNG, WebP.',
        ));
        if (\count($violations) > 0) {
            return $this->error((string) $violations->get(0)->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $file;
    }

    private function error(string $detail, int $status): JsonResponse
    {
        return new JsonResponse(['title' => 'Envoi refusé', 'detail' => $detail, 'status' => $status], $status);
    }
}
