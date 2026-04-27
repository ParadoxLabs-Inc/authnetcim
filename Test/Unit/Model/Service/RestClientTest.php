<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Module\Dir;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\Authnetcim\Model\Service\RestClient;
use ParadoxLabs\Authnetcim\Model\Service\RestClient\Curl;
use ParadoxLabs\Authnetcim\Model\Service\RestClient\CurlFactory;
use ParadoxLabs\TokenBase\Helper\Operation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RestClientTest extends TestCase
{
    private RestClient $restClient;
    private Operation|MockObject $helperMock;
    private ConfigProvider|MockObject $configMock;
    private ZendClientFactory|MockObject $httpClientFactoryMock;
    private Dir|MockObject $moduleDirMock;
    private CurlFactory|MockObject $curlClientFactoryMock;
    private Curl|MockObject $curlMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Operation::class);
        $this->configMock = $this->createMock(ConfigProvider::class);
        $this->httpClientFactoryMock = $this->createMock(ZendClientFactory::class);
        $this->moduleDirMock = $this->createMock(Dir::class);
        $this->curlClientFactoryMock = $this->createMock(CurlFactory::class);
        $this->curlMock = $this->createMock(Curl::class);

        $this->moduleDirMock->method('getDir')
            ->with('ParadoxLabs_Authnetcim')
            ->willReturn('/var/www/app/code/ParadoxLabs/Authnetcim');

        $this->curlClientFactoryMock->method('create')
            ->willReturn($this->curlMock);

        $this->restClient = new RestClient(
            $this->helperMock,
            $this->configMock,
            $this->httpClientFactoryMock,
            $this->moduleDirMock,
            $this->curlClientFactoryMock,
        );
    }

    public function testSetAuthStoresCredentials(): void
    {
        $this->restClient->setAuth('login123', 'txnKey456', 'sigKey789', false);

        // Verify by checking endpoint (sandbox flag stored)
        $this->assertSame(
            'https://api2.authorize.net/rest/v1/',
            $this->restClient->getRestEndpoint()
        );
    }

    public function testGetRestEndpointReturnsLiveUrl(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', false);

        $this->assertSame(
            'https://api2.authorize.net/rest/v1/',
            $this->restClient->getRestEndpoint()
        );
    }

    public function testGetRestEndpointReturnsSandboxUrl(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->assertSame(
            'https://apitest.authorize.net/rest/v1/',
            $this->restClient->getRestEndpoint()
        );
    }

    public function testPostSendsJsonBody(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $params = ['amount' => 100, 'currency' => 'USD'];
        $expectedUrl = 'https://apitest.authorize.net/rest/v1/transactions';
        $expectedBody = json_encode($params);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with($expectedUrl, $expectedBody);

        $this->curlMock->method('getStatus')
            ->willReturn(200);

        $this->curlMock->method('getBody')
            ->willReturn('{"transactionId":"123"}');

        $this->restClient->post('transactions', $params);
    }

    public function testPostReturnsDecodedResponse(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $responseData = ['transactionId' => '123', 'status' => 'approved'];

        $this->curlMock->method('getStatus')
            ->willReturn(200);

        $this->curlMock->method('getBody')
            ->willReturn(json_encode($responseData));

        $result = $this->restClient->post('transactions', ['amount' => 100]);

        $this->assertSame($responseData, $result);
    }

    public function testPostReturnsEmptyArrayOnNullResponse(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(200);

        $this->curlMock->method('getBody')
            ->willReturn('');

        $result = $this->restClient->post('transactions', ['amount' => 100]);

        $this->assertSame([], $result);
    }

    public function testGetBuildsQueryString(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $params = ['limit' => 10, 'offset' => 0];
        $expectedUrl = 'https://apitest.authorize.net/rest/v1/transactions?limit=10&offset=0';

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($expectedUrl);

        $this->curlMock->method('getStatus')
            ->willReturn(200);

        $this->curlMock->method('getBody')
            ->willReturn('[]');

        $this->restClient->get('transactions', $params);
    }

    public function testGetReturnsDecodedResponse(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $responseData = ['transactions' => [['id' => '1'], ['id' => '2']]];

        $this->curlMock->method('getStatus')
            ->willReturn(200);

        $this->curlMock->method('getBody')
            ->willReturn(json_encode($responseData));

        $result = $this->restClient->get('transactions', ['limit' => 10]);

        $this->assertSame($responseData, $result);
    }

    public function testPutSendsJsonBody(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $params = ['email' => 'test@example.com'];
        $expectedUrl = 'https://apitest.authorize.net/rest/v1/customers/123';
        $expectedBody = json_encode($params);

        $this->curlMock->expects($this->once())
            ->method('put')
            ->with($expectedUrl, $expectedBody);

        $this->curlMock->method('getStatus')
            ->willReturn(200);

        $this->curlMock->method('getBody')
            ->willReturn('{}');

        $this->restClient->put('customers/123', $params);
    }

    public function testDeleteCallsEndpoint(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $expectedUrl = 'https://apitest.authorize.net/rest/v1/customers/123';

        $this->curlMock->expects($this->once())
            ->method('delete')
            ->with($expectedUrl);

        $this->curlMock->method('getStatus')
            ->willReturn(200);

        $this->curlMock->method('getBody')
            ->willReturn('');

        $this->restClient->delete('customers/123');
    }

    public function testDeleteIgnores404Errors(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(404);

        $this->curlMock->method('getBody')
            ->willReturn('{"reason":"NOT_FOUND","message":"Resource not found"}');

        // Should not throw exception when NOT_FOUND is in response
        $this->restClient->delete('customers/nonexistent');

        $this->assertTrue(true); // Confirm no exception thrown
    }

    public function testCheckErrorsThrowsOn4xx(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(400);

        $this->curlMock->method('getBody')
            ->willReturn('{"reason":"BAD_REQUEST","message":"Invalid request"}');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid request (BAD_REQUEST)');

        $this->restClient->post('transactions', []);
    }

    public function testCheckErrorsThrowsOn5xx(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(500);

        $this->curlMock->method('getBody')
            ->willReturn('{"reason":"SERVER_ERROR","message":"Internal server error"}');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Internal server error (SERVER_ERROR)');

        $this->restClient->get('transactions');
    }

    public function testCheckErrorsExtractsMessage(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(401);

        $this->curlMock->method('getBody')
            ->willReturn('{"reason":"UNAUTHORIZED","message":"Authentication failed"}');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Authentication failed (UNAUTHORIZED)');

        $this->restClient->post('transactions', []);
    }

    public function testCheckErrorsExtractsDetailsMessage(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(422);

        $this->curlMock->method('getBody')
            ->willReturn(json_encode([
                'reason' => 'VALIDATION_ERROR',
                'message' => 'Validation failed',
                'details' => [
                    ['message' => 'Amount must be greater than zero'],
                ],
            ]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Amount must be greater than zero (VALIDATION_ERROR)');

        $this->restClient->post('transactions', ['amount' => 0]);
    }

    public function testCheckErrorsPassesOn200(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(200);

        $this->curlMock->method('getBody')
            ->willReturn('{"success":true}');

        // Should not throw exception
        $result = $this->restClient->post('transactions', ['amount' => 100]);

        $this->assertSame(['success' => true], $result);
    }

    public function testCheckErrorsPassesOn201(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(201);

        $this->curlMock->method('getBody')
            ->willReturn('{"id":"new-123"}');

        // Should not throw exception
        $result = $this->restClient->post('customers', ['email' => 'test@example.com']);

        $this->assertSame(['id' => 'new-123'], $result);
    }

    public function testCheckErrorsThrowsDefaultMessageOnEmptyResponse(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(503);

        $this->curlMock->method('getBody')
            ->willReturn('');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('RestClient: Unable to reach Authorize.net; no response.');

        $this->restClient->post('transactions', []);
    }

    public function testCheckErrorsThrowsOnRawStringError(): void
    {
        $this->restClient->setAuth('login', 'key', 'sig', true);

        $this->curlMock->method('getStatus')
            ->willReturn(503);

        $this->curlMock->method('getBody')
            ->willReturn('Service Unavailable');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Service Unavailable');

        $this->restClient->post('transactions', []);
    }
}
