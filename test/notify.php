<?php
require '../vendor/autoload.php';

$originData = file_get_contents("php://input");
// 建议记录原始数据便于校对
$originJsonData = json_decode($originData, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    exit(responseJson([], 406));
}

if (!isset($originJsonData['notification_type']) || empty($originJsonData['notification_type'])) {
    exit(responseJson([], 406));
}
$isSandbox = (isset($originJsonData['environment']) && ($originJsonData['environment'] == 'Sandbox'));

/**
 * 以下业务逻辑根据自己的业务自行调整，可以同步处理或者异步处理
 *  通知类型 @url https://developer.apple.com/documentation/appstoreservernotifications/notification_type
 *  CANCEL：取消了订阅
 *  DID_RENEW: 指示客户的订阅已成功成功自动续订了一个新的交易时段
 *  DID_CHANGE_RENEWAL_PREF：取消了订阅,在sandbox测试时,6次收据之后,就会变成这个状态
 *  DID_CHANGE_RENEWAL_STATUS：是各种状态改变下都会调用,比如说首次购买会收到两次回调,一个是INITIAL_BUY一个是DID_CHANGE_RENEWAL_STATUS
 *  DID_FAIL_TO_RENEW：由于结算问题，订阅无法续订
 *  DID_RECOVER：App Store通过计费重试恢复了已过期的订阅
 *  INITIAL_BUY：首次订阅成功
 *  INTERACTIVE_RENEWAL：恢复了订阅,在sandbox测试时,6次收据之后,重新订阅,就会是这个状态
 *  RENEWAL：恢复了订阅
 *  REFUND：AppleCare成功退还了有关消耗性，非消耗性或非续订订阅的交易
 *  部分通知整理 EVENT.json 文件
 */
switch ($originJsonData['notification_type']) {
    case 'INITIAL_BUY':
        /**
         * 首次购买订阅。将其latest_receipt作为令牌存储在您的服务器上，以便随时通过App Store进行验证来验证用户的订阅状态。
         * $latest_receipt 储存至数据库
         * 在latest_receipt_info数据中， 首次购买： transaction_id 和 original_transaction_id 是一致的
         */
        $latestReceipt = $originJsonData['unified_receipt']['latest_receipt'];
        $latestReceiptInfo = $originJsonData['unified_receipt']['latest_receipt_info'];
        // 推送的订阅数据与本地服务器储存的订单数据对比检查
        break;
    case 'CANCEL':
        /**
         * 表示Apple客户支持取消了自动更新订阅，或者用户升级了他们的自动更新订阅。该密钥包含的变化的日期和时间。cancellation_date
         */
        $latestReceipt = $originJsonData['unified_receipt']['latest_receipt'];
        $latestReceiptInfo = $originJsonData['unified_receipt']['latest_receipt_info'];
        //记录日志即可,标识用户取消了订阅 auto_renew_status:false, 可尝试挽留或激励策略
        foreach ($latestReceiptInfo as $v) {
            /**
             * cancellation_date_ms         取消时间(ms)
             * original_transaction_id      原始交易ID 通过此ID 可以找到原始订单数据以及关联用户数据
             * transaction_id               交易ID
             * product_id                   产品ID
             */
        }
        break;
    case 'RENEWAL':
    case 'DID_RECOVER':
    case 'INTERACTIVE_RENEWAL':
        /**
         *  RENEWAL                 自2021年3月10日起，该通知将不再在生产环境和沙箱环境中发送。更新现有代码以改为依赖于通知类型。DID_RECOVER
         *  INTERACTIVE_RENEWAL     指示客户使用您的应用程序界面或在该帐户的“订阅”设置中的App Store上以交互方式续订订阅。立即提供服务。
         */
        $latestReceipt = $originJsonData['unified_receipt']['latest_receipt'];
        $latestReceiptInfo = $originJsonData['unified_receipt']['latest_receipt_info'];
        // 已过期订阅的自动续订成功，一一对比本地数据库
        foreach ($latestReceiptInfo as $v) {
            /**
             * cancellation_date_ms         取消时间(ms)
             * original_transaction_id      原始交易ID 通过此ID 可以找到原始订单数据
             * transaction_id               交易ID
             * product_id                   产品ID
             */
        }
        break;
    case 'DID_CHANGE_RENEWAL_STATUS':
        /**
         * 触发条件:
         *      订阅处于活动状态；客户升级到另一个SKU
         *      订阅已过期；客户重新订阅了相同的SKU
         *      订阅已过期；客户重新订阅了另一个SKU（升级或降级）
         *      客户从“ App Store订阅”设置页面取消了订阅。他们的订阅不会自动续订，并且将在expires_date
         *      客户以前取消了订阅，但现在在订阅到期之前重新订阅了同一产品。订阅将在expires_date
         *      AppleCare退还了订阅
         *      失败的帐单重试尝试后，订阅失效
         * @url https://developer.apple.com/cn/documentation/storekit/in-app_purchase/subscriptions_and_offers/implementing_subscription_offers_in_your_app/
         * DID_CHANGE_RENEWAL_STATUS 订阅续订状态的更改。在JSON响应中，检查以了解上一次状态更新的日期和时间。检查以了解当前的续订状态。auto_renew_status_change_date_ms和auto_renew_status字段
         */
        $latestReceipt = $originJsonData['unified_receipt']['latest_receipt'];
        $latestReceiptInfo = $originJsonData['unified_receipt']['latest_receipt_info'];
        // 订阅续订状态的更改 检测 auto_renew_status_change_date_ms和auto_renew_status字段
        break;
    case 'DID_FAIL_TO_RENEW':
        /**
         * 表示由于计费问题而无法续订的订阅。 检查is_in_billing_retry_period以了解订阅的当前重试状态。 如果订阅处于计费宽限期内，请检查grace_period_expires_date以了解新服务的到期日期。
         */
        $latestReceipt = $originJsonData['unified_receipt']['latest_receipt'];
        $latestReceiptInfo = $originJsonData['unified_receipt']['latest_receipt_info'];
        // 订阅续订状态的更改 检测 auto_renew_status_change_date_ms和auto_renew_status字段
        foreach ($latestReceiptInfo as $v) {
            /**
             * cancellation_date_ms         取消时间(ms)
             * original_transaction_id      原始交易ID 通过此ID 可以找到原始订单数据
             * transaction_id               交易ID
             * product_id                   产品ID
             */
        }
        break;
    case 'DID_CHANGE_RENEWAL_PREF':// 客户更改了在下次订购续订时会影响的计划。当前活动计划不受影响。
        break;
}


function responseJson($data = [], $httpCode = 200)
{
    header('Content-Type:application/json');
    http_response_code($httpCode);
    $data = (is_array($data) && empty($data)) ? new \stdClass() : $data;
    echo json_encode($data);
}