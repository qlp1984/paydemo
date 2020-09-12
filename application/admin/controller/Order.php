<?php
/**
 * @Author Quincy  2019/1/8 下午5:28
 * @Note 交易订单控制器
 */

namespace app\admin\controller;

use app\common\model\Channels;
use app\common\model\Merchants;
use app\common\model\MerchantsAccounts;
use app\common\model\OrderDaily;
use app\common\model\PayOrders;
use app\common\model\PayNomatchOrders;
use app\common\model\Users;
use lib\Csv;
use xh\library\session;
use lib\Http;

class Order extends AdminBase
{

    protected $pay_type = [1=>'alipay',2=>'wechat',3=>'unionpay',4=>'bank'];
    /**
     * @Author Quincy  2019/1/8 下午7:54 JH支付 <2849084774@qq.com>
     * @Note 各通道收入统计
     * @return \think\response\View
     */
    public function channel()
    {

        $merchantName = input('get.merchant_name', '');
        $channelId = input('get.channel_id', '');
        $merchantId = '';
        if ($merchantName) {
            $merchantId = Merchants::UnitId($this->unitId)->where(['name|phone' => $merchantName])->value('id');
            $merchantId = $merchantId ?: -1;
        }

        $data = (new OrderDaily())->getChannelStats($merchantId,$channelId);

        return view('order/channel', [
            'data'=>$data,
            'channels'=>get_channels(),
        ]);
    }

    /**
     * 导出各通道统计收入 JH支付 <2849084774@qq.com>
     */
    public function exportChannel()
    {
        $merchantName = input('get.merchant_name', '');
        $channelId = input('get.channel_id', '');
        $merchantId = '';
        $name = session('admin')['name'];

        if ($merchantName) {
            $merchant = Merchants::where('name|phone', $merchantName)->find();
            $merchantId = !empty($merchant) ? $merchant->id : -1;
        }

        $data = (new PayOrders)->getChannelStats($merchantId,$channelId);

        $coulumnName = ['码商用户名','码商手机号', '通道', '今日收款', '今日收款数量','昨日收款','昨日收款数量', '7天收款','7天收款数量','30天收款','30天收款数量'];
        $fieldName = ['merchant_name', 'merchant_phone','channel_name', 'today_money','today_num', 'yesterday_money', 'yesterday_num','weekday_money', 'weekday_num', 'month_money','month_num'];
        $csvName = $name.'-' . date('Y-m-d').'.csv';
        addAdminLog('导出各通道收入统计');

        Csv::simpleCsv( $coulumnName, $fieldName,$csvName,$data);

    }

