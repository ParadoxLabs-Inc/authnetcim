<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\UrlInterface;
use Magento\Payment\Model\CcConfig;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Payment\Model\MethodInterface;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    private ConfigProvider $configProvider;
    private CcConfig|MockObject $ccConfigMock;
    private \Magento\Payment\Helper\Data|MockObject $paymentHelperMock;
    private \Magento\Checkout\Model\Session|MockObject $checkoutSessionMock;
    private CustomerSession|MockObject $customerSessionMock;
    private PaymentConfig|MockObject $paymentConfigMock;
    private Data|MockObject $dataHelperMock;
    private UrlInterface|MockObject $urlBuilderMock;
    private MethodInterface|MockObject $methodMock;

    protected function setUp(): void
    {
        $this->ccConfigMock = $this->createMock(CcConfig::class);
        $this->paymentHelperMock = $this->createMock(\Magento\Payment\Helper\Data::class);
        $this->checkoutSessionMock = $this->createMock(\Magento\Checkout\Model\Session::class);
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

    public function testCanSaveCardReturnsTrueWhenLoggedIn(): void
    {
        $this->customerSessionMock->method('isLoggedIn')
            ->willReturn(true);

        $result = $this->configProvider->canSaveCard();

        $this->assertTrue($result);
    }

    public function testCanSaveCardReturnsFalseForGuest(): void
    {
        $this->customerSessionMock->method('isLoggedIn')
            ->willReturn(false);

        $result = $this->configProvider->canSaveCard();

        $this->assertFalse($result);
    }

    public function testForceSaveCardInvertsAllowUnsaved(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'allow_unsaved') {
                    return 1;
                }

                return null;
            });

        $result = $this->configProvider->forceSaveCard();

        $this->assertFalse($result);
    }

    public function testForceSaveCardReturnsTrueWhenAllowUnsavedDisabled(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'allow_unsaved') {
                    return 0;
                }

                return null;
            });

        $result = $this->configProvider->forceSaveCard();

        $this->assertTrue($result);
    }

    public function testRequireCcvReturnsConfigValue(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'require_ccv') {
                    return 1;
                }

                return null;
            });

        $result = $this->configProvider->requireCcv();

        $this->assertTrue($result);
    }

    public function testGetLogoImageReturnsUrlWhenEnabled(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'show_branding') {
                    return 1;
                }

                return null;
            });

        $this->ccConfigMock->method('getViewFileUrl')
            ->with('ParadoxLabs_Authnetcim::images/logo.png')
            ->willReturn('https://example.com/logo.png');

        $result = $this->configProvider->getLogoImage();

        $this->assertSame('https://example.com/logo.png', $result);
    }

    public function testGetLogoImageReturnsFalseWhenDisabled(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'show_branding') {
                    return 0;
                }

                return null;
            });

        $result = $this->configProvider->getLogoImage();

        $this->assertFalse($result);
    }

    public function testGetClientKeyReturnsKeyForAcceptJs(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_ACCEPTJS;
                }

                if ($key === 'client_key') {
                    return 'test_client_key';
                }

                return null;
            });

        $result = $this->configProvider->getClientKey();

        $this->assertSame('test_client_key', $result);
    }

    public function testGetClientKeyReturnsEmptyForOtherTypes(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                return null;
            });

        $result = $this->configProvider->getClientKey();

        $this->assertSame('', $result);
    }

    public function testGetParamUrlReturnsHostedUrl(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'payment_action') {
                    return 'authorize';
                }

                return null;
            });

        $this->urlBuilderMock->method('getUrl')
            ->with('authnetcim/hosted/getPaymentParams')
            ->willReturn('https://example.com/authnetcim/hosted/getPaymentParams');

        $result = $this->configProvider->getParamUrl();

        $this->assertSame('https://example.com/authnetcim/hosted/getPaymentParams', $result);
    }

    public function testGetParamUrlReturnsProfileUrlForOrder(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'payment_action') {
                    return 'order';
                }

                return null;
            });

        $this->urlBuilderMock->method('getUrl')
            ->with('authnetcim/hosted/getProfileParams', ['source' => 'checkout'])
            ->willReturn('https://example.com/authnetcim/hosted/getProfileParams');

        $result = $this->configProvider->getParamUrl();

        $this->assertSame('https://example.com/authnetcim/hosted/getProfileParams', $result);
    }

    public function testGetParamUrlReturnsNullForOtherTypes(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_INLINE;
                }

                return null;
            });

        $result = $this->configProvider->getParamUrl();

        $this->assertSame('', $result);
    }

    public function testGetNewCardUrlReturnsHostedUrl(): void
    {
        $this->methodMock->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                return null;
            });

        $this->urlBuilderMock->method('getUrl')
            ->with('authnetcim/hosted/getNewCard')
            ->willReturn('https://example.com/authnetcim/hosted/getNewCard');

        $result = $this->configProvider->getNewCardUrl();

        $this->assertSame('https://example.com/authnetcim/hosted/getNewCard', $result);
    }

    public function testGetCodeReturnsAuthnetcim(): void
    {
        $result = $this->configProvider->getCode();

        $this->assertSame('authnetcim', $result);
    }
}
