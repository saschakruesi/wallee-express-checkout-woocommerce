<?php
/**
 * wallee SDK
 *
 * This library allows to interact with the wallee payment service.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * COMPATIBILITY COPY (wallee Express Checkout add-on).
 *
 * This is a copy of the wallee SDK enum \Wallee\Sdk\Model\TransactionUserInterfaceType, taken from
 * the SDK bundled with wallee plugin 3.4.11, with ONE addition: the value EXPRESS_CHECKOUT.
 * The bundled SDK does not know that value, so the main plugin throws an InvalidArgumentException
 * whenever it reads an Express Checkout transaction. The add-on loads this file (see
 * Wallee\ExpressCheckout\Compat\SdkShim) before the main plugin's SDK autoloader can load the
 * original class, and only when the main plugin's own file lacks the value.
 *
 * Remove this file and SdkShim once the main plugin ships an SDK that knows EXPRESS_CHECKOUT;
 * the shim already switches itself off in that case.
 */

namespace Wallee\Sdk\Model;
use \Wallee\Sdk\ObjectSerializer;

/**
 * TransactionUserInterfaceType model
 *
 * @category    Class
 * @description 
 * @package     Wallee\Sdk
 * @author      wallee AG
 * @license     http://www.apache.org/licenses/LICENSE-2.0 Apache License v2
 */
class TransactionUserInterfaceType
{
    /**
     * Possible values of this enum
     */
    const IFRAME = 'IFRAME';
    const LIGHTBOX = 'LIGHTBOX';
    const PAYMENT_PAGE = 'PAYMENT_PAGE';
    const MOBILE_SDK = 'MOBILE_SDK';
    const TERMINAL = 'TERMINAL';
    const EXPRESS_CHECKOUT = 'EXPRESS_CHECKOUT';
    
    /**
     * Gets allowable values of the enum
     * @return string[]
     */
    public static function getAllowableEnumValues()
    {
        return [
            self::IFRAME,
            self::LIGHTBOX,
            self::PAYMENT_PAGE,
            self::MOBILE_SDK,
            self::TERMINAL,
            self::EXPRESS_CHECKOUT,
        ];
    }
}