    /**
     * @Author Quincy  2019/1/5 下午2:18 JH支付 <2849084774@qq.com>
     * @Note 历史订单
     * @return \think\response\View
     */
    public function index()
    {
        $account = input('get.account', '');
        $unit_id = input('get.unit_id', '');
        $userName = input('get.user_name', '');
        $merchantName = input('get.merchant_name', '');
        $channelId = input('get.channel_id', '');
        $callback_status = input('get.callback_status', '');
        $order_no = input('get.order_no', '');
        $out_trade_no = input('get.out_trade_no', '');
        $pay_trade_no = input('get.pay_trade_no', '');
        $start_time = input('get.start_time', 0);
        $end_time = input('get.end_time', 0);

        // 获取 unitId
        $this->unitId = !empty($unit_id) ? $this->getUnitId($unit_id) : $this->unitId;

        $userId = $merchantId ='';
        $money = null;
        $orderSuccessOne = 0.00;
        $orderSuccessTwo = 0.00;
        $orderSuccessThree = 0.00;
        $orWhere = $this->unitId ? ['unit_id' => $this->unitId] : [];
        $orWhere['order_status'] = 2;
        $map = $this->unitId ? ['unit_id' => $this->unitId] : [];

        if ($userName) {
            $userId = Users::where(['name|phone' => $userName])->UnitId($this->unitId)->value('id') ?: -1;
            $orWhere['user_id'] = $userId;
        }

        if ($merchantName) {
            $merchantId = Merchants::where(['name|phone' => $merchantName])->UnitId($this->unitId)->value('id') ?: -1;
            $orWhere['merchant_id'] = $merchantId;
        }

        if( $channelId ){
            $orWhere['channel_id'] = $channelId;
        }

        if ($start_time || $end_time) {
            //$startTime = strtotime($start_time);
            //$endTime = strtotime($end_time);
            $map['create_time'] = ['between time', [$start_time, $end_time]];
        }
        $where = [];
        if($pay_trade_no){
            $where['pay_trade_no'] = $pay_trade_no;
        }
        if($account){
            $accountDtatId = model('MerchantsAccounts')->where(['receipt_name'=>$account])->field('id')->select();

            $accountId = [];
            foreach ($accountDtatId as $k=>$v){
                $accountId[$k] = $v['id'];
            }
            $where['merchant_account_id'] = ['in',$accountId];
        }

        //p($pay_trade_no);

        $order = PayOrders::with('assists')
            ->with(['users', 'merchants', 'accounts'])
            ->User($userId)
            ->Merchant($merchantId)
            ->Channel($channelId)
            ->CallbackStatus($callback_status)
            ->No($order_no)
            ->Time($start_time, $end_time)
            ->OutNo($out_trade_no)
            ->UnitId($this->unitId)
            ->where($where)
            ->order('id desc')
            ->paginate(15,false,['query'=>request()->param()]);

        //p(collection($order)->toArray());

        if (!empty($order->all())) {
            $money = model('PayOrders')->getPlatMoney($userId,$merchantId,$channelId, $map); // 统计金额

            $nowTime = time();
            $st1 = $nowTime-900;
            $st2 = $nowTime-1800;
            $st3 = $nowTime-3600;

            $str1 = 'create_time > '.$st1;
            $str2 = 'create_time > '.$st2;
            $str3 = 'create_time > '.$st3;

            $orderSuccessOne = model('PayOrders')->getOrderSuccessRate($orWhere,$str1);
            $orderSuccessTwo = model('PayOrders')->getOrderSuccessRate($orWhere,$str2);
            $orderSuccessThree = model('PayOrders')->getOrderSuccessRate($orWhere,$str3);
        }

        $nowTime = strtotime(date('Y-m-d',time()));
        // 准备条件
        $cashRecordArr = [];
        if ($start_time || $end_time) {
            $startTime = strtotime($start_time);
            $nowTime = strtotime(date('Y-m-d',$startTime));
            $cashRecordArr['create_time'] = ['between time', [$start_time, $end_time]];
        }

        $cashRecord = model('PayOrders')->getCashRecord($cashRecordArr,$nowTime); // 出入金、抢单

        return view('order/index' . $this->template, [
            'order'=>$order,
            'channels'=>get_channels(),
            'money'=>$money,
            'config'=> config('dictionary'),
            'orderSuccessOne' => $orderSuccessOne,
            'orderSuccessTwo' => $orderSuccessTwo,
            'orderSuccessThree' => $orderSuccessThree,
            'cashRecord'    => $cashRecord,
            'userBalance'   => model('UsersBalance')->sumBalance($this->Admin['unit_id']),
            'merchantBalance'   => model('MerchantsBalance')->sumBalance($this->Admin['unit_id']),
            'rechargeBalance'   => model('RechargeLog')->sumBalance($this->Admin['unit_id']),
        ]);
    }

