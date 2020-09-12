<?php

namespace app\gateway\controller;

use app\common\service\RedisService;
use app\gateway\controller\GatewayBase;
use lib\Http;
use \think\Request;
use app\common\service\Account;
use app\common\service\Sign;
use lib\Redis;


/**
 * Class Index
 * @package app\gateway\controller
 */
class Index extends GatewayBase
{

    private  $redis = null;

    private  $payTypeArr = ['alipay_red', 'bank', 'alipay_rec','alipay_tra','DingDing','DianDianChong','alipayWap','WangXin','ZiDanDuanXin','NailGroupCollection','alipayVariableTransfer','solidCode','alipaySolidCode','alipaySqueak','MicroChatRed','bankCode','pddAlipay','pddWechat','bubugaoBank','ZheJiangNongxin','AlipayGateway','MinimalistPayment','F2FPay','bankCodeAlipay','bankCodeWechat','ThreeWechatCode','ThreeAlipayCode','NuomiAlipay','MengPayFast','MengPayBank','FastConstruction','BankCardTransferBankCard','yijiujinfu','jys188','ksPDDWechat','ksPDDAlipay','FeiCode','HyAlipayH5','HyPddWechantH5','FeiZanBai','CopyToBank','HZHkerPay','ksYasewang','PersonalZhuanz'];

    private  $payTypeArrAll = ['wechat', 'alipay', 'alipay_red', 'bank', 'alipay_rec', 'alipay_tra', 'DingDing', 'DianDianChong','alipayWap','WangXin','ZiDanDuanXin','NailGroupCollection','alipayVariableTransfer','solidCode','alipaySolidCode','alipaySqueak','MicroChatRed','CloudFlashover','bankCode','pddAlipay','pddWechat','RuralCreditWechat','FlyChat','bubugaoBank','ZheJiangNongxin','AlipayGateway','RuralCreditAlipay','RuralCreditCloudFlashover','RuralCreditBank','MinimalistPayment','F2FPay','bankCodeAlipay','bankCodeWechat','ThreeWechatCode','ThreeAlipayCode','NuomiAlipay','Praise','MengPayFast','MengPayBank','FastConstruction','BankCardTransferBankCard','yijiujinfu','jys188','ksPDDWechat','ksPDDAlipay','FeiCode','HyAlipayH5','HyPddWechantH5','FeiZanBai','CopyToBank','HZHkerPay','ksYasewang','PersonalZhuanz'];

    //需要返回码串的通道
    private $payTypeQrcode = ['alipay', 'wechat','CloudFlashover','solidCode','alipaySolidCode','ZheJiangNongxin','bankCodeAlipay','bankCodeWechat','ThreeWechatCode','ThreeAlipayCode','bankCode','RuralCreditWechat','RuralCreditAlipay','RuralCreditCloudFlashover','RuralCreditBank','FeiCode','FeiZanBai'];
    //需要返回码串的通道ID
    private $payTypeQrcodeId = [1,2,15,16,19,20,22,25,27,28,29,33,34,35,36,47,50];

    private $payType1_1 = ['wechat', 'alipay','unionpay','bank'];

    private $payTypeChannelId = [ 'pddAlipay' => 21,'pddWechat'=>21 ];

    public function _initialize()
    {
        //连接 实例redis
        /*
        $this->redis = new Redis([
            'host' => config('redis.hostname'),
            'port' => config('redis.hostport'),
            'pwd' => config('redis.pwd'),
        ]);*/


    }

    public function ok()
    {
        echo 'success';
    }

    //支付成功页面
    public function paySuccess()
    {
        return view('pay/success');
    }

    /**
     * 首页
     * JH支付 <28490847742849084774@qq.com>
     * @return \think\response\View
     */
    public function index()
    {

        return view('index/demo');
    }

