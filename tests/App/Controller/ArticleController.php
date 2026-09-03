<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IndexNowKit\SymfonyBundle\Tests\App\Entity\Article;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ArticleController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function show(string $slug): Response
    {
        return new Response($slug);
    }

    public function create(Request $request): Response
    {
        $this->em->persist(new Article((string) $request->query->get('slug', 'hello')));
        $this->em->flush();

        return new Response('created', 201);
    }

    public function delete(string $slug): Response
    {
        $article = $this->em->getRepository(Article::class)->findOneBy(['slug' => $slug]);
        if ($article !== null) {
            $this->em->remove($article);
            $this->em->flush();
        }

        return new Response('deleted');
    }

    public function createAndFail(Request $request): Response
    {
        $this->em->wrapInTransaction(function () use ($request): void {
            $this->em->persist(new Article((string) $request->query->get('slug', 'failed')));
            $this->em->flush();
            throw new RuntimeException('business rule violated');
        });

        // @phpstan-ignore-next-line deadCode.unreachable (wrapInTransaction() always rethrows above; kept for readability)
        return new Response('unreachable');
    }
}
