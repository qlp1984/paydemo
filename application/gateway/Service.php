<?php
/**
 * Class Index
 * @package app\gateway\controller
 */

namespace app\gateway\controller;
use app\common\model\PayOrders;
use app\common\service\OrderDaily;
use app\common\service\Account;
use app\socket\controller\Api;
use app\common\service\Sign;
use lib\Http;
use think\Cache;
use lib\alipay\AlipayWapNotifyService;
use lib\pinduoduo\Pinduoduo;
use lib\alipay\aop\AopClient;
use lib\yijiujinfu\Yijiujinfu;
use lib\jys\Jys;
use lib\ksPDD\KsPDD;

class Service extends GatewayBase
{


    /**
     * 通知回调地址
     * JH支付 <8588·*2849084774@qq.com>
     */
    public function callBack()
    {
        die;
        set_time_limit(0);
        //查找未支付订单
        $orderList = PayOrders::with(['assists','users'])
            ->field('id,order_no,amount,qrcode,ip,user_id,merchant_id,merchant_account_id,out_trade_no,pay_trade_no,pay_type,fees,channel_id,create_time,pay_time,success_url,error_url')
            ->where(['status'=>2,'callback_status'=>1])
            ->whereOr(['status'=>1,'callback_status'=>1])
            ->limit(100)
            ->order('id asc')
            ->select();

        //总条数
        $count = count($orderList);

        $orderListKey=[];
        if($count > 10) {
            //获取随机失败条数
            $num = mt_rand(1, ceil($count*0.05));
            for ($i = 0; $i <= $num; $i++) {
                $orderListKey[] = mt_rand(1, count($orderList));
            }
        }

        foreach($orderList as $k => $v){
            $signData = [];
            if($v['assists']['callback_count']<3 && !empty($v['assists']['callback_url'])){
                // 下发码商额度
                $this->merchantsMoney($v['merchant_id']);
                // 下发通道信息
                $this->accountOrderData($v['merchant_account_id']);

                if(!empty($orderListKey)){
                    foreach($orderListKey as $ks => $vs){
                        if(($vs-1) == $k){
                            //修改订单，回调状态
                            $signData['callbacks'] = 'CODE_FAILURE';
                        }else{
                            //修改订单状态为已支付
                            $update=['status'=>4];
                            model('payOrders')->where(['id'=>$v['id']])->update($update);

                            $signData['callbacks'] = 'CODE_SUCCESS';

                        }
                    }
                }else{
                    $signData['callbacks'] = 'CODE_SUCCESS';
                }

                //echo $v['pay_type'];die;
                $signData['appid'] = $v['users']['mch_id'];
                $signData['pay_type'] = payTypeV1_1()[$v['pay_type']];
                $signData['success_url'] = $v['success_url'];
                $signData['error_url'] = $v['error_url'];
                $signData['out_trade_no'] = $v['order_no'];
                $signData['amount'] = (string)$v['amount'];

                //生成签名
                $signData['sign'] = model('Sign', 'service')->getSign($v['users']['mch_key'], $signData);

                //请求回调
                $res = Http::sendRequest($v['assists']['callback_url'], $signData);

                //回调成功并返回 ok
                if($res['ret'] && strtolower($res['msg']) == 'success'){
                    //增加订单统计'=
                    OrderDaily::optDaily($v['order_no']);

                    //修改订单回调状态
                    $update=['callback_status'=>2];
                    model('payOrders')->where(['id'=>$v['id']])->update($update);
                    $update=[
                        'callback_content'=>'ok',
                        'callback_time'=>time(),
                        'callback_count'=>$v['assists']['callback_count']+1,
                    ];
                    model('payOrderAssists')->where(['order_no'=>$v['order_no']])->update($update);

                }else{
                    $update=[
                        'callback_content'=>isset($res['info'])?$res['info']:null,
                        'callback_time'=>time(),
                        'callback_count'=>$v['assists']['callback_count']+1,
                    ];
                    model('payOrderAssists')->where(['order_no'=>$v['order_no']])->update($update);

                }

            }
        }

    }


