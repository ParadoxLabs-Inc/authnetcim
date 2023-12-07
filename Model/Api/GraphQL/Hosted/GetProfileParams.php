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

class GetProfileParams implements \ParadoxLabs\TokenBase\Model\Api\GraphQL\ResolverInterface
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Api\GraphQL
     */
    protected $graphQL;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\GraphQLRequest
     */
    protected $hostedForm;

    /**
     * GetParams constructor.
     *
     * @param \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL
     * @param \ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\GraphQLRequest $hostedForm
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL,
        \ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\GraphQLRequest $hostedForm
    ) {
        $this->graphQL = $graphQL;
        $this->hostedForm = $hostedForm;
    }

    /**
     * Get hosted form parameters for the current request
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

        $payload = $this->hostedForm->getParams();
        $payload['iframeParams'] = json_encode($payload['iframeParams']);
        $payload['iframeSessionId'] = $this->hostedForm->getCustomerProfileId();

        return $payload;
    }
}
