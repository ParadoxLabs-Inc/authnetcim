<?php declare(strict_types=1);
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Controller\Hosted;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

class UpdateCard extends GetNewCard
{
    /**
     * Get hosted profile page request token
     *
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface
     */
    public function getCard()
    {
        $cardId = $this->_request->getParam('card_id') ?? $this->_request->getParam('id');

        if (!empty($cardId)) {
            $card = $this->cardRepository->getByHash($cardId);

            if ($card->hasOwner((int)$this->checkoutSession->getQuote()->getCustomerId()) === false) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
            }

            $this->updateCard($card);
        } else {
            // If not an existing card, load it in as a newly added card
            $card = parent::getCard();
        }

        return $card;
    }

    /**
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function updateCard(\ParadoxLabs\TokenBase\Api\Data\CardInterface $card): void
    {
        $methodCode = \ParadoxLabs\Authnetcim\Model\ConfigProvider::CODE;
        if ($this->_request->getParam('method') === \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider::CODE) {
            $methodCode = \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider::CODE;
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
        $method = $this->methodFactory->getMethodInstance($methodCode);
        $method->setStore($this->storeManager->getStore()->getId());
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $method->gateway();

        // Get CIM profile cards
        $gateway->setParameter('customerProfileId', $card->getProfileId());
        $gateway->setParameter('customerPaymentProfileId', $card->getPaymentId());
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerPaymentProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\Exception\LocalizedException(__($response['messages']['message']['text']));
        }

        $paymentProfile = $response['paymentProfile'];

        if (isset($paymentProfile['payment']['creditCard'])) {
            [$yr, $mo] = explode('-', (string)$paymentProfile['payment']['creditCard']['expirationDate'], 2);
            $day = date('t', strtotime($yr . '-' . $mo));
            $type = $this->helper->mapCcTypeToMagento($paymentProfile['payment']['creditCard']['cardType']);

            $paymentData = $card->getAdditional();
            $paymentData = [
                'cc_type'      => $type,
                'cc_last4'     => substr((string)$paymentProfile['payment']['creditCard']['cardNumber'], -4),
                'cc_exp_year'  => $yr,
                'cc_exp_month' => $mo,
                'cc_bin'       => $paymentProfile['payment']['creditCard']['issuerNumber'],
            ] + $paymentData;

            $card->setAdditional($paymentData);
            $card->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        }

        $this->cardRepository->save($card);
    }
}
