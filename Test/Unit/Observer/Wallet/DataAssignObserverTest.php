<?php
/**
 * Copyright © 2015-present ParadoxLabs, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Need help? Try our knowledgebase and support system:
 *
 * @link https://support.paradoxlabs.com
 */

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Observer\Wallet;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use ParadoxLabs\Authnetcim\Observer\Wallet\DataAssignObserver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DataAssignObserverTest extends TestCase
{
    private InfoInterface|MockObject $payment;

    /**
     * @var array
     */
    private array $info = [];

    protected function setUp(): void
    {
        $this->payment = $this->createMock(InfoInterface::class);
        $this->payment->method('setAdditionalInformation')->willReturnCallback(
            function ($key, $value = null) {
                $this->info[$key] = $value;

                return $this->payment;
            }
        );
        $this->payment->method('unsAdditionalInformation')->willReturnCallback(
            function ($key = null) {
                unset($this->info[$key]);

                return $this->payment;
            }
        );
    }

    /**
     * @return void
     */
    public function testAssignsValidWalletData(): void
    {
        $this->execute([
            'wallet_type' => 'googlepay',
            'wallet_token' => 'eyJzaWduYXR1cmUiOiJNRVlDIn0=',
            'card_display' => 'Visa •••• 4242<script>',
        ]);

        $this->assertSame('googlepay', $this->info['wallet_type']);
        $this->assertSame('eyJzaWduYXR1cmUiOiJNRVlDIn0=', $this->info['wallet_token']);
        $this->assertSame('Visa •••• 4242script', $this->info['card_display']);
    }

    /**
     * GraphQL/Hyvä placeOrder re-imports payment data without the token; that must not wipe the token.
     *
     * @return void
     */
    public function testEmptyTokenIsNoOp(): void
    {
        $this->execute([
            'wallet_type' => 'googlepay',
        ]);

        $this->assertSame([], $this->info);
    }

    /**
     * The approved wallet sheet total is kept with the token, for the order total check before the charge.
     *
     * @return void
     */
    public function testAssignsApprovedWalletAmount(): void
    {
        $this->execute([
            'wallet_type' => 'applepay',
            'wallet_token' => 'dG9rZW4=',
            'wallet_amount' => '1234.50',
        ]);

        $this->assertSame('dG9rZW4=', $this->info['wallet_token']);
        $this->assertSame('1234.50', $this->info['wallet_amount']);
    }

    /**
     * A new token without an amount must not inherit the amount assigned with an earlier token.
     *
     * @return void
     */
    public function testTokenWithoutAmountClearsStaleWalletAmount(): void
    {
        $this->info['wallet_amount'] = '99.99';

        $this->execute([
            'wallet_type' => 'googlepay',
            'wallet_token' => 'dG9rZW4=',
            'wallet_amount' => '',
        ]);

        $this->assertSame('dG9rZW4=', $this->info['wallet_token']);
        $this->assertArrayNotHasKey('wallet_amount', $this->info);
    }

    /**
     * The placeOrder re-import carries no token; it must leave an assigned amount alone and never fail on it.
     *
     * @return void
     */
    public function testEmptyTokenLeavesWalletAmountAlone(): void
    {
        $this->info['wallet_amount'] = '25.00';

        $this->execute([
            'wallet_type' => 'googlepay',
            'wallet_amount' => 'not-an-amount',
        ]);

        $this->assertSame(
            [
                'wallet_amount' => '25.00',
            ],
            $this->info
        );
    }

    /**
     * @return array
     */
    public static function invalidAmountProvider(): array
    {
        return [
            'three decimals' => ['12.345'],
            'negative' => ['-12.34'],
            'exponent' => ['1e3'],
            'comma decimal' => ['12,34'],
            'currency symbol' => ['$12.34'],
            'leading space' => [' 12.34'],
            'trailing newline' => ["12.34\n"],
            'trailing dot' => ['12.'],
            'too many digits' => ['12345678901'],
            'text' => ['twelve'],
            'array' => [
                [
                    '12.34',
                ],
            ],
        ];
    }

    /**
     * @dataProvider invalidAmountProvider
     * @param mixed $amount
     * @return void
     */
    #[DataProvider('invalidAmountProvider')]
    public function testRejectsInvalidWalletAmount(mixed $amount): void
    {
        try {
            $this->execute([
                'wallet_type' => 'googlepay',
                'wallet_token' => 'dG9rZW4=',
                'wallet_amount' => $amount,
            ]);
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $exception) {
            $this->assertSame('Invalid wallet payment data. Please try again.', $exception->getMessage());
            $this->assertSame([], $this->info, 'Nothing may be assigned from invalid wallet data.');
        }
    }

    /**
     * @return void
     */
    public function testRejectsUnknownWalletType(): void
    {
        $this->expectException(LocalizedException::class);

        $this->execute([
            'wallet_type' => 'samsungpay',
            'wallet_token' => 'dG9rZW4=',
        ]);
    }

    /**
     * @return void
     */
    public function testRejectsNonBase64Token(): void
    {
        $this->expectException(LocalizedException::class);

        $this->execute([
            'wallet_type' => 'applepay',
            'wallet_token' => '{"not":"base64"}',
        ]);
    }

    /**
     * @return void
     */
    public function testRejectsOversizedToken(): void
    {
        $this->expectException(LocalizedException::class);

        $this->execute([
            'wallet_type' => 'applepay',
            'wallet_token' => str_repeat('A', 40000),
        ]);
    }

    /**
     * @param array $additionalData
     * @return void
     */
    private function execute(array $additionalData): void
    {
        $event = new Event([
            AbstractDataAssignObserver::DATA_CODE => new DataObject([
                'additional_data' => $additionalData,
            ]),
            AbstractDataAssignObserver::MODEL_CODE => $this->payment,
        ]);

        (new DataAssignObserver())->execute(new Observer(['event' => $event]));
    }
}
