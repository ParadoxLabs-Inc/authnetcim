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

namespace ParadoxLabs\Authnetcim\Model\Api\GraphQL\Wallet;

use Magento\QuoteGraphQl\Model\Cart\Payment\AdditionalDataProviderInterface;

/**
 * Map PaymentMethodInput.authnetcim_wallet onto the payment additional_data read by the wallet DataAssignObserver.
 */
class AdditionalDataProvider implements AdditionalDataProviderInterface
{
    public const INPUT_KEY = 'authnetcim_wallet';

    /**
     * Wallet input fields passed through to additional_data. Anything else in the input is dropped.
     */
    private const FIELDS = [
        'wallet_type',
        'wallet_token',
        'card_display',
    ];

    /**
     * Return the wallet additional data from the payment method input.
     *
     * @param array $data
     * @return array
     */
    public function getData(array $data): array
    {
        $input = $data[self::INPUT_KEY] ?? null;
        if (!is_array($input)) {
            return [];
        }

        $result = [];
        foreach (self::FIELDS as $field) {
            if (isset($input[$field]) && is_scalar($input[$field])) {
                $result[$field] = (string)$input[$field];
            }
        }

        return $result;
    }
}
