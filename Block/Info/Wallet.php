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

namespace ParadoxLabs\Authnetcim\Block\Info;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;

/**
 * Payment info block: wallet type, card, and (admin only) transaction details.
 */
class Wallet extends ConfigurableInfo
{
    /**
     * @param string $field
     * @return Phrase
     */
    protected function getLabel($field)
    {
        return match ($field) {
            'wallet_type' => __('Wallet'),
            'card_display' => __('Card'),
            'avs_result_code' => __('AVS Response'),
            'auth_code' => __('Authorization Code'),
            'transaction_id' => __('Transaction ID'),
            default => __($field),
        };
    }

    /**
     * @param string $field
     * @param string $value
     * @return string|Phrase
     */
    protected function getValueView($field, $value)
    {
        if ($field === 'wallet_type') {
            return WalletType::LABELS[(string)$value] ?? $value;
        }

        return parent::getValueView($field, $value);
    }
}
