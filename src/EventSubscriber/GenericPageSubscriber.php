<?php

namespace NMIPayment\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Page\MetaInformation;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Shopware\Storefront\Page\Search\SearchPageLoadedEvent;

class GenericPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
          GenericPageLoadedEvent::class => ['setNoindexNofollow', 1000],
          SearchPageLoadedEvent::class  => ['setNoindexNofollowSearchPage', 1000],
        ];
    }

    public function setNoindexNofollow(GenericPageLoadedEvent $event): void
    {
        // Apply noindex, nofollow directly
        $page = $event->getPage();
        $page->setMetaInformation((new MetaInformation())->assign([
          'robots' => 'noindex,nofollow',
        ]));
    }

    public function setNoindexNofollowSearchPage(SearchPageLoadedEvent $event): void
    {
        // Apply noindex, nofollow directly for search page
        $page = $event->getPage();
        $page->setMetaInformation((new MetaInformation())->assign([
          'robots' => 'noindex,nofollow',
        ]));
    }
}