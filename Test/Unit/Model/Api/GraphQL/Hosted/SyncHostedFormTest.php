<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Api\GraphQL\Hosted;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use ParadoxLabs\Authnetcim\Model\Api\GraphQL\Hosted\SyncHostedForm;
use ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\GraphQLRequest;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Model\Api\GraphQL;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SyncHostedFormTest extends TestCase
{
    private SyncHostedForm $syncHostedForm;
    private GraphQL|MockObject $graphQLMock;
    private GraphQLRequest|MockObject $hostedFormMock;
    private Field|MockObject $fieldMock;
    private ContextInterface|MockObject $contextMock;
    private ResolveInfo|MockObject $resolveInfoMock;

    protected function setUp(): void
    {
        $this->graphQLMock = $this->createMock(GraphQL::class);
        $this->hostedFormMock = $this->createMock(GraphQLRequest::class);
        $this->fieldMock = $this->createMock(Field::class);
        $this->contextMock = $this->createMock(ContextInterface::class);
        $this->resolveInfoMock = $this->createMock(ResolveInfo::class);

        $this->syncHostedForm = new SyncHostedForm(
            $this->graphQLMock,
            $this->hostedFormMock,
        );
    }

    public function testResolveAuthenticatesUser(): void
    {
        $args = [
            'input' => [
                'cardId' => 'card123',
            ],
        ];

        $cardMock = $this->createCardMock();
        $this->hostedFormMock->method('getCard')
            ->willReturn($cardMock);

        $this->graphQLMock->expects($this->once())
            ->method('authenticate')
            ->with($this->contextMock, true);

        $this->syncHostedForm->resolve(
            $this->fieldMock,
            $this->contextMock,
            $this->resolveInfoMock,
            null,
            $args
        );
    }

    public function testResolveSetsGraphQLContext(): void
    {
        $args = [
            'input' => [
                'cardId' => 'card123',
                'method' => 'authnetcim',
            ],
        ];

        $cardMock = $this->createCardMock();
        $this->hostedFormMock->method('getCard')
            ->willReturn($cardMock);

        $this->hostedFormMock->expects($this->once())
            ->method('setGraphQLContext')
            ->with($this->contextMock, $args['input']);

        $this->syncHostedForm->resolve(
            $this->fieldMock,
            $this->contextMock,
            $this->resolveInfoMock,
            null,
            $args
        );
    }

    public function testResolveThrowsExceptionWhenBothCardIdAndSessionIdEmpty(): void
    {
        $args = [
            'input' => [
                'cardId' => '',
                'iframeSessionId' => '',
            ],
        ];

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Input must include cardId or iframeSessionId');

        $this->syncHostedForm->resolve(
            $this->fieldMock,
            $this->contextMock,
            $this->resolveInfoMock,
            null,
            $args
        );
    }

    public function testResolveThrowsExceptionWhenInputMissing(): void
    {
        $args = [
            'input' => [],
        ];

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Input must include cardId or iframeSessionId');

        $this->syncHostedForm->resolve(
            $this->fieldMock,
            $this->contextMock,
            $this->resolveInfoMock,
            null,
            $args
        );
    }

    public function testResolveAcceptsCardId(): void
    {
        $args = [
            'input' => [
                'cardId' => 'card123',
            ],
        ];

        $cardMock = $this->createCardMock();
        $this->hostedFormMock->method('getCard')
            ->willReturn($cardMock);

        $this->hostedFormMock->expects($this->once())
            ->method('getCard');

        $result = $this->syncHostedForm->resolve(
            $this->fieldMock,
            $this->contextMock,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertArrayHasKey('card', $result);
    }

    public function testResolveAcceptsIframeSessionId(): void
    {
        $args = [
            'input' => [
                'iframeSessionId' => 'session456',
            ],
        ];

        $cardMock = $this->createCardMock();
        $this->hostedFormMock->method('getCard')
            ->willReturn($cardMock);

        $this->hostedFormMock->expects($this->once())
            ->method('getCard');

        $result = $this->syncHostedForm->resolve(
            $this->fieldMock,
            $this->contextMock,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertArrayHasKey('card', $result);
    }

    public function testResolveReturnsCardData(): void
    {
        $args = [
            'input' => [
                'cardId' => 'card123',
            ],
        ];

        $cardMock = $this->createMock(CardInterface::class);
        $cardMock->method('getHash')
            ->willReturn('hash123');
        $cardMock->method('getLabel')
            ->willReturn('Visa xxxx-1111');
        $cardMock->method('getMethod')
            ->willReturn('authnetcim');
        $cardMock->method('getType')
            ->willReturn('VI');
        $cardMock->method('getAdditional')
            ->willReturnCallback(function ($key) {
                if ($key === 'cc_bin') {
                    return '411111';
                }

                if ($key === 'cc_last4') {
                    return '1111';
                }

                return null;
            });

        $this->hostedFormMock->method('getCard')
            ->willReturn($cardMock);

        $result = $this->syncHostedForm->resolve(
            $this->fieldMock,
            $this->contextMock,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertSame('hash123', $result['card']['id']);
        $this->assertSame('Visa xxxx-1111', $result['card']['label']);
        $this->assertSame('authnetcim', $result['card']['method']);
        $this->assertFalse($result['card']['selected']);
        $this->assertTrue($result['card']['new']);
        $this->assertSame('VI', $result['card']['type']);
        $this->assertSame('411111', $result['card']['cc_bin']);
        $this->assertSame('1111', $result['card']['cc_last4']);
    }

    public function testResolveWithBothCardIdAndSessionId(): void
    {
        $args = [
            'input' => [
                'cardId' => 'card123',
                'iframeSessionId' => 'session456',
            ],
        ];

        $cardMock = $this->createCardMock();
        $this->hostedFormMock->method('getCard')
            ->willReturn($cardMock);

        $result = $this->syncHostedForm->resolve(
            $this->fieldMock,
            $this->contextMock,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertArrayHasKey('card', $result);
    }

    private function createCardMock(): CardInterface|MockObject
    {
        $cardMock = $this->createMock(CardInterface::class);
        $cardMock->method('getHash')
            ->willReturn('defaultHash');
        $cardMock->method('getLabel')
            ->willReturn('Test Card');
        $cardMock->method('getMethod')
            ->willReturn('authnetcim');
        $cardMock->method('getType')
            ->willReturn('VI');
        $cardMock->method('getAdditional')
            ->willReturn(null);

        return $cardMock;
    }
}