    public function test(){
        p(Cache::get('curl_xi2222'));
    }

    public function testDay($orderNo='')
    {
        OrderDaily::optDaily($orderNo);
    }

    /**
     * 通知回调地址（单条）
     * JH支付 <2849084774@qq.com>
     * @param string $orderNo
     * @return \think\response\Json
     */
    public function callBackOne($orderNo=''){
        //p($orderNo);
        if(!$orderNo || empty($orderNo) || $orderNo=='0'){
            return json(['code'=>0,'msg'=>'orderNo->参数错误']);
        }

        // $orderNo = 'C125968883114254';

        //查找已支付订单
        $order = PayOrders::with(['assists','users','merchants'])
            ->where(['status'=>4,'order_no'=>$orderNo,'callback_status'=>1,'is_balance'=>0,'pay_time'=>['>',time()-24*60*60]])
            ->order('id desc')
            //->fetchSql(true)
            ->find();

        if(empty($order)){
            return json(['code'=>0,'msg'=>'orderInfo->找到不到订单信息']);
        }

        //开始回调-----------
        //组装回调信息
        $signData['callbacks'] = 'CODE_SUCCESS';
        $signData['appid'] = $order['users']['mch_id'];
        $signData['pay_type'] = payTypeV1_1()[$order['pay_type']];
        $signData['success_url'] = $order['success_url'];
        $signData['error_url'] = $order['error_url'];
        $signData['out_trade_no'] = $order['out_trade_no'];
        $signData['amount'] = $order['amount'];

        //版本兼容
        if( !in_array( $order['version'] ,['v1.0'] ) ){
            //v1.0.1 回调新增字段
            $signData['amount_true'] = $order['money'];//实付金额
            $signData['out_uid'] = $order['out_uid'];//用户网站的请求支付用户信息，可以是帐号也可以是数据库的ID
        }

        //生成签名
        $signData['sign'] = model('Sign', 'service')->getSign($order['users']['mch_key'], $signData);
        //echo $order['assists']['callback_url'];p($signData);
        //修改订单状态

        //请求回调
        $resCurl = Http::sendRequest($order['assists']['callback_url'],$signData);
       // trace('fail'.print_r($signData,true),'callback');
        //回调成功并返回 success
        if($resCurl['ret'] && strtolower($resCurl['msg']) == 'success'){

            //添加订单到结算队列
            if($order['order_status'] == 2) {
                //添加订单到结算队列
                $res = (new Account())->payOrderIsBalance($order['id'],'add');

                //写入错误日志
                //if($res['code']!=1) trace('return:'.json_encode($res),'callbackBalance['.$orderNo.']');
               // $res='';
            }

            //增加订单统计
            OrderDaily::optDaily($orderNo);

            //修改订单回调状态
            model('PayOrders')->where(['id'=>$order['id']])->update(['callback_status'=>2]);

            //记录回调信息
            $update=[
                'callback_content'=>'success',
                'callback_time'=>time(),
                'callback_count'=>$order['assists']['callback_count']+1,
                'remark'    => json_encode($signData)
            ];
            model('PayOrderAssists')->where(['order_no'=>$order['order_no']])->update($update);

        }else{

            //回调失败，记录回调返回信息
            $update=[
                'callback_content'=>isset($resCurl['info'])?json_encode($resCurl['info']):null,
                'callback_time'=>time(),
                'callback_count'=>$order['assists']['callback_count']+1,
                'remark'    => json_encode($signData)
            ];
            model('PayOrderAssists')->where(['order_no'=>$order['order_no']])->update($update);
        }

    }