    /**
     * 获取二维码
     * JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     */
    public function getQrcode()
    {

        $orderNo = input('no', '');
        if (empty($orderNo)) return json(['status' => -1, 'msg' => '订单号不能为空']);
        $orderData = model('PayOrders')->where(['order_no' => $orderNo, 'status' => 2])->field('qrcode')->find();

        if (!empty($orderData)) {
            return json(['status' => 1, 'url' => $orderData['qrcode']]);
        }

        return json(['status' => -1, 'msg' => '无可用条形码']);
    }

    /**
     * 获取订单状态
     * JH支付 <85·82849084774@qq.com>
     * @return \think\response\Json
     */
    public function getStatus()
    {
        $orderNo = input('no', '');

        $orderData = model('PayOrders')->where(['order_no' => $orderNo, 'status' => 4])->field('success_url')->find();

        if (!empty($orderData)) {
            return json(['status' => 1, 'url' => $orderData['success_url']]);
        }

        return json(['status' => -1, 'msg' => '狗蛋儿']);
    }

    /**
     * 统一下单地址
     * JH支付 <8582849084774@qq.com>
     * @return \think\response\Json
     */
    public function unifiedorder()
    {
        $data = [];
        $format = input('get.format/s', 'text');

        // 是否为假数据  1  不是  2 是
        $falseBool = input('post.test_status/d', 1);


        $orderStatus = input('post.order_status/d', 2);
        $accountName = input('post.receipt_name/s', '');

        $data['appid'] = input('post.appid/s', '');
        $data['pay_type'] = input('post.pay_type/s', '');
        $data['callback_url'] = input('post.callback_url/s', '');
        $data['success_url'] = input('post.success_url/s', '');
        $data['error_url'] = input('post.error_url/s', '');
        $data['out_trade_no'] = input('post.out_trade_no/s', '');
        $data['amount'] = sprintf("%.2f", input('post.amount/f', '0.00'));
        $data['sign'] = input('post.sign/s', '');
        $data['out_uid'] = input('post.out_uid/s', '');
        $data['version'] = input('post.version/s', '');
        $data['return_type'] = input('post.return_type/s', '');


        //1：检查参数
        if (!$data['appid']) {
            return json(['code' => 10001, 'msg' => '请传入appid']);
        }

        //-------兼容默认v1.0版本，v1.0.1版本----------
        if( in_array( $data['version'], ['v1.0','v1.0.1','v2.0'] ) || empty($data['version']) || $data['version']=='')
        {
            //-------默认v1.0版本----------
            if (!in_array($data['pay_type'],$this->payTypeArrAll )) {
                return json(['code' => 10002, 'msg' => '请传入通道类型']);
            }

            //平多多通道，需要选择支付宝微信
            if( isset( $this->payTypeChannelId[$data['pay_type']] ) &&  $this->payTypeChannelId[$data['pay_type']] > 0 ){
                $channel = model('Channels')->where(['id' => $this->payTypeChannelId[$data['pay_type']]  ])->find();
            }

            //校检金额
            if(in_array($data['pay_type'],['ksPDDWechat','ksPDDAlipay'])){
                if(((float)$data['amount']%100)!= 0){
                    return json(['code' => 10005, 'msg' => '请求的金额必须是100的倍数，如 100,200等']);
                }
            }

        }else{
            // v1.1
            if (!in_array($data['pay_type'], $this->payType1_1)) {
                return json(['code' => 10002, 'msg' => '请传入通道类型']);
            }
        }

        if (!$data['callback_url']) {
            return json(['code' => 10003, 'msg' => '请传入回调地址']);
        }
        if (!$data['out_trade_no']) {
            return json(['code' => 10004, 'msg' => '请传入订单信息']);
        }
        if ($data['amount'] <= 0) {
            return json(['code' => 10005, 'msg' => '请传入支付金额']);
        }
        if (!$data['sign']) {
            return json(['code' => 10006, 'msg' => '请传入签名']);
        }
        if (model('PayOrders')->where(['out_trade_no' => $data['out_trade_no']])->count()) {
            return json(['code' => 10007, 'msg' => '订单号（out_trade_no）重复']);
        }


        if ($orderStatus == 2) { //验证用户
            $user = model('Users')->where(['mch_id' => $data['appid']])->field('id,switch,audit_status,tid,agent_id,unit_id')->find();
            if (!$user) {
                return json(['code' => 20001, 'msg' => '盘口用户不存在']);
            }
            if ($user['switch'] != '1') {
                return json(['code' => 20002, 'msg' => '盘口用户状态已禁止']);
            }
            if ($user['audit_status'] != '1') {
                return json(['code' => 20003, 'msg' => '盘口用户状态未审核']);
            }

        } else if($orderStatus != 3){  //验证码商
            $merchant = model('Merchants')->where(['mch_id' => $data['appid']])->field('id,switch,audit_status')->find();
            if (!$merchant) {
                return json(['code' => 20001, 'msg' => '码商用户不存在']);
            }
            if ($merchant['switch'] != '1') {
                return json(['code' => 20002, 'msg' => '码商用户状态已禁止']);
            }
            if ($merchant['audit_status'] != '1') {
                return json(['code' => 20003, 'msg' => '码商用户状态未审核']);
            }
        }


        //2：签名验证
        $signClass = new Sign();
        $signRes = $signClass->verifySign($data, $orderStatus);
        if ($signRes === false) {
            return json(['code' => 30000, 'msg' => $signClass->getError()]);
        }


        //-------兼容v1.1 以及 后续版本----------
        if( !in_array( $data['version'], ['v1.0','v1.0.1','v2.0'] ) &&  !empty($data['version']) ){

            $return = $this->payOrderV_1_1($data,$user,$format,$orderStatus);
            if(!($return['code']==200)){
                return json($return);
            }
            if ($format == 'json') {
                return json($return);
            }else {
                header("Location:{$return['url']}");
            }
            exit;
        }

        //3：通道、费率
        if(!isset($channel) || empty($channel)){
            $channel = model('Channels')->where(['code_name' => $data['pay_type']])->find();
        }

        if (!$channel) {
            return json(['code' => 40001, 'msg' => '通道不存在']);
        }
        if ($channel['switch'] != '1') {
            return json(['code' => 40002, 'msg' => '通道已关闭']);
        }
        $channelId = $channel['id'];


        //判断金额
        if(floatval($data['amount'])<floatval($channel['min_amount'])){
            return json(['code' => 40003, 'msg' => '请求的金额不能低于最小金额('.$channel['min_amount'].')']);
        }
        if(floatval($data['amount'])>floatval($channel['max_amount'])){
            return json(['code' => 40004, 'msg' => '请求的金额不能大于最大金额('.$channel['max_amount'].')']);
        }

        if (isset($user['id']) && $user['id']>0) {             //用户费率
            $rate = model('Rates')->where(['type' => 2, 'channel_id' => $channelId, 'user_id' => $user['id']])->find();
            if (!$rate || $rate === null) {
                $channelRate = $channel['rate'];
                if ($channelRate < 0) {
                    return json(['code' => 20004, 'msg' => '盘口用户费率不存在']);
                }
                $data['user_rate'] = $channelRate;
            } else {
                if ($rate['rate'] < 0) {
                    return json(['code' => 20005, 'msg' => '盘口用户费率不正确']);
                }
                $data['user_rate'] = $rate['rate'];
            }
        } else {
            $data['user_rate'] = 0;
        }


        if ($orderStatus == 2) { //正常的收款账户

            $algorithm = get_algorithm();
            if (!in_array($algorithm, ['random', 'queue', 'mer_balance', 'mer_weight'])) {
                return json(['code' => 40003, 'msg' => '通道的轮训算法错误']);
            }

            $account = (new Account())->getPoolAccount($user['unit_id'],$algorithm,$user,$data['amount'],$channelId,$channel['type'],1);

            if ($account['code']!=1) {
                return json(['code' => 40004, 'msg' => '没有可用的通道']);
            }

            $account = $account['data'];

        } else if($orderStatus == 3){
            $merchantId = input('post.merchant_id');
            $account = model('MerchantsAccounts')
                ->where(['receipt_name'=> $accountName,'is_receiving'=>1,'merchant_id'=>$merchantId,'channel_id'=>$channelId])
                ->find();
            if (!$account) return json(['code' => 40005, 'msg' => '测试没有可用的二维码']);
        }else{
            $activeTime = time() - 30;
            $account = model('MerchantsAccounts')
                ->alias('a')
                ->field('a.*,d.active_time as active_time,a.unit_id')
                ->join('ln_merchants_accounts_data d', 'a.id=d.merchant_account_id')
                ->where('a.type', 2)
                ->where('a.is_receiving', '1')
                ->where('a.parent_id', '0')
                ->where('a.receipt_name', $accountName)
                ->where('a.channel_id', $channelId)
                ->where('a.merchant_id', $merchant['id'])
                ->where('d.active_time', '>', $activeTime)
                ->find();
            if (!$account) return json(['code' => 40005, 'msg' => '测试没有可用的二维码']);
        }

        //---------银行卡转银行卡---------------
        if($account == 'BankCardTransferBankCard'){
            $return = $this->BankCardTransferBankCard($data,$user,$format,$orderStatus,$channelId);
            if(!($return['code']==200)){
                return json($return);
            }
            if ($format == 'json') {
                return json($return);
            }else {
                header("Location:{$return['url']}");
            }
            exit;
        }
        //---------银行卡转银行卡---------------


        if ($account['merchant_id']) { //码商费率
            $merRate = model('Rates')->where(['type' => 1, 'channel_id' => $channelId, 'merchant_id' => $account['merchant_id']])->find();
            if (!$merRate || $merRate === null) {
                $channelRate = $channel['rate'];
                if ($channelRate < 0) {
                    return json(['code' => 20005, 'msg' => '码商用户费率不存在']);
                }
                $data['mer_rate'] = $channelRate;
            } else {
                if ($merRate['rate'] < 0) {
                    return json(['code' => 20006, 'msg' => '码商用户费率不正确']);
                }
                $data['mer_rate'] = $merRate['rate'];
            }
        } else {
            $data['mer_rate'] = 0;
        }

        if ( $data['pay_type'] == 'NailGroupCollection' ){
            $orderNo = make_order_no_two();
        } else {
            $orderNo = make_order_no();
        }
        $orderType = ($orderStatus == 2) ? 2 : 1;
        $data['user_id'] = isset($user) ? $user['id'] : 0;

        $data['mtid'] = model('Merchants')->where(['id'=>$account['merchant_id']])->value('tid');
        $data['utid'] = isset($user['tid']) ? $user['tid'] : 0;

        //组装支付接口
        $key = urlencode(encrypt($orderNo));
        $code = $channel['code_name'];
        $url = url("gateway/pay/payOrder",['key'=>$key,'order_no'=>$orderNo]);

        $payUrlCode = 'null';

        if ( in_array( $data['pay_type'], $this->payTypeArr ) ) {
            $payUrlCode = $url;
        }

        $orderService = new \app\common\service\Order();

        $result = $orderService->createOrder($orderType, $orderNo, $channelId, $account, $data,$payUrlCode,$channel['code_name'],$falseBool);

        if ($result['code'] != 1) {
            return json(['code' => 50000, 'msg' => $result['msg'], 'data' => []]);
        }

        //订单生成成功，扣除码商余额池里面的余额
        $AccountS = new Account();
        $AccountS->updataMerchantBalancePool($account['unit_id'],$account['merchant_id'],$result['data']['merchant_gain'],'reduce');
        $AccountS->orderAmount($result['data']['id'],'Add');

        // 通道关闭加次数
        model('AccountOffRecord')->plusNumber($account['id']);

        if ($format == 'json') {
            // 实时码 qrcode 返回码串
            if (in_array($data['pay_type'], $this->payTypeQrcode)) {
                sleep(2);
                $qrcode = model('PayOrders')->where(['order_no' => $orderNo])->value('qrcode');
                if ($qrcode) {
                    return json(['code' => 200, 'msg' => '获取二维码成功', 'data' =>['qrcode'=>$qrcode,'order_no'=>$orderNo],'url'=>$url,'key'=>$key]);
                }
            }
            return json(['code' => 200, 'msg' => '获取二维码成功', 'data' =>['qrcode'=>$url,'order_no'=>$orderNo],'url'=>$url,'key'=>$key]);
        } else {
//            echo "<form name='fr' action=".$url." method='POST'><input type='hidden' name='key' value=".$key."></form><script type='text/javascript'>document.fr.submit();</script>";
            header("Location:{$url}");
            exit;
        }

    }


