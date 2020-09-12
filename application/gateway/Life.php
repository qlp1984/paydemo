<?php
/**
 * Class Index
 * @package app\gateway\controller
 */

namespace app\gateway\controller;

use lib\AopClient;
use lib\AlipayOpenPublicQrcodeCreateRequest;
use lib\alipay\AlipayF2FPayService;

class Life extends GatewayBase
{

    public static function sendPostRequst($url, $data) {
        $postdata = http_build_query ( $data );
        $opts = array (
            'http' => array (
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => $postdata
            )
        );

        $context = stream_context_create ( $opts );

        $result = file_get_contents ( $url, false, $context );
        return $result;
    }

    public static function getRequest($key) {
        $request = null;
        if (isset ( $_GET [$key] ) && ! empty ( $_GET [$key] )) {
            $request = $_GET [$key];
        } elseif (isset ( $_POST [$key] ) && ! empty ( $_POST [$key] )) {
            $request = $_POST [$key];
        }
        return $request;
    }

    /**
     * 接收主的
     */
    public function gateway() {
        header ( "Content-type: text/html; charset=gbk" );

        if (get_magic_quotes_gpc ()) {
            foreach ( $_POST as $key => $value ) {
                $_POST [$key] = stripslashes ( $value );
            }
            foreach ( $_GET as $key => $value ) {
                $_GET [$key] = stripslashes ( $value );
            }
            foreach ( $_REQUEST as $key => $value ) {
                $_REQUEST [$key] = stripslashes ( $value );
            }
        }
//        file_put_contents('3.log',var_export ( $_POST, true ));

        $sign = self::getRequest ( "sign" );
        $sign_type = self::getRequest ( "sign_type" );
        $biz_content = self::getRequest ( "biz_content" );
        $service = self::getRequest ( "service" );
        $charset = self::getRequest ( "charset" );

        if (empty ( $sign ) || empty ( $sign_type ) || empty ( $biz_content ) || empty ( $service ) || empty ( $charset )) {
            echo "some parameter is empty.";
            file_put_contents('3.log','some parameter is empty');
            exit ();
        }

        // 收到请求，先验证签名
        $as = new AopClient();
        $as->alipayrsaPublicKey=config('alipay_config.alipay_public_key');
        $sign_verify = $as->rsaCheckV2 ( $_REQUEST, config('alipay_config.alipay_public_key') ,config('alipay_config.sign_type'));

//        if (! $sign_verify) {
//            file_put_contents('5.log','sign qianming verfiy fail');
//            // 如果验证网关时，请求参数签名失败，则按照标准格式返回，方便在服务窗后台查看。
//            if (HttpRequest::getRequest ( "service" ) == "alipay.service.check") {
//                file_put_contents('5.log','sign qianming verfiy fail');
//            } else {
//                file_put_contents('5.log','sign qianming verfiy fail');
//            }
//            exit ();
//        }

        // 验证网关请求
        if (self::getRequest ( "service" ) == "alipay.service.check") {

            $this->verifygw(true);
            file_put_contents('3.log','some parameter is empty11111');
        } else if (self::getRequest ( "service" ) == "alipay.mobile.public.message.notify") {
            // 处理收到的消息
//            require_once 'Message.php';
//            $msg = new Message ( $biz_content );
        }


    }