    /** 查找所有已过期的订单，修改状态
     * @author xi 2019/2/17 15:28
     * @Note
     */
    public function orderTimeOut(){

        //查找所有过期的订单
        $list = model('PayOrders')
            ->field('id,amount,merchant_id,unit_id,merchant_gain')
            ->where('status','<=',3)
            ->where(['is_balance'=>0,'callback_status'=>1])
            ->where('deadline_time','<=',time())
            ->where('deadline_time','>',time()-15*60)
            ->order('id desc')
            ->limit(30)
            ->select();

        foreach($list as $k=>$v){

            $this->upadteOrderStatus($v['unit_id'],$v['id'],$v['merchant_id'],$v['merchant_gain']);

        }
    }

    /** 修改订单状态
     * @author xi 2019/2/17 15:32
     * @Note
     * @param $id
     * @param $merchantId
     * @param $amount 订单金额
     */
    public function upadteOrderStatus($unitId,$id,$merchantId,$amount){
        //码商池回收码商余额
        $Account = new Account();
        $res = $Account->orderAmount($id,'Reduce');
        if($res===true){
            //删除记录的订单金额，已超时订单
            $Account->updataMerchantBalancePool($unitId,$merchantId,$amount,'add');
        }
    }


    /**回调成功后，结算订单 redis队列  （处理码商，用户余额变动，资金统计）
     * @author xi 2019/2/19 14:49
     * @Note
     * @param $order 订单信息 array
     * @return array
     */
    public function callbackBalance($orderId){
        //删除累计的订单
        (new Account())->orderAmount($orderId,'Reduce');
        $is_balance = model('PayOrders')->orderBalance($orderId);
        //结算状态
        if($is_balance['code']==1) {
            //修改结算状态
            $update['is_balance'] = 1;
            $res = model('PayOrders')->where(['id'=>$orderId])->update($update);

            return json(['code'=>1,'msg'=>'success->订单(ID:'.$orderId.')结算']);

        }
        return json(['code'=>0,'msg'=>'fail->订单(ID:'.$orderId.')结算']);
    }

    /** 支付宝异步回调
     * @author xi 2019/3/28 19:19
     * @Note
     */
    public function alipayWapNotify(){
        header('Content-type:text/html; Charset=utf-8');
        $postData = input('post.');

        if(!isset($postData['out_trade_no'])){
            return json(['code'=>0,'msg'=>'找不到订单']);
        }

        //获取订单信息
        $orderInfo = model('PayOrders')->with('assists')->where([
            'order_no'=>$postData['out_trade_no'],
            'is_balance'=>0,
            'callback_status'=>1,
             'status'=>['<=',2],
            'deadline_time'=>['>', time()-1800]
        ])->find();
        if(empty($orderInfo)){
            return json(['code'=>0,'msg'=>'找不到订单']);
        }

        //获取原生通道接口额外参数：密钥、appid
        $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');
        if(empty($apiData) || $apiData == null){
            return json(['code'=>0,'msg'=>'api参数错误']);
        }
        $apiData = json_decode($apiData,true);

        //支付宝公钥，账户中心->密钥管理->开放平台密钥，找到添加了支付功能的应用，根据你的加密类型，查看支付宝公钥
        $alipayPublicKey = $apiData['public_key'];

        $aliPay = new AlipayWapNotifyService($alipayPublicKey);
        //验证签名
        $result = $aliPay->rsaCheck($_POST,$_POST['sign_type']);

        trace($alipayPublicKey.'|'.$result,$postData['out_trade_no']);

        if($result===true && $postData['trade_status']== 'TRADE_SUCCESS'){
            //修改订单为已支付
            model('PayOrders')->update(['status'=>4,'pay_time'=>time()],['order_no'=>$postData['out_trade_no']]);

            // 更新关闭通道列表
            model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

            //系统回调
            $this->callBackOne($postData['out_trade_no']);
            //处理你的逻辑，例如获取订单号$_POST['out_trade_no']，订单金额$_POST['total_amount']等
            //程序执行完后必须打印输出“success”（不包含引号）。如果商户反馈给支付宝的字符不是success这7个字符，支付宝服务器会不断重发通知，直到超过24小时22分钟。一般情况下，25小时以内完成8次通知（通知的间隔频率一般是：4m,10m,10m,1h,2h,6h,15h）；
            echo 'success';exit();
        }
        echo 'error';exit();
    }


