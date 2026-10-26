<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Validations;

use NMIPayment\Service\VaultedCustomerService;
use NMIPayment\Validations\VaultOwnershipGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(VaultOwnershipGuard::class)]
class VaultOwnershipGuardTest extends TestCase
{
    public function testOwnVaultPasses(): void
    {
        $service = $this->createMock(VaultedCustomerService::class);
        $service->method('customerOwnsVault')->with(self::anything(), 'customer-1', 'vault-1')->willReturn(true);

        self::assertNull((new VaultOwnershipGuard($service, $this->createMock(LoggerInterface::class)))
            ->assertVaultOwnership('vault-1', $this->context('customer-1')));
    }

    public function testForeignVaultIsForbidden(): void
    {
        $service = $this->createMock(VaultedCustomerService::class);
        $service->method('customerOwnsVault')->willReturn(false);

        $response = (new VaultOwnershipGuard($service, $this->createMock(LoggerInterface::class)))
            ->assertVaultOwnership('vault-of-someone-else', $this->context('customer-1'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response?->getStatusCode());
    }

    public function testNamedCardMustBeInTheCustomersVault(): void
    {
        $service = $this->createMock(VaultedCustomerService::class);
        $service->expects(self::never())->method('customerOwnsVault');
        $service->method('customerOwnsCard')->with(self::anything(), 'customer-1', '123', 'billing-x')->willReturn(false);

        $response = (new VaultOwnershipGuard($service, $this->createMock(LoggerInterface::class)))
            ->assertVaultOwnership(123, $this->context('customer-1'), 'billing-x');

        self::assertSame(Response::HTTP_FORBIDDEN, $response?->getStatusCode());
    }

    public function testMissingOrNonScalarVaultIdIsABadRequest(): void
    {
        $guard = new VaultOwnershipGuard($this->createMock(VaultedCustomerService::class), $this->createMock(LoggerInterface::class));

        self::assertSame(Response::HTTP_BAD_REQUEST, $guard->assertVaultOwnership('', $this->context('customer-1'))?->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $guard->assertVaultOwnership(['x'], $this->context('customer-1'))?->getStatusCode());
    }

    private function context(string $customerId): SalesChannelContext
    {
        $customer = new CustomerEntity();
        $customer->setId($customerId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
