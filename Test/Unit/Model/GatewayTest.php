<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model;

use Magento\Framework\HTTP\ClientInterfaceFactory;
use Magento\Framework\Module\Dir;
use Magento\Framework\Registry;
use Magento\Payment\Gateway\Command\CommandException;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use ParadoxLabs\TokenBase\Model\Gateway\ResponseFactory;
use ParadoxLabs\TokenBase\Model\Gateway\Xml;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Void-path error handling.
 *
 * TokenBase's void-error-surfacing change (tokenization-base issue #5, PR #7) stops AbstractMethod::void()
 * from swallowing every gateway failure: anything thrown out of the gateway becomes a PaymentException
 * unless the gateway classifies it as benign via isExpectedVoidFailure(). These tests pin the fact that
 * Authnetcim's one benign void outcome -- the referenced authorization is expired/unknown, Auth.Net error
 * code 16 "The transaction cannot be found." -- never reaches that classifier, because the gateway already
 * treats it as a successful no-op instead of throwing. Everything else does throw, and should.
 */
class GatewayTest extends TestCase
{
    private Gateway $gateway;
    private Data|MockObject $helperMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->helperMock->method('getArrayValue')
            ->willReturnCallback(
                static function ($data, $path, $default = '') {
                    foreach (explode('/', (string)$path) as $key) {
                        if (!isset($data[$key])) {
                            return $default;
                        }

                        $data = $data[$key];
                    }

                    return $data;
                }
            );

        $responseFactoryMock = $this->createMock(ResponseFactory::class);
        $responseFactoryMock->method('create')
            ->willReturnCallback(
                static function () {
                    return new Response();
                }
            );

        $this->gateway = new Gateway(
            $this->helperMock,
            $this->createMock(Xml::class),
            $responseFactoryMock,
            $this->createMock(ClientInterfaceFactory::class),
            $this->createMock(Dir::class),
            $this->createMock(Registry::class)
        );

        $this->gateway->setParameter('transactionType', 'voidTransaction');
    }

    /**
     * A void of an expired/unknown authorization must not throw: there is nothing left to reverse.
     *
     * @return void
     */
    public function testMissingTransactionIsNotAnApiError(): void
    {
        $this->setLastResponse($this->buildErrorResult(16, 'The transaction cannot be found.'));

        $this->invokeHandleTransactionError();

        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testMissingTransactionYieldsASuccessfulResponse(): void
    {
        $response = $this->invokeInterpretTransaction(
            $this->buildErrorResult(16, 'The transaction cannot be found.')
        );

        $this->assertFalse($response->getIsError());
        $this->assertSame(16, (int)$response->getResponseReasonCode());
        $this->assertSame('void', $response->getData('transaction_type'));
    }

    /**
     * Any other gateway rejection must surface, so the auth is not closed as if it had been reversed.
     *
     * @return void
     */
    public function testOtherApiErrorsThrow(): void
    {
        $this->setLastResponse($this->buildErrorResult(310, 'This transaction has already been voided.'));

        $this->expectException(CommandException::class);

        $this->invokeHandleTransactionError();
    }

    /**
     * @return void
     */
    public function testDeclinedVoidThrows(): void
    {
        $this->expectException(CommandException::class);

        $this->invokeInterpretTransaction(
            $this->buildErrorResult(11, 'A duplicate transaction has been submitted.')
        );
    }

    /**
     * @return void
     */
    public function testResponseWithoutTransactionDataThrows(): void
    {
        $this->expectException(CommandException::class);

        $this->invokeInterpretTransaction(
            [
                'messages' => [
                    'resultCode' => 'Error',
                    'message' => [
                        'code' => 'E00027',
                        'text' => 'The transaction was unsuccessful.',
                    ],
                ],
            ]
        );
    }

    /**
     * Build an Auth.Net createTransactionRequest result carrying a transaction-level error.
     *
     * @param int $errorCode
     * @param string $errorText
     * @return array
     */
    private function buildErrorResult(int $errorCode, string $errorText): array
    {
        return [
            'messages' => [
                'resultCode' => 'Error',
                'message' => [
                    'code' => 'E00027',
                    'text' => 'The transaction was unsuccessful.',
                ],
            ],
            'transactionResponse' => [
                'responseCode' => 3,
                'transId' => '0',
                'errors' => [
                    'error' => [
                        'errorCode' => $errorCode,
                        'errorText' => $errorText,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array $result
     * @return void
     */
    private function setLastResponse(array $result): void
    {
        $property = (new ReflectionClass(Gateway::class))->getProperty('lastResponse');
        $property->setValue($this->gateway, $result);
    }

    /**
     * @return void
     */
    private function invokeHandleTransactionError(): void
    {
        $method = (new ReflectionClass(Gateway::class))->getMethod('handleTransactionError');
        $method->invoke($this->gateway);
    }

    /**
     * @param array $result
     * @return Response
     */
    private function invokeInterpretTransaction(array $result): Response
    {
        $method = (new ReflectionClass(Gateway::class))->getMethod('interpretTransaction');

        return $method->invoke($this->gateway, $result);
    }
}
