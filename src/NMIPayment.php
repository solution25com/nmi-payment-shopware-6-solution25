<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace NMIPayment;

use Doctrine\DBAL\Connection;
use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\PaymentMethods\PaymentMethodInterface;
use NMIPayment\PaymentMethods\PaymentMethods;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class NMIPayment extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->runInstallers($installContext->getContext());

        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->addPaymentMethod($this->createPaymentMethodInstance($paymentMethod), $installContext->getContext());
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $uninstallContext->getContext(), $this->createPaymentMethodInstance($paymentMethod));
        }

        if (!$uninstallContext->keepUserData()) {
            $connection = $this->container->get(Connection::class);
            $connection->executeStatement('DROP TABLE IF EXISTS nmi_transaction');
            $connection->executeStatement('DROP TABLE IF EXISTS nmi_vaulted_customer');
            $connection->executeStatement('DROP TABLE IF EXISTS nmi_card_velocity');

            $connection->executeStatement(
                'DELETE FROM `migration` WHERE `class` LIKE :migrationPattern;',
                [
                    'migrationPattern' => 'NMIPayment\Migration\%',
                ]
            );
        }

        parent::uninstall($uninstallContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $instance = $this->createPaymentMethodInstance($paymentMethod);
            $this->addPaymentMethod($instance, $activateContext->getContext());
            $this->setPaymentMethodIsActive(true, $activateContext->getContext(), $instance);
        }
        parent::activate($activateContext);
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->runInstallers($updateContext->getContext());
        $this->migrateNet30HandlerIdentifier($updateContext->getContext());

        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->addPaymentMethod($this->createPaymentMethodInstance($paymentMethod), $updateContext->getContext());
        }
        parent::update($updateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $deactivateContext->getContext(), $this->createPaymentMethodInstance($paymentMethod));
        }
        parent::deactivate($deactivateContext);
    }

    public function getDependency($name): mixed
    {
        return $this->container->get($name);
    }

    private function runInstallers(Context $context): void
    {
        $installer = new OrderStateInstaller(
            $this->getDependency('state_machine.repository'),
            $this->getDependency('state_machine_state.repository'),
            $this->getDependency('state_machine_transition.repository')
        );
        $installer->install($context);
    }

    private function migrateNet30HandlerIdentifier(Context $context): void
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $criteria = (new Criteria())->addFilter(new EqualsFilter(
            'handlerIdentifier',
            'NMIPayment\\Payment\\NetThirtyPaymentHandler'
        ));
        $ids = $paymentRepository->searchIds($criteria, $context);

        if ($ids->getTotal() === 0) {
            return;
        }

        $updates = array_map(
            fn (string $id) => [
                'id' => $id,
                'handlerIdentifier' => \NMIPayment\Gateways\Net30::class,
            ],
            $ids->getIds()
        );

        $paymentRepository->update($updates, $context);
    }

    private function createPaymentMethodInstance(string $paymentMethodClass): PaymentMethodInterface
    {
        if (!is_subclass_of($paymentMethodClass, PaymentMethodInterface::class)) {
            throw new \InvalidArgumentException(
                sprintf('Class %s must implement %s', $paymentMethodClass, PaymentMethodInterface::class)
            );
        }

        return new $paymentMethodClass();
    }

    private function addPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler(), $context);

        $pluginIdProvider = $this->getDependency(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        if ($paymentMethodId) {
            $this->setPluginId($paymentMethodId, $pluginId, $context);

            return;
        }

        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        $paymentData = [
            'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
            'name' => $paymentMethod->getName(),
            'description' => $paymentMethod->getDescription(),
            'technicalName' => $paymentMethod->getTechnicalName(),
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true,
        ];

        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentRepository->create([$paymentData], $context);
    }

    private function setPluginId(string $paymentMethodId, string $pluginId, Context $context): void
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentMethodData = [
            'id' => $paymentMethodId,
            'pluginId' => $pluginId,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function setPaymentMethodIsActive(bool $active, Context $context, PaymentMethodInterface $paymentMethod): void
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler(), $context);

        if (!$paymentMethodId) {
            return;
        }

        $paymentMethodData = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function getPaymentMethodId(string $paymentMethodHandler, Context $context): ?string
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter(
            'handlerIdentifier',
            $paymentMethodHandler
        ));
        $paymentIds = $paymentRepository->searchIds($paymentCriteria, $context);

        if ($paymentIds->getTotal() === 0) {
            return null;
        }

        return $paymentIds->getIds()[0];
    }
}
