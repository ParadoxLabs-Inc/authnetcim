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

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet;

use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use PHPUnit\Framework\TestCase;

class PemTest extends TestCase
{
    /**
     * A key pasted into a single-line field loses its line breaks; normalize must restore valid PEM.
     *
     * @return void
     */
    public function testRestoresFlattenedKey(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);

        $flattened  = str_replace("\n", ' ', $pem);
        $normalized = (new Pem())->normalize($flattened);

        $this->assertNotFalse(openssl_pkey_get_private($normalized));
        $this->assertStringStartsWith("-----BEGIN PRIVATE KEY-----\n", $normalized);
    }

    /**
     * @return void
     */
    public function testNonPemIsEmpty(): void
    {
        $this->assertSame('', (new Pem())->normalize('not a key'));
    }
}
