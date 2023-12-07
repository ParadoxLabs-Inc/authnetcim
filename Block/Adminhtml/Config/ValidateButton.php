<?php declare(strict_types=1);
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
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Block\Adminhtml\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Widget\Button;

class ValidateButton extends Field
{
    protected $_template = 'ParadoxLabs_Authnetcim::config/validate-button.phtml';
    protected $groupCode = 'authnetcim';
    protected $buttonId = 'validate_button';

    /**
     * Remove scope label
     *
     * @param  AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()
                ->unsCanUseWebsiteValue()
                ->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * Return element html
     *
     * @param  AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Return ajax url for collect button
     *
     * @return string
     */
    public function getAjaxUrl()
    {
        return $this->getUrl('authnetcim/system_config/initWebhooks', ['method' => $this->getGroupCode()]);
    }

    /**
     * Generate collect button html
     *
     * @return string
     */
    public function getButtonHtml()
    {
        $button = $this->getLayout()->createBlock(Button::class);
        $button->setData('id', $this->getButtonId())
               ->setData('label', __('Verify keys and connect webhooks'));

        return $button->toHtml();
    }

    /**
     * Get buttonId
     *
     * @return string
     */
    public function getButtonId()
    {
        return $this->getGroupCode() . '_' . $this->buttonId;
    }

    /**
     * Get setting group code
     *
     * @return mixed
     */
    public function getGroupCode()
    {
        return $this->groupCode;
    }
}
