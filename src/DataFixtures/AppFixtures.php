<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Bookmark;
use App\Entity\DirectMessage;
use App\Entity\Dish;
use App\Entity\DishCollection;
use App\Entity\DishCollectionItem;
use App\Entity\DishLike;
use App\Entity\Notification;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\DishVisibility;
use App\Enum\NotificationType;
use App\Repository\ReviewRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    private const PASSWORD = 'password';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ReviewRepository $reviews,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(42);

        $admin = $this->createUser('admin@example.com', 'Administrateur', ['ROLE_ADMIN']);
        $manager->persist($admin);

        /** @var array<string, User> $mothers */
        $mothers = [];
        foreach ($this->mothers() as $key => [$email, $name, $roles, $city, $bio]) {
            $mothers[$key] = $this->createUser($email, $name, $roles)->setCity($city)->setBio($bio);
            $manager->persist($mothers[$key]);
        }

        // Followers: regular users following the mothers.
        $followers = [];
        foreach (['Tiana', 'Fanja', 'Hery', 'Nirina', 'Toky', 'Lova'] as $i => $name) {
            $follower = $this->createUser(\sprintf('%s@example.com', mb_strtolower($name)), $name, []);
            $manager->persist($follower);
            $followers[] = $follower;
            foreach (array_slice($mothers, $i % 3, 3) as $mother) {
                $follower->follow($mother);
            }
        }
        foreach ($mothers as $key => $mother) {
            foreach ($mothers as $otherKey => $other) {
                if ($key !== $otherKey && 0 === (\strlen($key) + \strlen($otherKey)) % 3) {
                    $mother->follow($other);
                }
            }
        }

        // slug => [price per person (Ar), minutes, servings]
        $extra = [
            'romazava' => [4500, 150, 4], 'ravitoto-sy-henomby' => [3800, 180, 4], 'henomby-ritra' => [5000, 120, 4],
            'kitoza' => [4200, 90, 4], 'vary-amin-anana' => [2200, 40, 4], 'ravimbomanga' => [3500, 70, 4],
            'akoho-sy-voanio' => [4800, 75, 4], 'henakisoa-sy-voanio' => [5200, 90, 4], 'koba' => [800, 90, 8],
            'mofo-gasy' => [500, 30, 6], 'sambos' => [800, 45, 6], 'lasopy' => [1800, 35, 4],
            'ranonapango' => [500, 10, 4], 'tilapia-sy-anamamy' => [3800, 40, 2], 'achards' => [1000, 30, 6],
        ];

        /** @var list<Dish> $dishes */
        $dishes = [];
        /** @var array<string, Dish> $bySlug */
        $bySlug = [];
        foreach ($this->dishes() as $i => $data) {
            [$data['price'], $minutes, $servings] = $extra[$data['slug']];
            $dish = (new Dish())
                ->setTitle($data['title'])
                ->setDescription($data['description'])
                ->setIngredients($this->ingredientsFor($data['slug']))->setVegetarian(\in_array($data['slug'], ['lasopy', 'achards', 'mofo-gasy', 'koba', 'ranonapango'], true))->setFestive(\in_array($data['slug'], ['henakisoa-sy-voanio', 'akoho-sy-voanio', 'koba', 'romazava', 'sambos'], true))
                ->setSteps($data['steps'])
                ->setPrice((float) $data['price'])->setPrepTimeMinutes($minutes)->setServings($servings)
                ->setCategory($data['category'])
                ->setPhotoUrl(\sprintf('https://cdn.example.com/dishes/%s.jpg', $data['slug']))
                ->setIsDailySpecial($data['special'])
                ->setAuthor($mothers[$data['author']])
                ->setCreatedAt(new \DateTimeImmutable(\sprintf('-%d minutes', 20 + $i * 75)));
            $dish->setVisibility(match ($data['slug']) {
                'kitoza' => DishVisibility::Followers,
                'sambos' => DishVisibility::Private,
                default => DishVisibility::Public,
            });
            $manager->persist($dish);
            $dishes[] = $dish;
            $bySlug[$data['slug']] = $dish;
        }
        $manager->flush();

        $comments = [
            5 => ['Un délice, toute la famille a redemandé !', 'Exactement comme chez grand-mère.', 'Parfait pour le dimanche.'],
            4 => ['Très bon, un peu plus de sel la prochaine fois.', 'Simple et savoureux.', 'Bonne recette, facile à suivre.'],
            3 => ['Correct, mais un peu long à préparer.', 'Bien, mais j\'ai ajusté les quantités.'],
            2 => ['Pas tout à fait à mon goût.'],
        ];
        $reviewers = [...$followers, ...array_values($mothers)];
        foreach ($dishes as $dish) {
            $pool = array_values(array_filter($reviewers, static fn (User $u): bool => $u !== $dish->getAuthor()));
            shuffle($pool);
            foreach (array_slice($pool, 0, mt_rand(2, 6)) as $reviewer) {
                $rating = [5, 5, 5, 4, 4, 3, 2][mt_rand(0, 6)];
                $manager->persist((new Review())
                    ->setDish($dish)
                    ->setAuthor($reviewer)
                    ->setRating($rating)
                    ->setComment($comments[$rating][array_rand($comments[$rating])]));
            }
        }
        $manager->flush();

        foreach ($dishes as $dish) {
            $stats = $this->reviews->computeStats($dish);
            $dish->updateRatingStats($stats['average'], $stats['count']);
        }

        $likesByDish = [];
        foreach ($dishes as $dish) {
            $pool = array_values(array_filter($reviewers, static fn (User $u): bool => $u !== $dish->getAuthor()));
            shuffle($pool);
            $likers = array_slice($pool, 0, mt_rand(2, \count($pool)));
            foreach ($likers as $liker) {
                $manager->persist(new DishLike($liker, $dish));
            }
            $dish->setLikeCount(\count($likers));
            $likesByDish[$dish->getId()] = $likers;
        }
        foreach (array_slice($followers, 0, 4) as $i => $follower) {
            foreach (array_slice($dishes, $i * 2, 3) as $dish) {
                $manager->persist(new Bookmark($follower, $dish));
            }
        }
        $manager->flush();

        $this->seedNotifications($manager, $dishes, $likesByDish, $followers);
        $manager->flush();

        $tiana = $followers[0];
        $this->seedCollections($manager, $mothers, $tiana, $bySlug);
        $this->seedMessages($manager, $mothers['rasoa'], $tiana);
        $manager->flush();
    }

    /**
     * Ingredients with quantity, price (Ariary) and market aisle, per dish slug.
     *
     * @return list<array{name: string, quantity: ?string, price: ?int, aisle: string}>
     */
    private function ingredientsFor(string $slug): array
    {
        $all = [
            'romazava' => [['name' => 'Bœuf (morceaux)', 'quantity' => '500 g', 'price' => 8000, 'aisle' => 'viandes'], ['name' => 'Brèdes mafana', 'quantity' => '1 botte', 'price' => 1500, 'aisle' => 'legumes'], ['name' => 'Brèdes morelle', 'quantity' => '1 botte', 'price' => 1200, 'aisle' => 'legumes'], ['name' => 'Tomates', 'quantity' => '2', 'price' => 700, 'aisle' => 'legumes'], ['name' => 'Oignon, ail, gingembre', 'quantity' => null, 'price' => 800, 'aisle' => 'legumes'], ['name' => 'Sel et poivre', 'quantity' => null, 'price' => 200, 'aisle' => 'epicerie']],
            'ravitoto-sy-henomby' => [['name' => 'Bœuf', 'quantity' => '400 g', 'price' => 6500, 'aisle' => 'viandes'], ['name' => 'Feuilles de manioc pilées', 'quantity' => '500 g', 'price' => 2500, 'aisle' => 'legumes'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Ail', 'quantity' => '4 gousses', 'price' => 300, 'aisle' => 'legumes'], ['name' => 'Tomate', 'quantity' => '1', 'price' => 350, 'aisle' => 'legumes'], ['name' => 'Huile', 'quantity' => '2 c. à soupe', 'price' => 300, 'aisle' => 'epicerie']],
            'henomby-ritra' => [['name' => 'Bœuf séché', 'quantity' => '500 g', 'price' => 9000, 'aisle' => 'viandes'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Ail', 'quantity' => '4 gousses', 'price' => 300, 'aisle' => 'legumes'], ['name' => 'Gingembre', 'quantity' => '1 morceau', 'price' => 250, 'aisle' => 'legumes'], ['name' => 'Tomates', 'quantity' => '2', 'price' => 700, 'aisle' => 'legumes'], ['name' => 'Huile', 'quantity' => null, 'price' => 400, 'aisle' => 'epicerie']],
            'kitoza' => [['name' => 'Bœuf en lanières', 'quantity' => '600 g', 'price' => 9000, 'aisle' => 'viandes'], ['name' => 'Ail', 'quantity' => '4 gousses', 'price' => 300, 'aisle' => 'legumes'], ['name' => 'Gingembre', 'quantity' => '1 morceau', 'price' => 250, 'aisle' => 'legumes'], ['name' => 'Citron', 'quantity' => '1', 'price' => 300, 'aisle' => 'legumes'], ['name' => 'Sel et poivre', 'quantity' => null, 'price' => 200, 'aisle' => 'epicerie']],
            'vary-amin-anana' => [['name' => 'Riz', 'quantity' => '1 tasse', 'price' => 800, 'aisle' => 'epicerie'], ['name' => 'Brèdes mafana', 'quantity' => '2 bottes', 'price' => 3000, 'aisle' => 'legumes'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Tomates', 'quantity' => '2', 'price' => 700, 'aisle' => 'legumes'], ['name' => 'Viande de zébu', 'quantity' => '200 g', 'price' => 3000, 'aisle' => 'viandes']],
            'ravimbomanga' => [['name' => 'Porc', 'quantity' => '400 g', 'price' => 5500, 'aisle' => 'viandes'], ['name' => 'Feuilles de patate douce', 'quantity' => '3 bottes', 'price' => 3000, 'aisle' => 'legumes'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Tomates', 'quantity' => '2', 'price' => 700, 'aisle' => 'legumes'], ['name' => 'Ail', 'quantity' => '3 gousses', 'price' => 250, 'aisle' => 'legumes']],
            'akoho-sy-voanio' => [['name' => 'Poulet découpé', 'quantity' => '1', 'price' => 9000, 'aisle' => 'viandes'], ['name' => 'Lait de coco', 'quantity' => '400 ml', 'price' => 3000, 'aisle' => 'epicerie'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Ail', 'quantity' => '3 gousses', 'price' => 250, 'aisle' => 'legumes'], ['name' => 'Gingembre', 'quantity' => '1 morceau', 'price' => 250, 'aisle' => 'legumes'], ['name' => 'Tomates', 'quantity' => '2', 'price' => 700, 'aisle' => 'legumes']],
            'henakisoa-sy-voanio' => [['name' => 'Porc', 'quantity' => '500 g', 'price' => 7000, 'aisle' => 'viandes'], ['name' => 'Lait de coco', 'quantity' => '400 ml', 'price' => 3000, 'aisle' => 'epicerie'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Ail', 'quantity' => '3 gousses', 'price' => 250, 'aisle' => 'legumes'], ['name' => 'Gingembre', 'quantity' => '1 morceau', 'price' => 250, 'aisle' => 'legumes']],
            'koba' => [['name' => 'Farine de riz', 'quantity' => '250 g', 'price' => 1500, 'aisle' => 'epicerie'], ['name' => 'Cacahuètes pilées', 'quantity' => '150 g', 'price' => 1500, 'aisle' => 'epicerie'], ['name' => 'Sucre', 'quantity' => '150 g', 'price' => 800, 'aisle' => 'epicerie'], ['name' => 'Bananes mûres', 'quantity' => '3', 'price' => 900, 'aisle' => 'legumes'], ['name' => 'Feuilles de bananier', 'quantity' => null, 'price' => 400, 'aisle' => 'legumes']],
            'mofo-gasy' => [['name' => 'Farine de riz', 'quantity' => '2 tasses', 'price' => 1500, 'aisle' => 'epicerie'], ['name' => 'Sucre', 'quantity' => '1 tasse', 'price' => 800, 'aisle' => 'epicerie'], ['name' => 'Lait de coco', 'quantity' => '1 verre', 'price' => 800, 'aisle' => 'epicerie'], ['name' => 'Levure', 'quantity' => '1 sachet', 'price' => 200, 'aisle' => 'epicerie'], ['name' => 'Huile', 'quantity' => null, 'price' => 300, 'aisle' => 'epicerie']],
            'sambos' => [['name' => 'Feuilles de brick', 'quantity' => '1 paquet', 'price' => 1500, 'aisle' => 'epicerie'], ['name' => 'Viande hachée', 'quantity' => '300 g', 'price' => 3500, 'aisle' => 'viandes'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Ail', 'quantity' => '1 gousse', 'price' => 100, 'aisle' => 'legumes'], ['name' => 'Persil', 'quantity' => '1 botte', 'price' => 300, 'aisle' => 'legumes'], ['name' => 'Huile de friture', 'quantity' => '1 l', 'price' => 5000, 'aisle' => 'epicerie']],
            'lasopy' => [['name' => 'Carottes', 'quantity' => '2', 'price' => 800, 'aisle' => 'legumes'], ['name' => 'Pommes de terre', 'quantity' => '2', 'price' => 900, 'aisle' => 'legumes'], ['name' => 'Poireau', 'quantity' => '1', 'price' => 600, 'aisle' => 'legumes'], ['name' => 'Vermicelle', 'quantity' => '100 g', 'price' => 700, 'aisle' => 'epicerie'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Cube de bouillon', 'quantity' => '1', 'price' => 200, 'aisle' => 'epicerie']],
            'ranonapango' => [['name' => 'Riz grillé au fond de la casserole', 'quantity' => '1 tasse', 'price' => 800, 'aisle' => 'epicerie'], ['name' => 'Eau', 'quantity' => '1 l', 'price' => null, 'aisle' => 'epicerie']],
            'tilapia-sy-anamamy' => [['name' => 'Tilapia nettoyé', 'quantity' => '2', 'price' => 6000, 'aisle' => 'viandes'], ['name' => 'Farine', 'quantity' => '3 c. à soupe', 'price' => 300, 'aisle' => 'epicerie'], ['name' => 'Brèdes', 'quantity' => '2 bottes', 'price' => 2000, 'aisle' => 'legumes'], ['name' => 'Ail', 'quantity' => '3 gousses', 'price' => 250, 'aisle' => 'legumes'], ['name' => 'Huile', 'quantity' => '20 cl', 'price' => 1500, 'aisle' => 'epicerie'], ['name' => 'Citron', 'quantity' => '1', 'price' => 300, 'aisle' => 'legumes']],
            'achards' => [['name' => 'Chou', 'quantity' => '1/2', 'price' => 1500, 'aisle' => 'legumes'], ['name' => 'Carottes', 'quantity' => '3', 'price' => 1200, 'aisle' => 'legumes'], ['name' => 'Haricots verts', 'quantity' => '200 g', 'price' => 1500, 'aisle' => 'legumes'], ['name' => 'Oignon', 'quantity' => '1', 'price' => 400, 'aisle' => 'legumes'], ['name' => 'Curcuma et gingembre', 'quantity' => null, 'price' => 500, 'aisle' => 'epicerie'], ['name' => 'Vinaigre', 'quantity' => '10 cl', 'price' => 500, 'aisle' => 'epicerie'], ['name' => 'Huile', 'quantity' => '10 cl', 'price' => 800, 'aisle' => 'epicerie']],
        ];

        return $all[$slug];
    }

    /**
     * Demo notification feed for every mother (likes, reviews, follows) and the daily specials seen by followers.
     *
     * @param list<Dish>                $dishes
     * @param array<int, list<User>>    $likesByDish
     * @param list<User>                $followers
     */
    private function seedNotifications(ObjectManager $manager, array $dishes, array $likesByDish, array $followers): void
    {
        $minutes = 12;
        foreach ($dishes as $dish) {
            $author = $dish->getAuthor();
            foreach (array_slice($likesByDish[$dish->getId()] ?? [], 0, 2) as $liker) {
                $manager->persist((new Notification($author, NotificationType::Like, $liker, $dish))
                    ->setCreatedAt(new \DateTimeImmutable(\sprintf('-%d minutes', $minutes))));
                $minutes += 47;
            }
            if ($dish->isDailySpecial()) {
                foreach ($followers as $follower) {
                    if ($follower->isFollowing($author)) {
                        $manager->persist((new Notification($follower, NotificationType::DailySpecial, $author, $dish))
                            ->setCreatedAt($dish->getCreatedAt()));
                    }
                }
            }
        }
        foreach (array_slice($followers, 0, 3) as $i => $follower) {
            foreach ($follower->getFollowing() as $mother) {
                $manager->persist((new Notification($mother, NotificationType::Follow, $follower))
                    ->setCreatedAt(new \DateTimeImmutable(\sprintf('-%d hours', 3 + $i * 9))));
            }
        }
    }

    /**
     * @param array<string, User> $mothers
     * @param array<string, Dish> $bySlug
     */
    private function seedCollections(ObjectManager $manager, array $mothers, User $tiana, array $bySlug): void
    {
        $lists = [
            [$mothers['voahangy'], 'Classiques malgaches', true, ['romazava', 'ravitoto-sy-henomby', 'henomby-ritra', 'vary-amin-anana']],
            [$mothers['rasoa'], 'Petits budgets', true, ['mofo-gasy', 'ranonapango', 'lasopy', 'achards']],
            [$tiana, 'Pour le dimanche', false, ['akoho-sy-voanio', 'henakisoa-sy-voanio', 'koba']],
        ];
        foreach ($lists as $i => [$owner, $name, $public, $slugs]) {
            $collection = new DishCollection($owner, $name, $public);
            $manager->persist($collection);
            foreach ($slugs as $slug) {
                $manager->persist(new DishCollectionItem($collection, $bySlug[$slug]));
                $author = $bySlug[$slug]->getAuthor();
                if ($public && $author !== $owner && 0 === $i) {
                    $manager->persist((new Notification($author, NotificationType::Collection, $owner, $bySlug[$slug]))
                        ->setLabel($name)
                        ->setCreatedAt(new \DateTimeImmutable(\sprintf('-%d hours', 20 + \count($slugs)))));
                }
            }
        }
    }

    private function seedMessages(ObjectManager $manager, User $rasoa, User $tiana): void
    {
        $thread = [
            [$tiana, $rasoa, 'Bonjour Mama Rasoa ! Ton Romazava a l\'air délicieux 😍', 190],
            [$rasoa, $tiana, 'Merci Tiana ! Il mijote depuis ce matin.', 175],
            [$tiana, $rasoa, 'Tu mets des brèdes anamalao aussi ?', 160],
            [$rasoa, $tiana, 'Oui, une botte, et un peu de gingembre. Je t\'envoie la recette complète demain !', 12],
        ];
        foreach ($thread as [$from, $to, $body, $minutesAgo]) {
            $message = (new DirectMessage($from, $to, $body))->setCreatedAt(new \DateTimeImmutable(\sprintf('-%d minutes', $minutesAgo)));
            if ($to === $rasoa) {
                $message->setReadAt(new \DateTimeImmutable());
            }
            $manager->persist($message);
        }
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, string $name, array $roles): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($name)
            ->setRoles($roles)
            ->setAvatarUrl(null);
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));

        return $user;
    }

    /**
     * @return array<string, array{string, string, list<string>, string, string}>
     */
    private function mothers(): array
    {
        return [
            'rasoa' => ['rasoa@example.com', 'Mama Rasoa', ['ROLE_CHEF'], 'Antananarivo · Analakely', 'Je cuisine pour trois enfants avec peu, et je partage ce que ma grand-mère m\'a appris.'],
            'voahangy' => ['voahangy@example.com', 'Mama Voahangy', ['ROLE_CHEF'], 'Fianarantsoa', 'Les classiques du dimanche, mijotés lentement.'],
            'soa' => ['soa@example.com', 'Mama Soa', [], 'Toamasina', 'Cuisine de la côte, riche en coco et en poisson.'],
            'hanitra' => ['hanitra@example.com', 'Mama Hanitra', [], 'Toamasina', 'Petits budgets, grands sourires.'],
            'lalao' => ['lalao@example.com', 'Mama Lalao', [], 'Mahajanga', 'Végétarien et rapide, pour les soirs pressés.'],
            'mirana' => ['mirana@example.com', 'Mama Mirana', [], 'Antsirabe', 'Les recettes du marché de mon quartier.'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dishes(): array
    {
        return [
            [
                'slug' => 'romazava', 'title' => 'Romazava', 'author' => 'rasoa', 'special' => true, 'category' => 'plat', 'price' => 12000.0,
                'description' => 'Le plat national malgache : un bouillon parfumé de bœuf et de brèdes mafana, qui picotent légèrement la langue.',
                'ingredients' => ['500 g de bœuf en morceaux', '2 bottes de brèdes mafana', '1 botte de brèdes morelle', '2 tomates', '1 oignon', '3 gousses d\'ail', '1 morceau de gingembre', 'Sel et poivre'],
                'steps' => ['Faire revenir la viande avec l\'oignon, l\'ail et le gingembre.', 'Ajouter les tomates et laisser mijoter 10 minutes.', 'Couvrir d\'eau et cuire 45 minutes jusqu\'à ce que la viande soit tendre.', 'Ajouter les brèdes lavées et cuire encore 10 minutes.', 'Rectifier l\'assaisonnement et servir avec du riz blanc.'],
            ],
            [
                'slug' => 'ravitoto-sy-henomby', 'title' => 'Ravitoto sy Hen\'omby', 'author' => 'voahangy', 'special' => true, 'category' => 'plat', 'price' => 14000.0,
                'description' => 'Feuilles de manioc pilées longuement mijotées avec du bœuf, un grand classique des repas du dimanche.',
                'ingredients' => ['400 g de bœuf', '500 g de feuilles de manioc pilées', '1 oignon', '4 gousses d\'ail', '1 tomate', '2 cuillères à soupe d\'huile', 'Sel'],
                'steps' => ['Faire dorer la viande dans l\'huile avec l\'oignon et l\'ail.', 'Ajouter la tomate et un peu d\'eau, cuire 30 minutes.', 'Incorporer le ravitoto pilé.', 'Laisser mijoter à feu doux 1 heure en remuant régulièrement.', 'Servir très chaud avec du riz.'],
            ],
            [
                'slug' => 'henomby-ritra', 'title' => 'Hen\'omby ritra', 'author' => 'soa', 'special' => true, 'category' => 'plat', 'price' => 13000.0,
                'description' => 'Bœuf séché puis revenu, tendre et parfumé à l\'ail et au gingembre.',
                'ingredients' => ['500 g de bœuf séché', '1 oignon', '4 gousses d\'ail', '1 morceau de gingembre', '2 tomates', 'Huile', 'Sel et poivre'],
                'steps' => ['Faire tremper le bœuf séché 30 minutes puis l\'égoutter.', 'Le faire revenir dans l\'huile jusqu\'à ce qu\'il soit doré.', 'Ajouter l\'oignon, l\'ail, le gingembre et les tomates.', 'Couvrir et laisser mijoter 25 minutes.', 'Servir avec du riz et des brèdes.'],
            ],
            [
                'slug' => 'kitoza', 'title' => 'Kitoza', 'author' => 'hanitra', 'special' => false, 'category' => 'plat', 'price' => 10000.0,
                'description' => 'Lanières de bœuf ou de porc marinées, séchées puis grillées, servies traditionnellement avec du riz et du rougail.',
                'ingredients' => ['600 g de bœuf en lanières', '4 gousses d\'ail', '1 morceau de gingembre', '1 cuillère à soupe de sel', 'Poivre', '1 citron'],
                'steps' => ['Mélanger l\'ail, le gingembre, le sel, le poivre et le citron.', 'Mariner les lanières une nuit au frais.', 'Les faire sécher au soleil ou à four très doux.', 'Les faire griller quelques minutes de chaque côté.', 'Servir avec du riz et du rougail tomate.'],
            ],
            [
                'slug' => 'vary-amin-anana', 'title' => 'Vary amin\'anana', 'author' => 'lalao', 'special' => false, 'category' => 'plat', 'price' => 4500.0,
                'description' => 'Soupe de riz aux brèdes, réconfortante et économique, idéale les soirs de pluie.',
                'ingredients' => ['1 tasse de riz', '2 bottes de brèdes mafana', '1 oignon', '2 tomates', '200 g de viande de zébu', 'Sel'],
                'steps' => ['Faire revenir l\'oignon et la viande.', 'Ajouter les tomates et le riz, couvrir largement d\'eau.', 'Cuire 25 minutes en remuant.', 'Ajouter les brèdes et cuire 5 minutes.', 'Servir très chaud.'],
            ],
            [
                'slug' => 'ravimbomanga', 'title' => 'Ravimbomanga sy Henakisoa', 'author' => 'mirana', 'special' => false, 'category' => 'plat', 'price' => 9000.0,
                'description' => 'Feuilles de patate douce mijotées avec du porc, un plat simple et très apprécié.',
                'ingredients' => ['400 g de porc', '3 bottes de feuilles de patate douce', '1 oignon', '2 tomates', '3 gousses d\'ail', 'Sel'],
                'steps' => ['Dorer le porc avec l\'oignon et l\'ail.', 'Ajouter les tomates et un verre d\'eau, cuire 30 minutes.', 'Ajouter les feuilles de patate douce lavées.', 'Cuire 15 minutes à feu doux.', 'Servir avec du riz.'],
            ],
            [
                'slug' => 'akoho-sy-voanio', 'title' => 'Akoho sy Voanio', 'author' => 'rasoa', 'special' => false, 'category' => 'plat', 'price' => 11000.0,
                'description' => 'Poulet mijoté dans du lait de coco, un classique de la côte malgache.',
                'ingredients' => ['1 poulet découpé', '400 ml de lait de coco', '1 oignon', '3 gousses d\'ail', '1 morceau de gingembre', '2 tomates', 'Curcuma, sel'],
                'steps' => ['Faire revenir le poulet avec l\'oignon, l\'ail et le gingembre.', 'Ajouter les tomates et le curcuma.', 'Verser le lait de coco et couvrir.', 'Laisser mijoter 40 minutes à feu doux.', 'Servir avec du riz blanc.'],
            ],
            [
                'slug' => 'henakisoa-sy-voanio', 'title' => 'Henakisoa sy Voanio', 'author' => 'voahangy', 'special' => false, 'category' => 'plat', 'price' => 12500.0,
                'description' => 'Porc fondant cuit dans du lait de coco, un régal pour les grandes occasions.',
                'ingredients' => ['500 g de porc', '400 ml de lait de coco', '1 oignon', '3 gousses d\'ail', 'Gingembre', 'Sel et poivre'],
                'steps' => ['Faire dorer le porc dans une cocotte.', 'Ajouter l\'oignon, l\'ail et le gingembre.', 'Verser le lait de coco.', 'Cuire à feu doux 1 heure.', 'Servir avec du riz.'],
            ],
            [
                'slug' => 'koba', 'title' => 'Koba akondro', 'author' => 'soa', 'special' => false, 'category' => 'dessert', 'price' => 1500.0,
                'description' => 'Gâteau traditionnel à la farine de riz, aux cacahuètes et à la banane, cuit à la vapeur dans des feuilles de bananier.',
                'ingredients' => ['250 g de farine de riz', '150 g de cacahuètes pilées', '150 g de sucre', '3 bananes mûres', 'Feuilles de bananier'],
                'steps' => ['Mélanger la farine, les cacahuètes, le sucre et les bananes écrasées.', 'Répartir la pâte dans les feuilles de bananier.', 'Ficeler les paquets.', 'Cuire à la vapeur pendant 1 heure.', 'Laisser refroidir avant de trancher.'],
            ],
            [
                'slug' => 'mofo-gasy', 'title' => 'Mofo gasy', 'author' => 'hanitra', 'special' => false, 'category' => 'encas', 'price' => 500.0,
                'description' => 'Petits pains malgaches au riz, dorés et moelleux, parfaits pour le petit-déjeuner.',
                'ingredients' => ['2 tasses de farine de riz', '1 tasse de sucre', '1 verre de lait de coco', '1 pincée de sel', 'Levure', 'Huile'],
                'steps' => ['Mélanger tous les ingrédients jusqu\'à obtenir une pâte lisse.', 'Laisser reposer 30 minutes.', 'Chauffer le moule avec un peu d\'huile.', 'Verser la pâte et cuire 5 minutes de chaque côté.', 'Servir tiède.'],
            ],
            [
                'slug' => 'sambos', 'title' => 'Sambos', 'author' => 'lalao', 'special' => false, 'category' => 'encas', 'price' => 800.0,
                'description' => 'Petits triangles frits garnis de viande hachée ou de légumes, très prisés à l\'apéritif.',
                'ingredients' => ['Feuilles de brick', '300 g de viande hachée', '1 oignon', '1 gousse d\'ail', 'Persil', 'Huile de friture'],
                'steps' => ['Faire revenir la viande avec l\'oignon, l\'ail et le persil.', 'Garnir les feuilles de brick et les plier en triangle.', 'Coller les bords avec un peu d\'eau.', 'Frire jusqu\'à ce qu\'ils soient dorés.', 'Égoutter et servir chaud.'],
            ],
            [
                'slug' => 'lasopy', 'title' => 'Lasopy', 'author' => 'mirana', 'special' => true, 'category' => 'entree', 'price' => 3500.0,
                'description' => 'Soupe de légumes et de vermicelle, réconfortante et rapide à préparer.',
                'ingredients' => ['2 carottes', '2 pommes de terre', '1 poireau', '100 g de vermicelle', '1 oignon', '1 cube de bouillon'],
                'steps' => ['Couper les légumes en petits dés.', 'Les faire revenir avec l\'oignon.', 'Couvrir d\'eau, ajouter le bouillon et cuire 20 minutes.', 'Ajouter le vermicelle et cuire 5 minutes.', 'Servir bien chaud.'],
            ],
            [
                'slug' => 'ranonapango', 'title' => 'Ranon\'apango', 'author' => 'soa', 'special' => false, 'category' => 'boisson', 'price' => 500.0,
                'description' => 'Boisson traditionnelle obtenue en versant de l\'eau sur le riz grillé au fond de la marmite.',
                'ingredients' => ['Riz grillé au fond de la casserole', '1 litre d\'eau'],
                'steps' => ['Laisser le riz former une croûte dorée au fond de la casserole.', 'Retirer le riz, verser l\'eau sur la croûte.', 'Porter à ébullition 5 minutes.', 'Filtrer et servir chaud ou tiède.'],
            ],
            [
                'slug' => 'tilapia-sy-anamamy', 'title' => 'Tilapia frit aux brèdes', 'author' => 'mirana', 'special' => false, 'category' => 'plat', 'price' => 8000.0,
                'description' => 'Tilapia croustillant servi avec des brèdes sautées à l\'ail.',
                'ingredients' => ['2 tilapias nettoyés', 'Farine', '2 bottes de brèdes', '3 gousses d\'ail', 'Huile', 'Sel, citron'],
                'steps' => ['Assaisonner les poissons avec le sel et le citron.', 'Les fariner légèrement.', 'Les frire jusqu\'à ce qu\'ils soient dorés.', 'Faire sauter les brèdes avec l\'ail.', 'Servir avec du riz.'],
            ],
            [
                'slug' => 'achards', 'title' => 'Achards de légumes', 'author' => 'voahangy', 'special' => false, 'category' => 'accompagnement', 'price' => 2500.0,
                'description' => 'Légumes marinés au curcuma et au gingembre, l\'accompagnement idéal du riz et des viandes grillées.',
                'ingredients' => ['1/2 chou', '3 carottes', '200 g de haricots verts', '1 oignon', 'Curcuma', 'Gingembre', 'Vinaigre', 'Huile'],
                'steps' => ['Couper les légumes en fines lamelles.', 'Les blanchir rapidement.', 'Faire chauffer l\'huile avec le curcuma et le gingembre.', 'Mélanger aux légumes avec le vinaigre.', 'Laisser mariner quelques heures.'],
            ],
        ];
    }
}
