<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Cron;

use ParadoxLabs\Authnetcim\Model\Card;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\Authnetcim\Model\Cron\AccountUpdater;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\Authnetcim\Model\Method;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Helper\Data;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use ParadoxLabs\TokenBase\Model\ResourceModel\Card\Collection;
use ParadoxLabs\TokenBase\Model\ResourceModel\Card\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AccountUpdaterTest extends TestCase
{
    private AccountUpdater $accountUpdater;
    private Data|MockObject $tokenbaseHelperMock;
    private CollectionFactory|MockObject $cardCollectionFactoryMock;
    private CardRepositoryInterface|MockObject $cardRepositoryMock;
    private Factory|MockObject $methodFactoryMock;
    private Method|MockObject $methodMock;
    private Gateway|MockObject $gatewayMock;

    protected function setUp(): void
    {
        $this->tokenbaseHelperMock = $this->createMock(Data::class);
        $this->cardCollectionFactoryMock = $this->createMock(CollectionFactory::class);
        $this->cardRepositoryMock = $this->createMock(CardRepositoryInterface::class);
        $this->methodFactoryMock = $this->createMock(Factory::class);
        $this->methodMock = $this->createMock(Method::class);
        $this->gatewayMock = $this->createMock(Gateway::class);

        $this->accountUpdater = new AccountUpdater(
            $this->tokenbaseHelperMock,
            $this->cardCollectionFactoryMock,
            $this->cardRepositoryMock,
            $this->methodFactoryMock,
        );
    }

    public function testExecuteReturnsWhenDisabled(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->with(ConfigProvider::CODE)
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->with('can_sync_account_updater')
            ->willReturn(0);

        $this->tokenbaseHelperMock->expects($this->never())
            ->method('log');

        $this->accountUpdater->execute();
    }

    public function testExecuteLogsStartAndCompletion(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 0,
                'auDetails' => [],
            ]);

        $logCalls = [];
        $this->tokenbaseHelperMock->method('log')
            ->willReturnCallback(function ($code, $message) use (&$logCalls) {
                $logCalls[] = $message;
            });

        $this->accountUpdater->execute();

        $this->assertCount(2, $logCalls);
        $this->assertStringContainsString('Starting', $logCalls[0]);
        $this->assertStringContainsString('Completed', $logCalls[1]);
    }

    public function testProcessBatchesPaginatesCorrectly(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        // First page returns 1000 (full page), second returns 500 (partial - stop)
        $this->gatewayMock->expects($this->exactly(2))
            ->method('getAccountUpdaterDetails')
            ->willReturnOnConsecutiveCalls(
                [
                    'totalNumInResultSet' => 1000,
                    'auDetails' => [],
                ],
                [
                    'totalNumInResultSet' => 500,
                    'auDetails' => [],
                ]
            );

        $this->accountUpdater->execute();
    }

    public function testProcessBatchesStopsOnLastPage(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        // Return less than 1000 on first page - stops after one call
        $this->gatewayMock->expects($this->once())
            ->method('getAccountUpdaterDetails')
            ->with(1)
            ->willReturn([
                'totalNumInResultSet' => 10,
                'auDetails' => [],
            ]);

        $this->accountUpdater->execute();
    }

    public function testProcessChangeRoutesToUpdate(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $cardMock = $this->createCardMock();
        $collectionMock = $this->createCollectionMock([$cardMock]);
        $this->cardCollectionFactoryMock->method('create')
            ->willReturn($collectionMock);

        $cardMock->method('getAdditional')
            ->willReturnCallback(function ($key = null) {
                return match ($key) {
                    'cc_last4' => '1111',
                    'cc_exp_year' => '2025',
                    'cc_exp_month' => '12',
                    default => null,
                };
            });

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 1,
                'auDetails' => [
                    'auUpdate' => [
                        'customerProfileID' => '12345',
                        'customerPaymentProfileID' => '67890',
                        'newCreditCard' => [
                            'cardNumber' => 'XXXX2222',
                            'expirationDate' => '202612',
                        ],
                    ],
                ],
            ]);

        $this->cardRepositoryMock->expects($this->once())
            ->method('save');

        $this->accountUpdater->execute();
    }

    public function testProcessChangeRoutesToDelete(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $cardMock = $this->createCardMock();
        $collectionMock = $this->createCollectionMock([$cardMock]);
        $this->cardCollectionFactoryMock->method('create')
            ->willReturn($collectionMock);

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 1,
                'auDetails' => [
                    'auDelete' => [
                        'customerProfileID' => '12345',
                        'customerPaymentProfileID' => '67890',
                    ],
                ],
            ]);

        $this->cardRepositoryMock->expects($this->once())
            ->method('delete');

        $this->accountUpdater->execute();
    }

    public function testUpdatePaymentProfileUpdatesLast4(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $cardMock = $this->createCardMock();
        $collectionMock = $this->createCollectionMock([$cardMock]);
        $this->cardCollectionFactoryMock->method('create')
            ->willReturn($collectionMock);

        $cardMock->method('getAdditional')
            ->willReturnCallback(function ($key = null) {
                return match ($key) {
                    'cc_last4' => '1111', // Old value
                    'cc_exp_year' => '2025',
                    'cc_exp_month' => '12',
                    default => null,
                };
            });

        $setAdditionalCalls = [];
        $cardMock->method('setAdditional')
            ->willReturnCallback(function ($key, $value = null) use (&$setAdditionalCalls, $cardMock) {
                $setAdditionalCalls[$key] = $value;

                return $cardMock;
            });

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 1,
                'auDetails' => [
                    'auUpdate' => [
                        'customerProfileID' => '12345',
                        'customerPaymentProfileID' => '67890',
                        'newCreditCard' => [
                            'cardNumber' => 'XXXX3333', // New last4
                            'expirationDate' => 'XXXX',
                        ],
                    ],
                ],
            ]);

        $this->accountUpdater->execute();

        $this->assertArrayHasKey('cc_last4', $setAdditionalCalls);
        $this->assertSame('3333', $setAdditionalCalls['cc_last4']);
    }

    public function testUpdatePaymentProfileUpdatesExpiration(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $cardMock = $this->createCardMock();
        $collectionMock = $this->createCollectionMock([$cardMock]);
        $this->cardCollectionFactoryMock->method('create')
            ->willReturn($collectionMock);

        $cardMock->method('getAdditional')
            ->willReturnCallback(function ($key = null) {
                return match ($key) {
                    'cc_last4' => '1111',
                    'cc_exp_year' => '2025',
                    'cc_exp_month' => '12',
                    default => null,
                };
            });

        $setAdditionalCalls = [];
        $cardMock->method('setAdditional')
            ->willReturnCallback(function ($key, $value = null) use (&$setAdditionalCalls, $cardMock) {
                $setAdditionalCalls[$key] = $value;

                return $cardMock;
            });

        $expiresSet = null;
        $cardMock->method('setExpires')
            ->willReturnCallback(function ($value) use (&$expiresSet, $cardMock) {
                $expiresSet = $value;

                return $cardMock;
            });

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 1,
                'auDetails' => [
                    'auUpdate' => [
                        'customerProfileID' => '12345',
                        'customerPaymentProfileID' => '67890',
                        'newCreditCard' => [
                            'cardNumber' => 'XXXX1111',
                            'expirationDate' => '202806', // New expiration
                        ],
                    ],
                ],
            ]);

        $this->accountUpdater->execute();

        $this->assertArrayHasKey('cc_exp_year', $setAdditionalCalls);
        $this->assertArrayHasKey('cc_exp_month', $setAdditionalCalls);
        $this->assertSame('2028', $setAdditionalCalls['cc_exp_year']);
        $this->assertSame('06', $setAdditionalCalls['cc_exp_month']);
        $this->assertStringContainsString('2028-06', $expiresSet);
    }

    public function testUpdatePaymentProfileSkipsXxxxExpiration(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $cardMock = $this->createCardMock();
        $collectionMock = $this->createCollectionMock([$cardMock]);
        $this->cardCollectionFactoryMock->method('create')
            ->willReturn($collectionMock);

        $cardMock->method('getAdditional')
            ->willReturnCallback(function ($key = null) {
                return match ($key) {
                    'cc_last4' => '1111',
                    'cc_exp_year' => '2025',
                    'cc_exp_month' => '12',
                    default => null,
                };
            });

        $cardMock->expects($this->never())
            ->method('setExpires');

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 1,
                'auDetails' => [
                    'auUpdate' => [
                        'customerProfileID' => '12345',
                        'customerPaymentProfileID' => '67890',
                        'newCreditCard' => [
                            'cardNumber' => 'XXXX2222',
                            'expirationDate' => 'XXXX', // Should be skipped
                        ],
                    ],
                ],
            ]);

        $this->accountUpdater->execute();
    }

    public function testDeletePaymentProfileSetsInactive(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $cardMock = $this->createCardMock();
        $collectionMock = $this->createCollectionMock([$cardMock]);
        $this->cardCollectionFactoryMock->method('create')
            ->willReturn($collectionMock);

        $cardMock->expects($this->once())
            ->method('setActive')
            ->with(0);

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 1,
                'auDetails' => [
                    'auDelete' => [
                        'customerProfileID' => '12345',
                        'customerPaymentProfileID' => '67890',
                    ],
                ],
            ]);

        $this->accountUpdater->execute();
    }

    public function testDeletePaymentProfileClearsPaymentId(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $cardMock = $this->createCardMock();
        $collectionMock = $this->createCollectionMock([$cardMock]);
        $this->cardCollectionFactoryMock->method('create')
            ->willReturn($collectionMock);

        $cardMock->expects($this->once())
            ->method('setPaymentId')
            ->with('');

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 1,
                'auDetails' => [
                    'auDelete' => [
                        'customerProfileID' => '12345',
                        'customerPaymentProfileID' => '67890',
                    ],
                ],
            ]);

        $this->accountUpdater->execute();
    }

    public function testLoadCardsFiltersCorrectly(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('getConfigData')
            ->willReturn(1);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $collectionMock = $this->createMock(Collection::class);

        $filterCalls = [];
        $collectionMock->method('addFieldToFilter')
            ->willReturnCallback(function ($field, $value) use (&$filterCalls, $collectionMock) {
                $filterCalls[$field] = $value;

                return $collectionMock;
            });

        $collectionMock->method('count')
            ->willReturn(0);

        $collectionMock->method('getIterator')
            ->willReturn(new \ArrayIterator([]));

        $this->cardCollectionFactoryMock->method('create')
            ->willReturn($collectionMock);

        $this->gatewayMock->method('getAccountUpdaterDetails')
            ->willReturn([
                'totalNumInResultSet' => 1,
                'auDetails' => [
                    'auUpdate' => [
                        'customerProfileID' => '111',
                        'customerPaymentProfileID' => '222',
                        'newCreditCard' => [
                            'cardNumber' => 'XXXX1234',
                            'expirationDate' => 'XXXX',
                        ],
                    ],
                ],
            ]);

        $this->accountUpdater->execute();

        $this->assertArrayHasKey('method', $filterCalls);
        $this->assertArrayHasKey('profile_id', $filterCalls);
        $this->assertArrayHasKey('payment_id', $filterCalls);
        $this->assertSame(ConfigProvider::CODE, $filterCalls['method']);
        $this->assertSame('111', $filterCalls['profile_id']);
        $this->assertSame('222', $filterCalls['payment_id']);
    }

    private function createCardMock(): Card|MockObject
    {
        $cardMock = $this->getMockBuilder(Card::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAdditional', 'setAdditional', 'setExpires', 'setPaymentId', 'setActive'])
            ->getMock();

        $cardMock->method('setAdditional')
            ->willReturnSelf();
        $cardMock->method('setExpires')
            ->willReturnSelf();
        $cardMock->method('setPaymentId')
            ->willReturnSelf();
        $cardMock->method('setActive')
            ->willReturnSelf();

        return $cardMock;
    }

    private function createCollectionMock(array $cards): Collection|MockObject
    {
        $collectionMock = $this->createMock(Collection::class);
        $collectionMock->method('addFieldToFilter')
            ->willReturnSelf();
        $collectionMock->method('count')
            ->willReturn(count($cards));
        $collectionMock->method('getIterator')
            ->willReturn(new \ArrayIterator($cards));

        return $collectionMock;
    }
}
