<?php

namespace NMIPayment\Storefront\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class TestController extends StorefrontController
{
  #[Route(path: '/example', name: 'frontend.example.example', methods: ['GET'])]
  public function showExample(): Response
  {
    return $this->renderStorefront('@SwagBasicExample/storefront/page/example.html.twig', [
    ]);
  }
}