    /** v1.1 下单接口,兼容后续版本
     * @author xi 2019/3/19 18:18
     * @Note
     * @param $data
     * @param $user
     * @param $format
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function payOrderV_1_1($data,$user,$format,$orderStatus)
    {
        //p('1');
        $algorithm = get_algorithm();
        if (!in_array($algorithm, ['random', 'queue', 'mer_balance', 'mer_weight'])) {
            return ['code' => 40003, 'msg' => '通道的轮训算法错误'];
        }


        $account = (new Account())->getPoolAccount($user['unit_id'],$algorithm,$user,$data['amount'],'',$data['pay_type'],2);


        if ($account['code']!=1) {
            return ['code' => 40004, 'msg' => '没有可用的通道'];
        }

        $account = $account['data'];


        if($account == 'BankCardTransferBankCard'){
            $channelId = 42;
        }else{
            $channelId = $account['channel_id'];
        }

        //校检金额
        if(in_array($channelId,[45,46])){
            if(((float)$data['amount']%100)!= 0){
                return json(['code' => 10005, 'msg' => '请求的金额必须是100的倍数，如 100,200等']);
            }
        }

        $channelInfo = model('Channels')->where(['id'=>$channelId])->find();

        if (isset($user['id']) && $user['id']>0) {            //用户费率
            $rate = model('Rates')->where(['type' => 2, 'channel_id' => $channelInfo['id'], 'user_id' => $user['id']])->find();
            if (!$rate || $rate === null) {
                $channelRate = $channelInfo['rate'];
                if ($channelRate < 0) {
                    return ['code' => 20004, 'msg' => '盘口用户费率不存在'];
                }
                $data['user_rate'] = $channelRate;
            } else {
                if ($rate['rate'] < 0) {
                    return ['code' => 20005, 'msg' => '盘口用户费率不正确'];
                }
                $data['user_rate'] = $rate['rate'];
            }
        } else {
            $data['user_rate'] = 0;
        }

        //---------银行卡转银行卡---------------
        if($account == 'BankCardTransferBankCard'){
            $channelId = 42;
            $return = $this->BankCardTransferBankCard($data,$user,$format,$orderStatus,$channelId);
            if(!($return['code']==200)){
                return $return;
            }
            if ($format == 'json') {
                return $return;
            }else {
                header("Location:{$return['url']}");
            }
            exit;
        }
        //---------银行卡转银行卡---------------



        if (isset($account['merchant_id']) && $account['merchant_id']) {  //码商费率
            $merRate = model('Rates')->where(['type' => 1, 'channel_id' => $channelInfo['id'], 'merchant_id' => $account['merchant_id']])->find();
            if (!$merRate || $merRate === null) {
                $channelRate = $channelInfo['rate'];
                if ($channelRate < 0) {
                    return ['code' => 20005, 'msg' => '码商用户费率不存在'];
                }
                $data['mer_rate'] = $channelRate;
            } else {
                if ($merRate['rate'] < 0) {
                    return ['code' => 20006, 'msg' => '码商用户费率不正确'];
                }
                $data['mer_rate'] = $merRate['rate'];
            }
        } else {
            $data['mer_rate'] = 0;
        }


        if ( $channelInfo['code_name'] == 'NailGroupCollection' ){
            $orderNo = make_order_no_two();
        } else {
            $orderNo = make_order_no();
        }
        $orderType = 2;
        $data['user_id'] = isset($user) ? $user['id'] : 0;

        $data['mtid'] = model('Merchants')->where(['id'=>$account['merchant_id']])->value('tid');
        $data['utid'] = isset($user['tid']) ? $user['tid'] : 0;

        //组装支付接口
        $key = urlencode(encrypt($orderNo));

        $url = url("gateway/pay/payOrder", "key={$key}&order_no={$orderNo}");
        //p($url);
        $payUrlCode = 'null';

        if ( in_array( $channelInfo['code_name'], $this->payTypeArr ) ) {
            $payUrlCode = $url;
        }

        $orderService = new \app\common\service\Order();

        $result = $orderService->createOrder($orderType, $orderNo, $channelInfo['id'], $account, $data,$payUrlCode,$channelInfo['code_name'],1);

        if ($result['code'] != 1) {
            return ['code' => 50000, 'msg' => $result['msg'], 'data' => []];
        }

        //订单生成成功，扣除码商余额池里面的余额
        $AccountS = new Account();
        $AccountS->updataMerchantBalancePool($account['unit_id'],$account['merchant_id'],$result['data']['merchant_gain'],'reduce');
        $AccountS->orderAmount($result['data']['id'],'Add');

        // 通道关闭加次数
        model('AccountOffRecord')->plusNumber($account['id']);

        if ($format == 'json') {
            if (in_array($account['channel_id'], $this->payTypeQrcodeId)) {
                sleep(2);
                $qrcode = model('PayOrders')->where(['order_no' => $orderNo])->value('qrcode');
                if ($qrcode) {
                    return ['code' => 200, 'msg' => '获取二维码成功', 'data' =>['qrcode'=>$qrcode,'order_no'=>$orderNo],'url'=>$url];
                }
            }
            return ['code' => 200, 'msg' => '获取二维码成功', 'data' =>['qrcode'=>$url,'order_no'=>$orderNo],'url'=>$url];

        } else {
            return ['code'=>200,'url'=>$url];
        }


    }

    /** 银行卡转银行卡，下单
     * @author xi 2019/7/15 19:45
     * @Note
     * @param $data
     * @param $user
     * @param $format
     * @param $orderStatus
     * @return array
     */
    private function BankCardTransferBankCard($data,$user,$format,$orderStatus){

        $channelId = 42;
        $orderNo = make_order_no();

        $data['user_id'] = isset($user) ? $user['id'] : 0;
        $data['merchant_id'] = 0;
        $data['mtid'] = 0;
        $data['utid'] = isset($user['tid']) ? $user['tid'] : 0;

        //组装支付接口
        $key = urlencode(encrypt($orderNo));

        $userKey = model('Users')->where(['mch_id'=>$data['appid']])->value('mch_key');
        if(!$userKey) return ['code'=>0,'msg'=>'参数错误'];

        $url = url("gateway/pay/payOrder", "key={$key}&order_no={$orderNo}");
        //p($url);
        $payUrlCode = $url;

        $orderService = new \app\common\service\Order();

        $result = $orderService->createOrder(2, $orderNo, $channelId, 0, $data,$payUrlCode,'BankCardTransferBankCard',1);
        if($result['code']==1){
            if ($format == 'json') {

                return ['code' => 200, 'msg' => '获取二维码成功', 'data' =>['qrcode'=>$url,'order_no'=>$orderNo],'url'=>$url];

            } else {
                return ['code'=>200,'url'=>$url];
            }
        }
        return ['code'=>0,'msg'=>$result['msg']];
    }


