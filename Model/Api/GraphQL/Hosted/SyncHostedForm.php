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

namespace ParadoxLabs\Authnetcim\Model\Api\GraphQL\Hosted;

/**
 * Soft dependency: Supporting 2.3 GraphQL without breaking <2.3 compatibility.
 * 2.3+ implements \Magento\Framework\GraphQL; lower does not.
 */
if (!interface_exists('\ParadoxLabs\TokenBase\Model\Api\GraphQL\ResolverInterface')) {
    if (interface_exists('\Magento\Framework\GraphQl\Query\ResolverInterface')) {
        class_alias(
            '\Magento\Framework\GraphQl\Query\ResolverInterface',
            '\ParadoxLabs\TokenBase\Model\Api\GraphQL\ResolverInterface'
        );
    } else {
        class_alias(
            '\ParadoxLabs\TokenBase\Model\Api\GraphQL\FauxResolverInterface',
            '\ParadoxLabs\TokenBase\Model\Api\GraphQL\ResolverInterface'
        );
    }
}

/**
 * SyncHostedForm Class
 */
class SyncHostedForm implements \ParadoxLabs\TokenBase\Model\Api\GraphQL\ResolverInterface
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Api\GraphQL
     */
    protected $graphQL;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\Hosted\GraphQLRequest
     */
    protected $hostedForm;

    /**
     * GetParams constructor.
     *
     * @param \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL
     * @param \ParadoxLabs\Authnetcim\Model\Service\Hosted\GraphQLRequest $hostedForm
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL,
        \ParadoxLabs\Authnetcim\Model\Service\Hosted\GraphQLRequest $hostedForm
    ) {
        $this->graphQL = $graphQL;
        $this->hostedForm = $hostedForm;
    }

    /**
     * Fetches the data from persistence models and format it according to the GraphQL schema.
     *
     * @param \Magento\Framework\GraphQl\Config\Element\Field $field
     * @param \Magento\Framework\GraphQl\Query\Resolver\ContextInterface $context
     * @param \Magento\Framework\GraphQl\Schema\Type\ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @throws \Exception
     * @return mixed|\Magento\Framework\GraphQl\Query\Resolver\Value
     */
    public function resolve(
        \Magento\Framework\GraphQl\Config\Element\Field $field,
        $context,
        \Magento\Framework\GraphQl\Schema\Type\ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        $this->graphQL->authenticate($context, true);
        $this->hostedForm->setGraphQLContext($context, $args['input']);

        if (empty($args['input']['cardId']) && empty($args['input']['iframeSessionId'])) {
            throw new \Magento\Framework\GraphQl\Exception\GraphQlInputException(
                __('Input must include cardId or iframeSessionId')
            );
        }

        $card = $this->hostedForm->getCard();

        return [
            'card' => [
                'id' => $card->getHash(),
                'label' => $card->getLabel(),
                'selected' => false,
                'new' => true,
                'type' => $card->getType(),
                'cc_bin' => $card->getAdditional('cc_bin'),
                'cc_last4' => $card->getAdditional('cc_last4'),
            ],
        ];
    }
}
