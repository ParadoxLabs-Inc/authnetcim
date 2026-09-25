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

namespace ParadoxLabs\Authnetcim\Model\Wallet\ApplePay;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use Throwable;

/**
 * Apple Pay merchant validation: request a merchant session from Apple using the merchant identity certificate.
 *
 * The validation URL comes from the browser (ApplePaySession onvalidatemerchant), so it is untrusted: it must be an
 * https Apple Pay gateway host, or we would be sending an authenticated request anywhere a client asks.
 */
class MerchantValidator
{
    /**
     * Apple Pay gateway hosts, production and sandbox, including regional/China pods.
     *
     * @see https://developer.apple.com/documentation/apple_pay_on_the_web/setting_up_your_server
     */
    private const HOST_PATTERN = '/^(cn-)?apple-pay-gateway(-[a-z0-9]+)*\.apple\.com$/';

    private const TIMEOUT = 15;

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param Filesystem $filesystem
     * @param Random $random
     * @param Helper $helper
     * @param Pem $pem
     */
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly Filesystem $filesystem,
        private readonly Random $random,
        private readonly Helper $helper,
        private readonly Pem $pem,
    ) {
    }

    /**
     * Check whether the given URL is an Apple Pay gateway validation URL.
     *
     * @param string $validationUrl
     * @return bool
     */
    public function isValidationUrlAllowed(string $validationUrl): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- strict component checks for the SSRF allow-list
        $parts = parse_url($validationUrl);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int)$parts['port'] === 443)
            && preg_match(self::HOST_PATTERN, strtolower((string)($parts['host'] ?? ''))) === 1;
    }

    /**
     * Get an Apple Pay merchant session for the given validation URL and storefront domain.
     *
     * @param string $validationUrl
     * @param string $domain Storefront domain the payment sheet was opened on (initiativeContext)
     * @param int|null $storeId
     * @return array Merchant session, to pass to ApplePaySession.completeMerchantValidation() as-is.
     * @throws LocalizedException
     */
    public function getMerchantSession(string $validationUrl, string $domain, ?int $storeId): array
    {
        if ($this->config->isApplePayEnabled($storeId) !== true) {
            throw new LocalizedException(__('Apple Pay is not available.'));
        }

        if ($this->isValidationUrlAllowed($validationUrl) !== true) {
            throw new LocalizedException(__('Invalid Apple Pay validation URL.'));
        }

        $payload = $this->json->serialize([
            'merchantIdentifier' => $this->config->getApplePayMerchantIdentifier($storeId),
            'displayName' => $this->config->getApplePayDisplayName($storeId),
            'initiative' => 'web',
            'initiativeContext' => $domain,
        ]);

        try {
            [$status, $body] = $this->post(
                $validationUrl,
                $payload,
                $this->pem->normalize($this->config->getApplePayIdentityCert($storeId)),
                $this->pem->normalize($this->config->getApplePayIdentityKey($storeId))
            );
        } catch (Throwable $exception) {
            $this->helper->log(
                WalletType::METHOD_CODE,
                sprintf('Apple Pay merchant validation connection failed: %s', $exception->getMessage())
            );

            throw new LocalizedException(__('Apple Pay is unavailable right now. Please try again.'));
        }

        $session = $this->decode($body);
        if ($status !== 200 || $session === null) {
            $this->helper->log(
                WalletType::METHOD_CODE,
                sprintf(
                    'Apple Pay merchant validation failed for %s: HTTP %s %s',
                    $domain,
                    $status,
                    mb_substr($body, 0, 500)
                )
            );

            throw new LocalizedException(__('Apple Pay is unavailable right now. Please try again.'));
        }

        return $session;
    }

    /**
     * POST to Apple with the merchant identity certificate. Uses in-memory cert blobs when libcurl supports them,
     * otherwise short-lived private temp files.
     *
     * @param string $url
     * @param string $payload
     * @param string $certificate
     * @param string $privateKey
     * @return array [int status, string body]
     * @throws LocalizedException
     */
    private function post(string $url, string $payload, string $certificate, string $privateKey): array
    {
        if ($certificate === '' || $privateKey === '') {
            throw new LocalizedException(__('The Apple Pay merchant identity certificate is not configured.'));
        }

        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->addHeader('Content-Type', 'application/json');
        $curl->setOptions([
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $tempFiles = [];
        try {
            if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
                $curl->setOption(CURLOPT_SSLCERTTYPE, 'PEM');
                $curl->setOption(CURLOPT_SSLCERT_BLOB, $certificate);
                $curl->setOption(CURLOPT_SSLKEYTYPE, 'PEM');
                $curl->setOption(CURLOPT_SSLKEY_BLOB, $privateKey);
            } else {
                $tempFiles = [
                    'cert' => $this->writeTempFile($certificate),
                    'key' => $this->writeTempFile($privateKey),
                ];

                $curl->setOption(CURLOPT_SSLCERT, $tempFiles['cert']);
                $curl->setOption(CURLOPT_SSLKEY, $tempFiles['key']);
            }

            $curl->post($url, $payload);

            return [
                (int)$curl->getStatus(),
                (string)$curl->getBody(),
            ];
        } finally {
            $this->removeTempFiles($tempFiles);
        }
    }

    /**
     * Decode the merchant session response.
     *
     * @param string $body
     * @return array|null
     */
    private function decode(string $body): ?array
    {
        try {
            $session = $this->json->unserialize($body);
        } catch (Throwable) {
            return null;
        }

        return is_array($session) && isset($session['merchantSessionIdentifier']) ? $session : null;
    }

    /**
     * Write a private temp file for curl, returning its absolute path.
     *
     * @param string $contents
     * @return string
     */
    private function writeTempFile(string $contents): string
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);
        $path      = 'authnetcim_applepay_' . $this->random->getRandomString(24) . '.pem';

        $directory->writeFile($path, $contents);
        $directory->changePermissions($path, 0600);

        return $directory->getAbsolutePath($path);
    }

    /**
     * Remove temp files written by writeTempFile().
     *
     * @param string[] $paths
     * @return void
     */
    private function removeTempFiles(array $paths): void
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);

        foreach ($paths as $path) {
            try {
                $directory->delete($directory->getRelativePath($path));
            } catch (Throwable) {
                // Best effort; the file is 0600 in var/tmp.
            }
        }
    }
}