    /**
     * 生活号产二维码
     */
    public function productionCode($url="http://www.baidu.com"){
        $aop = new AopClient ();
        $aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
        $aop->appId = config('alipay_config.app_id');
        $aop->rsaPrivateKey = config('alipay_config.merchant_private_key');
        $aop->alipayrsaPublicKey= config('alipay_config.alipay_public_key');
        $aop->apiVersion = '1.0';
        $aop->signType = 'RSA2';
        $aop->postCharset='UTF-8';
        $aop->format='json';
        $request = new AlipayOpenPublicQrcodeCreateRequest ();
        $request->setBizContent("{" .
            "\"code_info\":{" .
            "\"scene\":{" .
            "\"scene_id\":\"1234\"" .
            "      }," .
            "\"goto_url\":\"$url\"" .
            "    }," .
            "\"code_type\":\"TEMP\"," .
            "\"expire_second\":\"600\"," .
            "\"show_logo\":\"N\"" .
            "  }");
        $result = $aop->execute ( $request);

        $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
        $resultCode = $result->$responseNode->code;
        $resultUrl= $result->$responseNode->code_img;
        if(!empty($resultCode)&&$resultCode == 10000){
            $str = $resultUrl;
            $str1 = strstr($str,'=');
            $str2 = strstr($str1,'&picSize',true);
            $str3 = 'HTTPS://QR.ALIPAY.COM/'.str_replace('=','',$str2);

            return ['code'=>1,'url'=>$resultUrl,'str'=>$str3];
//            echo "成功";
//            file_put_contents('3.log',var_export ( $result, true ));
        } else {
            return ['code'=>-1,'url'=>'','str'=>''];
//            file_put_contents('3.log',var_export ( $result, true ));
//            echo "失败";
        }
    }


    /**
     * 验证网关
     */
    public function verifygw() {
        $biz_content = self::getRequest ("biz_content");

        $disableLibxmlEntityLoader = libxml_disable_entity_loader(true);
        $xml = simplexml_load_string ( $biz_content );
        libxml_disable_entity_loader($disableLibxmlEntityLoader);
        // print_r($xml);
        $EventType = ( string ) $xml->EventType;
        // echo $EventType;
        if ($EventType == "verifygw") {

            $as = new AopClient();
            $as->rsaPrivateKey=config('alipay_config.merchant_private_key');

            $response_xml = "<biz_content>" . config('alipay_config.merchant_public_key') . "</biz_content><success>true</success>";

            $mysign=$as->alonersaSign($response_xml,config('alipay_config.merchant_private_key'),config('alipay_config.sign_type'));
            $return_xml = "<?xml version=\"1.0\" encoding=\"".config('alipay_config.charset')."\"?><alipay><response>".$response_xml."</response><sign>".$mysign."</sign><sign_type>".config('alipay_config.sign_type')."</sign_type></alipay>";

            file_put_contents('2.log',$return_xml);
            echo $return_xml;
            exit ();
        }

    }

    public function alipayF2FPay($orderNo,$orderInfo,$apiData){
        /*** 获取通道的签名参数 end ***/

        $f2fpayService=new AlipayF2FPayService();


        /*** 配置开始 ***/
        $f2fpayService->setAppid($apiData['appid']);//https://open.alipay.com 账户中心->密钥管理->开放平台密钥，填写添加了电脑网站支付的应用的APPID
        $f2fpayService->setNotifyUrl(url("gateway/service/f2fpay")); //付款成功后的异步回调地址
        //商户私钥，填写对应签名算法类型的私钥，如何生成密钥参考：https://docs.open.alipay.com/291/105971和https://docs.open.alipay.com/200/105310
        $f2fpayService->setRsaPrivateKey($apiData['privateKey']);
        $f2fpayService->setTotalFee($orderInfo['money']);//付款金额，单位:元
        $f2fpayService->setOutTradeNo($orderNo);//你自己的商品订单号，不能重复
        $f2fpayService->setOrderName('标题-'.$orderNo);//订单标题
        /*** 配置结束 ***/

        //var_dump($f2fpayService);die;
        //调用接口
        $result = $f2fpayService->doPay();
        $result = $result['alipay_trade_precreate_response'];
        //var_dump($result);die;

        if($result['code'] && $result['code']=='10000') {
            //修改订单状态
            model('PayOrders')->update(['qrcode'=>$result['qr_code'],'status'=>2],['order_no'=>$orderNo,'status'=>1]);
            //trace(print_r($result,true),$orderNo);
            return ['code'=>1,'msg'=>'请求成功','result'=>print_r($result,true)];
        }else{
            trace(print_r($result,true),$orderNo.'error');
            model('PayOrders')->update(['err_msg'=>print_r($result,true)],['order_no'=>$orderNo,'status'=>1]);
        }
        return ['code'=>0,'msg'=>'请求失败','result'=>print_r($result,true)];
    }

}
