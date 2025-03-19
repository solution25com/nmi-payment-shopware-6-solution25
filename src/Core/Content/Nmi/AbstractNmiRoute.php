<?php

namespace NMIPayment\Core\Content\Nmi;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractNmiRoute
{
    abstract public function creditCardPayment(Request $request, SalesChannelContext $context): JsonResponse;
    //  abstract public function achEcheckPayment(Request $request, SalesChannelContext $context): JsonResponse;
    //  abstract public function vaultedCustomerPayment(Request $request, SalesChannelContext $context): JsonResponse;
    //  abstract public function getVaultedCustomer(Request $request, SalesChannelContext $context): JsonResponse;
    //  abstract public function deleteVaultedCustomer(Request $request, SalesChannelContext $context): JsonResponse;
}
