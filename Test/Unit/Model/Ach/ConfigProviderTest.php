<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Ach;

use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\UrlInterface;
use Magento\Payment\Model\CcConfig;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Payment\Model\MethodInterface;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    private ConfigProvider $configProvider;
    private CcConfig|MockObject $ccConfigMock;
    private \Magento\Payment\Helper\Data|MockObject $paymentHelperMock;
    private Session|MockObject $checkoutSessionMock;
    private CustomerSession|MockObject $customerSessionMock;
    private PaymentConfig|MockObject $paymentConfigMock;
    private Data|MockObject $dataHelperMock;
    private UrlInterface|MockObject $urlBuilderMock;
    private MethodInterface|MockObject $methodMock;

    protected function setUp(): void
    {
        $this->ccConfigMock = $this->createMock(CcConfig::class);
        $this->paymentHelperMock = $this->createMock(\Magento\Payment\Helper\Data::class);
        $this->checkoutSessionMock = $this->createMock(Session::class);
        $this->customerSessionMock = $this->createMock(CustomerSession::class);
        $this->paymentConfigMock = $this->createMock(PaymentConfig::class);
        $this->dataHelperMock = $this->createMock(Data::class);
        $this->urlBuilderMock = $this->createMock(UrlInterface::class);

        $this->methodMock = $this->createMock(MethodInterface::class);

        $this->paymentHelperMock->method('getMethodInstance')
            ->with(ConfigProvider::CODE)
            ->willReturn($this->methodMock);

        $this->configProvider = new ConfigProvider(
            $this->ccConfigMock,
            $this->paymentHelperMock,
            $this->checkoutSessionMock,
            $this->customerSessionMock,
            $this->paymentConfigMock,
            $this->dataHelperMock,
            $this->urlBuilderMock,
        );
    }

    public function testProviderUsesTheAchMethodCode(): void
    {
        $this->assertSame('authnetcim_ach', $this->configProvider->getCode());
    }

    public function testDefaultSaveCardHonorsOptOutWhenSaveIsOptional(): void
    {
        $this->mockConfigData([
            'allow_unsaved' => 1,
            'savecard_opt_out' => 0,
        ]);

        $this->assertFalse($this->configProvider->defaultSaveCard());
    }

    public function testDefaultSaveCardReturnsTrueWhenSaveIsForced(): void
    {
        // The ACH provider inherits defaultSaveCard()/forceSaveCard() from the CC provider, but
        // resolves them against its own method code, so the forced-save default applies here too.
        $this->mockConfigData([
            'allow_unsaved' => 0,
            'savecard_opt_out' => 0,
        ]);

        $this->assertTrue($this->configProvider->defaultSaveCard());
    }

    public function testGetConfigDefaultsSaveCardOnWhenSaveIsForced(): void
    {
        $this->methodMock->method('isAvailable')
            ->willReturn(true);
        $this->mockConfigData([
            'allow_unsaved' => 0,
            'savecard_opt_out' => 0,
        ]);

        $this->customerSessionMock->method('isLoggedIn')
            ->willReturn(true);
        $this->dataHelperMock->method('getActiveCustomerCardsByMethod')
            ->willReturn([]);
        $this->dataHelperMock->method('getAchAccountTypes')
            ->willReturn([]);

        $result = $this->configProvider->getConfig();

        $this->assertTrue($result['payment'][ConfigProvider::CODE]['forceSaveCard']);
        $this->assertTrue($result['payment'][ConfigProvider::CODE]['defaultSaveCard']);
    }

    /**
     * Stub method config values by key.
     *
     * @param array $values
     * @return void
     */
    private function mockConfigData(array $values): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(
                static function ($key) use ($values) {
                    return $values[$key] ?? null;
                }
            );
    }
}
