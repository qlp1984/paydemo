<?php

namespace app\gateway\controller;

use app\socket\controller\Api;
use lib\AlipayService;
use think\Exception;
use think\Request;
use think\Session;
use lib\Http;
use lib\alipay\AlipayWapService;
use lib\pooul\HtmlSubmit;
use lib\pooul\JwtSerice;
use lib\pinduoduo\Pinduoduo;
use app\common\service\Account;
use app\common\service\Order;
use app\common\service\RedisService;
use app\common\service\LaoNiuYun;
use app\common\service\Crypt;
use lib\mengpay\MengPay;
use lib\yijiujinfu\Yijiujinfu;
use lib\jys\Jys;
use lib\ksPDD\KsPDD;

/**
 * Class Index
 * @package app\gateway\controller
 */
class Pay extends GatewayBase
{

    public function index()
    {
        $view_data = [
            'name' => 'member',
        ];

        return view('index/index', $view_data);
    }

    public function test()
    {
        $key = urlencode(encrypt('C212561798156030'));
        $code = 'alipay';
        $url = "http://niu.com/gateway/pay/payOrder?key={$key}&code={$code}";
        header("Location:{$url}");
        exit;
    }

    /**
     * 服务版  JH支付 <8582849084774@qq.com>
     * @return \think\response\View
     */
    public function payOrder()
    {
        $input = input();
        $key = $input['key'];
        $orderNo = isset($input['order_no'])?$input['order_no']:'1';

        if ( empty($key) && strlen($orderNo) > 6 ){
            return json(['code' => -1, 'msg' => '请求出错！']);
        }

        $order_no = decrypt($key);

        if ( strlen($orderNo) > 6 ){
            $order_no = $orderNo;
        }

        $orderSInfo = model('PayOrders')->where(['order_no' => $order_no])->find();

        if(!$orderSInfo || empty($orderSInfo)){
            return json(['code' => -1, 'msg' => '订单不存在']);
        }

        // 获取数据库的授权配置
        $authorizationUrlData = getAuthorizationUrl();

        if ( $authorizationUrlData['code'] < 0 ){
            $url = url('gateway/pay/payOrderTwo', ['key'=>$key,'order_no' => $order_no]);
        } else {
            $url = $authorizationUrlData['url'].'/pay/payOrderTwo?key='.$key.'&order_no='.$order_no;
        }

        header("Location:" . $url);
    }

    /**
     * 服务版  JH支付 <8582849084774@qq.com>
     * @return \think\response\View
     */
    public function payOrderTwo()
    {
        $input = input();
        $key = $input['key'];
        $orderNo = isset($input['order_no'])?$input['order_no']:'1';

        if ( empty($key) && strlen($orderNo) > 6 ){
            return json(['code' => -1, 'msg' => '请求出错！']);
        }

        $order_no = decrypt($key);

        if ( strlen($orderNo) > 6 ){
            $order_no = $orderNo;
        }

        $channel_id = model('PayOrders')->where(['order_no' => $order_no])->value('channel_id');

        $orderSInfo = model('PayOrders')->where(['order_no' => $order_no])->find();

        if(!$orderSInfo || empty($orderSInfo)){
            return json(['code' => -1, 'msg' => '订单不存在']);
        }

        //-------银行卡转银行卡------------
        if( (in_array( $channel_id,[42] ) && $orderSInfo['merchant_id']==0 && $orderSInfo['merchant_account_id']==0 && $orderSInfo['order_status']==2) ||  (in_array( $channel_id,[42] ) &&  $orderSInfo['order_status']==1 && empty($orderSInfo['bank_desc']))){

            $return = $this->BankCardTransferBankCard($orderSInfo,$key,$orderSInfo['order_status']);
            if(is_array($return) && isset($return['view']) && $return['view']==1){
                return view("pay/bankCodeSelect", $return['data']);
            }else{
                return $return;
            }
        }
        //-------银行卡转银行卡------------


        //客户定制拼多多接口
        if( in_array( $channel_id,[21] ) ){
            $codeName = 'pinduoduo';
        }else{
            $codeName = model('channels')->where(['id' => $channel_id])->value('code_name');
        }

        $suffix = Request::instance()->isMobile() ? 'mobile' : 'pc';
        $data = [];
        $deadline_time = model('PayOrders')->where(['order_no' => $order_no])->value('deadline_time');
        $data['amount'] = model('PayOrders')->where(['order_no' => $order_no])->value('amount');
        $data['money'] = model('PayOrders')->where(['order_no' => $order_no])->value('money');
        $merchantAccountId = model('PayOrders')->where(['order_no' => $order_no])->value('merchant_account_id');
        $data['userId'] = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('alipay_user_id');
        $data['receipt_name'] = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('receipt_name');
        $userId = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('alipay_user_id');
        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openAlipay', ['order_no' => $order_no]);
        $surname = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('surname');;
        $bankData = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->field('bank_user,receipt_name,bank_name')->find();

        if (!Session::has('state') && $suffix == 'mobile' ) {
            Session::set('state', 1);
            $url = url('gateway/pay/payOrder',['key'=>$key]);
            return view("pay/mobile/mobile", ['url'=>$url,'key'=>$key]);
        }

        // 获取数据库的授权配置
        $authorizationUrlData = getAuthorizationUrl();

        $orderTime = $deadline_time - time();

        if (time() > $deadline_time) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        Session::delete('state');

        // 判断是否启用了搜索码
        if ( ($channel_id == 4 || $channel_id == 6) && $suffix == 'mobile' ){
            $searchType = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('is_alipay_account');

            // 启用
            if ( $searchType == 1 ){
                $userId = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('alipay_user_id');
                $merchant_id = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('merchant_id');
                $alipayUserId = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->field('receipt_name,alipay_user_id,bank_user,bank_name,bank_desc,channel_id,card_id,account_no,unit_id')->find();
                if ( $channel_id == 6 ){
                    $paytype = "alipays://platformapi/startapp?appId=20000123&actionType=scan&biz_data={\"s\": \"money\",\"u\": \"".$userId."\",\"a\": \"".$data['money']."\"}&t=".rand(0,999).time();
                } else {
                    $baseUrl = "alipays://platformapi/startapp?appId=09999988&actionType=toCard&ap_framework_sceneId=20000067&";
                    $customUrl = "bankAccount=". $alipayUserId['bank_user'] ."&cardNo=请勿修改金额,2分钟内到账****&bankName=". $alipayUserId['bank_user'] ."&bankMark=". $alipayUserId['bank_desc'] ."&cardIndex=". $alipayUserId['card_id'] ."&cardChannel=HISTORY_CARD&cardNoHidden=true&money=" .$data['money'] . "&amount=" .$data['money'] ."&REALLY_STARTAPP=true&startFromExternal=false&from=mobile";
                    $paytype = $baseUrl .$customUrl;
                }

                $res = (new Api())->getSearchCode($merchant_id, $order_no, $paytype);

                if ( $res['code'] == 1 ){
                    return view("pay/mobile/search_code", ['money' => $data['money'],'order_no' => $order_no]);
                }
            }
        }

        $data['order_no'] = $order_no;

        //
        switch ($codeName) {
            //ksYasewang 快闪客户定制
            case 'ksYasewang':
                $return = $this->ksYasewang($order_no);
                // $data['money'] = $return['data']['money'];
                if($return['code']!=1) return json($return);
                //header("Location:{$return['h5_url']}");
                break;
            //互站定制对接接口
            case 'HZHkerPay':
                $return = $this->HZHkerPay($order_no,1);
                // $data['money'] = $return['data']['money'];
                if($return['code']!=1) return json($return);
                //header("Location:{$return['h5_url']}");
                break;
            //环游定制对接接口（没给钱）
            case 'HyAlipayH5':
                $return = $this->HyAlipayH5($order_no,1);
                // $data['money'] = $return['data']['money'];
                if($return['code']!=1) return json($return);
                //header("Location:{$return['h5_url']}");
                break;
            case 'HyPddWechantH5':
                $return = $this->HyAlipayH5($order_no,2);
                // $data['money'] = $return['data']['money'];
                if($return['code']!=1) return json($return);
                //header("Location:{$return['h5_url']}");
                break;
            //快闪拼多多(快闪客户定制原生接口)
            case 'ksPDDWechat':
                $return = $this->ksPDD($order_no,2);
                // $data['money'] = $return['data']['money'];
                if($return['code']!=1) return json($return);
                //header("Location:{$return['h5_url']}");
                break;
            case 'ksPDDAlipay':
                $return = $this->ksPDD($order_no,1);
                if($return['code']!=1) return json($return);
                //header("Location:{$return['h5_url']}");
                // $data['money'] = $return['data']['money'];
                break;
            //jys188扫码(快闪客户定制原生接口)
            case 'jys188':
                $return = $this->jys188($order_no);
                $data['money'] = $return['data']['money'];
                break;
            //壹玖智能扫码(快闪客户定制原生接口)
            case 'yijiujinfu':
                $this->yijiujinfu($order_no);
                break;
            //梦支付一个快捷1218，一个网银1217
            case 'MengPayFast':
                echo $this->mengPay($order_no,1218);die;
                break;
            case 'MengPayBank':
                echo $this->mengPay($order_no,1217);die;
                break;
            case 'alipay_tra':
            case 'PersonalZhuanz':
                $url2222 = '{"s":"money","u":"'.$userId.'","a":"'.$data['money'].'","m":""}';
                $paytype =  'alipays://platformapi/startapp?appId=20000123&actionType=scan&biz_data='.$url2222;
                $dataJson['alipay'] = $paytype;
                $dataJson['yuming'] = url('gateway/index/demo');
                $data['alipay_tra'] =  (new Crypt())->encrypt(json_encode($dataJson));;
                break;
            case 'bank':
                $alipayUserId = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->field('receipt_name,alipay_user_id,bank_user,bank_name,bank_desc,channel_id,card_id,account_no,unit_id')->find();
                $baseUrl = "alipays://platformapi/startapp?appId=09999988&actionType=toCard&ap_framework_sceneId=20000067&";
                $customUrl = "bankAccount=". $alipayUserId['bank_user'] ."&cardNo=请勿修改金额,2分钟内到账****&bankName=". $alipayUserId['bank_user'] ."&bankMark=". $alipayUserId['bank_desc'] ."&cardIndex=". $alipayUserId['card_id'] ."&cardChannel=HISTORY_CARD&cardNoHidden=true&money=" .$data['money'] . "&amount=" .$data['money'] ."&REALLY_STARTAPP=true&startFromExternal=false&from=mobile";
                $paytype = $baseUrl .$customUrl;
//                $paytype = 'alipays://platformapi/startapp?appId=%30%39%39%39%39%39%38%38&actionType=toCard&ap_framework_sceneId=20000067&bankAccount='.$alipayUserId['bank_user'].'&cardNo='.$alipayUserId['account_no'].'&bankName='.urlencode($alipayUserId['bank_name']).'&bankMark=' . $alipayUserId['bank_desc'] . '&money=' . $data['money'] .'&amount=' . $data['money'] .'&REALLY_STARTAPP=true&startFromExternal=false';
                $dataJson['alipay'] = $paytype;
                $dataJson['yuming'] = url('gateway/index/demo');
                $data['alipay_tra'] =  (new Crypt())->encrypt(json_encode($dataJson));
                break;
            case 'alipayVariableTransfer':
                $name = '请勿修改金额与备注,否则订单无效(865)-姓名'.$surname;
                $paytype = 'alipays://platformapi/startapp?appId=09999988&actionType=toAccount&goBack=NO&amount='.$data['money'].'&userId='.$userId.'&memo='.urlencode($name);
                $dataJson['alipay'] = $paytype;
                $dataJson['yuming'] = url('gateway/index/demo');
                $data['alipay_tra'] =  (new Crypt())->encrypt(json_encode($dataJson));
                break;
        }

        switch ($codeName) {
            case 'bubugaoBank':
                $this->bubugaoBank($order_no);
                return;
                break;
            case 'DianDianChong': // 点点虫
                $data['alipay_auth'] = url('gateway/pay/openDianDianChong', ['order_no' => $order_no]);
                $this->payDianDianChongDo($key);
                break;
            case 'alipay_red':  // 红包
                if ( $authorizationUrlData['code'] < 0 ){
                    $data['alipay_auth'] = url('gateway/pay/openRed', ['order_no' => $order_no]);
//                    $data['alipay_auth'] = url('gateway/pay/openAlipay', ['order_no' => $order_no]);
                    $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openAlipay', ['order_no' => $order_no]);
                } else {
                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openRed?order_no='.$order_no;;
//                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
                    $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;

                }
                break;
            case 'alipay_tra':  // 个人转账
                $data['alipay_auth'] = $paytype;
                if ( $authorizationUrlData['code'] < 0 ){
                    $data['alipay_auth'] = url('gateway/pay/openTra', ['order_no' => $order_no,'sign'=>1]);
                } else {
                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openTra?order_no='.$order_no.'&sign=1';
                }
                break;
            case 'PersonalZhuanz':  // 个人转账
                $data['alipay_auth'] = $paytype;
                if ( $authorizationUrlData['code'] < 0 ){
                    $data['alipay_auth'] = url('gateway/pay/openPersonalZhuanz', ['order_no' => $order_no,'sign'=>1]);
                } else {
                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openPersonalZhuanz?order_no='.$order_no.'&sign=1';
                }
                break;
            case 'bank':  // 银行卡
                if ( $authorizationUrlData['code'] < 0 ){
                    $data['alipay_auth'] = url('gateway/pay/openBank', ['order_no' => $order_no,'sign'=>1]);
                } else {
                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openBank?order_no='.$order_no.'&sign=1';
                }
                break;
            case 'alipayVariableTransfer':  // 支付宝可变转账
                if ( $authorizationUrlData['code'] < 0 ){
                    $data['alipay_auth'] = url('gateway/pay/openAlipayVariableTransfer', ['order_no' => $order_no,'sign'=>1]);
                } else {
                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openAlipayVariableTransfer?order_no='.$order_no.'&sign=1';
                }
                $data['alipay_auth'] = $paytype;
                break;
            case 'alipay_rec':  // 主动收款
                if ( $authorizationUrlData['code'] < 0 ){
                    $data['alipay_auth'] = url('gateway/pay/openAlipay', ['order_no' => $order_no]);
                    $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openAlipay', ['order_no' => $order_no]);
                } else {
                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
                    $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
                }
                break;
            case 'WangXin':  // 旺信
                $appid = config('alipay_auto.appid');
                $data['alipay_auth'] = "https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id={$appid}&scope=auth_base&redirect_uri=" . urlencode(url('gateway/pay/openWangXin', ['order_no' => $order_no, 'state' => 1]));
                break;
            case 'NailGroupCollection':  // 钉钉群收款

                $dngDingJson = model('PayOrders')->where(['order_no' => $order_no])->value('DingDingJson');
                if ( strlen($dngDingJson) < 10 ){
                    // 下发第二次
                    $this->getDingDingQunJson($order_no,$data['amount'],$data['receipt_name']);
                }
                $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openNailGroupCollection', ['order_no' => $order_no]);
                $appid = config('alipay_auto.appid');
                $data['alipay_auth'] = "https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id={$appid}&scope=auth_base&redirect_uri=" . urlencode(url('gateway/pay/openNailGroupCollection', ['order_no' => $order_no, 'state' => 1]));
                break;
            case 'DingDing':  // 钉钉群收款
                $appid = config('alipay_auto.appid');
                $data['alipay_auth'] = "https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id={$appid}&scope=auth_base&redirect_uri=" . urlencode(url('gateway/pay/openDingDing', ['order_no' => $order_no, 'state' => 1]));
                break;
            case 'MinimalistPayment':  // 支付宝可变转账
                if ( $authorizationUrlData['code'] < 0 ){
                    $data['alipay_auth'] = url('gateway/pay/openAlipay', ['order_no' => $order_no]);
                } else {
                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
                }
                break;
            case 'solidCode':  // 跑风固码
                $data['is_default'] = model('PayOrders')->where(['order_no' => $order_no])->value('is_default');
                break;
            case 'alipaySqueak':  // 支付宝吱口令
                $appid = config('alipay_auto.appid');
                $data['alipay_auth'] = "https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id={$appid}&scope=auth_base&redirect_uri=" . urlencode(url('gateway/pay/openAlipaySqueak', ['order_no' => $order_no, 'state' => 1]));
                break;
            case 'MicroChatRed':  // 微聊
                $appid = config('alipay_auto.appid');
                $data['alipay_auth'] = "https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id={$appid}&scope=auth_base&redirect_uri=" . urlencode(url('gateway/pay/openMicroChatRed', ['order_no' => $order_no, 'state' => 1]));
                break;
            case 'FlyChat':  // 飞聊
                if ( $authorizationUrlData['code'] < 0 ){
                    $data['alipay_auth'] = url('gateway/pay/openAlipay', ['order_no' => $order_no]);
                } else {
                    $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
                }
                break;
        }

        // 判断是否直接在支付宝内
        if( $suffix == 'mobile' && isInAlipayClient() == true && in_array($codeName, ['DingDing','alipay_red', 'alipay_rec', 'DianDianChong', 'alipay_tra', 'bank', 'WangXin']) ){
            Session::set('state', 1);
            switch ($codeName) {
                case 'alipay_rec':  // 主动收款
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openRec', ['order_no' => $order_no]))));
                    break;
                case 'DianDianChong': // 点点虫
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openDianDianChong', ['order_no' => $order_no]))));
                    break;
                case 'alipay_red':  // 红包
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openRed', ['order_no' => $order_no]))));
                    break;
                case 'alipay_tra':  // 个人转账
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openTra', ['order_no' => $order_no]))));
                    break;
                case 'bank':  // 银行卡
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openBank', ['order_no' => $order_no]))));
                    break;
                case 'WangXin':  // 旺信
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openWangXin', ['order_no' => $order_no]))));
                    break;
                case 'NailGroupCollection':  // 钉钉群收款
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openNailGroupCollection', ['order_no' => $order_no]))));
                    break;
                case 'DingDing':  // 钉钉红包
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openDingDing', ['order_no' => $order_no]))));
                    break;
                case 'alipayVariableTransfer':  // 支付宝可变转账
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openAlipayVariableTransfer', ['order_no' => $order_no]))));
                    break;
                case 'MicroChatRed':  // 微聊
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openMicroChatRed', ['order_no' => $order_no]))));
                    break;
                case 'FlyChat':  // 微聊
                    header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openFlyChat', ['order_no' => $order_no]))));
                    break;
            }
            exit;
        }

        //mobile 环境
        if ($suffix == 'mobile' ) {
            switch ($codeName) {
                case 'F2FPay':
                    $this->alipayF2FPay($order_no);
                    break;
                case 'alipayWap':  // 原生
                    $res = $this->alipayWap($order_no);
                    return json($res);
                    break;
                case 'pinduoduo':  // 签约 拼多多
                    $res = $this->aaPinduoduo($order_no);
                    return json($res);
                    break;

//                case 'bank':  // 银行卡
//                    $alipayUserId = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->field('receipt_name,alipay_user_id,bank_user,bank_name,bank_desc,channel_id,card_id,account_no')->find();
//                    $url = 'https://www.alipay.com/?from=pc&appId=20000116&actionType=toCard&cardNo='.$alipayUserId['receipt_name'].'&bankAccount='.urlencode($alipayUserId['bank_user']).'&money=' . $data['money'] .'&amount=' . $data['money'] .'&bankMark=' . urlencode($alipayUserId['bank_desc']) . '&bankName='.urlencode($alipayUserId['bank_name']) ;
//                    $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接: '.  $url;
//                    break;

                case 'alipayVariableTransfer':  // 个人转账可以修改金额的
//                    $data['s1'] = 'alipays://platformapi/startApp?appId=60000050&showToolBar=NO&showTitleBar=YES&waitRender=150&showLoading=YES&url=https%3a%2f%2frender.alipay.com%2fp%2fs%2fi%3fscheme%3dalipays%253a%252f%252fplatformapi%252fstartapp%253fsaId%253d66666722%2526url%253dalipays%25253a%25252f%25252fplatformapi%25252fstartapp%25253fappId%25253d20000167%252526targetAppId%25253dback%252526tUserId%25253d'.$data['userId'].'%252526tUserType%25253d1%252526tLoginId%25253d'.$data['receipt_name'].'%252526autoFillContent%25253d'.$data['money'].'%252526autoFillBiz%25253d'.$data['money'];
////                    if ( $authorizationUrlData['code'] < 0 ){
////                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openAlipay', ['order_no' => $order_no]);
////                    } else {
////                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
////                    }
//                    if ( $authorizationUrlData['code'] < 0 ){
//                        $payOpenUrl = url('gateway/pay/openAlipay', ['order_no' => $order_no]);
//                    } else {
//                        $payOpenUrl = $authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
//                    }
//                    if ( model('UrlRecords')->findUrl() == 'http://btc.hocan.cn' ){
//                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$payOpenUrl;
//                    } else {
//                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.model('UrlRecords')->findUrl().'/mian.php?url='. urlencode($payOpenUrl) ;
//                    }
                    if ( $authorizationUrlData['code'] < 0 ){
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openAlipayVariableTransfer', ['order_no' => $order_no,'sign'=>1]);
                    } else {
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$authorizationUrlData['url'].'/pay/openAlipayVariableTransfer?order_no='.$order_no.'&sign=>1';
                    }
                    $surname = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('surname');
                    break;
                case 'alipay_tra':
                    // 个人转账
                    if ( $authorizationUrlData['code'] < 0 ){
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openTra', ['order_no' => $order_no,'sign'=>1]);
                    } else {
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$authorizationUrlData['url'].'/pay/openRed?order_no='.$order_no.'&sign=>1';
                    }


                    break;
                case 'alipaySqueak':  // 支付宝吱口令
                    $accountData = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->field('surname,squeak')->find();
                    $url = $accountData['squeak'];
                    $surname = $accountData['surname'];
                    break;
                case 'MicroChatRed':  // 个人转账
                    $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openMicroChatRed', ['order_no' => $order_no]);
                    break;
                case 'bank':  // 银行卡
                    if ( $authorizationUrlData['code'] < 0 ){
                        $data['alipay_auth'] = url('gateway/pay/openBank', ['order_no' => $order_no]);
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openBank', ['order_no' => $order_no,'sign'=>1]);
                    } else {
                        $data['alipay_auth'] = $authorizationUrlData['url'].'/pay/openBank?order_no='.$order_no;
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$authorizationUrlData['url'].'/pay/openBank?order_no='.$order_no.'&sign=>1';
                    }
                    break;
                case 'alipay_rec':  // 主动收款
                case 'FlyChat':  // 飞聊
                    if ( $authorizationUrlData['code'] < 0 ){
                        $payOpenUrl = url('gateway/pay/openAlipay', ['order_no' => $order_no]);
                    } else {
                        $payOpenUrl = $authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
                    }
                    if ( model('UrlRecords')->findUrl() == 'http://btc.hocan.cn' ){
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$payOpenUrl;
                    } else {
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.model('UrlRecords')->findUrl().'/mian.php?url='. urlencode($payOpenUrl) ;
                    }
                    break;
                case 'NailGroupCollection':  // 钉钉群收款
                    $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openNailGroupCollection', ['order_no' => $order_no]);
                    break;
                case 'alipay_red':  // 红包
                    if ( $authorizationUrlData['code'] < 0 ){
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openRed', ['order_no' => $order_no]);
                    } else {
                        $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.$authorizationUrlData['url'].'/pay/openRed?order_no='.$order_no;
                    }
                    break;
            }
        }

        if ( $suffix == 'pc' ){
            // 获取手机型号
            $osData = $this->getOS();
            $osData['pay_type'] = 'pc';
            $osData['order_no'] = $order_no;
            $this->mobileModelRecord($osData);
        } else {
            // 获取手机型号
            $osData = $this->getOS();
            $osData['pay_type'] = 'mobile';
            $osData['order_no'] = $order_no;
            $this->mobileModelRecord($osData);
        }



        // 判断是否生活号跳转
        $isLifeCode = is_life_code();

        return view("pay/{$suffix}/" . $codeName, ['key' => $key, 'codeName' => $codeName, 'order' => $data,'url'=>$url,'surname'=>$surname,'isLifeCode'=>$isLifeCode['code'],'bankData'=>$bankData,'orderTime'=>$orderTime,'cardTreasure'=>cardTreasure()]);
    }

