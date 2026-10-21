<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\DishCategory;
use App\Enum\DishVisibility;
use App\Enum\IngredientAisle;
use App\Repository\DishRepository;
use App\State\AuthorAssigningProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DishRepository::class)]
#[ORM\Index(columns: ['is_daily_special', 'created_at'], name: 'idx_dish_special_created')]
#[ORM\Index(columns: ['price'], name: 'idx_dish_price')]
#[ORM\Index(columns: ['average_rating'], name: 'idx_dish_rating')]
#[ORM\Index(columns: ['author_id', 'created_at'], name: 'idx_dish_author_created')]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_USER')", processor: AuthorAssigningProcessor::class),
        new Patch(security: "is_granted('AUTHOR_EDIT', object)"),
        new Delete(security: "is_granted('AUTHOR_EDIT', object)"),
    ],
    normalizationContext: ['groups' => ['dish:read']],
    denormalizationContext: ['groups' => ['dish:write']],
    order: ['createdAt' => 'DESC'],
    cacheHeaders: ['max_age' => 0, 'shared_max_age' => 30, 'vary' => ['Content-Type', 'Authorization', 'Origin']],
    // Updates are broadcast publicly: never for a dish that is not public.
    mercure: 'object.isPublic()',
)]
#[ApiFilter(RangeFilter::class, properties: ['price', 'averageRating', 'prepTimeMinutes'])]
#[ApiFilter(OrderFilter::class, properties: ['price', 'createdAt', 'averageRating', 'reviewCount', 'likeCount'])]
#[ApiFilter(SearchFilter::class, properties: ['title' => 'ipartial', 'category' => 'exact', 'author' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isDailySpecial', 'vegetarian', 'festive'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
class Dish implements AuthoredInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['dish:read', 'review:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    #[Groups(['dish:read', 'dish:write', 'review:read'])]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Groups(['dish:read', 'dish:write'])]
    private string $description = '';

    /**
     * Each item: name, optional quantity ("500 g"), optional price in Ariary for that quantity, and a market aisle.
     * Plain strings are accepted on write and normalised to this shape.
     *
     * @var array<int|string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, max: 60)]
    #[Groups(['dish:read', 'dish:write'])]
    private array $ingredients = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, max: 60)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 1000)])]
    #[Groups(['dish:read', 'dish:write'])]
    private array $steps = [];

    /** Price in Malagasy Ariary (MGA). */
    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\PositiveOrZero]
    #[Groups(['dish:read', 'dish:write'])]
    private float $price = 0.0;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(callback: [DishCategory::class, 'values'])]
    #[Groups(['dish:read', 'dish:write'])]
    private string $category = 'plat';

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Groups(['dish:read'])]
    private ?string $photoUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['dish:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['dish:write'])]
    private bool $isDailySpecial = false;

    #[ORM\Column(length: 12, enumType: DishVisibility::class, options: ['default' => 'public'])]
    #[Groups(['dish:read', 'dish:write'])]
    private DishVisibility $visibility = DishVisibility::Public;

    /** Hidden by a moderator: invisible to everyone, whatever its visibility. */
    #[ORM\Column(options: ['default' => false])]
    private bool $hiddenByModeration = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['dish:read', 'dish:write'])]
    private bool $vegetarian = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['dish:read', 'dish:write'])]
    private bool $festive = false;

    #[ORM\ManyToOne(inversedBy: 'dishes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['dish:read'])]
    private ?User $author = null;

    /** Denormalised from reviews; maintained by ReviewProcessor. */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0])]
    #[Groups(['dish:read'])]
    private float $averageRating = 0.0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['dish:read'])]
    private int $reviewCount = 0;

    /** Denormalised from DishLike; maintained by LikeController. */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['dish:read'])]
    private int $likeCount = 0;

    /** Cooking time in minutes. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 1440)]
    #[Groups(['dish:read', 'dish:write'])]
    private ?int $prepTimeMinutes = null;

    /** Number of people the price refers to ("Ar / pers."). */
    #[ORM\Column(options: ['default' => 4])]
    #[Assert\Range(min: 1, max: 50)]
    #[Groups(['dish:read', 'dish:write'])]
    private int $servings = 4;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return list<array{name: string, quantity: ?string, price: ?float, aisle: string}>
     */
    public function getIngredients(): array
    {
        return array_map(self::normalizeIngredient(...), array_values($this->ingredients));
    }

    /**
     * @param array<int|string, mixed> $ingredients
     */
    public function setIngredients(array $ingredients): static
    {
        $this->ingredients = array_map(self::normalizeIngredient(...), array_values($ingredients));

        return $this;
    }

    /**
     * @return array{name: string, quantity: ?string, price: ?float, aisle: string}
     */
    private static function normalizeIngredient(mixed $item): array
    {
        if (!\is_array($item)) {
            $item = ['name' => \is_string($item) ? $item : ''];
        }
        $quantity = isset($item['quantity']) && \is_string($item['quantity']) && '' !== trim($item['quantity']) ? trim($item['quantity']) : null;
        $price = isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : null;
        $aisle = isset($item['aisle']) && \is_string($item['aisle']) ? $item['aisle'] : IngredientAisle::Other->value;

        return [
            'name' => isset($item['name']) && \is_string($item['name']) ? trim($item['name']) : '',
            'quantity' => $quantity,
            'price' => $price,
            'aisle' => $aisle,
        ];
    }

    #[Assert\Callback]
    public function validateIngredients(ExecutionContextInterface $context): void
    {
        foreach ($this->getIngredients() as $i => $item) {
            $path = \sprintf('ingredients[%d]', $i);
            if ('' === $item['name'] || mb_strlen($item['name']) > 200) {
                $context->buildViolation('Le nom de l\'ingrédient est obligatoire (200 caractères maximum).')->atPath($path.'.name')->addViolation();
            }
            if (null !== $item['quantity'] && mb_strlen($item['quantity']) > 40) {
                $context->buildViolation('La quantité est trop longue (40 caractères maximum).')->atPath($path.'.quantity')->addViolation();
            }
            if (null !== $item['price'] && ($item['price'] < 0 || $item['price'] > 100_000_000)) {
                $context->buildViolation('Le prix de l\'ingrédient est invalide.')->atPath($path.'.price')->addViolation();
            }
            if (!\in_array($item['aisle'], IngredientAisle::values(), true)) {
                $context->buildViolation('Rayon inconnu.')->atPath($path.'.aisle')->addViolation();
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @param list<string> $steps
     */
    public function setSteps(array $steps): static
    {
        $this->steps = $steps;

        return $this;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getPhotoUrl(): ?string
    {
        return $this->photoUrl;
    }

    public function setPhotoUrl(?string $photoUrl): static
    {
        $this->photoUrl = $photoUrl;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    #[Groups(['dish:read'])]
    #[SerializedName('isDailySpecial')]
    public function isDailySpecial(): bool
    {
        return $this->isDailySpecial;
    }

    public function setIsDailySpecial(bool $isDailySpecial): static
    {
        $this->isDailySpecial = $isDailySpecial;

        return $this;
    }

    public function getVisibility(): DishVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(DishVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function isPublic(): bool
    {
        return DishVisibility::Public === $this->visibility && !$this->hiddenByModeration && !$this->author?->isBanned();
    }

    public function isHiddenByModeration(): bool
    {
        return $this->hiddenByModeration;
    }

    public function setHiddenByModeration(bool $hidden): static
    {
        $this->hiddenByModeration = $hidden;

        return $this;
    }

    public function isVegetarian(): bool
    {
        return $this->vegetarian;
    }

    public function setVegetarian(bool $vegetarian): static
    {
        $this->vegetarian = $vegetarian;

        return $this;
    }

    public function isFestive(): bool
    {
        return $this->festive;
    }

    public function setFestive(bool $festive): static
    {
        $this->festive = $festive;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getAverageRating(): float
    {
        return $this->averageRating;
    }

    public function getReviewCount(): int
    {
        return $this->reviewCount;
    }

    public function getLikeCount(): int
    {
        return $this->likeCount;
    }

    public function setLikeCount(int $likeCount): static
    {
        $this->likeCount = max(0, $likeCount);

        return $this;
    }

    public function getPrepTimeMinutes(): ?int
    {
        return $this->prepTimeMinutes;
    }

    public function setPrepTimeMinutes(?int $prepTimeMinutes): static
    {
        $this->prepTimeMinutes = $prepTimeMinutes;

        return $this;
    }

    public function getServings(): int
    {
        return $this->servings;
    }

    public function setServings(int $servings): static
    {
        $this->servings = $servings;

        return $this;
    }

    public function updateRatingStats(float $average, int $count): static
    {
        $this->averageRating = round($average, 2);
        $this->reviewCount = $count;

        return $this;
    }
}
