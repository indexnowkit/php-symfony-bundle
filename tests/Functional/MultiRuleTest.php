<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\SymfonyBundle\Tests\App\Entity\MultiRuleArticle;

/**
 * An entity with several #[IndexNow] rules: each rule contributes its own URL(s) independently, guarded
 * by its own (and the class-wide) `when`.
 */
final class MultiRuleTest extends BundleTestCase
{
    protected static string $dispatch = 'multirule';

    public function testCreatingAPublishedArticleSubmitsEveryRule(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $em->persist(new MultiRuleArticle('hello'));
        $em->flush();
        $indexNow = static::getContainer()->get('indexnowkit');
        \assert($indexNow instanceof \IndexNowKit\IndexNowKit);
        $indexNow->flush();

        self::assertSame(
            ['https://www.example.com/articles/hello', 'https://www.example.com/articles/hello/amp', 'https://www.example.com/'],
            $this->sentUrls(),
        );
    }

    /**
     * `amp` flips true -> false on an update: ChangeClassifier classifies the amp rule as Event::Deleted
     * (its page just disappeared, engines must recrawl and find it gone) in the very same flush as the
     * Updated main rule and the always-on literal rule; AttributeUrlResolver::resolveRule() resolves a
     * Deleted rule's URL from the state it had while it still applied (it does not re-check `when` against
     * the now-flipped current state), so the AMP URL is still submitted once more, alongside the main URL.
     */
    public function testUnpublishingAmpResubmitsTheAmpUrlAsDeletionAlongsideTheMainUpdate(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $article = new MultiRuleArticle('hello2');
        $em->persist($article);
        $em->flush();
        $indexNow = static::getContainer()->get('indexnowkit');
        \assert($indexNow instanceof \IndexNowKit\IndexNowKit);
        $indexNow->flush();
        $this->transport()->posts = [];

        $article->amp = false;
        $em->flush();
        $indexNow->flush();

        // The amp rule is classified as a deletion and resolved immediately (onFlush); the main and literal
        // rules stay updates and are resolved afterwards (postFlush), hence the amp URL comes first.
        self::assertSame(
            ['https://www.example.com/articles/hello2/amp', 'https://www.example.com/articles/hello2', 'https://www.example.com/'],
            $this->sentUrls(),
        );
    }

    public function testUnpublishingSubmitsNothingForADraftBeingDeleted(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $article = new MultiRuleArticle('draft', published: false, amp: false);
        $em->persist($article);
        $em->flush();
        $indexNow = static::getContainer()->get('indexnowkit');
        \assert($indexNow instanceof \IndexNowKit\IndexNowKit);
        $indexNow->flush();
        self::assertSame([], $this->sentUrls(), 'a draft was never published: nothing to resubmit');

        $em->remove($article);
        $em->flush();
        $indexNow->flush();

        self::assertSame([], $this->sentUrls(), 'deleting a page that was never public submits nothing');
    }
}