    /**
     *
     * 微信跑分固码
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openSolidCode(){
        $order_no = input('get.order_no/s');

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,merchant_account_id,money')->find();

        if ($orderStatus['status'] == 4 || $orderStatus['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        return view("pay/mobile/openSolidCode", ['order_no' => $order_no,'money'=>$orderStatus['money']]);
    }

    /**
     *
     * 飞聊
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openFlyChat()
    {
        $order_no = input('get.order_no/s');


        // 更新步骤 到支付页
        $this->updateRecStep($order_no,3);

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,merchant_account_id,money')->find();

        if ($orderStatus['status'] == 4 || $orderStatus['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $user_id = $this->getUserId();

        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

        if ($alipay_user_id) {
            if (!($user_id == $alipay_user_id)) {
                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
            }
        } else {
            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
        }

        if (isInAlipayClient()) {
            Session::delete('state');

            return view("pay/mobile/openFlyChat", ['order_no' => $order_no,'money'=>$orderStatus['money']]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /**
     *
     * 微聊
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openMicroChatRed()
    {
        $order_no = input('get.order_no/s');

        if (!Session::has('state') ) {
//            Session::set('state', 1);
//            header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openMicroChatRed', ['order_no' => $order_no]))));
//            exit;
        }

        // 更新步骤 到支付页
        $this->updateRecStep($order_no,3);

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,merchant_account_id,money')->find();

        if ($orderStatus['status'] == 4 || $orderStatus['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $user_id = $this->getUserId();

        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

        if ($alipay_user_id) {
            if (!($user_id == $alipay_user_id)) {
                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
            }
        } else {
            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
        }

        if (isInAlipayClient()) {
            Session::delete('state');

            return view("pay/mobile/openMicroChatRed", ['order_no' => $order_no,'money'=>$orderStatus['money']]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /**
     * 测试
     * @return \think\response\View
     */
    public function openAlipayVariableTransferHeader()
    {
        $order_no = input('get.order_no/s');

        if (!Session::has('state') ) {
            Session::set('state', 1);
            header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openAlipayVariableTransferHeader', ['order_no' => $order_no]))));
            exit;
        }

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,merchant_account_id,money')->find();

        if ($orderStatus['status'] == 4 || $orderStatus['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $user_id = $this->getUserId();

        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

        if ($alipay_user_id) {
            if (!($user_id == $alipay_user_id)) {
                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
            }
        } else {
            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
        }

        if (isInAlipayClient()) {
            Session::delete('state');

            $accountData = model('MerchantsAccounts')->where(['id' => $orderStatus['merchant_account_id']])->field('surname,alipay_user_id')->find();

            return view("pay/mobile/openAlipayVariableTransferHeader", ['order_no' => $order_no,'surname'=>$accountData['surname'],'money'=>$orderStatus['money']]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);
    }

    /**
     *
     * 支付宝吱口令
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openAlipaySqueak()
    {
        $order_no = input('get.order_no/s');

        // 更新步骤 到支付页
        $this->updateRecStep($order_no,3);

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,merchant_account_id,money')->find();

        if ($orderStatus['status'] == 4 || $orderStatus['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $user_id = $this->getUserId();

        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

        if ($alipay_user_id) {
            if (!($user_id == $alipay_user_id)) {
                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
            }
        } else {
            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
        }

        if (isInAlipayClient()) {
            Session::delete('state');

            $accountData = model('MerchantsAccounts')->where(['id' => $orderStatus['merchant_account_id']])->field('surname,alipay_user_id')->find();

            return view("pay/mobile/openAlipaySqueak", ['order_no' => $order_no,'surname'=>$accountData['surname'],'money'=>$orderStatus['money']]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /**
     *
     * 支付宝可变转账
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openAlipayVariableTransfer()
    {
        $order_no = input('get.order_no/s');

        // 更新步骤
        //model('UrlRecords')->where('order_no',$order_no)->update(['step'=>3,'status'=>2]);

        // 更新步骤 到支付页
        $this->updateRecStep($order_no,3);

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,merchant_account_id,money')->find();

        if ($orderStatus['status'] == 4 || $orderStatus['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $user_id = $this->getUserId();

        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

        if ($alipay_user_id) {
            if (!($user_id == $alipay_user_id)) {
                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
            }
        } else {
            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
        }

        if (isInAlipayClient()) {
            Session::delete('state');

            $accountData = model('MerchantsAccounts')->where(['id' => $orderStatus['merchant_account_id']])->field('surname,alipay_user_id')->find();
//            $name = '请勿修改金额与备注,否则订单无效(865)-姓名'.$accountData['surname'];
//            $url = 'alipays://platformapi/startapp?appId=09999988&actionType=toAccount&goBack=NO&amount='.$orderStatus['money'].'&userId='.$accountData['alipay_user_id'].'&memo='.urlencode($name);
//
//            header("Location:" . $url);
            return view("pay/mobile/openAlipayVariableTransferScan", [ 'order_no' => $order_no,'money'=>$orderStatus['money'],'data'=>$accountData]);

            //return view("pay/mobile/openAlipayVariableTransfer", [ 'number'=>$number,'order_no' => $order_no,'surname'=>$accountData['surname'],'money'=>$orderStatus['money'],'qr'=>$qr]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /**
     *
     * 钉钉红包
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openDingDing()
    {

        $order_no = input('get.order_no/s');

        $sign = input('get.sign/s', 'false');

        if (!Session::has('state') && $sign == 'false' ) {
            Session::set('state', 1);
            header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openDingDing', ['order_no' => $order_no]))));
            exit;
        }

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');
        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        if (isInAlipayClient()) {
            Session::delete('state');
            $key = urlencode(encrypt($order_no));
            return view("pay/mobile/openDingDing", [ 'order_no' => $order_no,'key'=>$key]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /**
     *
     * 钉钉群收款
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openNailGroupCollection()
    {
        $order_no = input('get.order_no/s');

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');

        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        if ($orderStatus == 5) {
            return json(['code' => -1, 'msg' => '订单创建失败！请重新生成！']);
        }

        $dngDingJson = model('PayOrders')->where(['order_no' => $order_no])->value('DingDingJson');

        if ( strlen($dngDingJson) < 10 ){
            $amount = model('PayOrders')->where(['order_no' => $order_no])->value('amount');
            $merchantAccountId = model('PayOrders')->where(['order_no' => $order_no])->value('merchant_account_id');
            $receipt_name = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('receipt_name');

            // 下发第三次
            $this->getDingDingQunJson($order_no,$amount,$receipt_name);

            sleep(1);
            $dngDingJson = model('PayOrders')->where(['order_no' => $order_no])->value('DingDingJson');

            if ( strlen($dngDingJson) < 10 ){
                return json(['code' => -1, 'msg' => '订单创建失败！请重新生成！']);
            }
        }

        $url = url('gateway/pay/openNailGroupCollection', ['order_no' => $order_no]);

        if (isInAlipayClient()) {
            Session::delete('state');
            $key = urlencode(encrypt($order_no));
            return view("pay/mobile/openNailGroupCollection", [ 'order_no' => $order_no,'key'=>$key,'dngDingJson'=>$dngDingJson,'url'=>urlencode($url)]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /**
     *
     * 旺信
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openWangXin()
    {

        $order_no = input('get.order_no/s');

        $sign = input('get.sign/s', 'false');

        if (!Session::has('state') && $sign == 'false' ) {
            Session::set('state', 1);
            header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openWangXin', ['order_no' => $order_no]))));
            exit;
        }

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');
        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        if (isInAlipayClient()) {
            Session::delete('state');
            $key = urlencode(encrypt($order_no));
            return view("pay/mobile/openWangXin", [ 'order_no' => $order_no,'key'=>$key]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }


    /** 支付宝转银行卡
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openBank()
    {
        $order_no = input('get.order_no/s');
        $input = input();
        if ( isset($input['order_no']) ){
            $order_no = $input['order_no'];
        }

        // 更新步骤
//        model('UrlRecords')->where('order_no',$order_no)->update(['step'=>3,'status'=>2]);

        $sign = input('get.sign',false);

        if (isInAlipayClient()) {

            $orderService = new \app\common\service\Order();
            $order = $orderService->getOrderInfo($order_no);

            // 订单不存在
            if (empty($order)) return json(['code' => 40006, 'msg' => '订单不存在']);

            if ($order['status'] == 4 || $order['status']  == 3) {
                return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
            }

//            $user_id = $this->getUserId();
//
//            if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
//            $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');
//
//            if ($alipay_user_id) {
//                if (!($user_id == $alipay_user_id)) {
//                    return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
//                }
//            } else {
//                model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
//            }

            $order['deadline_time'] = $order['deadline_time'] - time();

            $alipayUserId = model('MerchantsAccounts')->where(['id' => $order['merchant_account_id']])->field('receipt_name,alipay_user_id,bank_user,bank_name,bank_desc,channel_id,card_id,account_no')->find();
            $alipayUserId['money'] = $order['money'];

            //$url = 'alipays://platformapi/startapp?appId=20000116&actionType=toCard&cardNo='.$alipayUserId['receipt_name'].'&bankAccount='.$alipayUserId['bank_user'].'&money=' . $order['money'] .'&amount=' . $order['money'] .'&bankMark=' . $alipayUserId['bank_desc'] . '&bankName='.$alipayUserId['bank_name'];
            //$url = 'alipays://platformapi/startapp?appId=09999988&t=' . (string)time() . '&actionType=toCard&sourceId=bill&cardNo=' . $alipayUserId['receipt_name'] . '&bankAccount=' . $alipayUserId['bank_user'] . '&money=' . $order['money'] . '&amount=' . $order['money'] . '&bankMark=' . $alipayUserId['bank_desc'] . '&bankName=' . $alipayUserId['bank_name'] . '&cardNoHidden=True&cardChannel=HISTORY_CARD&orderSource=from';


            $baseUrl = "alipays://platformapi/startapp?saId=09999988&clientVersion=10.1.60&actionType=toCard&ap_framework_sceneId=20000067&";
            $customUrl = "bankAccount=". $alipayUserId['bank_user'] ."&cardNo=请勿修改金额,2分钟内到账****&bankName=". $alipayUserId['bank_user'] ."&bankMark=". $alipayUserId['bank_desc'] ."&cardIndex=". $alipayUserId['card_id'] ."&cardChannel=HISTORY_CARD&cardNoHidden=true&money=" .$order['money'] . "&amount=" .$order['money'] ."&REALLY_STARTAPP=true&startFromExternal=false&from=mobile";

//            header("Location:" . "https://ds.alipay.com/?from=mobilecodec&scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . ($baseUrl.$customUrl)));

//            header("Location:" . "https://ds.alipay.com/?from=mobilecodec&scheme=" . urlencode($baseUrl.$customUrl));
//            header("Location:" . $baseUrl.$customUrl);
//            exit;


            if ( $sign == 1 ){
                return view("pay/mobile/openBankFlightPC", [ 'order_no' => $order_no,'money'=>$order['money'],'data'=>$alipayUserId]);
//                return view("pay/mobile/openBankScan", [ 'order_no' => $order_no,'money'=>$order['money'],'data'=>$alipayUserId]);
            } else {
                return view("pay/mobile/openBankFlightH5", [ 'order_no' => $order_no,'money'=>$order['money'],'data'=>$alipayUserId]);
//                return view("pay/mobile/openBank", [ 'order_no' => $order_no,'sign'=>$sign,'data'=>$alipayUserId]);
            }


        }
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /** 支付宝个人转账
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openTra()
    {
        $order_no = input('get.order_no/s');
        $sign = input('get.sign',false);

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,money,merchant_account_id')->find();
        if ($orderStatus['status'] == 4 || $orderStatus['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $alipayUserId = model('MerchantsAccounts')->where(['id' => $orderStatus['merchant_account_id']])->field('alipay_user_id')->find();

//        $user_id = $this->getUserId();
//
//        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
//        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');
//
//        if ($alipay_user_id) {
//            if (!($user_id == $alipay_user_id)) {
//                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
//            }
//        } else {
//            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
//        }

        if (isInAlipayClient()) {
            Session::delete('state');
            return view("pay/mobile/openTraFlight", [ 'order_no' => $order_no,'money'=>$orderStatus['money'],'alipayUserId'=>$alipayUserId['alipay_user_id']]);
//            if ( $sign == 1 ){
//                return view("pay/mobile/openTraScan", [ 'order_no' => $order_no,'money'=>$orderStatus['money']]);
//            } else {
//                return view("pay/mobile/openTra", [ 'order_no' => $order_no,'money'=>$orderStatus['money']]);
//            }
        }

        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /** 支付宝个人转账
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openPersonalZhuanz()
    {
        $order_no = input('get.order_no/s');
        $sign = input('get.sign',false);

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,money,merchant_account_id')->find();
        if ($orderStatus['status'] == 4 || $orderStatus['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $alipayUserId = model('MerchantsAccounts')->where(['id' => $orderStatus['merchant_account_id']])->field('alipay_user_id')->find();

//        $user_id = $this->getUserId();
//
//        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
//        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');
//
//        if ($alipay_user_id) {
//            if (!($user_id == $alipay_user_id)) {
//                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
//            }
//        } else {
//            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
//        }

        if (isInAlipayClient()) {
            Session::delete('state');
            return view("pay/mobile/openPersonalZhuanz", [ 'order_no' => $order_no,'money'=>$orderStatus['money'],'alipayUserId'=>$alipayUserId['alipay_user_id']]);

        }

        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /** 支付宝红包
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openRed()
    {
        $order_no = input('get.order_no/s');

        if (!Session::has('state') ) {
            Session::set('state', 1);
            header("Location:" . "https://ds.alipay.com/?from=mobilecodec&scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openRed', ['order_no' => $order_no]))));
            exit;
        }

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');
        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        if (isInAlipayClient()) {
            Session::delete('state');

//            $user_id = $this->getUserId();
//
//            if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
//            $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');
//
//            if ($alipay_user_id) {
//                if (!($user_id == $alipay_user_id)) {
//                    return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
//                }
//            } else {
//                model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
//            }

            //暂时无限发送指令
            $orderService = new \app\common\service\Order();
            $order = $orderService->getOrderInfo($order_no);
            $data = model('MerchantsAccounts')->where(['id' => $order['merchant_account_id']])->field('receipt_name,alipay_user_id')->find();

            return view("pay/mobile/openRedTwo", ['order' => $order, 'order_no' => $order_no, 'amount' => $order['amount'], 'data' => $data, 'status' => $order['status']]);
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);
    }

    /** 支付宝红包2
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openRedTwo()
    {
        $order_no = input('get.order_no/s');

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');
        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        if (isInAlipayClient()) {

            $user_id = $this->getUserId();

            if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
            $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

            if ($alipay_user_id) {
                if (!($user_id == $alipay_user_id)) {
                    return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
                }
            } else {
                model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
            }

            //暂时无限发送指令
            $orderService = new \app\common\service\Order();
            $order = $orderService->getOrderInfo($order_no);
            $data = model('MerchantsAccounts')->where(['id' => $order['merchant_account_id']])->field('receipt_name,alipay_user_id')->find();

            return view("pay/mobile/openRedTwo", ['order' => $order, 'order_no' => $order_no, 'amount' => $order['amount'], 'data' => $data, 'status' => $order['status']]);
        }

        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /** 支付宝主动收款
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */
    public function openRec()
    {
        $order_no = input('get.order_no/s');

        // 更新步骤
        model('UrlRecords')->where('order_no',$order_no)->update(['step'=>3,'status'=>2]);

        if (empty($order_no)) return json(['code' => -1, 'msg' => '订单号不能为空']);
        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');
        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        if (isInAlipayClient()) {
            $user_id = $this->getUserId();
            Session::delete('state');

            if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
            $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

            if ($alipay_user_id) {
                if (!($user_id == $alipay_user_id)) {
                    return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
                }
            } else {
                model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
            }

            // 更新步骤 跳到添加好友
            //$this->updateRecStep($order_no,2);

            //暂时无限发送指令
            $orderService = new \app\common\service\Order();
            //$orderService->editSendSocket($order);
            $order = $orderService->getOrderInfo($order_no);
            $data = model('MerchantsAccounts')->where(['id' => $order['merchant_account_id']])->field('receipt_name,alipay_user_id')->find();
            $data['user_alipay_id'] = $user_id;

            //发送socket指令
            //(new Api())->getTransferNo($data['receipt_name'], $order_no, $order['amount'], $user_id, 'alipay_rec');

            return view("pay/mobile/openRec", ['order' => $order, 'order_no' => $order_no, 'amount' => $order['amount'], 'data' => $data, 'status' => $order['status']]);

        }

        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);

    }

    /** 支付宝主动收款，。加好友跳转
     * @author xi 2019/3/11 18:29
     * @Note
     * @return \think\response\Json|\think\response\View
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function payRecDo()
    {

        $order_no = input('get.order_no/s');
        $order = model('PayOrders')->where(['order_no' => $order_no])->find();
        if ($order['status'] == 4 || $order['status'] == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        if (isInAlipayClient()) {
            // $order[] = ;
            return view("pay/index", ['data' => $order]);
        } else {
            return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);
        }

    }

    private function pay_alipay_rec($key, $order_no, $channel_id)
    {
        $data = [];
        if (in_array($channel_id, [5, 220]) && isInAlipayClient() == false) {

            // header("Location:" . "https://ds.alipay.com/?from=mobilecodec&scheme=" . urlencode("alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=" . url("gateway/pay/payOrder", ['key' => $key])));
            header("Location:" . 'alipays://platformapi/startapp?appId=20000691&t=' . (string)time() . '&url=' . url('gateway/pay/payOrder', ['key' => $key]));
            exit();
        }
        //获取用户信息并下发user_id
        if (isInAlipayClient()) {
            //获取支付宝用户授权
            $this->getUserId();
        }

        $orderService = new \app\common\service\Order();
        $order = $orderService->getOrderInfo($order_no, 'merchant_account_id');
        $data = model('MerchantsAccounts')->where(['id' => $order['merchant_account_id']])->field('receipt_name,alipay_user_id')->find();

        return $data;
    }


    /**
     *
     * 点点虫跳转地址
     *
     * @author xi 2019/3/1 14:38
     * @Note
     *
     * @param $key $order_no  $channel_id
     *
     * @return mixed
     */

    public function openDianDianChong()
    {
        $order_no = input('get.order_no/s');

        if (!Session::has('state')) {
            Session::set('state', 1);
            header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/openDianDianChong', ['order_no' => $order_no]))));
            exit;
        }

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');
        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }
        $key = urlencode(encrypt($order_no));
        if (isInAlipayClient()) {
            Session::delete('state');
            return view("pay/mobile/openDianDianChong", ['key' => $key, 'codeName' => 'openDianDianChong','order_no'=>$order_no]);
        }
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);
    }

