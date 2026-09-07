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
        // the business rule fails after the flush, so wrapInTransaction() rolls back and rethrows; the condition is
        // always true at run time and undecidable for phpstan, so no vendor set (ORM 2 `@return mixed`, ORM 3 `@return T`)
        // gets a dead line to report
        $response = $this->em->wrapInTransaction(function () use ($request): Response {
            $article = new Article((string) $request->query->get('slug', 'failed'));
            $this->em->persist($article);
            $this->em->flush();
            if ($this->em->contains($article)) {
                throw new RuntimeException('business rule violated');
            }

            return new Response('unreachable');
        });
        \assert($response instanceof Response);

        return $response;
    }
}
