<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\DishVisibility;
use App\Enum\UserPlan;
use App\Repository\UserRepository;
use App\State\UserProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse e-mail est déjà utilisée.')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('PUBLIC_ACCESS')", processor: UserProcessor::class),
        new Patch(security: "is_granted('ROLE_USER') and object == user", processor: UserProcessor::class),
    ],
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:write']],
    paginationItemsPerPage: 20,
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'dish:read', 'review:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:write'])]
    private string $email = '';

    #[ORM\Column]
    private string $password = '';

    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Length(min: 8, max: 128)]
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 80)]
    #[Groups(['user:read', 'user:write', 'dish:read', 'review:read'])]
    private string $displayName = '';

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Groups(['user:read', 'dish:read'])]
    private ?string $avatarUrl = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['user:read', 'user:write', 'dish:read'])]
    private ?string $city = null;

    #[ORM\Column(length: 280, nullable: true)]
    #[Assert\Length(max: 280)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $bio = null;

    /** Other mothers may see my bookmarked dishes (the "Favoris" tab of my profile). */
    #[ORM\Column(options: ['default' => true])]
    #[Groups(['user:read', 'user:write'])]
    private bool $favoritesPublic = true;

    /** Set by a moderator: the account cannot log in and its content is hidden. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bannedAt = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $banReason = null;

    /** Messaging is refused until this instant (set by moderation). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $messagingSuspendedUntil = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['user:read'])]
    private array $roles = [];

    #[ORM\Column(length: 20, enumType: UserPlan::class)]
    #[Groups(['user:read'])]
    private UserPlan $plan = UserPlan::Free;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $planExpiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, User> Mothers this user follows */
    #[ORM\ManyToMany(targetEntity: self::class, inversedBy: 'followers', fetch: 'EXTRA_LAZY')]
    #[ORM\JoinTable(name: 'user_follow')]
    #[ORM\JoinColumn(name: 'follower_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'followed_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $following;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: self::class, mappedBy: 'following', fetch: 'EXTRA_LAZY')]
    private Collection $followers;

    /** @var Collection<int, Dish> */
    #[ORM\OneToMany(targetEntity: Dish::class, mappedBy: 'author', fetch: 'EXTRA_LAZY')]
    private Collection $dishes;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->following = new ArrayCollection();
        $this->followers = new ArrayCollection();
        $this->dishes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): static
    {
        $this->avatarUrl = $avatarUrl;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    /** Dishes visible on the profile (private ones are not counted). */
    #[Groups(['user:read'])]
    public function getDishCount(): int
    {
        return $this->dishes->matching(Criteria::create()->where(Criteria::expr()->neq('visibility', DishVisibility::Private->value)))->count();
    }

    /**
     * @return Collection<int, Dish>
     */
    public function getDishes(): Collection
    {
        return $this->dishes;
    }

    /** Every dish, private ones included (for the owner only). */
    public function getTotalDishCount(): int
    {
        return $this->dishes->count();
    }

    public function getPlan(): UserPlan
    {
        return $this->plan;
    }

    public function setPlan(UserPlan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getPlanExpiresAt(): ?\DateTimeImmutable
    {
        return $this->planExpiresAt;
    }

    public function setPlanExpiresAt(?\DateTimeImmutable $planExpiresAt): static
    {
        $this->planExpiresAt = $planExpiresAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isFavoritesPublic(): bool
    {
        return $this->favoritesPublic;
    }

    public function setFavoritesPublic(bool $favoritesPublic): static
    {
        $this->favoritesPublic = $favoritesPublic;

        return $this;
    }

    public function isBanned(): bool
    {
        return null !== $this->bannedAt;
    }

    public function getBannedAt(): ?\DateTimeImmutable
    {
        return $this->bannedAt;
    }

    public function getBanReason(): ?string
    {
        return $this->banReason;
    }

    public function ban(?string $reason): static
    {
        $this->bannedAt = new \DateTimeImmutable();
        $this->banReason = null === $reason ? null : mb_substr($reason, 0, 300);

        return $this;
    }

    public function unban(): static
    {
        $this->bannedAt = null;
        $this->banReason = null;

        return $this;
    }

    public function getMessagingSuspendedUntil(): ?\DateTimeImmutable
    {
        return $this->messagingSuspendedUntil;
    }

    public function setMessagingSuspendedUntil(?\DateTimeImmutable $until): static
    {
        $this->messagingSuspendedUntil = $until;

        return $this;
    }

    public function isMessagingSuspended(): bool
    {
        return null !== $this->messagingSuspendedUntil && $this->messagingSuspendedUntil > new \DateTimeImmutable();
    }

    public function isChef(): bool
    {
        return \in_array('ROLE_CHEF', $this->roles, true);
    }

    /**
     * @return Collection<int, User>
     */
    public function getFollowing(): Collection
    {
        return $this->following;
    }

    #[Groups(['user:read'])]
    public function getFollowingCount(): int
    {
        return $this->following->count();
    }

    #[Groups(['user:read'])]
    public function getFollowersCount(): int
    {
        return $this->followers->count();
    }

    public function follow(self $mother): static
    {
        if ($mother !== $this && !$this->following->contains($mother)) {
            $this->following->add($mother);
        }

        return $this;
    }

    public function unfollow(self $mother): static
    {
        $this->following->removeElement($mother);

        return $this;
    }

    public function isFollowing(self $mother): bool
    {
        return $this->following->contains($mother);
    }
}