    /**
     *
     * 获取点点虫跳转的路由
     *
     * @author xi 2019/3/1 14:31
     * @Note
     *
     * @param $key
     */
    public function payDianDianChongDo($key)
    {
        $key = input('get.key/s', '');
        $order_no = decrypt($key);
        if (empty($order_no)) return json(['code' => 40005, 'msg' => '订单号不正确']);

        // 查询订单
        $orderData = model('PayOrders')->where(['order_no' => $order_no])->field('amount,merchant_account_id,DingDingJson')->find();

        if (empty($orderData)) return json(['code' => 40005, 'msg' => '订单号存在']);

        // 是否已经有值了
        if ( strlen($orderData['DingDingJson']) < 5 ){
            // 点点虫token
            $diandian_token = model('MerchantsAccounts')->where(['id' => $orderData['merchant_account_id']])->value('diandian_token');

            $diandianTokenArr = explode("_", $diandian_token);

            // 第一次请求接口的参数
            $oneData['size'] ='1';
            $oneData['appkey'] ='21603258';
            $oneData['congratulations'] ='恭喜发财';
            $oneData['amount'] = $orderData['amount'];
            $oneData['_v_'] ='3';
            $oneData['t'] ='1553013482919';
            $oneData['imei'] ='111111111111111';
            $oneData['type'] ='0';
            $oneData['imsi'] = '111111111111111';
            $oneData['sender'] = $diandianTokenArr[(count($diandianTokenArr)-1)];
            $oneData['access_token'] =$diandian_token;

            $oneUrl = 'https://redenvelop.laiwang.com/v2/redenvelop/send/doGenerate';

            //第一次请求
            $resCurl = Http::sendRequest($oneUrl,$oneData);

            // 第一次的结果
            $oneResCurl = json_decode($resCurl['msg'],true);

            if ( isset($oneResCurl['businessId']) && !empty($oneResCurl['businessId']) ){

                $twoUrl = 'http://api.laiwang.com/v2/internal/act/alipaygift/getPayParams?tradeNo='.$oneResCurl['businessId'].'&bizType=biz_account_transfer&access_token='.$diandian_token;
                //第二次请求
                $twoResCurl = Http::get($twoUrl);

                // 第一次的结果
                $diandianJson = json_decode($twoResCurl,true);

                if ( empty($diandianJson['value']) ){
                    model('PayOrders')->where(['order_no' => $order_no])->update(['status'=>5,'err_msg'=>'点点虫跳转路径获取失败']);
                    return json(['code' => 400, 'msg' => '失败']);
                }

                model('PayOrders')->where(['order_no' => $order_no])->update(['DingDingJson'=>$diandianJson['value'],'clusterId'=>$oneResCurl['clusterId']]);
                return json(['code' => 200, 'msg' => $twoResCurl]);
            }

            return json(['code' => 404, 'msg' => '失败']);
        }
    }

