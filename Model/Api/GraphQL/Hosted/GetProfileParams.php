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
 *
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model\Api\GraphQL\Hosted;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\GraphQLRequest;
use ParadoxLabs\TokenBase\Model\Api\GraphQL;

class GetProfileParams implements ResolverInterface
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
        GraphQL $graphQL,
        GraphQLRequest $hostedForm
    ) {
        $this->graphQL    = $graphQL;
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
     * @return mixed|\Magento\Framework\GraphQl\Query\Resolver\Value
     * @throws \Exception
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $this->graphQL->authenticate($context, true);
        $this->hostedForm->setGraphQLContext($context, $args['input']);

        $payload                    = $this->hostedForm->getParams();
        $payload['iframeParams']    = json_encode($payload['iframeParams']);
        $payload['iframeSessionId'] = $this->hostedForm->getCustomerProfileId();

        return $payload;
    }
}
