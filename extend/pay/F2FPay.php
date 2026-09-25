<?php
namespace pay;

/**
 * 支付宝当面付（f2fpay）API 客户端
 * 用于开放 API 的余额充值下单：发起预下单并返回二维码支付链接（qr_code）
 */
class F2FPay
{
    private $appId;
    private $rsaPrivateKey;
    private $charset = 'utf-8';

    function __construct($config)
    {
        $this->appId = $config['appid'];
        $this->rsaPrivateKey = $config['rsa_private_key'];
    }

    /**
     * 预下单（alipay.trade.precreate）
     * @param string $outTradeNo 商户订单号
     * @param string $totalFee   金额（元）
     * @param string $subject    订单标题
     * @param string $notifyUrl  异步回调地址
     * @return array ['code'=>1,'qr_code'=>...] 或 ['code'=>-1,'msg'=>...]
     */
    public function precreate($outTradeNo, $totalFee, $subject, $notifyUrl)
    {
        try {
            $requestConfigs = array(
                'out_trade_no'   => $outTradeNo,
                'total_amount'   => $totalFee, // 单位 元
                'subject'        => $subject,  // 订单标题
                'timeout_express'=> '2h',
            );
            $commonConfigs = array(
                'app_id'      => $this->appId,
                'method'      => 'alipay.trade.precreate',
                'format'      => 'JSON',
                'charset'     => $this->charset,
                'sign_type'   => 'RSA2',
                'timestamp'   => date('Y-m-d H:i:s'),
                'version'     => '1.0',
                'notify_url'  => $notifyUrl,
                'biz_content' => json_encode($requestConfigs),
            );
            $commonConfigs['sign'] = $this->generateSign($commonConfigs, $commonConfigs['sign_type']);
            $result = $this->curlPost('https://openapi.alipay.com/gateway.do?charset=' . $this->charset, $commonConfigs);
            $arr = json_decode($result, true);
            if (!is_array($arr)) {
                return ['code' => -1, 'msg' => '支付宝接口返回异常：' . mb_substr((string)$result, 0, 200)];
            }
            $resp = isset($arr['alipay_trade_precreate_response']) ? $arr['alipay_trade_precreate_response'] : $arr;
            if (isset($resp['code']) && (string)$resp['code'] === '10000') {
                return ['code' => 1, 'qr_code' => $resp['qr_code']];
            }
            $msg = isset($resp['msg']) ? $resp['msg'] : '未知错误';
            $subMsg = isset($resp['sub_msg']) ? $resp['sub_msg'] : '';
            return ['code' => -1, 'msg' => '支付宝下单失败：' . $msg . ($subMsg ? '（' . $subMsg . '）' : '')];
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '支付宝下单异常：' . $e->getMessage()];
        }
    }

    private function generateSign($params, $signType = 'RSA2')
    {
        $priKey = $this->rsaPrivateKey;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        if ('RSA2' == $signType) {
            openssl_sign($this->getSignContent($params), $sign, $res, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($this->getSignContent($params), $sign, $res);
        }
        return base64_encode($sign);
    }

    private function checkEmpty($value)
    {
        if (!isset($value)) return true;
        if (trim($value) === '') return true;
        return false;
    }

    private function getSignContent($params)
    {
        ksort($params);
        $stringToBeSigned = '';
        $i = 0;
        foreach ($params as $k => $v) {
            if (false === $this->checkEmpty($v) && '@' != substr($v, 0, 1)) {
                if ($i == 0) {
                    $stringToBeSigned .= $k . '=' . $v;
                } else {
                    $stringToBeSigned .= '&' . $k . '=' . $v;
                }
                $i++;
            }
        }
        unset($k, $v);
        return $stringToBeSigned;
    }

    private function curlPost($url = '', $postData = '')
    {
        if (is_array($postData)) {
            $postData = http_build_query($postData);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
}