    /**
     *  导出历史订单 JH支付 <2849084774@qq.com>
     */
    public function exportOrder()
    {
        $userName = input('get.user_name', '');
        $merchantName = input('get.merchant_name', '');
        $channelId = input('get.channel_id', '');
        $callback_status = input('get.callback_status', '');
        $order_no = input('get.order_no', '');
        $out_trade_no = input('get.out_trade_no', '');
        $name = session('admin')['name'];
        $start_time = input('get.start_time', 0);
        $end_time = input('get.end_time', 0);

        $where = [];



        if($start_time && $end_time){
            $where['create_time'] = ['between time', [$start_time, $end_time]];
        }
        if ($userName || $merchantName || $channelId ) {

            $userId = $merchantId = $accountId = '';
            if ($userName) {
                $userId = Users::UnitId(cookie('unitId'))->where(['name|phone' => $userName])->value('id') ?: -1;
                if($userId>0) $where['user_id'] = $userId;
            }

            if ($merchantName) {
                $merchantId = Merchants::UnitId(cookie('unitId'))->where(['name|phone' => $merchantName])->value('id') ?: -1;
                if($merchantId>0) $where['merchant_id'] = $merchantId;
            }

            if($channelId) $where['channel_id'] = $channelId;

        }
        $query = PayOrders::with('assists')
            ->with(['users', 'merchants', 'accounts', 'mobile'])
            ->No($order_no)
            ->CallbackStatus($callback_status)
            ->UnitId($this->unitId)
            ->OutNo($out_trade_no)
            ->where($where);


        $coulumnName = [ '系统订单号', '商户订单号','网站用户名', '码商用户名', '收款账户', '通道', '应付价','实付','条码金额',
            '盘口费率','码商费率','盘口手续费','码商扣钱','平台盈利',
            '创建时间','支付时间','回调时间','手续费','订单状态','回调状态','回调内容','重试次数',
            '收款步骤','支付类型','手机型号','系统'
        ];


        $fieldName = [ 'order_no', 'out_trade_no','name@users','name@merchants','name@accounts','channel_id', 'pricing', 'money', 'amount',
            'user_rate','mer_rate','fees','merchant_gain','platform_gain',
            'create_time','pay_time','callback_time@assists', 'status_str', 'callback_status_str',  'callback_content@assists', 'callback_count@assists',
            'rec_step_str','pay_type@mobile','type@mobile','model@mobile'
        ];
        $csvName = $name.'-' . date('Y-m-d').'.csv';
        addAdminLog('导出历史订单统计');

        Csv::downCsv($query, $coulumnName, $fieldName,$csvName);
    }


    /**
     * @Author Quincy  2019/1/5 下午2:18 JH支付 <82849084774@qq.com>
     * @Note 无匹配订单
     * @return \think\response\View
     */
    public function nomatch()
    {
        $unit_id = input('get.unit_id', '');
        $userName = input('get.user_name', '');
        $merchantName = input('get.merchant_name', '');
        $channelId = input('get.channel_id', '');

        // 获取 unitId
        $this->unitId = !empty($unit_id) ? $this->getUnitId($unit_id) : $this->unitId;

        $userId = $merchantId =  '';

        if ($userName) {
            $userId = Users::where('name|phone', $userName)->UnitId($this->unitId)->value('id') ?: -1;
        }

        if ($merchantName) {
            $merchantId = Merchants::where('name|phone', $merchantName)->UnitId($this->unitId)->value('id') ?: -1;
        }

        $order = PayNomatchOrders::with('assists')
            ->with(['user', 'merchants', 'accounts'])
            ->UnitId($this->unitId)
            ->User($userId)
            ->Merchant($merchantId)
            ->Channel($channelId)
            ->order('id desc')
            ->paginate(15,false,['query' => request()->param()]);

        return view('order/nomatch' . $this->template, ['order'=>$order,'channels'=>get_channels()]);
    }

    /**
     * 导出无匹配订单 JH支付 <2849084774@qq.com>
     */
    public function exportNomatch()
    {
        $userName = input('get.user_name', '');
        $merchantName = input('get.merchant_name', '');
        $channelId = input('get.channel_id', '');
        $name = session('admin')['name'];

        $query = PayNomatchOrders::with(['assists', 'user', 'merchants']);

        if ($userName || $merchantName || $channelId ) {
            $userId = $merchantId = '';

            if ($userName) {
                $userId = Users::where('name|phone', $userName)->value('id') ?: -1;
            } else {
                $where['user_id'] = ['in', Users::UnitId($this->unitId)->column('id')];
            }

            if ($merchantName) {
                $merchantId = Merchants::where('name|phone', $merchantName)->value('id') ?: -1;
            } else {
                $where['merchant_id'] = ['in', Merchants::UnitId($this->unitId)->column('id')];
            }

            $query = $query->User($userId)
                ->Merchant($merchantId)
                ->Channel($channelId);
        }

        $coulumnName = [ '系统订单号', '通道', '实付', '创建时间','回调状态'];
        $fieldName = [ 'order_no', 'channel_id', 'money', 'create_time', 'callback_status_str'];
        $csvName = $name.'-' . date('Y-m-d').'.csv';
        addAdminLog('导出无匹配订单统计');
        Csv::downCsv($query, $coulumnName, $fieldName,$csvName);
    }