    /** 获取点点虫订单成功
     * @author xi 2019/3/1 14:31
     * @Note
     *
     * @param $key
     */
    public function dianDianChongOrderSuccess($order_no)
    {
        $order_no = input('get.order_no/s', '');
        if (empty($order_no)) return json(['code' => 40005, 'msg' => '订单号不正确']);

        // 当前回话的IP
        $ip = isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'127.0.0.1';

        // 查询订单
        $orderData = model('PayOrders')->where(['order_no' => $order_no])->field('merchant_account_id,clusterId,channel_id,user_id,merchant_id,money,amount,deadline_time,obtain_dot_worm_result_number')->find();

        if (empty($orderData)) return json(['code' => 40005, 'msg' => '订单号不存在']);

        // 自动去获取结果的次数
        $number = $orderData['obtain_dot_worm_result_number']+1;

        // 判断订单超时
        if ( $orderData['deadline_time'] < (time()-1) ){
            // 插入记录
            $data['channel_id'] = $orderData['channel_id'];
            $data['merchant_id'] = $orderData['merchant_id'];
            $data['user_id'] = $orderData['user_id'];
            $data['merchant_account_id'] = $orderData['merchant_account_id'];
            $data['money'] = $orderData['money'];
            $data['order_no'] = $order_no;
            $data['callback_ip'] = $ip;
            $data['callback_source'] = 'pc';
            $data['mark'] = '订单超时';
            $data['create_time'] = time();

            // 无匹配订单
            model('PayNomatchOrders')->insert($data);

            // 更新次数
            model('PayOrders')->where(['order_no' => $order_no, 'status' => 2])->update(['obtain_dot_worm_result_number'=>5]);

            return json(['code' => 40005, 'msg' => '订单超时']);
        }

        // 判断点点虫的订单号
        if ( strlen($orderData['clusterId']) < 5 ){
            // 插入记录
            $data['channel_id'] = $orderData['channel_id'];
            $data['merchant_id'] = $orderData['merchant_id'];
            $data['user_id'] = $orderData['user_id'];
            $data['merchant_account_id'] = $orderData['merchant_account_id'];
            $data['money'] = $orderData['money'];
            $data['order_no'] = $order_no;
            $data['callback_source'] = 'pc';
            $data['callback_ip'] = $ip;
            $data['mark'] = '无匹配订单--点点虫的订单号错误';
            $data['create_time'] = time();

            // 无匹配订单
            model('PayNomatchOrders')->insert($data);

            // 更新次数
            model('PayOrders')->where(['order_no' => $order_no, 'status' => 2])->update(['obtain_dot_worm_result_number'=>5]);

            return json(['code' => 40005, 'msg' => '参数错误']);
        }

        // 查询通道是否存在
        $accountData = model('MerchantsAccounts')
            ->where(['id' => $orderData['merchant_account_id']])
            ->field('diandian_token')->find();

        // 判断通道是否存在
        if (empty($accountData)) {
            $data['channel_id'] = $orderData['channel_id'];
            $data['merchant_id'] = $orderData['merchant_id'];
            $data['user_id'] = $orderData['user_id'];
            $data['merchant_account_id'] = $orderData['merchant_account_id'];
            $data['money'] = $orderData['money'];
            $data['order_no'] = $order_no;
            $data['callback_source'] = 'pc';
            $data['callback_ip'] = $ip;
            $data['mark'] = '此通道不存在';
            $data['create_time'] = time();

            // 无匹配订单
            model('PayNomatchOrders')->insert($data);

            // 更新次数
            model('PayOrders')->where(['order_no' => $order_no, 'status' => 2])->update(['obtain_dot_worm_result_number'=>5]);

            return json(['code' => 40005, 'msg' => '通道不存在']);
        }

        // 拿红包结果的接口
        $oneUrl = 'https://redenvelop.laiwang.com/v2/redenvelop/send/doSend';

        // 获取点点虫账号的ID
        $diandianTokenArr = explode("_", $accountData['diandian_token']);

        // 拿红包结果的接口参数
        $oneData['clusterId'] = $orderData['clusterId'];
        $oneData['appkey'] = '21603258';
        $oneData['imei'] = '111111111111111';
        $oneData['type'] = 0;
        $oneData['imsi'] = '111111111111111';
        $oneData['sender'] = $diandianTokenArr[1];
        $oneData['t'] = '1553015551752';
        $oneData['access_token'] = $accountData['diandian_token'];

        //请求
        $res = Http::sendRequest($oneUrl,$oneData);

        // msg  是{}就是支付成功   有值则失败
        if ( $res['msg']== true && strlen($res['msg']) == 2 ){
            // 查看是否已经领取的红包
            $twoUrl = 'https://redenvelop.laiwang.com/v2/redenvelop/pick/doPick';

            // 拿红包结果的接口参数
            $twoData['clusterId'] =$orderData['clusterId'];
            $twoData['appkey'] ='21603258';
            $twoData['t'] ='1553015672266';
            $twoData['imei'] ='111111111111111';
            $twoData['imsi'] ='111111111111111';
            $twoData['_c_'] ='21750770';
            $twoData['_s_'] = '98298c4fe8e400c5293255fccbdb8ab1';
            $twoData['sender'] = $diandianTokenArr[1];
            $twoData['access_token'] =$accountData['diandian_token'];

            //请求
            $twoRes = Http::sendRequest($twoUrl,$twoData);

            // 最终的结果
            $finalResCurl = json_decode($twoRes['msg'],true);

            // 支付成功的
            if ( isset($finalResCurl['rEClusterWrapperVO']['rEClusterVO']) && $finalResCurl['rEClusterWrapperVO']['rEClusterVO']['clusterId'] == $orderData['clusterId'] && $finalResCurl['rEClusterWrapperVO']['rEClusterVO']['amount'] == $orderData['amount'] ){
                $oidData['status'] = 4;
                $oidData['pay_time'] = time();
                $oidData['pay_trade_no'] = $finalResCurl['rEClusterWrapperVO']['rEClusterVO']['alipayTradeNo'];
                $oidData['callback_ip'] = $ip;
                $oidData['callback_source'] = 'pc';

                // 更新
                model('PayOrders')->where(['order_no' => $order_no, 'status' => 2])->update($oidData);

                // 更新关闭通道列表
                model('AccountOffRecord')->reduceNumber($orderData['merchant_account_id']);

                return json(['code' => 200, 'msg' => 'ok']);
            }

            $data['channel_id'] = $orderData['channel_id'];
            $data['merchant_id'] = $orderData['merchant_id'];
            $data['user_id'] = $orderData['user_id'];
            $data['merchant_account_id'] = $orderData['merchant_account_id'];
            $data['money'] = $orderData['money'];
            $data['order_no'] = $order_no;
            $data['callback_source'] = 'pc';
            $data['callback_ip'] = $ip;
            $data['mark'] = '此订单:['.$order_no.']没有收到款';
            $data['create_time'] = time();

            // 无匹配订单
            model('PayNomatchOrders')->insert($data);

            // 更新次数
            model('PayOrders')->where(['order_no' => $order_no, 'status' => 2])->update(['obtain_dot_worm_result_number'=>$number]);

            return json(['code' => 40005, 'msg' => '没有收到款']);
        }

        $data['channel_id'] = $orderData['channel_id'];
        $data['merchant_id'] = $orderData['merchant_id'];
        $data['user_id'] = $orderData['user_id'];
        $data['merchant_account_id'] = $orderData['merchant_account_id'];
        $data['money'] = $orderData['money'];
        $data['order_no'] = $order_no;
        $data['callback_source'] = 'pc';
        $data['callback_ip'] = $ip;
        $data['mark'] = '此订单:['.$order_no.']没有支付';
        $data['create_time'] = time();

        // 无匹配订单
        model('PayNomatchOrders')->insert($data);

        // 更新次数
        model('PayOrders')->where(['order_no' => $order_no, 'status' => 2])->update(['obtain_dot_worm_result_number'=>$number]);

        return json(['code' => 40005, 'msg' => $res]);
    }


    /**
     * 获取订单信息
     * @author LvGang 2019/2/21 0021 18:27 JH支付 <85***82849084774@qq.com>
     * @return \think\response\Json
     */
    public function orderQuery()
    {
        // 所有人都可以访问
        //header("Access-Control-Allow-Origin:*");
        $order_no = input('get.order_no/s', '');
        if (empty($order_no)) {
            $key = input('get.key/s', '');
            if($key) $order_no = decrypt($key);
        }
        if (empty($order_no)) return json(['code' => 40005, 'msg' => '订单号不正确']);

        $orderService = new \app\common\service\Order();
        $order = $orderService->getOrderInfo($order_no);

        // 订单不存在
        if (empty($order)) return json(['code' => 40006, 'msg' => '订单不存在']);

        $order['deadline_time'] = $order['deadline_time'] - time();

        $alipayUserId = model('MerchantsAccounts')->where(['id' => $order['merchant_account_id']])->field('receipt_name,alipay_user_id,bank_user,bank_name,bank_desc,channel_id,card_id,account_no,unit_id')->find();

        $order['alipayUserId'] = $alipayUserId['alipay_user_id'];
        $order['receipt_name'] = $alipayUserId['receipt_name'];//账号


        //支付宝JH支付渠道
        switch ($alipayUserId['channel_id']) {
            //支付宝主动收款 -----------------------------
            case '5':
                $order['amount_a_order_no'] = $order['amount'] . 'a' . $order_no;
                $order['user_alipay_user_id'] = Session::get('alipay_user_id');
                break;
            //支付宝银行卡转账 $codeName == bank --------------------------------
            case '4':
                if ( !isset($key) ){
                    $key = urlencode(encrypt($order_no));
                }
                // $order['pay_url'] = 'alipays://platformapi/startapp?appId=%30%39%39%39%39%39%38%38&actionType=toCard&ap_framework_sceneId=20000067&bankAccount='.$alipayUserId['bank_user'].'&cardNo='.$alipayUserId['account_no'].'&bankName='.urlencode($alipayUserId['bank_name']).'&bankMark=' . $alipayUserId['bank_desc'] . '&money=' . $order['money'] .'&amount=' . $order['money'] .'&REALLY_STARTAPP=true&startFromExternal=false';
                // $order['pay_url'] = 'alipays://platformapi/startapp?appId=09999988&t=' . (string)time() . '&actionType=toCard&sourceId=bill&cardNo=' . $alipayUserId['receipt_name'] . '&bankAccount=' . $alipayUserId['bank_user'] . '&money=' . $order['money'] . '&amount=' . $order['money'] . '&bankMark=' . $alipayUserId['bank_desc'] . '&bankName=' . $alipayUserId['bank_name'] . '&cardNoHidden=True&cardChannel=HISTORY_CARD&orderSource=from';
                //$order['pay_url']  = 'alipays://platformapi/startapp?appId=20000116&actionType=toCard&cardNo='.$alipayUserId['receipt_name'].'&bankAccount='.$alipayUserId['bank_user'].'&money=' . $order['money'] .'&amount=' . $order['money'] .'&bankMark=' . $alipayUserId['bank_desc'] . '&bankName='.$alipayUserId['bank_name'];
                $order['pay_url'] = 'alipays://platformapi/startapp?appId=09999988&actionType=toCard&sourceId=bill&cardNo=' . $alipayUserId['receipt_name'] . '&bankAccount=' . $alipayUserId['bank_user'] . '&money=' . $order['money'] . '&amount=' . $order['money'] . '&bankMark=' . $alipayUserId['bank_desc'] . '&bankName=' . $alipayUserId['bank_name'] . '&cardNoHidden=True&cardChannel=HISTORY_CARD&orderSource=from&REALLY_STARTAPP=true&startFromExternal=false&ap_framework_sceneId=20000067';
                if (!Request::instance()->isMobile()) {
                    $order['pay_url'] = 'alipays://platformapi/startapp?appId=20000691&t=' . (string)time() . '&url=' . url('gateway/pay/payOrder', ['key' => $key]);
                }
                $order['qrcode_pay_url'] = 'alipays://platformapi/startapp?appId=20000691&t=' . (string)time() . '&url=' . url('gateway/pay/payOrder', ['key' => $key]);
                $order['bank_user'] = $alipayUserId['bank_user'];
                $order['bank_name'] = $alipayUserId['bank_name'];
                $order['bank_desc'] = $alipayUserId['bank_desc'];
                $order['receipt_name'] = $alipayUserId['receipt_name'];
                $order['card_id'] = $alipayUserId['card_id'];
                $order['account_no'] = $alipayUserId['account_no'];
                break;
            //支付宝个人转账 $codeName == bank --------------------------------
            case '6':
                $strNumber = strlen($order['order_no']);
                $order['order_no'] = substr($order['order_no'],0,5).'='.substr($order['order_no'],5,$strNumber);
                break;
            case '38':
                $praiseStatus = new \app\gateway\controller\Praise();
                $praiseStatus->getOrderStatus($order_no);
                break;
            case 42:
                $order['bank_desc'] = bankCardTreasure($order['bank_desc']);
                break;
        }


        $order['alipay_user_id'] = Session::get('alipay_user_id');

        if ( empty($order['alipay_user_id']) ){
            $order['alipay_user_id']  = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');
        }

        if (!is_array($order))
            json(['code' => -2, 'msg' => '订单交易不存在']);

        if ($order['status'] == 4){
            return json(['code' => 200, 'msg' => '支付成功', 'data' => $order]);
        }
        if ($order['status'] == 5){
            return json(['code' => -1, 'msg' => '订单创建失败！请重新创建', 'data' => $order]);
        }
        if ($order['deadline_time'] <= 0 && $order['status'] < 3 ) {
            (new Service())->upadteOrderStatus($alipayUserId['unit_id'],$order['id'], $order['merchant_id'], $order['amount']);

            return json(['code' => -1, 'msg' => '订单支付已超时', 'data' => $order]);
        }

        return json(['code' => 100, 'msg' => '获取成功', 'data' => $order]);

    }


    /** 下发支付宝收款订单号
     * JH支付 <2849084774***7906@qq.com>
     * @Note
     */
    public function getTransferNo()
    {

        $receipt_name = input('post.receipt_name/s', '');
        $order_no = input('post.order_no/s', '');
        $amount = input('post.amount/s', '');
        $alipay_user_id = input('post.alipay_user_id/s', '');


        //trace('return:'.$receipt_name.$amount.$alipay_user_id,'getTransferNo['.$order_no.']');
        $orderService = new \app\common\service\Order();
        $res = (new Api())->getTransferNo($receipt_name, $order_no, $amount, $alipay_user_id, 'alipay_rec');

        $orderService->editSendSocket($order_no);
    }