    public function testAcc()
    {
        $algorithm = get_algorithm();
        if (!in_array($algorithm, ['random', 'queue', 'mer_balance', 'mer_weight'])) {
            return json(['code' => 40003, 'msg' => '通道的轮训算法错误']);
        }

        $account = (new Account())->getAccount($algorithm, 1);
    }


    public function demo()
    {
        return view('index/demo');
    }

    /**
     * 支付订单
     * JH支付 <8582849084774@qq.com>
     * @return \think\response\View
     */
    public function payOrder()
    {
        $setting = getVariable('setting',-1);
        if(isset($setting['mUser'])){
            $setting = $setting['mUser'];
        }else{
            return json(['code'=>0,'msg'=>'请指定默认测试商户（盘口）']);
        }
        //p($setting);
        //商户ID->到平台首页自行复制粘贴
        $appid = $setting['appid'];

        //S_KEY->商户KEY，到平台首页自行复制粘贴，该参数无需上传，用来做签名验证和回调验证，请勿泄露
        $app_key = $setting['key'];

        //订单号码->这个是四方网站发起订单时带的订单信息，一般为用户名，交易号，等字段信息
        $out_trade_no = date("YmdHis") . mt_rand(10000, 99999);
        //支付类型alipay、wechat
        $pay_type = $_REQUEST['pay_type'];
        //支付金额
        $amount = sprintf("%.2f", $_REQUEST['amount']);
        //异步通知接口url->用作于接收成功支付后回调请求
        $callback_url = url("gateway/index/ok");
        //支付成功后自动跳转url
        $success_url = url("gateway/index/demo");
        //支付失败或者超时后跳转url
        $error_url = url("gateway/index/demo");
        //p($pay_type);

        //版本号
        $version = 'v1.0';



        //用户网站的请求支付用户信息，可以是帐号也可以是数据库的ID:15017399440
        $out_uid = '';

        $data = [
            'appid'        => $appid,
            'pay_type'     => $pay_type,
            'out_trade_no' => $out_trade_no,
            'amount'       => $amount,
            'callback_url' => $callback_url,
            'success_url'  => $success_url,
            'error_url'    => $error_url,
            'version'      => $version,
            'out_uid'      => $out_uid,
        ];


        $data['sign'] = (new Sign())->getSign($app_key, $data);
        $data['url'] = url("gateway/index/unifiedorder");
        $data['test_status'] = 1;

        return view('index/payOrder', $data);
    }