    /**
     * 交易管理->历史订单->手动补单 JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     */
    public function suppleOrder()
    {
        $orderNo = input('post.order_no','');
        $orderMdl = model('PayOrders');
        if(!$orderNo) return json(['status'=>0,'msg'=>'请传入系统订单号']);

        $order = $orderMdl->UnitId($this->unitId)->where(['order_no'=>$orderNo])->find();
        if(!$order) return json(['status'=>0,'msg'=>'订单不存在']);
        if($order['callback_status'] == '2') return json(['status'=>0,'该订单已经回调成功，不用补单']);

        $result = $orderMdl->suppleOrder($order, '平台手工回调(订单:'.$order['order_no'].')');

        if($result['code'] ==1) {
            addAdminLog('平台补单成功，订单号：' . $order['order_no'], 'action'); // 写日志
        }

        return json($result);
    }

    /**
     * 处理无匹配订单
     * JH支付 <85·8882849084774@qq.com>
     * @return \think\response\Json
     */
    public function suppleNomatchOrder()
    {
        $orderNo = input('post.order_no','');
        if(!$orderNo) return json(['status'=>0,'msg'=>'请传入系统订单号']);
        $payNomatchOrders = new PayNomatchOrders();

        $order = $payNomatchOrders->UnitId($this->unitId)->where(['order_no'=>$orderNo])->find();
        if(!$order) return json(['status'=>0,'msg'=>'订单不存在']);
        if($order['callback_status'] == '2') return json(['status'=>0,'该订单已经处理过']);

        $result = $payNomatchOrders->suppleNomatchOrder($order);

        if($result['code'] !==1 ) return json(['status'=>0,'msg'=>$result['msg']]);

        return json(['status'=>1,'msg'=>'处理成功']);
    }


    /** 手动结算
     * @author xi 2019/4/12 16:29
     * @Note
     * @return array
     */
    public function suppleOrderBalance(){
        $orderId = input('post.order_id','');

        //执行结算
        $is_balance = model('PayOrders')->orderBalance($orderId);
        //结算状态
        if($is_balance['code']==1) {
            //修改结算状态
            $update['is_balance'] = 1;
            $res = model('PayOrders')->where(['id'=>$orderId])->update($update);

            return ['code'=>1,'msg'=>'success->订单(ID:'.$orderId.')结算'];

        }
        return ['code'=>0,'msg'=>'fail->订单(ID:'.$orderId.')结算'];

    }

    /** 补发通知
     * @author xi 2019/6/4 11:17
     * @Note
     */
    public function notifyOrder(){
        $orderId = input('post.order_id','');

        $orderInfo = model('PayOrders')::with('assists','user')->where(['id'=>$orderId])->find();
        if($orderInfo['callback_status']==2 && $orderInfo['order_status']==2 && $orderInfo['status']==4){
            //可以补发通知
            //开始回调-----------
            //组装回调信息
            $signData['callbacks'] = 'CODE_SUCCESS';
            $signData['appid'] = $orderInfo['users']['mch_id'];
            $signData['pay_type'] = $this->pay_type[$orderInfo['pay_type']];
            $signData['success_url'] = $orderInfo['success_url'];
            $signData['error_url'] = $orderInfo['error_url'];
            $signData['out_trade_no'] = $orderInfo['out_trade_no'];
            $signData['amount'] = $orderInfo['amount'];

            //版本兼容
            if( !in_array( $orderInfo['version'] ,['v1.0'] ) ){
                //v1.0.1 回调新增字段
                $signData['amount_true'] = $orderInfo['money'];//实付金额
                $signData['out_uid'] = $orderInfo['out_uid'];//用户网站的请求支付用户信息，可以是帐号也可以是数据库的ID
            }

            //生成签名
            $signData['sign'] = model('Sign', 'service')->getSign($orderInfo['users']['mch_key'], $signData);
            //echo $order['assists']['callback_url'];p($signData);
            //修改订单状态

            //补发通知
           Http::sendRequest($orderInfo['assists']['callback_url'],$signData);
            return ['code'=>1,'msg'=>'补发通知成功'];
        }else{
            return ['code'=>0,'msg'=>'参数错误'];
        }
    }

}