    /** 下发钉钉群收款支付链接
     * JH支付 <2849084774***7906@qq.com>
     * @Note
     */
    public function getDingDingQunJson($order_no,$amount,$receipt_name)
    {

        if (empty($order_no)) return json(['code' => -1, 'msg' => '订单号异常']);

        $orderData = model('PayOrders')->where('order_no',$order_no)->field('channel_id,merchant_id,status,merchant_account_id,id,unit_id,money')->find();

        if (empty($orderData)) return json(['code' => -1, 'msg' => '此订单不存在']);

        if ( $orderData['status'] == 5 ) return json(['code' => -1, 'msg' => '此订单创建失败']);

        $type = 'DingDing';

        $amount = $orderData['money'];

        // 当前时间
        $nowTime = time();

        $dingdingqun_userid = '';

        if( $orderData['channel_id']==12 ){

            $orderBool = model('PayOrders')->where('order_no',$order_no)->where('status',1)->value('id');

            if ( empty($orderBool) ){
                return json(['code' => -1, 'msg' => '钉钉群收款更新失败']);
            }

            $qTime = time()-86400;

            $where['account_id'] = $orderData['merchant_account_id'];
            $where['status'] = 0;
            $where['money'] = $amount;
            $where['create_time'] = ['>',$qTime];
            $where['account_fid'] = ['>',0];

            // 获取是否存在
            $bool = model('NailPayUrl')->where($where)->order('id asc')->field('pay_url,id,uid,bizId')->find();

            if ( empty($bool) ){
                $type = 'NailGroupCollection';

                // 调用Redis模型
                $redis = RedisService::getInstance();
                if (RedisService::$status !== true) {
                    exception('redis服务出错' . RedisService::$status);
                }
                $reBool = $redis->get($order_no);
                if ( empty($reBool) ){
                    //设置缓存
                    $redis->set($order_no, 1, 1200);
                } else {
                    if ( $reBool == 1 ){
                        //设置缓存
                        $redis->set($order_no, 2, 1200);
                    } else {
                        return json(['code' => -1, 'msg' => '钉钉群收款已经下发过了两次']);
                    }
                }

                $fWhere['merchant_account_id'] = $orderData['merchant_account_id'];
                $fWhere['now_data'] = date('Ymd',time());
//                $fWhere['dingding_number'] = ['<',30];
                // 次数限制
                $merchantsAccountsData = model('MerchantsAccountsData')->where($fWhere)->value('dingding_number');

                if ( empty($merchantsAccountsData) ){
                    model('MerchantsAccountsData')->where('merchant_account_id',$orderData['merchant_account_id'])->update(['now_data'=>date('Ymd',time()),'dingding_number'=>1]);
                } else {
                    if ( $merchantsAccountsData < 30  ){
                        model('MerchantsAccountsData')->where('merchant_account_id',$orderData['merchant_account_id'])->update(['now_data'=>date('Ymd',time()),'dingding_number'=>$merchantsAccountsData+1]);
                    } else {
                        model('PayOrders')->where('order_no',$order_no)->update(['status'=>5,'err_msg'=>'钉钉获取次数已达到30次数限制']);

                        $accountsUpdatedata['last_off_desc'] = '钉钉获取次数已达到30次数限制';
                        $accountsUpdatedata['is_receiving'] = '2';
                        $accountsUpdatedata['confirm_ip_status'] = '0';
                        $accountsUpdatedata['is_training'] = 0;
                        model('MerchantsAccounts')->where(['id' => $orderData['merchant_account_id']])->update($accountsUpdatedata);

                        // 删除池子
                        (new Account())->updataMerchantPool($orderData['unit_id'],$orderData['merchant_id'], $orderData['channel_id'], $orderData['merchant_account_id'], 'off');
                        return json(['code' => -1, 'msg' => '钉钉群收款更新失败']);
                    }
                }

            } else {
                $uData['oid'] = $orderData['id'];
                $uData['status'] = 1;

                $uBool = model('NailPayUrl')->where(['id'=>$bool['id']])->update($uData);

                if ( $uBool === false ){
                    return json(['code' => -1, 'msg' => '钉钉群收款更新失败']);
                } else {
                    model('PayOrders')->where('order_no',$order_no)->where('status',1)->update(['status'=>2,'DingDingJson'=>$bool['pay_url']]);
                    model('PayOrderAssists')->where('order_no',$order_no)->update(['dingding_uid'=>$bool['uid'],'bizId'=>$bool['bizId']]);
                    return json(['code' => 1, 'msg' => '获取成功']);
                }
            }
        } else if ( $orderData['channel_id']==18 ){
            $type = 'MicroChatRed';
        }

        (new Api())->getQrCode($orderData['merchant_id'],$receipt_name, $order_no, $amount, $type,$dingdingqun_userid);

    }

    /** 下发支付宝钉钉红包订单号
     * JH支付 <2849084774***7906@qq.com>
     * @Note
     */
    public function getDingDingJson()
    {
        $order_no = input('post.order_no/s', '');
        $amount = input('post.amount/s', '');
        $receipt_name = input('post.receipt_name/s', '');

        if (empty($order_no)) return json(['code' => -1, 'msg' => '订单号异常']);

        $orderData = model('PayOrders')->where('order_no',$order_no)->field('channel_id,merchant_id,status,merchant_account_id,id,unit_id,money')->find();

        if (empty($orderData)) return json(['code' => -1, 'msg' => '此订单不存在']);

        if ( $orderData['status'] == 5 ) return json(['code' => -1, 'msg' => '此订单创建失败']);

        $amount = $orderData['money'];

        $type = 'DingDing';

        // 当前时间
        $nowTime = time();

        $dingdingqun_userid = '';

        if ($orderData['channel_id']==10){
            $type = 'WangXin';
        } else if( $orderData['channel_id']==12 ){

            $orderBool = model('PayOrders')->where('order_no',$order_no)->where('status',1)->value('id');

            if ( empty($orderBool) ){
                return json(['code' => -1, 'msg' => '钉钉群收款更新失败']);
            }

            $qTime = time()-86400;

            $where['account_id'] = $orderData['merchant_account_id'];
            $where['status'] = 0;
            $where['money'] = $amount;
            $where['create_time'] = ['>',$qTime];
            $where['account_fid'] = ['>',0];

            // 获取是否存在
            $bool = model('NailPayUrl')->where($where)->order('id asc')->field('pay_url,id,uid,bizId')->find();

            if ( empty($bool) ){
                $type = 'NailGroupCollection';

                // 调用Redis模型
                $redis = RedisService::getInstance();
                if (RedisService::$status !== true) {
                    exception('redis服务出错' . RedisService::$status);
                }
                $reBool = $redis->get($order_no);
                if ( empty($reBool) ){
                    //设置缓存
                    $redis->set($order_no, 1, 1200);
                } else {
                    if ( $reBool == 1 ){
                        //设置缓存
                        $redis->set($order_no, 2, 1200);
                    } else {
                        return json(['code' => -1, 'msg' => '钉钉群收款已经下发过了两次']);
                    }
                }

                $fWhere['merchant_account_id'] = $orderData['merchant_account_id'];
                $fWhere['now_data'] = date('Ymd',time());
//                $fWhere['dingding_number'] = ['<',30];
                // 次数限制
                $merchantsAccountsData = model('MerchantsAccountsData')->where($fWhere)->value('dingding_number');

                if ( empty($merchantsAccountsData) ){
                    model('MerchantsAccountsData')->where('merchant_account_id',$orderData['merchant_account_id'])->update(['now_data'=>date('Ymd',time()),'dingding_number'=>1]);
                } else {
                    if ( $merchantsAccountsData < 30  ){
                        model('MerchantsAccountsData')->where('merchant_account_id',$orderData['merchant_account_id'])->update(['now_data'=>date('Ymd',time()),'dingding_number'=>$merchantsAccountsData+1]);
                    } else {
                        model('PayOrders')->where('order_no',$order_no)->update(['status'=>5,'err_msg'=>'钉钉获取次数已达到30次数限制']);

                        $accountsUpdatedata['last_off_desc'] = '钉钉获取次数已达到30次数限制';
                        $accountsUpdatedata['is_receiving'] = '2';
                        $accountsUpdatedata['confirm_ip_status'] = '0';
                        $accountsUpdatedata['is_training'] = 0;
                        model('MerchantsAccounts')->where(['id' => $orderData['merchant_account_id']])->update($accountsUpdatedata);

                        // 删除池子
                        (new Account())->updataMerchantPool($orderData['unit_id'],$orderData['merchant_id'], $orderData['channel_id'], $orderData['merchant_account_id'], 'off');
                        return json(['code' => -1, 'msg' => '钉钉群收款更新失败']);
                    }
                }

            } else {
                $uData['oid'] = $orderData['id'];
                $uData['status'] = 1;

                $uBool = model('NailPayUrl')->where(['id'=>$bool['id']])->update($uData);

                if ( $uBool === false ){
                    return json(['code' => -1, 'msg' => '钉钉群收款更新失败']);
                } else {
                    model('PayOrders')->where('order_no',$order_no)->where('status',1)->update(['status'=>2,'DingDingJson'=>$bool['pay_url']]);
                    model('PayOrderAssists')->where('order_no',$order_no)->update(['dingding_uid'=>$bool['uid'],'bizId'=>$bool['bizId']]);
                    return json(['code' => 1, 'msg' => '获取成功']);
                }
            }
        } else if ( $orderData['channel_id']==18 ){
            $type = 'MicroChatRed';
        } else if ( $orderData['channel_id']==19 ){
            $type = 'CloudFlashover';
        } else if ( $orderData['channel_id']==22 ){
            $type = 'RuralCreditWechat';
        } else if ( $orderData['channel_id']==23 ){
            $type = 'FlyChat';
        }else if ( $orderData['channel_id']==27 ){
            $type = 'RuralCreditAlipay';
        }else if ( $orderData['channel_id']==28 ){
            $type = 'RuralCreditCloudFlashover';
        }else if ( $orderData['channel_id']==29 ){
            $type = 'RuralCreditBank';
        }

        // $res  = (new Api())->getDingDingJson($receipt_name, $order_no, $amount, 'DingDing');
        $qrcode = (new Api())->getQrCode($orderData['merchant_id'],$receipt_name, $order_no, $amount, $type,$dingdingqun_userid);
        //p($res);

        //$orderService->editSendSocket($order_no);
    }

    // 支付宝跳银行卡
    public function bank()
    {
        $key = input('get.key/s', '');
        $order_no = decrypt($key);
        if (empty($order_no)) return json(['code' => 40005, 'msg' => '订单号不正确']);

        // 查询订单
        $orderData = model('PayOrders')->where(['order_no' => $order_no])->field('amount,money,merchant_account_id')->find();

        if (empty($orderData)) return json(['code' => 40005, 'msg' => '订单号存在']);

        // 查询银行卡
        $bankData = model('MerchantsAccounts')->where(['id' => $orderData['merchant_account_id']])->field('receipt_name,bank_user,bank_desc,bank_name')->find();

        $bankData['amount'] = $orderData['amount'];
        $bankData['money'] = $orderData['money'];

        return view("pay/mobile/bankH5", ['data' => $bankData]);
        //return view("pay/mobile/bankH5", ['data' => $bankData]);

    }

    /**
     * 支付宝里面获取支付宝id
     * JH支付 <2849084774***7906@qq.com>
     * @return bool
     */
    public function getUserId()
    {
        /*** 请填写以下配置信息 ***/
        $scope = 'auth_base';       //auth_base或auth_userinfo。如果只需要获取用户id，填写auth_base即可。如需获取头像、昵称等信息，则填写auth_userinfo

        // 获取数据库的授权配置
        $data = getAuthorizationUrl();

        // 判断
        if ( $data['code'] < 0 ){
            $appid = config('alipay_auto.appid');    //https://open.alipay.com 账户中心->密钥管理->开放平台密钥，填写开通了“获取会员信息”应用的APPID
            //商户私钥，填写对应签名算法类型的私钥，如何生成密钥参考：https://docs.open.alipay.com/291/105971和https://docs.open.alipay.com/200/105310
            $rsaPrivateKey = config('alipay_auto.rsaPrivateKey');
        } else {
            $appid = $data['app_id'];
            $rsaPrivateKey = $data['app_key'];
        }

        $signType = 'RSA2';       //签名算法类型，支持RSA2和RSA，推荐使用RSA2

        header('Content-type:text/html; Charset=utf-8');
        $aliPay = new AlipayService();
        $aliPay->setAppid($appid);
        $aliPay->setScope($scope);
        $aliPay->signType = $signType;
        $aliPay->setRsaPrivateKey(trim($rsaPrivateKey));
        $result = $aliPay->getToken();
        $user = array();
        if ($baseInfo = $result['alipay_system_oauth_token_response']) {
            //trace(print_r($baseInfo,true).'|'.date('Y-m-d his',time()),'getUserId');
            //$alipay_user_id = $baseInfo['alipay_user_id'];
            $userid = $baseInfo['user_id'];
            //Session::set('alipay_user_id', $userid);
            if ($userid) {
                return $userid;
            } else {
                return $baseInfo;
            }
        }

        return false;
    }

    /**
     * 支付宝里面获取支付宝id
     * JH支付 <2849084774***7906@qq.com>
     * @return bool
     */
    public function getAlipayUserId()
    {
        /*** 请填写以下配置信息 ***/
        $scope = 'auth_base';       //auth_base或auth_userinfo。如果只需要获取用户id，填写auth_base即可。如需获取头像、昵称等信息，则填写auth_userinfo
        $appid = config('alipay_auto.appid');    //https://open.alipay.com 账户中心->密钥管理->开放平台密钥，填写开通了“获取会员信息”应用的APPID

        //商户私钥，填写对应签名算法类型的私钥，如何生成密钥参考：https://docs.open.alipay.com/291/105971和https://docs.open.alipay.com/200/105310
        $rsaPrivateKey = config('alipay_auto.rsaPrivateKey');

        $signType = 'RSA2';       //签名算法类型，支持RSA2和RSA，推荐使用RSA2

        header('Content-type:text/html; Charset=utf-8');
        $aliPay = new AlipayService();
        $aliPay->setAppid($appid);
        $aliPay->setScope($scope);
        $aliPay->signType = $signType;
        $aliPay->setRsaPrivateKey(trim($rsaPrivateKey));
        $result = $aliPay->getToken();
        $user = array();
        if ($baseInfo = $result['alipay_system_oauth_token_response']) {
            //trace(print_r($baseInfo,true).'|'.date('Y-m-d his',time()),'getUserId');
            //$alipay_user_id = $baseInfo['alipay_user_id'];
            $userid = $baseInfo['user_id'];
            //Session::set('alipay_user_id', $userid);

            if ( $userid ){
//                $go_url = 'alipays://platformapi/startapp?appId=09999988&actionType=toAccount&goBack=NO&userId=2088532191100572&amount=100';
//                echo $go_url.'<br>';
//                echo 111;
//                header("Location:" . $go_url);
            }
        }


    }



    /**
     *  h5 跳转（淘宝方式）
     */
    public function taobaoH5(){
        $url = input('get.url/s', '');

        $sign = 'aaa';

        $url = $url.'&sign='.$sign;

        $alipayUrl = "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode='.($url));

        $jumpUrl = 'taobao://www.alipay.com/?appId=10000007&qrcode='.urlencode($alipayUrl);

        header("Location:" . $jumpUrl);
        exit;

    }

    /**
     *  h5 跳转（直接跳支付宝）
     */
    public function alipayH5(){
        $url = input('get.url/s', '');

        $sign = input('get.sign/s', 'false');

        $url = $url.'&sign='.$sign;

        $alipayUrl = "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode='.($url));

//        $jumpUrl = 'alipays://platformapi/startapp?appId=66666722&appClearTop=false&startMultApp=YES&url='.urlencode($url);
        $jumpUrl = 'alipays://platformapi/startapp?appId=20000067&url='.urlencode($url);

        header("Location:" . $jumpUrl);
        exit;

    }