    /**
     * 获取商户订单
     * JH支付 <8582849084774@qq.com>
     */
    public function getOrder(){
        $data = [];

        $data['appid'] = input('post.appid/s', '');
        $data['out_trade_no'] = input('post.out_trade_no/s', '');
        $data['sign'] = input('post.sign/s', '');

        //1：检查参数
        if (!$data['appid']) {
            return json(['code' => 10003, 'msg' => '请传入appid']);
        }
        if (!$data['out_trade_no']) {
            return json(['code' => 10004, 'msg' => '请传入订单信息']);
        }
        if (!$data['sign']) {
            return json(['code' => 10005, 'msg' => '请传入签名']);
        }

        $user = model('Users')->where(['mch_id' => $data['appid']])->field('id,switch,audit_status')->find();
        if (!$user) {
            return json(['code' => 20001, 'msg' => '盘口用户不存在']);
        }
        if ($user['switch'] != '1') {
            return json(['code' => 20002, 'msg' => '盘口用户状态已禁止']);
        }
        if ($user['audit_status'] != '1') {
            return json(['code' => 20003, 'msg' => '盘口用户状态未审核']);
        }

        //2：签名验证
        $signClass = new Sign();
        $signRes = $signClass->verifySign($data, 2);
        if ($signRes === false) {
            return json(['code' => 30000, 'msg' => $signClass->getError()]);
        }

        $orderData = model('PayOrders')
            ->alias('a')
            ->join('ln_pay_order_assists d', 'a.order_no=d.order_no')
            ->where('a.user_id',$user['id'])
            ->where('a.out_trade_no',$data['out_trade_no'])
            ->order('a.id','desc')
            ->field('a.out_trade_no,a.amount,a.status,a.pay_time,a.callback_status,d.callback_url')
            ->select();

        if (empty($orderData)) {
            return json(['code' => 40000, 'msg' => '该用户暂无订单']);
        }

        return json(['code' => 200, 'msg' => '获取订单成功','data'=>$orderData]);
    }


