<?php


namespace Simplephp;

/**
 * Class AppstoreNotification
 * @package simplephp
 */
class AppstoreNotification
{
    /**
     * IAP VERSION
     */
    const APP_VERSION = '1.0.0';

    /**
     * @var string 沙盒地址
     */
    private $sandboxURI = 'https://sandbox.itunes.apple.com/verifyReceipt';

    /**
     * @var string 正式地址
     */
    private $releaseURI = 'https://buy.itunes.apple.com/verifyReceipt';

    /**
     * @var string verify
     */
    private $endpoint;

    /**
     * @var string App Store connect 创建的秘钥
     */
    private $sharedSecret;

    /**
     * @var string 错误信息
     */
    private $error;

    /**
     * @var array 错误信息
     */
    private $errorMessages = [
        '21000' => '未使用HTTP POST请求方法向App Store发出请求',
        '21001' => 'App Store不再发送此状态代码',
        '21002' => '该receipt-data物业的数据格式不正确或遗失',
        '21003' => '收据无法通过身份验证',
        '21004' => '您提供的共享密码与您帐户的共享密钥不符',
        '21005' => '收据服务器当前不可用',
        '21006' => '此收据有效但订阅已过期。当此状态代码返回到您的服务器时，收据数据也会被解码并作为响应的一部分返回。仅针对自动续订订阅的iOS 6样式交易收据返回',
        '21007' => '此收据来自测试环境，但已发送到生产环境进行验证',
        '21008' => '此收据来自生产环境，但已发送到测试环境进行验证',
        '21009' => '内部数据访问错误。稍后再试',
        '21010' => '无法找到或已删除用户帐户'
    ];

    /**
     * Iap constructor.
     * @param bool $inSandbox
     */
    public function __construct($inSandbox = false)
    {
        $this->setEndpoint($inSandbox);
    }

    /**
     * 校验
     * @param string $receiptData
     * @return array|bool
     */
    public function verifyReceipt(string $receiptData)
    {
        if ($this->sharedSecret) {
            $requestBody = '{"receipt-data":"' . $receiptData . '","password":"' . $this->sharedSecret . '"}';
        } else {
            $requestBody = '{"receipt-data":"' . $receiptData . '"}';
        }

        $stringResult = $this->curlPost($this->endpoint, $requestBody);
        $jsonResult = json_decode($stringResult, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = json_last_error();
            return false;
        }
        // 苹果这边可能会抛出异常 com.apple.jingle.commercelogic.inappcache.MZInAppCacheAccessException
        /**
         * ["status"]=>
         * int(21199)
         * ["environment"]=>
         * string(10) "Production"
         * ["is_retryable"]=>
         * bool(true)
         * ["exception"]=>
         * string(69) "com.apple.jingle.commercelogic.inappcache.MZInAppCacheAccessException"
         */
        if (isset($jsonResult['exception'])) {
            $this->error = $jsonResult['exception'];
            return false;
        }
        if ($jsonResult['status'] === '0') {
            $this->error = $this->getError2Message($jsonResult['status']);
            return false;
        }
        /**
         * @url https://developer.apple.com/cn/documentation/storekit/in-app_purchase/validating_receipts_with_the_app_store/
         * @url https://developer.apple.com/cn/app-store/Receipt-Validation-Programming-Guide-CN.pdf
         * 数据中包含:in_app 数组包含非消耗型、非续期订阅，以及用户之前购买的自动续期订阅。根据需要，检查响应中这些 App 内购买项目类型对应的值来验证交易。
         * 对于自动续期订阅项目，请解析响应来获取关于当前有效订阅期的信息。在验证订阅的收据时，latest_receipt 包含最新编码的收据，它的值与请求中 receipt-data 的值相同，latest_receipt_info 包含订阅的所有交易，其中包括初次购买和后续续期，但不包括任何恢复购买。
         * $transactionList结果并未格式化 自动连续续订类型数据和非连续续订数据
         */
        $transactionList = [];
        // 自动续订类型检测
        $serviceTimestamp = $this->timestamp();
        if (isset($jsonResult['receipt']['latest_receipt_info']) && !empty($jsonResult['receipt']['latest_receipt_info'])) {
            foreach ($jsonResult['receipt']['latest_receipt_info'] as $infoA) {
                if ($infoA['expires_date_ms'] > $serviceTimestamp) {
                    $transactionList[$infoA['transaction_id']] = $infoA;
                }
            }
        }
        // 非连续续订
        if (isset($jsonResult['receipt']['in_app']) && !empty($jsonResult['receipt']['in_app'])) {
            foreach ($jsonResult['receipt']['in_app'] as $infoB) {
                if (($infoB['expires_date_ms'] > $serviceTimestamp) && !isset($transactionList[$infoB['transaction_id']])) {
                    $transactionList[$infoB['transaction_id']] = $infoB;
                }
            }
        }
        if (empty($transactionList)) {
            $this->error = '暂无订购有效期内数据';
            return false;
        }
        return $transactionList;
    }

    /**
     * @param bool $inSandbox
     * @return $this
     */
    public function setEndpoint($inSandbox = false)
    {
        $this->endpoint = $inSandbox ? $this->sandboxURI : $this->releaseURI;
        return $this;
    }

    /**
     * 设置秘钥
     * @param string $sharedSecret
     * @return $this
     */
    public function setSharedSecret(string $sharedSecret)
    {
        $this->sharedSecret = $sharedSecret;
        return $this;
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 获取错误信息
     * @param string $code
     * @return mixed|null
     */
    public function getError2Message(string $code)
    {
        return isset($this->errorMessages[$code]) ? $this->errorMessages[$code] : null;
    }

    /**
     * curl post 请求
     * @param $url
     * @param $data
     * @param int $timeout
     * @return bool|string
     */
    public function curlPost($url, $data, $timeout = 30)
    {
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_TIMEOUT, 10);
        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADER, 0);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
        curl_setopt($handle, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, FALSE);

        $tmpInfo = curl_exec($handle);
        if (curl_errno($handle)) {
            curl_close($handle);
            return false;
        } else {
            curl_close($handle);
            return $tmpInfo;
        }
    }

    /**
     * 获取当前毫秒
     * @return float
     */
    public function timestamp()
    {
        list($msec, $sec) = explode(' ', microtime());
        return (float)sprintf('%.0f', ((float)$msec + (float)$sec) * 1000);
    }

    /**
     * 获取版本
     * @return string
     */
    public function getVersion()
    {
        return self::APP_VERSION;
    }
}