    /** 更新支付宝收款的步骤
     * JH支付 <2849084774***7906@qq.com>
     * @Note
     */
    public function updateRecStep($order_no,$number)
    {
        $where['status'] = ['<',4];
        $where['order_no'] = $order_no;
        // 更新步骤 跳到添加好友
        model('PayOrders')->where($where)->update(['rec_step'=>$number]);
    }

    /** 更新支付宝收款的步骤
     * JH支付 <2849084774***7906@qq.com>
     * @Note
     */
    public function updateRecStepTwo()
    {
        $order_no = input('post.order_no/s');
        $number = intval(input('post.number/d',1));

        if( $number == 1 ){
            return;
        }

        $where['status'] = ['<',4];
        $where['order_no'] = $order_no;

        // 更新步骤 跳到添加好友
        model('PayOrders')->where($where)->update(['rec_step'=>$number]);
    }

    /** 记录手机型号信息
     * JH支付 <2849084774***7906@qq.com>
     * @Note
     */
    private function mobileModelRecord($data)
    {
        $arr['order_no'] = isset($data['order_no'])?$data['order_no']:'1';
        $arr['pay_type'] = isset($data['pay_type'])?$data['pay_type']:'1';
        $arr['type'] = isset($data['brand'])?$data['brand']:'1';
        $arr['model'] = isset($data['version'])?$data['version']:'1';
        $arr['create_time'] = time();

        $bool = model('MobileModel')->where(['order_no' => $arr['order_no']])->field('id')->find();

        // 判断记录
        if ( empty($bool) ){
            // 记录
            model('MobileModel')->insert($arr);
        }
    }

    // 获取手机型号
    private function getOS(){
        //这里只进行IOS和Android两个操作系统的判断，其他操作系统原理一样
        if(!isset($_SERVER['HTTP_USER_AGENT'])) {
             $data['brand'] = '未知手机';
            $data['version'] = 0 ;
            return $data;
        }
        $user_agent = $_SERVER['HTTP_USER_AGENT'];

        if (strpos($user_agent, 'Android') !== false) {//strpos()定位出第一次出现字符串的位置，这里定位为0
            preg_match("/(?<=Android )[\d\.]{1,}/", $user_agent, $version);
            $data['version'] = $version[0];
        } elseif (strpos($user_agent, 'iPhone') !== false) {
            preg_match("/(?<=CPU iPhone OS )[\d\_]{1,}/", $user_agent, $version);
            $data['version'] = str_replace('_', '.', $version[0]);
        } elseif (strpos($user_agent, 'iPad') !== false) {
            preg_match("/(?<=CPU OS )[\d\_]{1,}/", $user_agent, $version);
            $data['version'] = str_replace('_', '.', $version[0]);
        }

        if (stripos($user_agent, "iPhone")!==false) {
            $brand = 'iPhone';
        } else if (stripos($user_agent, "SAMSUNG")!==false || stripos($user_agent, "Galaxy")!==false || strpos($user_agent, "GT-")!==false || strpos($user_agent, "SCH-")!==false || strpos($user_agent, "SM-")!==false) {
            $brand = '三星';
        } else if (stripos($user_agent, "Huawei")!==false || stripos($user_agent, "Honor")!==false || stripos($user_agent, "H60-")!==false || stripos($user_agent, "H30-")!==false) {
            $brand = '华为';
        } else if (stripos($user_agent, "Lenovo")!==false) {
            $brand = '联想';
        } else if (strpos($user_agent, "MI-ONE")!==false || strpos($user_agent, "MI")!==false || strpos($user_agent, "MI 2")!==false || strpos($user_agent, "MI 3")!==false || strpos($user_agent, "MI 4")!==false || strpos($user_agent, "MI-4")!==false) {
            $brand = '小米';
        } else if (strpos($user_agent, "HM NOTE")!==false || strpos($user_agent, "HM201")!==false) {
            $brand = '红米';
        } else if (stripos($user_agent, "Coolpad")!==false || strpos($user_agent, "8190Q")!==false || strpos($user_agent, "5910")!==false) {
            $brand = '酷派';
        } else if (stripos($user_agent, "ZTE")!==false || stripos($user_agent, "X9180")!==false || stripos($user_agent, "N9180")!==false || stripos($user_agent, "U9180")!==false) {
            $brand = '中兴';
        } else if (stripos($user_agent, "OPPO")!==false || strpos($user_agent, "X9007")!==false || strpos($user_agent, "X907")!==false || strpos($user_agent, "X909")!==false || strpos($user_agent, "R831S")!==false || strpos($user_agent, "R827T")!==false || strpos($user_agent, "R821T")!==false || strpos($user_agent, "R811")!==false || strpos($user_agent, "R2017")!==false) {
            $brand = 'OPPO';
        } else if (strpos($user_agent, "HTC")!==false || stripos($user_agent, "Desire")!==false) {
            $brand = 'HTC';
        } else if (stripos($user_agent, "vivo")!==false) {
            $brand = 'vivo';
        } else if (stripos($user_agent, "K-Touch")!==false) {
            $brand = '天语';
        } else if (stripos($user_agent, "Nubia")!==false || stripos($user_agent, "NX50")!==false || stripos($user_agent, "NX40")!==false) {
            $brand = '努比亚';
        } else if (strpos($user_agent, "M045")!==false || strpos($user_agent, "M032")!==false || strpos($user_agent, "M355")!==false) {
            $brand = '魅族';
        } else if (stripos($user_agent, "DOOV")!==false) {
            $brand = '朵唯';
        } else if (stripos($user_agent, "GFIVE")!==false) {
            $brand = '基伍';
        } else if (stripos($user_agent, "Gionee")!==false || strpos($user_agent, "GN")!==false) {
            $brand = '金立';
        } else if (stripos($user_agent, "HS-U")!==false || stripos($user_agent, "HS-E")!==false) {
            $brand = '海信';
        } else if (stripos($user_agent, "Nokia")!==false) {
            $brand = '诺基亚';
        } else {
            $brand = '其他手机';
        }

        $data['brand'] = $brand;
        return $data;
    }

    /** 支付宝 原生通道 手机网站支付
     * @author xi 2019/4/11 21:50
     * @Note
     * @param $order_no
     * @return array
     */
    public function alipayWap($order_no){
        /**
        $appid = 'xxxxx';  //https://open.alipay.com 账户中心->密钥管理->开放平台密钥，填写添加了电脑网站支付的应用的APPID
        $returnUrl = 'http://www.xxx.com/alipay/return.php';     //付款成功后的同步回调地址
        $notifyUrl = 'http://www.xxx.com/alipay/notify.php';     //付款成功后的异步回调地址
        $outTradeNo = uniqid();     //你自己的商品订单号
        $payAmount = 0.01;          //付款金额，单位:元
        $orderName = '支付测试';    //订单标题
        $signType = 'RSA2';         //签名算法类型，支持RSA2和RSA，推荐使用RSA2
        $rsaPrivateKey='xxxxx';     //商户私钥，填写对应签名算法类型的私钥，如何生成密钥参考：https://docs.open.alipay.com/291/105971和https://docs.open.alipay.com/200/105310
         */
        if(!$order_no){
            return ['code'=>0,'msg'=>'找不到订单号'];
        }
        //获取订单信息
        $orderInfo = model('PayOrders')->with('assists')->where(['order_no'=>$order_no])->find();
        if(empty($orderInfo)){
            return ['code'=>0,'msg'=>'找不到订单'];
        }
        //p($orderInfo);
        //获取原生通道接口额外参数：密钥、appid
        $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');

        //p(json_decode($apiData,true));

        if(empty($apiData) || $apiData == null){
            return ['code'=>0,'msg'=>'api参数错误'];
        }
        $apiData = json_decode($apiData,true);

        $sysInfo = model('Variable')->where(['name'=>'setting'])->value('value');
        if(!empty($sysInfo)){
            $sysInfo = json_decode($sysInfo,true);
        }else{
            $sysInfo['title'] = '支付测试';
        }

        $signData = [
            'appid'        => $apiData['appid'],
            'returnUrl'     => url('gateway/index/paySuccess'),
            'notifyUrl' => url('gateway/service/alipayWapNotify'),
            'outTradeNo'       => $order_no,
            'payAmount' => $orderInfo['amount'],
            'orderName'  => $sysInfo['title'],
            'rsaPrivateKey'      => $apiData['rsa_private_key'],
        ];
        //p($signData);
        //调用接口
        $aliPay = (new AlipayWapService());
        $aliPay->setAppid($signData['appid']);
        $aliPay->setReturnUrl($signData['returnUrl']);
        $aliPay->setNotifyUrl($signData['notifyUrl']);
        $aliPay->setRsaPrivateKey($signData['rsaPrivateKey']);
        $aliPay->setTotalFee($signData['payAmount']);
        $aliPay->setOutTradeNo($signData['outTradeNo']);
        $aliPay->setOrderName($signData['orderName']);
        $sHtml = $aliPay->doPay();
        //p($sHtml);
        if(isInAlipayClient()){
            $queryStr = http_build_query($sHtml);
            header("Location:https://openapi.alipay.com/gateway.do?{$queryStr}");
        }else{
            echo $aliPay->buildRequestForm($sHtml);
        }
        die;

    }



    /** 普尔 登录接口 获取Authorization
     * @author xi 2019/4/10 16:32
     * @Note
     */
    public function pooulLogin(){
        $data = [
            'login_name' => 'tpwl121',
            'password'   => '123456',
        ];
        $url = 'https://api.pooul.com/web/user/session/login_name';
        $res = HtmlSubmit::sendRequestHeader($url,json_encode($data));
        //print_r($_SERVER['HTTP_TEST']);
        preg_match('/Authorization: (.*)\s\n/',$res['msg'],$authstr);
        //结果$authstr[1]
        $authorization = $authstr[1];
        echo $authorization;

        $merchantId = '1625218932479297';
        //$res = $this->pooulJwt();
        //p($res);

    }


    /**  普尔 上传公钥key
     * @author xi 2019/4/11 13:57
     * @Note
     * @param $merchantId
     * @param $authorization
     */
    public function uploadPublicKey($merchantId='',$authorization=''){
        //$merchantId = '7329069086523452';
        $url = 'https://api.pooul.com/cms/merchants/'.$merchantId.'/public_key';
        //$url = 'http://api.hocan.cn/index/ok';
        $data = [
            'public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0n1m5sIeJgvi7QO80hX/d1QKXXeknddNZY3SaWeG4lgyLSpQJi/9y9iiWzdDrEH8IccI0vBOP938jcK56vIfvnc+YmG4uSX1QgGVdSYGOYmQRDPEXw24l+LKWL8S7xog0SJN/u/E2M3XOYZWOkbV7heiXJsqn09OBu12zykl0bOcoCih46F7WjHCzSIbLszOI2Bfqu23JuDc4tDLYHg4T1RvrR7IVCPpBgxVZ1MdxLJJp2D1UdDqvXm4q2odktll2S1r/Ak06mT8J4XW6cMnviLtfzyRXYn8hWocEQHTQjVzp91fo5AU+ohiFxPwZp8PKnj4nduClWXEzLj3wxCN3wIDAQAB',
        ];
        $res = HtmlSubmit::curPut($url,$data,$authorization);
        p($res);

    }

    /** 普尔 获取普尔公钥
     * @author xi 2019/4/11 14:15
     * @Note
     * @param $authorization
     * @return array
     */
    public function getPublicKey($authorization=''){
        $url = 'https://api.pooul.com/cms/pooul_public_key';
        $res = HtmlSubmit::curlGet($url,[],'dd643b61b44a159db2c3e469e2d1c780247d058b');
        p($res);
    }

    /** 生成jwt
     * @author xi 2019/4/11 18:47
     * @Note
     * @param array $data
     */
    public function pooulJwt($data=[]){

        $privateKey = file_get_contents(ROOT_PATH.'public/pooul/pra123.pem');
        //p($privateKey);
        $data = [
            'pay_type' => 'wechat.scan',//微信扫码
            'nonce_str' => get_rand_char(5).time(),//自定义字符串
            'mch_trade_id' => '1625218932479297',
            'total_fee' => '1',//微信扫码
            'body' => '测试支付',//微信扫码
        ];
        //$dataJson = '{"pay_type":"wechat.scan","nonce_str":"o0oin4s95c","mch_trade_id":"1554963875221","total_fee":1,"body":"测试支付"}';
        $jwtStr = JwtSerice::encode($data, $privateKey, 'RS256');
        echo ($jwtStr);

        $publicKey = file_get_contents(ROOT_PATH.'public/pooul/public123.pem');
        // 下面开始解密JWT并验签
        try {
            $decoded = JwtSerice::decode($jwtStr, $publicKey, ['RS256']); //解密同时验签
            echo json_encode($decoded,JSON_UNESCAPED_SLASHES );
        }catch (\Firebase\JWT\SignatureInvalidException $signatureInvalidException){
            http_response_code(500);
            echo "验签错误";
        }catch (\UnexpectedValueException $ex){
            http_response_code(500);
            echo "JWT内容不合法错误";
        }

        //请求支付


    }



    /** 直接跳转支付宝付款（个人转账模式）
     * @author zhou 2019/4/11 18:47
     * @Note
     * @param array $data
     */
    public function locationAlipay(){
        $order_no = input('get.order_no/s');

        if (empty($order_no)) return json(['code' => 40005, 'msg' => '订单号不正确']);

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');
        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $amount = model('PayOrders')->where(['order_no' => $order_no])->value('money');
        $merchantAccountId = model('PayOrders')->where(['order_no' => $order_no])->value('merchant_account_id');
        $userId = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('alipay_user_id');

        $user_id = $this->getUserId();

        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

        $url2 = "alipays://platformapi/startapp?appId=20000123&actionType=scan&biz_data={\"s\": \"money\",\"u\": \"".$userId."\",\"a\": \"".$amount."\"}";
        $url = 'alipays://platformapi/startapp?appId=66666743&url='.urlencode($url2);
        if ($alipay_user_id) {
            Session::delete('state');
            if (!($user_id == $alipay_user_id)) {
                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
            } else {
                return view("pay/mobile/scan", ['url' => $url,'order_no'=>$order_no,'money'=>$amount]);
            }
        } else {
            Session::delete('state');
            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
            return view("pay/mobile/scan", ['url' => $url,'order_no'=>$order_no,'money'=>$amount]);
        }
    }

    // 测试
    public function eliminate(){
        $order_no = input('get.order_no/s');

        if (!Session::has('state') ) {
            Session::set('state', 1);
            header("Location:" . "https://render.alipay.com/p/s/i?scheme=" . urlencode('alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=' . (url('gateway/pay/eliminate', ['order_no' => $order_no]))));
            exit;
        }

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('merchant_account_id,money,status')->find();

        $alipay_user_id = model('MerchantsAccounts')->where(['id' => $orderStatus['merchant_account_id']])->value('alipay_user_id');

        $user_id = $this->getUserId();

        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

        if ($alipay_user_id) {
            if (!($user_id == $alipay_user_id)) {
                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
            }
        } else {
            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);
        }