    public function text(){

        $channelId = 5;$type='alipay';$action='Off';
        $res = (new Account())->channelPool($channelId,$type,$action);
        p($res);
        die;
        $merchantId=1;$channelId=3;$accountId=6;

        $res = (new Account())->updataMerchantPool($merchantId,$channelId,$accountId,'on');

    }

    // 下单假数据接口
    public function testPlaceOrder(){
        return json(['code'=>0,'msg'=>'非法访问']);
        $typeArr = ['CloudFlashover', 'CloudFlashover','CloudFlashover','CloudFlashover','CloudFlashover','CloudFlashover'];
        $str = mt_rand(0,count($typeArr));

        $amount = mt_rand(1,100)*mt_rand(10,100);

        $setting = getVariable('setting',-1);
        if(isset($setting['mUser'])){
            $setting = $setting['mUser'];
        }else{
            return json(['code'=>0,'msg'=>'请指定默认测试商户（盘口）']);
        }
        //p($setting);
        //商户ID->到平台首页自行复制粘贴
        $appid = $setting['appid'];

        //S_KEY->商户KEY，到平台首页自行复制粘贴，该参数无需上传，用来做签名验证和回调验证，请勿泄露
        $app_key = $setting['key'];

        //订单号码->这个是四方网站发起订单时带的订单信息，一般为用户名，交易号，等字段信息
        $out_trade_no = date("YmdHis") . mt_rand(10000, 99999);
        //支付类型alipay、wechat
        $pay_type = 'CloudFlashover';
        //支付金额
        $amount = sprintf("%.2f",$amount);
        //异步通知接口url->用作于接收成功支付后回调请求
        $callback_url = url("gateway/index/ok");
        //支付成功后自动跳转url
        $success_url = 'http://aa.hocan.cn';
        //支付失败或者超时后跳转url
        $error_url = 'http://aa.hocan.cn';
        //版本号
        $version = 'v2.0';
        //用户网站的请求支付用户信息，可以是帐号也可以是数据库的ID:15017399440
        $out_uid = '';

        $data = [
            'appid'        => $appid,
            'pay_type'     => $pay_type,
            'out_trade_no' => $out_trade_no,
            'amount'       => $amount,
            'callback_url' => $callback_url,
            'success_url'  => $success_url,
            'error_url'    => $error_url,
            'version'      => $version,
            'out_uid'      => $out_uid,
        ];

        $data['sign'] = (new Sign())->getSign($app_key, $data);
        $data['url'] = url("gateway/index/unifiedorder");
        $data['test_status'] = 2;

        return view('index/payOrder', $data);
    }


