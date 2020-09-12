<?php

namespace app\gateway\controller;

use app\common\service\RedisService;
use app\gateway\controller\GatewayBase;
use \think\Request;
use app\common\service\Account;
use app\common\service\Order;
use app\common\service\Sign;
use lib\Redis;

/**
 * Class Index
 * @package app\gateway\controller
 */
class Index extends GatewayBase
{
    private  $redis = null;
    public function _initialize()
    {
        //连接 实例redis
        $this->redis = new Redis([
            'host' => config('redis.hostname'),
            'port' => config('redis.hostport'),
            'pwd' => config('redis.pwd'),
        ]);


    }

    public function ok()
    {
        die('success');
    }

    /**
     * 首页
     * JH支付 <28490847742849084774@qq.com>
     * @return \think\response\View
     */
    public function index()
    {
        $orderNo = input('oid', '');

        $view_data = [
            'orderNo' => $orderNo
        ];

        return view('index/index', $view_data);
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
        if (!in_array($data['pay_type'], ['wechat', 'alipay','alipay_red','bank','alipay_rec'])) {
            return json(['code' => 10002, 'msg' => '请传入通道类型']);
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
            $user = model('Users')->where(['mch_id' => $data['appid']])->field('id,switch,audit_status,tid')->find();
            if (!$user) {
                return json(['code' => 20001, 'msg' => '网站用户不存在']);
            }
            if ($user['switch'] != '1') {
                return json(['code' => 20002, 'msg' => '网站用户状态已禁止']);
            }
            if ($user['audit_status'] != '1') {
                return json(['code' => 20003, 'msg' => '网站用户状态未审核']);
            }

        } else {  //验证码商
            $merchant = model('Merchants')->where(['mch_id' => $data['appid']])->field('id,switch,audit_status,tid')->find();
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


        //3：通道、费率
        $channel = model('Channels')->where(['code_name' => $data['pay_type']])->find();
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

        if ($orderStatus == 2) {             //用户费率
            $rate = model('Rates')->where(['type' => 2, 'channel_id' => $channelId, 'user_id' => $user['id']])->find();
            if (!$rate || $rate === null) {
                $channelRate = $channel['rate'];
                if ($channelRate < 0) {
                    return json(['code' => 20004, 'msg' => '网站用户费率不存在']);
                }
                $data['user_rate'] = $channelRate;
            } else {
                if ($rate['rate'] < 0) {
                    return json(['code' => 20005, 'msg' => '网站用户费率不正确']);
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

            //查找是否有指定码商通道
            $account = model('Designation')->getThisMerchant($user['id'],$channelId,$data['amount']);

            //is_parent_id => 1 存在父级码商
            if( $account['code']!=1 && $account['is_parent_id']==0 ) {
                // 没有父级码商，才会进入系统轮训通道方法  is_parent_id=>0 不存在父级码商
                $account = '';

                //获取指定码商通道失败，系统指定轮训算法
                $account = (new Account())->getAccount($algorithm, $channelId, $data['amount']);
            }

            if ($account['code']!=1) {
                return json(['code' => 40004, 'msg' => '没有可用的通道']);
            }

            $account = $account['data'];

        } else {
            $activeTime = time() - 30;
            $account = model('MerchantsAccounts')
                ->alias('a')
                ->join('ln_merchants_accounts_data d', 'a.id=d.merchant_account_id')
                ->where('a.type', 2)
                ->where('a.is_receiving', '1')
                ->where('a.receipt_name', $accountName)
                ->where('a.channel_id', $channelId)
                ->where('a.merchant_id', $merchant['id'])
                ->where('d.active_time', '>', $activeTime)
                ->find();
            if (!$account) return json(['code' => 40005, 'msg' => '测试没有可用的二维码']);
        }

        if ($orderStatus == 2) { //码商费率
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

        $orderNo = make_order_no();
        $orderType = ($orderStatus == 2) ? 2 : 1;
        $data['user_id'] = isset($user) ? $user['id'] : 0;



        $data['mtid'] = model('Merchants')->where(['id'=>$account['merchant_id']])->value('tid');
        $data['utid'] = $user['tid'];

        //组装支付接口
        $key = urlencode(encrypt($orderNo));
        $code = $channel['code_name'];
        $url = url("gateway/pay/payOrder", "key={$key}");

        $payUrlCode = 'null';

        if ( in_array( $data['pay_type'], ['alipay_red', 'bank', 'alipay_rec'] ) ) {
            $payUrlCode = $url;
        }

        $orderService = new Order();

        $result = $orderService->createOrder($orderType, $orderNo, $channelId, $account, $data,$payUrlCode,$channel['code_name']);

        if ($result['code'] != 1) {
            return json(['code' => 50000, 'msg' => $result['msg'], 'data' => []]);
        }

        //订单生成成功，扣除码商余额池里面的余额
        (new Account())->updataMerchantBalancePool($account['merchant_id'],$data['amount'],'reduce');

        // 通道关闭加次数
        model('AccountOffRecord')->plusNumber($account['id']);

        if ($format == 'json') {
            return json(['code' => 200, 'msg' => '获取二维码成功', 'data' =>['qrcode'=>$url,'order_no'=>$orderNo],'url'=>$url] );
        } else {
            header("Location:{$url}");
            exit;
        }

    }

    public function testAcc()
    {
        $algorithm = get_algorithm();
        if (!in_array($algorithm, ['random', 'queue', 'mer_balance', 'mer_weight'])) {
            return json(['code' => 40003, 'msg' => '通道的轮训算法错误']);
        }

        $account = (new Account())->getAccount($algorithm, 1);
    }

    public function test()
    {
        $redis = RedisService::getInstance();
        if (RedisService::$status !== true) {
            $this->redisStatus = RedisService::$status;
        }
        $length = $redis->lLen('useQueue');
        echo $length;

        $lists = $redis->lRange('useQueue', 0, $length - 1);
        dump($lists);

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
        //商户ID->到平台首页自行复制粘贴
        $appid = config('base.api_demo')['appid'];

        //S_KEY->商户KEY，到平台首页自行复制粘贴，该参数无需上传，用来做签名验证和回调验证，请勿泄露
        $app_key = config('base.api_demo')['app_key'];

        //订单号码->这个是四方网站发起订单时带的订单信息，一般为用户名，交易号，等字段信息
        $out_trade_no = date("YmdHis") . mt_rand(10000, 99999);
        //支付类型alipay、wechat
        $pay_type = $_REQUEST['pay_type'];
        //支付金额
        $amount = sprintf("%.2f", $_REQUEST['amount']);
        //异步通知接口url->用作于接收成功支付后回调请求
        $callback_url = url("gateway/index/ok");
        //支付成功后自动跳转url
        $success_url = 'http://aa.hocan.cn';
        //支付失败或者超时后跳转url
        $error_url = 'http://aa.hocan.cn';
        //版本号
        $version = '';
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
            return json(['code' => 20001, 'msg' => '网站用户不存在']);
        }
        if ($user['switch'] != '1') {
            return json(['code' => 20002, 'msg' => '网站用户状态已禁止']);
        }
        if ($user['audit_status'] != '1') {
            return json(['code' => 20003, 'msg' => '网站用户状态未审核']);
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


        $merchantId=1;$channelId=3;$accountId=55;

        $res = (new Account())->updataMerchantPool($merchantId,$channelId,$accountId,'off');
        die;
        $merchantId=1;$channelId=3;$accountId=6;

        $res = (new Account())->updataMerchantPool($merchantId,$channelId,$accountId,'on');

    }


}
