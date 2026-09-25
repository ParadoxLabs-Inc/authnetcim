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

namespace ParadoxLabs\Authnetcim\Model\Wallet;

/**
 * Supported wallet types, and their Authorize.net opaqueData descriptors.
 *
 * The descriptor is always derived server-side from the wallet type; it is never accepted from the client.
 */
class WalletType
{
    public const METHOD_CODE = 'authnetcim_wallet';

    public const APPLE_PAY = 'applepay';
    public const GOOGLE_PAY = 'googlepay';

    public const DESCRIPTORS = [
        self::APPLE_PAY => 'COMMON.APPLE.INAPP.PAYMENT',
        self::GOOGLE_PAY => 'COMMON.GOOGLE.INAPP.PAYMENT',
    ];

    public const LABELS = [
        self::APPLE_PAY => 'Apple Pay',
        self::GOOGLE_PAY => 'Google Pay',
    ];

    /**
     * Magento cc type => [Apple Pay network, Google Pay network]
     */
    public const NETWORK_MAP = [
        'AE' => [
            self::APPLE_PAY => 'amex',
            self::GOOGLE_PAY => 'AMEX',
        ],
        'DI' => [
            self::APPLE_PAY => 'discover',
            self::GOOGLE_PAY => 'DISCOVER',
        ],
        'JCB' => [
            self::APPLE_PAY => 'jcb',
            self::GOOGLE_PAY => 'JCB',
        ],
        'MC' => [
            self::APPLE_PAY => 'masterCard',
            self::GOOGLE_PAY => 'MASTERCARD',
        ],
        'VI' => [
            self::APPLE_PAY => 'visa',
            self::GOOGLE_PAY => 'VISA',
        ],
    ];

    /**
     * Authorize.net accountType => Magento cc type
     */
    public const ACCOUNT_TYPE_MAP = [
        'AmericanExpress' => 'AE',
        'Discover' => 'DI',
        'JCB' => 'JCB',
        'MasterCard' => 'MC',
        'Visa' => 'VI',
        'DinersClub' => 'DN',
    ];
}