    /** 拼多多接收回调
     * @author xi 2019/5/22 18:20
     * @Note
     */
    public function pddNotify(){
        $data['mchid'] = input('get.mchid','');
        $data['orderno'] = input('get.orderno','');
        $data['outorderno'] = input('get.outorderno','');
        $data['amount'] = input('get.amount','');
        $data['attach'] = input('get.attach','');
        $sign = input('get.sign','');


        trace(print_r($data,true),$data['outorderno'].'pinduoduo');

        if( empty($data['mchid']) || empty($data['orderno']) || empty($data['outorderno']) || empty($data['amount']) || empty($data['attach']) || empty($sign) ){
            echo 'err';
        }
        //获取订单信息
        $orderInfo = model('PayOrders')->where(['order_no'=>$data['outorderno']])->find();
        if(!$orderInfo){
            echo 'err';
        }

        //获取订单的签名参数信息
        $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');

        if(empty($apiData) || $apiData == null){
            echo 'err';
        }
        $apiData = json_decode($apiData,true);

        //验签
        $getSign = Pinduoduo::getSignCallback($data,$apiData['key']);
        if($getSign != $sign){
            echo 'err';
        }
        echo 'ok';

        //修改订单状态
        model('PayOrders')->update(['status'=>4],['order_no'=>$data['outorderno'],'status'=>2]);

        //系统回调
        $this->callBackOne($data['outorderno']);


    }

