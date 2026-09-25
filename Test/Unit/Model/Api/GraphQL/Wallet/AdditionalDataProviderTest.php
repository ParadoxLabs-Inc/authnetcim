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

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Api\GraphQL\Wallet;

use ParadoxLabs\Authnetcim\Model\Api\GraphQL\Wallet\AdditionalDataProvider;
use PHPUnit\Framework\TestCase;

class AdditionalDataProviderTest extends TestCase
{
    private AdditionalDataProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new AdditionalDataProvider();
    }

    /**
     * The returned keys must be exactly the ones DataAssignObserver reads.
     *
     * @return void
     */
    public function testReturnsWalletInputFields(): void
    {
        $result = $this->provider->getData([
            'code' => 'authnetcim_wallet',
            'authnetcim_wallet' => [
                'wallet_type' => 'applepay',
                'wallet_token' => 'eyJ0b2tlbiI6MX0=',
                'card_display' => 'Visa 1234',
            ],
        ]);

        $this->assertSame(
            [
                'wallet_type' => 'applepay',
                'wallet_token' => 'eyJ0b2tlbiI6MX0=',
                'card_display' => 'Visa 1234',
            ],
            $result
        );
    }

    /**
     * @return void
     */
    public function testDropsUnknownAndNonScalarFields(): void
    {
        $result = $this->provider->getData([
            'code' => 'authnetcim_wallet',
            'authnetcim_wallet' => [
                'wallet_type' => 'googlepay',
                'wallet_token' => [
                    'nested',
                ],
                'descriptor' => 'COMMON.EVIL.PAYMENT',
            ],
        ]);

        $this->assertSame(
            [
                'wallet_type' => 'googlepay',
            ],
            $result
        );
    }

    /**
     * @return void
     */
    public function testMissingWalletInputReturnsEmptyArray(): void
    {
        $this->assertSame(
            [],
            $this->provider->getData([
                'code' => 'authnetcim_wallet',
            ])
        );
        $this->assertSame(
            [],
            $this->provider->getData([
                'code' => 'authnetcim_wallet',
                'tokenbase_data' => [
                    'acceptjs_value' => 'abc',
                ],
            ])
        );
    }
}