    //测试请求接口
    public function getsign(){


        // echo '0713E94E3CA0EDB11F66D851BAB09648'.'<br/>';
        $app_key='Xi3sdK3ykAOWMah6Wzrj0oYPC9ZDbldy';
        $data =[
            'appid'=>'1061843',
            //'order_no'=>'C407333473688514',
            'pay_type'     => 'RuralCreditWechat',
            // 'pay_type'     => 'NailGroupCollection',
            'amount'       => '500.00',
            //'amount_true'=>'0.01',
            'callback_url'=>'http://pay17.jxzf1.com/pay/sync_back/philip',
            'success_url'=>'http://pay17.jxzf1.com/pay/href_back/philip',
            'error_url'=>'http://pay17.jxzf1.com/pay/href_back/philip',
            // 'name'  => '王沛良',
            'out_trade_no'    => 'zzz123-1560999783679',
            'out_uid'=>'151634367',
            'version'=>'v2.0',
        ];

        $data['sign'] = (new Sign())->getSign($app_key, $data);
        echo $data['sign'];die;
        $res = Http::post('https://pay.ffb169.com/index/unifiedorder?format=json',$data);
        echo ($res);

    }
    //测试回调
    public function textCallback(){
        $postData = input('post.');
        trace(print_r($postData,true),'textCallback');
    }

    //清空redis
    public function clearRedis(){
        $id = input('get.key');
        if($id!='12345678'){
            die;
        }
        (new Account())->claerAllKeys();
    }



}