    /** 永利-步步高回调接口
     * @author xi 2019/6/3 15:57
     * @Note
     */
    public function bubugaoNotify(){
        $data['msg'] = input('get.msg','');
        $data['orderno'] = input('get.orderno','');
        $data['payno'] = input('get.payno','');
        $data['nickname'] = input('get.nickname','');
        $data['card'] = input('get.card','');

        trace(print_r($data,true),$data['orderno'].'bubugao');

        if($data['msg'] == 'success' && !empty($data['orderno'])){
            //获取订单信息
            $orderInfo = model('PayOrders')->where(['order_no'=>$data['orderno']])->find();
            if(!$orderInfo){
                echo 'err';
            }

            $updateData = ['status'=>4,'pay_time'=>time()];
            if($data['payno']){
                $updateData['pay_trade_no'] = $data['payno'];
            }
            if($orderInfo['order_status']==1){
                $updateData['callback_status'] = 2;
            }

            //修改订单状态
            model('PayOrders')->update($updateData,['order_no'=>$data['orderno'],'status'=>2]);

            if($orderInfo['order_status']==2){
                // 更新关闭通道列表
                model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);
                //系统回调
                $res = $this->callBackOne($data['orderno']);
                trace(print_r($res,true),$data['orderno'].'bubugao1');
            }


        }else{
            echo 'err';
        }
    }


    public static function f2fpayPutKeyfile($data){
        //print_r(__ROOT__);die;
        $path='public/f2fdata/';
        if(!is_dir($path)){
            mkdir($path);
        }

        $file_pointer = $path.'1.txt';
        //echo $file_pointer;die;
        //$vardata=file_get_contents($data['url']);
        file_put_contents($file_pointer,$data);
        return '/public/'.'1.txt';
    }

    /** 支付宝当面付 接收异步回调接口
     * @author xi 2019/7/1 16:21
     * @Note
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function f2fpay(){

        $pData = input('post.');
        if(!isset($pData) || empty($pData)){
            die('err-post');
        }
        //cache::set('f2fpay',print_r($pData,true));

        if($pData['trade_status'] == 'TRADE_SUCCESS'){

            //查找订单信息
            $orderInfo = model('PayOrders')->where([
                'order_no'=>$pData['out_trade_no'],
                'is_balance'=>0,
                'callback_status'=>1,
                'status'=>2,
                'deadline_time'=>['>', time()-1800]
            ])->find();
            if(empty($orderInfo)){
                return json(['code'=>0,'msg'=>'找不到订单']);
            }

            // 获取通道的签名参数 start
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');

            if(empty($apiData) || $apiData == null){
                return json(['code'=>0,'msg'=>'api参数错误']);
            }
            $apiData = json_decode($apiData,true);


            $public_key = $apiData['publicKey'];
            //cache::set('publicKey',$apiData['publicKey']);

            $aop = new AopClient();
            $aop->alipayrsaPublicKey = $public_key;
            //此处验签方式必须与下单时的签名方式一致
            $flag = $aop->rsaCheckV1($pData, $aop->alipayrsaPublicKey, "RSA2");
            //cache::set('f2fpay_sign',$flag);
            if (!$flag){
                echo 'error签名错误';exit();
            }

            //修改订单为已支付
            model('PayOrders')->update(['status'=>4,'pay_time'=>time()],['order_no'=>$pData['out_trade_no']]);

            // 更新关闭通道列表
            model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

            //系统回调
            $this->callBackOne($pData['out_trade_no']);
            //处理你的逻辑，例如获取订单号$_POST['out_trade_no']，订单金额$_POST['total_amount']等
            //程序执行完后必须打印输出“success”（不包含引号）。如果商户反馈给支付宝的字符不是success这7个字符，支付宝服务器会不断重发通知，直到超过24小时22分钟。一般情况下，25小时以内完成8次通知（通知的间隔频率一般是：4m,10m,10m,1h,2h,6h,15h）；
            echo 'success';exit();


        }


    }

    /** 梦支付  回调接口
     * @author xi 2019/7/8 15:11
     * @Note
     * @return array
     */
    public function mengPay(){
        $pData = input('post.');
        trace(print_r($pData,true),'mengpay_callback');
        if(!isset($pData) || empty($pData)){
            die('err-post');
        }

        //获取订单信息
        $orderInfo = model('PayOrders')->with('assists')->where(['order_no'=>$pData["orderid"]])->find();

        if(empty($orderInfo)) return json(['code'=>0,'msg'=>'找不到订单']);

        /*** 获取通道的签名参数 start ***/
        $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>$orderInfo['channel_id'],'account_id'=>$orderInfo['merchant_account_id']])->value('sign_data');

        if(empty($apiData) || $apiData == null) return json(['code'=>0,'msg'=>'api参数错误']);

        $apiData = json_decode($apiData,true);

        $returnArray = array( // 返回字段
            "memberid" => $pData["memberid"], // 商户ID
            "orderid" =>  $pData["orderid"], // 订单号
            "amount" =>  $pData["amount"], // 交易金额
            "datetime" =>  $pData["datetime"], // 交易时间
            "transaction_id" =>  $pData["transaction_id"], // 流水号
            "returncode" => $pData["returncode"]
        );

        $md5key = $apiData['key'];
        ksort($returnArray);
        reset($returnArray);
        $md5str = "";
        foreach ($returnArray as $key => $val) {
            $md5str = $md5str . $key . "=" . $val . "&";
        }
        $sign = strtoupper(md5($md5str . "key=" . $md5key));
        if ($sign == $pData["sign"]) {
            if ($pData["returncode"] == "00") {

                //修改订单为已支付
                model('PayOrders')->update(['status'=>4,'pay_time'=>time()],['order_no'=>$pData['orderid']]);

                // 更新关闭通道列表
                model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

                //系统回调
                $this->callBackOne($pData['orderid']);

                echo 'OK';
            }
        }
    }

    /** 壹玖智能扫码 - 回调接口
     * @author xi 2019/7/18 15:28
     * @Note
     * @return string|void
     */
    public function yijiujinfu(){
        $pData = input('post.');
        if(empty($pData)){
            return;
        }
        trace(print_r($pData,true),'yijiujinfu_callback');

        //接口回调成功，并且支付状态为1
        if($pData['code']==0 && $pData['trade_state']==1){
            $orderNo = $pData['out_trade_no'];
            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return json(['code' => 0, 'msg' => '找不到订单']);

            if( time() > $orderInfo['deadline_time']+5 ) {
                model('PayOrders')->update(['status'=>3],['order_no'=>$orderNo,'status'=>['<=',2]]);
                return json(['code' => 0, 'msg' => '订单超时']);
            }

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return json(['code' => 0, 'msg' => 'api参数错误']);

            $apiData = json_decode($apiData, true);

            //同步订单接口
           $apiUrl = 'http://openapi.yijiujinfu.com/haipay/refresh';
           $sData = [
               'appid'              => $apiData['appid'],
               'out_trade_no'       => $orderNo,
               'trade_id'           => $pData['trade_id'],
               'nonce_str'          => get_rand_char(32),
               'version'            => 'V1.0',
           ];
            $sData['sign'] = Yijiujinfu::getSign($apiData['key'], $sData);
            $return = Http::post($apiUrl, $sData);
            $return = json_decode($return, true);
            trace(print_r($return,true),'yijiujinfu_tongbu');

            //pay_time
            if($return['code']==0 && $return['trade_state']==1){
                //修改订单状态,支付成功
                $res = model('PayOrders')->update(['pay_trade_no'=>$return['trade_id'],'status'=>4,'pay_time'=>strtotime($return['pay_time'])],['status'=>2,'order_no'=>$orderNo]);
                if($res){
                    // 更新关闭通道列表
                    model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

                    //系统回调
                    $this->callBackOne($orderNo);
                    echo 'success';
                }
            }
        }

    }

    /** jys188扫码 - 回调接口
     * @author xi 2019/7/23 11:18
     * @Note
     * @return array|\think\response\Json|void
     */
    public function jys188(){
        $pData = input('post.');
        if(empty($pData)){
            return;
        }
        trace(print_r($pData,true),'jys188_callback');

        //接口回调成功，并且支付状态为1
        if(isset($pData['OrderStatus']) && isset($pData['SysId']) && $pData['OrderStatus']==1 && $pData['SysId']){
            $orderNo = $pData['SysId'];
            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['pay_trade_no' => $orderNo])->find();

            if (empty($orderInfo)) return json(['code' => 0, 'msg' => '找不到订单']);

            if( time() > $orderInfo['deadline_time']+5 ) {
                model('PayOrders')->update(['status'=>3],['pay_trade_no'=>$orderNo,'status'=>['<=',2]]);
                return json(['code' => 0, 'msg' => '订单超时']);
            }

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return json(['code' => 0, 'msg' => 'api参数错误']);

            $apiData = json_decode($apiData, true);

            $payTime = time();

            $signData = [
                'Nonce'=>$pData['Nonce'],
                'SysId'=>$orderInfo['pay_trade_no'],
                'PayMethod'=>$pData['PayMethod'],
                'ApplyMoney'=>$pData['ApplyMoney'],
                'CreMoney'=>$pData['CreMoney'],
                'CreTime'=>$pData['CreTime'],
                'OrderStatus'=>$pData['OrderStatus'],
            ];

            if(isset($pData['PayTime']) && $pData['PayTime']){
                $signData['PayTime'] = $pData['PayTime'];
                $payTime = $pData['PayTime'];
            }
            if(isset($pData['OrderMsg']) && $pData['OrderMsg']){
                $signData['OrderMsg'] = $pData['OrderMsg'];
            }
            if(isset($pData['Attach']) && $pData['Attach']){
                $signData['Attach'] = $pData['Attach'];
            }

            $sign = Jys::getSign($apiData['key'], $signData);
            if($sign != $pData['Sign']){
                //p($signData);
                return json(['code'=>0,'msg'=>'Sign error']);
            }

            //修改订单状态,支付成功
            $res = model('PayOrders')->update(['status'=>4,'pay_time'=>$payTime],['status'=>2,'pay_trade_no'=>$orderNo]);
            if($res){
                // 更新关闭通道列表
                model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

                //系统回调
                $this->callBackOne($orderInfo['order_no']);
                echo 'Success';
            }

        }

    }

    /** 回调ksPDD-快闪开发
     * @author xi 2019/7/29 19:29
     * @Note
     */
    public function ksPDD(){

        $pData = input('post.');
        if(empty($pData)){
            return;
        }

        //接口回调成功，并且支付状态为1
        if(isset($pData['callbacks']) && $pData['callbacks']=='CODE_SUCCESS'){
            $orderNo = $pData['api_order_sn'];
            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return json(['code' => 0, 'msg' => '找不到订单']);

            if( time() > $orderInfo['deadline_time']+5 ) {
                model('PayOrders')->update(['status'=>3],['order_no'=>$orderNo,'status'=>['<=',2]]);
                trace(print_r($pData,true),'ksPDD_callback_time');
                return json(['code' => 0, 'msg' => '订单超时']);
            }

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return json(['code' => 0, 'msg' => 'api参数错误']);

            $apiData = json_decode($apiData, true);

            $payTime = time();

            $signData = $pData;

            $sign = KsPDD::sign($signData,$apiData['key']);
            if($sign != $pData['sign']){
                //p($signData);
                trace(print_r($pData,true),'ksPDD_callback_sign');
                return json(['code'=>0,'msg'=>'Sign error']);
            }

            //修改订单状态,支付成功
            $res = model('PayOrders')->update(['status'=>4,'pay_time'=>$payTime],['status'=>2,'order_no'=>$orderNo]);
            if($res){
                // 更新关闭通道列表
                model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

                //系统回调
                $this->callBackOne($orderInfo['order_no']);
                echo 'Success';
            }

        }
    }

    /** 环游定制接口
     * @return \think\response\Json|void
     */
    public function HyAlipayH5(){

        $pData = input('post.');
        if(empty($pData)){
            return;
        }
        trace(print_r($pData,true),'HyAlipayH5_callback');
        //接口回调成功，并且支付状态为1
        if(isset($pData['ret_code']) && $pData['ret_code']=='0'){

            if (!isset($pData['optional'])) return json(['code' => 0, 'msg' => '找不到订单']);

            $orderNo = $pData['optional'];

            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return json(['code' => 0, 'msg' => '找不到订单']);

            if( time() > $orderInfo['deadline_time']+5 ) {
                model('PayOrders')->update(['status'=>3],['order_no'=>$orderNo,'status'=>['<=',2]]);

                return json(['code' => 0, 'msg' => '订单超时']);
            }

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return json(['code' => 0, 'msg' => 'api参数错误']);

            $apiData = json_decode($apiData, true);

            $payTime = time();

            $signData = $pData;

            $sign = Jys::getSignMd5Callback($apiData['secret'],$signData);
            if($sign != $pData['signature']){
                //p($signData);
                trace(print_r($pData,true),'ksPDD_callback_sign');
                return json(['code'=>0,'msg'=>'Sign error']);
            }

            //修改订单状态,支付成功
            $res = model('PayOrders')->update(['status'=>4,'pay_time'=>$payTime],['status'=>2,'order_no'=>$orderNo]);
            if($res){
                // 更新关闭通道列表
                model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

                //系统回调
                $this->callBackOne($orderInfo['order_no']);
                echo 'Success';
            }

        }
    }

    /** 互站定制 接口
     * @author xi 2019/8/8 14:44
     * @Note
     * @return \think\response\Json|void
     */
    public function HZHkerPay(){

        $pData = input('post.');
        if(empty($pData)){
            return;
        }
        trace(print_r($pData,true),'HZHkerPay_callback');
        //接口回调成功，并且支付状态为1
        if(isset($pData['orderStatus']) && $pData['orderStatus']=='SUCCESS'){

            if (!isset($pData['outOrderNo'])) return json(['code' => 0, 'msg' => '找不到订单']);

            $orderNo = $pData['outOrderNo'];
            $pay_trade_no = $pData['orderNo'];

            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return json(['code' => 0, 'msg' => '找不到订单']);

            if ($orderInfo['status']!=2) return json(['code' => 0, 'msg' => '找不到订单']);

            if( time() > $orderInfo['deadline_time']+5 ) {
                model('PayOrders')->update(['status'=>3],['order_no'=>$orderNo,'status'=>['<=',2]]);

                return json(['code' => 0, 'msg' => '订单超时']);
            }

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return json(['code' => 0, 'msg' => 'api参数错误']);

            $apiData = json_decode($apiData, true);

            $payTime = strtotime($pData['payTime']);

            $signData = $pData;
            unset($signData['sign']);
            $sign = Jys::getSignMd552($apiData['key'],$signData);
            if($sign != $pData['sign']){
                //p($signData);
                trace(print_r($pData,true),'HZHkerPay_callback_sign');
                return json(['code'=>0,'msg'=>'Sign error']);
            }

            //修改订单状态,支付成功
            $res = model('PayOrders')->update(['status'=>4,'pay_time'=>$payTime,'pay_trade_no'=>$pay_trade_no],['status'=>2,'order_no'=>$orderNo]);
            if($res){
                // 更新关闭通道列表
                model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

                //系统回调
                $this->callBackOne($orderInfo['order_no']);
                echo 'Success';
            }

        }
    }

    /** 53 快闪定制接口
     * @return \think\response\Json|void
     */
    public function ksYasewang(){

        $pData = input('post.');
        if(empty($pData)){
            return;
        }
        trace(print_r($pData,true),'ksYasewang_callback');
        //接口回调成功，并且支付状态为1
        if(isset($pData['trxstatus']) && Jys::aesDecrypt($pData['trxstatus'])=='0000'){

            if (!isset($pData['no']) || !isset($pData['sign'])) return json(['code' => 0, 'msg' => '找不到订单']);

            //解密订单号
            $orderNo = Jys::aesDecrypt($pData['no']);

            if(!$orderNo) return json(['code' => 0, 'msg' => '找不到订单']);

            //获取订单信息
            $orderInfo = model('PayOrders')->with('assists')->where(['order_no' => $orderNo])->find();

            if (empty($orderInfo)) return json(['code' => 0, 'msg' => '找不到订单']);

            if ($orderInfo['status']!=2) return json(['code' => 0, 'msg' => '找不到订单']);

            if( time() > $orderInfo['deadline_time']+5 ) {
                model('PayOrders')->update(['status'=>3],['order_no'=>$orderNo,'status'=>['<=',2]]);

                return json(['code' => 0, 'msg' => '订单超时']);
            }

            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id' => $orderInfo['channel_id'], 'account_id' => $orderInfo['merchant_account_id']])->value('sign_data');

            if (empty($apiData) || $apiData == null) return json(['code' => 0, 'msg' => 'api参数错误']);

            $apiData = json_decode($apiData, true);



            $sign = Jys::aesDecrypt($pData['sign']);
            if($sign != $apiData['sign']){
                //p($signData);
                trace(print_r($pData,true),'ksYasewang_callback_sign');
                return json(['code'=>0,'msg'=>'Sign error']);
            }

            //修改订单状态,支付成功
            $res = model('PayOrders')->update(['status'=>4,'pay_time'=>time()],['status'=>2,'order_no'=>$orderNo]);
            if($res){
                // 更新关闭通道列表
                model('AccountOffRecord')->reduceNumber($orderInfo['merchant_account_id']);

                //系统回调
                $this->callBackOne($orderInfo['order_no']);
                echo 'Success';
            }

        }
    }


    /** 手动结算订单
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
   public function text(){
       echo cache::get('f2fpay_sign');
       p( cache::get('f2fpay'));
       die;
         $orders = model('PayOrders')->where(['user_id'=>43,'is_balance'=>0,'callback_status'=>2,'status'=>4])->select();
     foreach($orders as $k=>$v){
       model('PayOrderAssists')->update(['balance_count'=>0],['order_no'=>$v['order_no']]);
       $this->callbackBalance($v['id']);
     }

   }

}
