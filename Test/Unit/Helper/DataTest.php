<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Helper;

use Magento\Backend\Model\Session\Quote as BackendQuote;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\State;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutFactory;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Payment\Model\Method\Factory as PaymentMethodFactory;
use Magento\Quote\Model\Quote\PaymentFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\WebsiteFactory;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\TokenBase\Helper\Address as AddressHelper;
use ParadoxLabs\TokenBase\Helper\Operation as OperationHelper;
use ParadoxLabs\TokenBase\Model\CardFactory;
use ParadoxLabs\TokenBase\Model\ResourceModel\Card\CollectionFactory as CardCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DataTest extends TestCase
{
    private Data $helper;
    private Context|MockObject $contextMock;
    private LayoutFactory|MockObject $layoutFactoryMock;
    private PaymentMethodFactory|MockObject $paymentMethodFactoryMock;
    private Emulation|MockObject $appEmulationMock;
    private PaymentConfig|MockObject $paymentConfigMock;
    private Initial|MockObject $initialConfigMock;
    private State|MockObject $appStateMock;
    private StoreManagerInterface|MockObject $storeManagerMock;
    private Registry|MockObject $registryMock;
    private WebsiteFactory|MockObject $websiteFactoryMock;
    private CustomerInterfaceFactory|MockObject $customerFactoryMock;
    private CustomerRepositoryInterface|MockObject $customerRepositoryMock;
    private PaymentFactory|MockObject $paymentFactoryMock;
    private BackendQuote|MockObject $backendSessionMock;
    private CheckoutSession|MockObject $checkoutSessionMock;
    private CustomerSession|MockObject $customerSessionMock;
    private CurrentCustomer|MockObject $currentCustomerSessionMock;
    private CardFactory|MockObject $cardFactoryMock;
    private CardCollectionFactory|MockObject $cardCollectionFactoryMock;
    private AddressHelper|MockObject $addressHelperMock;
    private OperationHelper|MockObject $operationHelperMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(Context::class);
        $this->layoutFactoryMock = $this->createMock(LayoutFactory::class);
        $this->paymentMethodFactoryMock = $this->createMock(PaymentMethodFactory::class);
        $this->appEmulationMock = $this->createMock(Emulation::class);
        $this->paymentConfigMock = $this->createMock(PaymentConfig::class);
        $this->initialConfigMock = $this->createMock(Initial::class);
        $this->appStateMock = $this->createMock(State::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->registryMock = $this->createMock(Registry::class);
        $this->websiteFactoryMock = $this->createMock(WebsiteFactory::class);
        $this->customerFactoryMock = $this->createMock(CustomerInterfaceFactory::class);
        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->paymentFactoryMock = $this->createMock(PaymentFactory::class);
        $this->backendSessionMock = $this->createMock(BackendQuote::class);
        $this->checkoutSessionMock = $this->createMock(CheckoutSession::class);
        $this->customerSessionMock = $this->createMock(CustomerSession::class);
        $this->currentCustomerSessionMock = $this->createMock(CurrentCustomer::class);
        $this->cardFactoryMock = $this->createMock(CardFactory::class);
        $this->cardCollectionFactoryMock = $this->createMock(CardCollectionFactory::class);
        $this->addressHelperMock = $this->createMock(AddressHelper::class);
        $this->operationHelperMock = $this->createMock(OperationHelper::class);

        $this->helper = new Data(
            $this->contextMock,
            $this->layoutFactoryMock,
            $this->paymentMethodFactoryMock,
            $this->appEmulationMock,
            $this->paymentConfigMock,
            $this->initialConfigMock,
            $this->appStateMock,
            $this->storeManagerMock,
            $this->registryMock,
            $this->websiteFactoryMock,
            $this->customerFactoryMock,
            $this->customerRepositoryMock,
            $this->paymentFactoryMock,
            $this->backendSessionMock,
            $this->checkoutSessionMock,
            $this->customerSessionMock,
            $this->currentCustomerSessionMock,
            $this->cardFactoryMock,
            $this->cardCollectionFactoryMock,
            $this->addressHelperMock,
            $this->operationHelperMock,
        );
    }

    public function testMapCcTypeToMagentoReturnsVisa(): void
    {
        $this->assertSame('VI', $this->helper->mapCcTypeToMagento('Visa'));
    }

    public function testMapCcTypeToMagentoReturnsMasterCard(): void
    {
        $this->assertSame('MC', $this->helper->mapCcTypeToMagento('MasterCard'));
    }

    public function testMapCcTypeToMagentoReturnsAmex(): void
    {
        $this->assertSame('AE', $this->helper->mapCcTypeToMagento('American Express'));
    }

    public function testMapCcTypeToMagentoReturnsAmexAlternate(): void
    {
        $this->assertSame('AE', $this->helper->mapCcTypeToMagento('AmericanExpress'));
    }

    public function testMapCcTypeToMagentoReturnsDiscover(): void
    {
        $this->assertSame('DI', $this->helper->mapCcTypeToMagento('Discover'));
    }

    public function testMapCcTypeToMagentoReturnsJcb(): void
    {
        $this->assertSame('JCB', $this->helper->mapCcTypeToMagento('JCB'));
    }

    public function testMapCcTypeToMagentoReturnsDinersClub(): void
    {
        $this->assertSame('DC', $this->helper->mapCcTypeToMagento('Diners Club'));
    }

    public function testMapCcTypeToMagentoReturnsUnionPay(): void
    {
        $this->assertSame('UN', $this->helper->mapCcTypeToMagento('UnionPay'));
    }

    public function testMapCcTypeToMagentoReturnsNullForUnknown(): void
    {
        $this->assertNull($this->helper->mapCcTypeToMagento('Unknown Card Type'));
    }

    public function testMapCcTypeToMagentoReturnsNullForEmpty(): void
    {
        $this->assertNull($this->helper->mapCcTypeToMagento(''));
    }

    /**
     * @dataProvider avsResponseProvider
     */
    public function testTranslateAvsReturnsTranslation(string $code, string $expectedContains): void
    {
        $result = $this->helper->translateAvs($code);

        $this->assertStringContainsString($expectedContains, (string)$result);
    }

    public static function avsResponseProvider(): array
    {
        return [
            'B - No address' => ['B', 'No address submitted'],
            'E - Invalid' => ['E', 'AVS data invalid'],
            'R - Unavailable' => ['R', 'AVS unavailable'],
            'G - Not supported' => ['G', 'AVS not supported'],
            'U - Unavailable alt' => ['U', 'AVS unavailable'],
            'S - Not supported alt' => ['S', 'AVS not supported'],
            'N - No match' => ['N', 'Street and zipcode do not match'],
            'A - Street match' => ['A', 'Street matches; zipcode does not'],
            'Z - 5-digit zip match' => ['Z', '5-digit zip matches'],
            'W - 9-digit zip match' => ['W', '9-digit zip matches'],
            'Y - Perfect match' => ['Y', 'Perfect match'],
            'X - Perfect match alt' => ['X', 'Perfect match'],
            'P - N/A' => ['P', 'N/A'],
        ];
    }

    public function testTranslateAvsReturnsCodeForUnknown(): void
    {
        $result = $this->helper->translateAvs('Q');

        $this->assertSame('Q', $result);
    }

    /**
     * @dataProvider ccvResponseProvider
     */
    public function testTranslateCcvReturnsTranslation(string $code, string $expectedContains): void
    {
        $result = $this->helper->translateCcv($code);

        $this->assertStringContainsString($expectedContains, (string)$result);
    }

    public static function ccvResponseProvider(): array
    {
        return [
            'M - Passed' => ['M', 'Passed'],
            'N - Failed' => ['N', 'Failed'],
            'P - Not processed' => ['P', 'Not processed'],
            'S - Not received' => ['S', 'Not received'],
            'U - N/A' => ['U', 'N/A'],
        ];
    }

    public function testTranslateCcvReturnsCodeForUnknown(): void
    {
        $result = $this->helper->translateCcv('X');

        $this->assertSame('X', $result);
    }

    /**
     * @dataProvider cavvResponseProvider
     */
    public function testTranslateCavvReturnsTranslation(string $code, string $expectedContains): void
    {
        $result = $this->helper->translateCavv($code);

        $this->assertStringContainsString($expectedContains, (string)$result);
    }

    public static function cavvResponseProvider(): array
    {
        return [
            '0 - Bad data' => ['0', 'Not validated'],
            '1 - Failed' => ['1', 'Failed'],
            '2 - Passed' => ['2', 'Passed'],
            '3 - Unavailable' => ['3', 'CAVV unavailable'],
            '4 - Unavailable alt' => ['4', 'CAVV unavailable'],
            '7 - Failed alt' => ['7', 'Failed'],
            '8 - Passed alt' => ['8', 'Passed'],
            '9 - Failed issuer' => ['9', 'Failed (issuer unavailable)'],
            'A - Passed issuer' => ['A', 'Passed (issuer unavailable)'],
            'B - Passed info' => ['B', 'Passed (info only)'],
        ];
    }

    public function testTranslateCavvReturnsCodeForUnknown(): void
    {
        $result = $this->helper->translateCavv('Z');

        $this->assertSame('Z', $result);
    }
}