        if (isInAlipayClient()) {
            Session::delete('state');
            return view("pay/mobile/eliminate");
        }
        Session::delete('state');
        return json(['code' => -1, 'msg' => '请在支付宝内进行扫码']);
    }

    // 生活号产码
    public function lifeProductionCode(){
        $order_no = input('get.order_no/s');

        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->field('status,channel_id')->find();
        if ($orderStatus['status'] == 4 || $orderStatus['status']  == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        // 获取数据库的授权配置
        $authorizationUrlData = getAuthorizationUrl();

        if ( $authorizationUrlData['code'] < 0 ){
            $url = url('gateway/pay/openAlipay', ['order_no' => $order_no]);
        } else {
            $url = $authorizationUrlData['url'].'/pay/openAlipay?order_no='.$order_no;
        }

        $life = new Life();

        // 更新步骤 到获取生活号的二维码
        $this->updateRecStep($order_no,4);

        $data = $life->productionCode($url);

        return json($data);

    }

    // 钉钉群收款 手机 区分是安卓还是苹果
    public function nailGroupCollectionMobilePhone(){
        $sign = input('get.sign', 1);  // 1 安卓 2 苹果
        $order_no = input('get.order_no/s');

        $data['amount'] = model('PayOrders')->where(['order_no' => $order_no])->value('amount');
        $data['money'] = model('PayOrders')->where(['order_no' => $order_no])->value('money');

        if ( $sign == 1 ){
            $url = '订单金额:'.$data['amount'].'，实付金额:'.$data['money'].'，付款链接:'.url('gateway/pay/openNailGroupCollection', ['order_no' => $order_no]);
            return view("pay/mobile/NailGroupCollectionAndroid", ['order_no' => $order_no,  'order' => $data,'url'=>$url]);
        } else {
            return view("pay/mobile/NailGroupCollectionIphone", ['order_no' => $order_no]);
        }
    }

    /**
     * 获取搜索码
     * @author LvGang 2019/2/21 0021 18:27 JH支付 <85***82849084774@qq.com>
     * @return \think\response\Json
     */
    public function codeQuery()
    {
        $order_no = input('get.order_no/s', '');
        if (empty($order_no)) return json(['code' => 40005, 'msg' => '订单号不正确']);

        $orderService = new \app\common\service\Order();
        $order = $orderService->getOrderInfo($order_no);

        // 订单不存在
        if (empty($order)) return json(['code' => 40006, 'msg' => '订单不存在']);

        $order['deadline_time'] = $order['deadline_time'] - time();

        // 搜索码
        $searchCode = model('PayOrderAssists')->where(['order_no' => $order_no])->value('search_code');

        // 下发
        if ( empty($searchCode) ){
            $data = model('PayOrders')->where(['order_no' => $order_no])->field('merchant_account_id,money')->find();
            $merchantAccountId = $data['merchant_account_id'];
            $searchType = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('is_alipay_account');

            // 启用
            if ( $searchType == 1 ){

                $channel_id = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('channel_id');
                $userId = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('alipay_user_id');
                $merchant_id = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->value('merchant_id');
                $alipayUserId = model('MerchantsAccounts')->where(['id' => $merchantAccountId])->field('receipt_name,alipay_user_id,bank_user,bank_name,bank_desc,channel_id,card_id,account_no,unit_id')->find();
                if ( $channel_id == 6 ){
                    $paytype = "alipays://platformapi/startapp?appId=20000123&actionType=scan&biz_data={\"s\": \"money\",\"u\": \"".$userId."\",\"a\": \"".$data['money']."\"}&t=".rand(0,999).time();
                } else {
//                    $paytype = 'alipays://platformapi/startapp?appId=09999988&actionType=toCard&sourceId=bill&cardNo=' . $alipayUserId['receipt_name'] . '&bankAccount=' . $alipayUserId['bank_user'] . '&money=' . $data['money'] . '&amount=' . $data['money'] . '&bankMark=' . $alipayUserId['bank_desc'] . '&bankName=' . $alipayUserId['bank_name'] . '&cardNoHidden=True&cardChannel=HISTORY_CARD&orderSource=from&REALLY_STARTAPP=true&startFromExternal=false&ap_framework_sceneId=20000067&t='.rand(0,999).time();
                    //$paytype = 'alipays://platformapi/startapp?appId=09999988&actionType=toCard&sourceId=bill&cardNo='. $alipayUserId['account_no'] . '&bankAccount=请勿修改金额，两分钟到账**&money='.$data['money'] .'&amount='.$data['money'] .'&bankMark='.$alipayUserId['bank_desc'].'&bankName='.$alipayUserId['bank_name'].'&cardIndex='.$alipayUserId['card_id'].'&cardChannel=HISTORY_CARD&cardNoHidden=true&orderSource=from%s';
                    //$paytype =  'alipays://platformapi/startapp?appId=09999988&actionType=toCard&sourceId=bill&cardNo='. $alipayUserId['account_no'] . '&bankAccount='.$alipayUserId['bank_user'].'&bankMark='.$alipayUserId['bank_desc'].'&bankName='.$alipayUserId['bank_name'].'&money='.$data['money'] .'&amount='.$data['money'] .'&&cardIndex='.$alipayUserId['card_id'].'&cardNoHidden=true&cardChannel=HISTORY_CARD&REALLY_STARTAPP=true&startFromExternal=false&ap_framework_sceneId=20000067';

                    //$baseUrl = "https://render.alipay.com/p/s/i?scheme=alipays%3A%2F%2Fplatformapi%2Fstartapp%3FappId%3D09999988%26actionType%3DtoCard%26ap_framework_sceneId%3D20000067%26";
                    $baseUrl = "alipays://platformapi/startapp?appId=09999988&actionType=toCard&ap_framework_sceneId=20000067&";
                    $customUrl = "bankAccount=". $alipayUserId['bank_user'] ."&cardNo=请勿修改金额,2分钟内到账****&bankName=". $alipayUserId['bank_user'] ."&bankMark=". $alipayUserId['bank_desc'] ."&cardIndex=". $alipayUserId['card_id'] ."&cardChannel=HISTORY_CARD&cardNoHidden=true&money=" .$data['money'] . "&amount=" .$data['money'] ."&REALLY_STARTAPP=true&startFromExternal=false&from=mobile";
                    $paytype = $baseUrl .$customUrl;
                }

//                $paytype = url('gateway/pay/openAlipay', ['order_no' => $order_no]);

                (new Api())->getSearchCode($merchant_id, $order_no, $paytype);
            }
        }

        if (empty($order)){
            return json(['code' => -2, 'msg' => '订单交易不存在']);
        }
        if ($order['status'] == 4){
            return json(['code' => 200, 'msg' => '支付成功', 'data' => $order]);
        }
        if ($order['status'] == 5){
            return json(['code' => -2, 'msg' => '订单创建失败！请重新创建', 'data' => $order]);
        }
        if ($order['deadline_time'] <= 0) {
            (new Service())->upadteOrderStatus($order['unit_id'],$order['id'], $order['merchant_id'], $order['amount']);

            return json(['code' => -1, 'msg' => '订单支付已超时', 'data' => $order]);
        }

        return json(['code' => 100, 'msg' => '获取成功', 'data' => $order,'searchCode'=>$searchCode]);

    }

    // 静默授权
    public function openAlipay()
    {
        $order_no = input('get.order_no/s');

        // 更新步骤
        model('UrlRecords')->where('order_no',$order_no)->update(['step'=>2]);

        $sign = input('get.sign',false);

        $user_id = $this->getUserId();

        if (empty($user_id)) return json(['code' => -1, 'msg' => '支付宝授权失败']);
        $alipay_user_id = model('AlipayRec')->where(['order_no' => $order_no])->value('alipay_user_id');

        $channelId = model('PayOrders')->where(['order_no' => $order_no])->value('channel_id');

        $url = '';

        switch ($channelId){
            case '3':
                $url = url('gateway/pay/openRed', ['order_no' => $order_no,'sign'=>$sign]);
                break;
            case '4':
                $url = url('gateway/pay/openBank', ['order_no' => $order_no,'sign'=>$sign]);
                break;
            case '5':
                $url = url('gateway/pay/openRec', ['order_no' => $order_no,'sign'=>$sign]);
                break;
            case '6':
                $url = url('gateway/pay/openTra', ['order_no' => $order_no,'sign'=>$sign]);
                break;
            case '13':
            case '31':
                $url = url('gateway/pay/openAlipayVariableTransfer', ['order_no' => $order_no,'sign'=>$sign]);
                break;
            case '23':
                $url = url('gateway/pay/openFlyChat', ['order_no' => $order_no,'sign'=>$sign]);
                break;
        }

        if ($alipay_user_id) {
            Session::delete('state');
            if (!($user_id == $alipay_user_id)) {
                return json(['code' => -1, 'msg' => '请使用第一次扫码的支付宝进行支付']);
            } else {
                return view("pay/mobile/openAlipayView",['order_no'=>$order_no,'sign'=>$sign,'url'=>$url]);
            }
        } else {
            Session::delete('state');
            model('AlipayRec')->insert(['order_no' => $order_no, 'alipay_user_id' => $user_id]);

            return view("pay/mobile/openAlipayView",['order_no'=>$order_no,'sign'=>$sign,'url'=>$url]);
        }
    }


    /** 拼多多请求支付
     * @author xi 2019/5/22 18:06
     * @Note
     * @param $orderNo
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function aaPinduoduo($orderNo){

        if(!$orderNo){
            return ['code'=>0,'msg'=>'订单号错误！'];
        }

        $apiUrl = 'http://112.74.206.59:8080/Pay/PostPay';

        //获取订单信息
        $orderInfo = model('PayOrders')->where(['order_no'=>$orderNo])->find();

        $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');

        if(empty($apiData) || $apiData == null){
            return ['code'=>0,'msg'=>'api参数错误'];
        }
        $apiData = json_decode($apiData,true);

        $signData = [
            'mchid' =>$apiData['appid'],
            'outorderno' => $orderNo,
            'amount' => floatval($orderInfo['amount'])*100,
            'type' => ( $orderInfo['pay_type'] == 1 ) ? 'zfb' : 'wx',
            'mode' => '0',
            'notifyurl' => url('gateway/service/pddNotify'),
            'attach' => $orderNo,
        ];

        $signData['sign'] = pinduoduo::getSign($signData,$apiData['key']);

        $return = http::post($apiUrl,$signData);
        $return = json_decode($return,true);
        if( $return['result']=='ok' && isset($return['orderno']) && isset($return['url']) ){
            model('PayOrders')->update(['pay_trade_no'=>$return['orderno'],'qrcode'=>$return['url'],'status'=>2],['order_no'=>$orderNo]);
            header("Location:{$return['url']}");
        }else{
            return $return;
        }

    }

    /** 永利-步步高支付接口
     * @author xi 2019/6/3 15:09
     * @Note
     * @param $orderNo
     */
    private function bubugaoBank($orderNo){
        $api = 'http://chen.weiyi888.com/user/index_pay.php';

        //获取订单信息
        $orderInfo = model('PayOrders')->where(['order_no'=>$orderNo])->find();

        $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');
        if(empty($apiData) || $apiData == null){
            return ['code'=>0,'msg'=>'api参数错误'];
        }
        $apiData = json_decode($apiData,true);
        $data =[
            'type'          => 'yhk',
            'username'      => $apiData['appid'],
            'password'      => $apiData['key'],
            'money'         => $orderInfo['money'],
            'orderno'       => $orderNo,
            'urlcallback'   => url('gateway/service/bubugaoNotify'),
        ];

        $return = Http::get($api,$data);
        $return = json_decode($return,true);
        //p($return);
        if( $return['msg']=='success' && isset($return['money']) && isset($return['ewmurl']) && isset($return['code']) ){
            model('PayOrders')->update(['qrcode'=>$return['ewmurl'],'money'=>$return['money'],'status'=>2],['order_no'=>$orderNo]);
            header("Location:{$return['ewmurl']}");
        }else{
            return $return;
        }
    }

    /** 支付宝网关
     * @author zhou 2019/6/3 15:09
     * @Note
     * @param $orderNo
     */
    public function receiveOrder(){
        $order_no = input('post.order_no/s');
        $bank_sn = input('post.bank_sn/s',false);

        if ( empty($bank_sn) ){
            return json(['code'=>-1,'msg'=>'不能二次请求']);
        }

        if (empty($order_no)) return json(['code' => -1, 'msg' => '订单号不能为空']);
        $orderStatus = model('PayOrders')->where(['order_no' => $order_no])->value('status');
        if ($orderStatus == 4 || $orderStatus == 3) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $type = cardTreasure($bank_sn);

        $merchantAccountId = model('PayOrders')->where(['order_no' => $order_no])->value('merchant_account_id');

        $amount = model('PayOrders')->where(['order_no' => $order_no])->value('money');
//        $unitId = model('PayOrders')->where(['order_no' => $order_no])->value('unit_id');
//
//        $setting = getVariable('setting',$unitId);
//
//        if ( !isset($setting['proxyUser']) && empty($setting['proxyUser']) || !isset($setting['proxyPass']) && empty($setting['proxyPass']) ){
//            return json(['code'=>-1,'msg'=>'请配置JH云']);
//        }

        $setting=[];

        $cookie1 = model('MerchantsAccountsData')->where(['merchant_account_id' => $merchantAccountId])->value('alipay_gateway_cookie');

        $cookie2 = trim($cookie1,"{");
        $cookie = trim($cookie2,"}");

        $data = (new LaoNiuYun())->alipayGatewayOrder($order_no,$amount,$type,$cookie,$setting);

        if ( is_array($data) ){
            echo '<pre>';
            print_r($data);
        }

    }


    /** 当面付接口
     * @author xi 2019/6/19 15:21
     * @Note
     * @param $orderNo
     * @return array|bool
     */
    private function alipayF2FPay($orderNo){

        if(!$orderNo){
            return ['code'=>0,'msg'=>'找不到订单号'];
        }
        //获取订单信息
        $orderInfo = model('PayOrders')->with('assists')->where(['order_no'=>$orderNo])->find();
        if(empty($orderInfo)){
            return ['code'=>0,'msg'=>'找不到订单'];
        }

        /*** 获取通道的签名参数 start ***/
        $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');

        if(empty($apiData) || $apiData == null){
            return ['code'=>0,'msg'=>'api参数错误'];
        }
        $apiData = json_decode($apiData,true);

        $life = new Life();
        $life->alipayF2FPay($orderNo,$orderInfo,$apiData);

    }

    /** 梦支付（客户）订单签约接口-快捷
     * @author xi 2019/7/8 11:42
     * @Note
     * @param $orderNo 订单号
     * @param $payNo （快捷1218，网银1217）
     * @return array|bool
     */
    public function mengPay($orderNo,$payNo){

        if( !in_array($payNo, [1217,1218]) ) return ['code'=>0,'msg'=>'参数错误'];

        if(!$orderNo) return ['code'=>0,'msg'=>'找不到订单号'];

        //获取订单信息
        $orderInfo = model('PayOrders')->with('assists')->where(['order_no'=>$orderNo])->find();

        if(empty($orderInfo)) return ['code'=>0,'msg'=>'找不到订单'];

        /*** 获取通道的签名参数 start ***/
        $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');

        if(empty($apiData) || $apiData == null) return ['code'=>0,'msg'=>'api参数错误'];

        $apiData = json_decode($apiData,true);

        $data = $signData = [
            "pay_memberid" => $apiData['appid'],
            "pay_orderid" => $orderInfo['order_no'],
            "pay_amount" => $orderInfo['money'],
            "pay_applydate" => date("Y-m-d H:i:s"),
            "pay_bankcode" => $payNo,
            "pay_notifyurl" => url('gateway/service/mengPay'),
            "pay_callbackurl" => Request::instance()->url(true),
        ];

        $mengpay = new Mengpay();
        //获取签名
        $data['pay_md5sign'] = $mengpay->getSign($signData,$apiData['key']);

        return $mengpay->buildRequestForm($data);
    }

    /** 银行卡转银行卡
     * @author xi 2019/7/15 20:39
     * @Note
     * @param $order_no
     * @param $key
     * @param $sign
     * @return \think\response\Json|\think\response\View
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function BankCardTransferBankCard($orderSInfo=[],$key='',$orderStatus=2){

        $bankDesc = input('post.bank_sn','');

        if((isset($orderSInfo)&& $orderSInfo && $key && isset($key)) || ($orderStatus==1 && empty($orderSInfo['bank_desc']) && empty($bankDesc))){
            if(($orderSInfo['status'] == 1) || ($orderSInfo['merchant_id'] == 0) || ($orderSInfo['merchant_account_id'] == 0)){
                $bankList = bankCardTreasure();
                //p($bankList);
                return ['view'=>1,'data'=>['orderkey' => $key,'bank_list'=>$bankList,'money'=>$orderSInfo['money']]];
            }
        }


        $orderKey = input('post.order_no','');
        $order_no = decrypt($orderKey);


        $orderSInfo = model('PayOrders')->where(['order_no' => $order_no])->find();

        if(!$orderSInfo || empty($orderSInfo)){
            return json(['code' => -1, 'msg' => '订单不存在']);
        }

        if (time() > $orderSInfo['deadline_time']) {
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }


        $userInfo = model('Users')->where(['id'=>$orderSInfo['user_id']])->find();



        if ( !empty($bankDesc) && in_array($bankDesc, bankCardTreasure()) ) {
            if (in_array($orderSInfo['version'], ['v1.0', 'v1.0.1', 'v2.0'])) {
                $training = 1;
            } else {
                $training = 2;
            }

            if($orderSInfo['order_status']==1){
                //测试订单
                $accountArr = (new Order())->getQrcode42($orderSInfo['order_no'],$bankDesc,$orderSInfo['merchant_account_id']);

                if($accountArr){
                    $url = url('gateway/pay/payOrder',['key'=>$orderKey]);
                    header("Location:{$url}");
                    exit;
                }else{
                    $return = ['code'=>0,'msg'=>'无可用通道'];
                    return json($return);
                }

            }else{

                $accountArr = (new Account())->getPoolAccount42($bankDesc,$userInfo['unit_id'],$userInfo, $orderSInfo['amount'], $training);
                if($accountArr !== false && $accountArr){
                    $accountData = $accountArr['data'];
                    //修改订单信息
                    $return = (new Order())->updateOrderAccount($order_no,$bankDesc,$accountData);
                    if($return['code']==0){
                        return $return;
                    }
                    $url = url('gateway/pay/payOrder',['key'=>$orderKey]);
                    header("Location:{$url}");
                    exit;
                }else{
                    $return = ['code'=>0,'msg'=>'无可用通道'];
                    return json($return);
                }
            }

        }

    }

    /** 壹玖智能扫码 快闪客户订单
     * @author xi 2019/7/18 11:41
     * @Note
     * @param $orderNo
     * @return array|bool
     */
    public function yijiujinfu($orderNo){

        try {

            if (!$orderNo) return ['code' => 0, 'msg' => '找不到订单号'];

            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return ['code' => 0, 'msg' => '找不到订单'];

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return ['code' => 0, 'msg' => 'api参数错误'];

            $apiData = json_decode($apiData, true);

            //拼接参数
            $sData = [
                'appid' => $apiData['appid'],
                'total_amount' => (float)$orderInfo['money'] * 100,
                'nonce_str' => get_rand_char(32),
                'out_trade_no' => $orderNo,
                'version' => 'V1.0',
                'return_url' => url('gateway/service/yijiujinfu'),
            ];

            $sData['sign'] = Yijiujinfu::getSign($apiData['key'], $sData);

            //记录请求支付的签名值
           // $res = model('PayOrderAssists')->update(['pay_sign'=>$sData['sign']],['order_no'=>$orderNo]);
           // if(!$res)  return false;

            $apiUrl = 'http://openapi.yijiujinfu.com/haipay/qrcodepay';

            $return = Http::post($apiUrl, $sData);
            $return = json_decode($return, true);
            if ($return['code'] == 0 && isset($return['qrcode_url']) && !empty($return['qrcode_url'])) {
                $res = model('PayOrders')->update(['status'=>2,'qrcode'=>$return['qrcode_url']],['status'=>1,'order_no'=>$orderNo]);
                if($res)  return true;
            }
            return false;
        }catch(\Exception $e){
            echo $e->getMessage();
            return false;
        }

    }

    /** jys188扫码 快闪客户订单
     * @author xi 2019/7/22 17:49
     * @Note
     * @param $orderNo
     * @return array|bool
     */
    public function jys188($orderNo){
        try {

            if (!$orderNo) return ['code' => 0, 'msg' => '找不到订单号'];

            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return ['code' => 0, 'msg' => '找不到订单'];

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return ['code' => 0, 'msg' => 'api参数错误'];

            $apiData = json_decode($apiData, true);

            $time = time();

            //拼接参数
            $sData = [
                'MerchantAccount'    => $apiData['appid'],
                'Timestamp'          => $time,
                'PayMoney'           => (float)$orderInfo['money'] * 100,
                'Nonce'              => get_rand_char(8),
                'OutOrderNo'         => $orderNo,
                'PayType'            => '2', //0 聚合 1 微信 2 支付宝
                'NoticeUrl'          => url('gateway/service/jys188'),
            ];

            $sData['Sign'] = Jys::getSign($apiData['key'], $sData);

            //记录请求支付的签名值
            // $res = model('PayOrderAssists')->update(['pay_sign'=>$sData['sign']],['order_no'=>$orderNo]);
            // if(!$res)  return false;

            $apiUrl = 'http://www.jys188.net/api/v2/worker/applyqrcodepay';

            $return = Http::post($apiUrl, $sData);
            $return = json_decode($return, true);
            if ($return['Success'] == 0 && isset($return['Data']['QrcodeUrl']) && !empty($return['Data']['SysId']) && !empty($return['Data']['CreMoney'])) {
                $money = (float)$return['Data']['CreMoney']/100;
                $res = model('PayOrders')->update(['status'=>2,'qrcode'=>$return['Data']['QrcodeUrl'],'pay_trade_no'=>$return['Data']['SysId'],'money'=>$money],['status'=>1,'order_no'=>$orderNo]);
                if($res) return ['code'=>1,'data'=>['money'=>$money]];
            }
            return false;
        }catch(\Exception $e){
            echo $e->getMessage();
            return false;
        }
    }

    /** 拼多多接口-快闪开发
     * @author xi 2019/7/29 15:24
     * @Note
     * @param $orderNo
     * @param $type
     * @return array|bool
     */
    protected function ksPDD($orderNo,$type){
        try {
            if (!$orderNo) return ['code' => 0, 'msg' => '找不到订单号'];

            if( !in_array($type,[1,2]) ){
                return ['code' => 0, 'msg' => '参数错误'];
            }
            $typeName = (int)$type==1 ? 'alipay' : 'wechat';

            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return ['code' => 0, 'msg' => '找不到订单'];

            if($orderInfo['status'] == 2) return ['code' => 1, 'msg' => 'success'];

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return ['code' => 0, 'msg' => 'api参数错误'];

            $apiData = json_decode($apiData, true);

            //$time = time();

            $sData = array(
                'type' => $typeName, // 通道代码 alipay/wechat
                'total' => (float)$orderInfo['money'], // 金额 单位 分
                'api_order_sn' => $orderInfo['order_no'], // 订单号
                'notify_url' => url('gateway/service/ksPDD'), // 异步回调地址
                'client_id' => $apiData['appid'],
                'timestamp' => KsPDD::getMillisecond() // 获取13位时间戳
            );

            $sData['sign'] = KsPDD::sign($sData,$apiData['key']); // 生成签名

            $apiUrl = 'http://pdd.ycsebo.cn/index/api/order';
            $return = Http::post($apiUrl, $sData);
            trace(print_r($return,true),'kspdd');

            $return = json_decode($return,true);
            if( isset($return['code']) && $return['code'] == 200 ){
                // 下单成功  自行处理
                //var_dump($return);
                if(isset($return['data']['h5_url']) && isset($return['data']['api_order_sn']) && isset($return['data']['order_sn'])){
                    $res = model('PayOrders')->update(['status'=>2,'qrcode'=>$return['data']['h5_url'],'pay_trade_no'=>$return['data']['order_sn']],['status'=>1,'order_no'=>$return['data']['api_order_sn']]);
                    if($res) return ['code' => 1, 'msg' => 'success','h5_url'=>$return['data']['h5_url']];
                }
            }
            // 下单失败
            //var_dump($return);
            return ['code' => 0, 'msg' => $return['msg'],'error_code'=>$return['error_code']];

        }catch(\Exception $e){
            echo $e->getMessage();
            return false;
        }

    }

    /** 环游客户定制
     * @param $orderNo
     * @param $type
     * @return array|bool
     */
    protected function HyAlipayH5($orderNo,$type){
        try {
            if (!$orderNo) return ['code' => 0, 'msg' => '找不到订单号'];

            if( !in_array($type,[1,2]) ){
                return ['code' => 0, 'msg' => '参数错误'];
            }
            $typeName = (int)$type==1 ? '8023' : '8024';

            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return ['code' => 0, 'msg' => '找不到订单'];

            if($orderInfo['status'] == 2) return ['code' => 1, 'msg' => 'success'];

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return ['code' => 0, 'msg' => 'api参数错误'];

            $apiData = json_decode($apiData, true);

            //$time = time();

            $sData = array(
                'uid' => $apiData['appid'],
                'title' => '环游测试商品',
                'total_fee' => (float)$orderInfo['money']*100, // 金额 单位 分
                'channel' => $typeName, // 通道代码
                'optional' => $orderNo,
                'notify_url' => url('gateway/service/HyAlipayH5'), // 异步回调地址
            );

            $sData['signature'] = Jys::getSignMd5($apiData['key'],$sData); // 生成签名

            $apiUrl = 'https://www.vpay526.com/Api/create/';
            $return = Jys::post($apiUrl, $sData);

            trace(print_r($return,true),'HyAlipayH5');
            if(isset($return['code']) && $return['code']){
                $return = json_decode($return['data'],true);
            }else{
                return ['code' => 0, 'msg' => '下单失败'];
            }

            if( isset($return['ret']) && $return['ret'] && isset($return['msg']) && !empty($return['msg']) ){
                // 下单成功  自行处理
                //var_dump($return);
                if(isset($return['msg']['payurl']) && isset($return['msg']['order_no'])){
                    $res = model('PayOrders')->update(['status'=>2,'qrcode'=>$return['msg']['payurl'],'pay_trade_no'=>$return['msg']['order_no']],['status'=>1,'order_no'=>$orderNo]);
                    if($res) return ['code' => 1, 'msg' => 'success'];
                }
            }
            // 下单失败
            //var_dump($return);
            return ['code' => 0, 'msg' => '下单失败'];

        }catch(\Exception $e){
            echo $e->getMessage();
            return false;
        }

    }


    /** 互站客户定制
     * @param $orderNo
     * @param $type
     * @return array|bool
     */
    protected function HZHkerPay($orderNo,$type){
        try {
            if (!$orderNo) return ['code' => 0, 'msg' => '找不到订单号'];


            if( !in_array($type,[1,2]) ){
                return ['code' => 0, 'msg' => '参数错误'];
            }
            $typeName = (int)$type==1 ? 'YS_A_01' : '8024';


            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return ['code' => 0, 'msg' => '找不到订单'];

            if($orderInfo['status'] == 2) return ['code' => 1, 'msg' => 'success'];

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return ['code' => 0, 'msg' => 'api参数错误'];

            $apiData = json_decode($apiData, true);

            //$time = time();

            $sData = array(
                'merchantNo' => $apiData['appid'],
                'productName' => 'aa互站api商品',
                'orderAmount' => (float)$orderInfo['money']*100, // 金额 单位 分
                'agentNo' => $apiData['jid'], // 通道代码
                'outOrderNo' => $orderNo,
                'notifyUrl'  => url('gateway/service/HZHkerPay'),
                'callbackUrl' => url('gateway/service/HZHkerPay'), // 异步回调地址
                'acqCode' => $typeName,
            );

            $sData['sign'] = Jys::getSignMd552($apiData['key'],$sData); // 生成签名

            $apiUrl = 'http://pycw.gotowayworld.com/papi/order';
            $return = Http::sendRequest($apiUrl, $sData);

            trace(print_r($return,true),'HZHkerPay');

            if(isset($return['ret']) && $return['ret']){
                $return = json_decode($return['msg'],true);
            }else{
                return ['code' => 0, 'msg' => '下单失败'];
            }

            if( isset($return['status']) && $return['status']=='T' && isset($return['payUrl']) && !empty($return['payUrl']) ) {
                // 下单成功  自行处理
                //var_dump($return);
                $res = model('PayOrders')->update(['status'=>2,'qrcode'=>$return['payUrl']],['status'=>1,'order_no'=>$orderNo]);
                if($res) return ['code' => 1, 'msg' => 'success'];
            }
            // 下单失败
            //var_dump($return);
            return ['code' => 0, 'msg' => $return['errMsg'],'error_code'=>$return['errCode']];

        }catch(\Exception $e){
            return ['code' => 0, 'msg' =>$e->getMessage()];
        }

    }

    /** 快闪客户定制
     * @author xi 2019/8/9 14:18
     * @Note
     * @param $orderNo
     * @return array
     */
    protected function ksYasewang($orderNo){
        try {
            if (!$orderNo) return ['code' => 0, 'msg' => '找不到订单号'];

            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return ['code' => 0, 'msg' => '找不到订单'];

            if($orderInfo['status'] == 2) return ['code' => 1, 'msg' => 'success'];

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return ['code' => 0, 'msg' => 'api参数错误'];

            $apiData = json_decode($apiData, true);

            //$time = time();

            $sData = array(
                'phone' => Jys::aesEncrypt($apiData['phone']),
                'returl' => Jys::aesEncrypt(url('gateway/index/ok')),
                'trxamt' => (float)$orderInfo['money']*100, // 金额 单位 分
                'sign' => Jys::aesEncrypt($apiData['sign']),
                'no' => Jys::aesEncrypt($orderNo),
                'notify_url' => Jys::aesEncrypt(url('gateway/service/ksYasewang')), // 异步回调地址
            );

            $apiUrl = 'https://m.8e88888.com/payM.ashx';
            $return = Http::sendRequest($apiUrl, $sData);

            trace(print_r($return,true),'ksYasewang');
            //p($return);
            if(isset($return['msg']) && $return['msg']){
                $res = model('PayOrders')->update(['status'=>2,'qrcode'=>$return['msg']],['status'=>1,'order_no'=>$orderNo]);
                if($res) return ['code' => 1, 'msg' => 'success'];
                return ['code' => 0, 'msg' => '下单失败'];
            }else{
                return ['code' => 0, 'msg' => '下单失败'];
            }

        }catch(\Exception $e){
            return ['code' => 0, 'msg' =>$e->getMessage()];
        }
    }

}
