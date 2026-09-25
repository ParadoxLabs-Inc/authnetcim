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
