<?php
/**
 * @Author Quincy  2019/1/8 上午10:27
 * @Note 盘口订单管理控制器
 */

namespace app\user\controller;

use app\common\model\UserBalancesRecords;
use lib\Csv;
use app\common\model\PayOrders;

class Order extends UserBase
{

    /**
     * 交易记录
     * @author JH支付 <858-887-906@qq.com>
     * @return \think\response\View
     */
    public function index()
    {
        // 通道类型类别
        $channelsData = model('Channels')->where(['switch'=>1])->select();

        $orderNo = input('get.order_no','');
        $outTradeNo = input('get.out_trade_no','');
        $status = input('get.status','');
        $callback_status = input('get.callback_status','');
        $money = floatval(input('get.money',0.00));
        $startTime = input('get.start_time',0);
        $endTime = input('get.end_time',0);
        $channels = intval(input('get.channels',0));
        $userId = session('users')['id'];

        $where = [];
        if ( !empty($channels) ){
            $where['channel_id'] = $channels;
        }
        if ( !empty($callback_status) ){
            $where['callback_status'] = $callback_status;
        }

        $order = PayOrders::with('assists')
            ->Status($status)
            ->CallbackStatus($callback_status)
            ->No($orderNo)
            ->OutNo($outTradeNo)
            ->Money($money)
            ->User($userId)
            ->Time($startTime, $endTime)
            ->where($where)
            ->order('id desc')
            ->paginate(15,false,['query' => request()->param()]);

        $dictionary =  config('dictionary');

        // 订单信息
        $money = model('PayOrders')->getPlatMoney($userId, '', '', $where); // 统计金额

        $nowTime = strtotime(date('Y-m-d',time()));

        // 准备条件
        $cashRecordArr = [];
        if ($startTime || $endTime) {
            $startTime = strtotime($startTime);
            $endTime = strtotime($endTime);
            $nowTime = strtotime(date('Y-m-d',$startTime));
            $cashRecordArr['create_time'] = ['between time', [$startTime, $endTime]];
        }

        $cashRecord = model('Users')->getCashRecord($userId,$cashRecordArr,$nowTime); // 出入金、抢单

        return view('order/index',['order'=>$order,'config'=>$dictionary,'money'=>$money,'channelsData'=> $channelsData,'cashRecord'=>$cashRecord]);
    }

    /**
     * 导出订单
     * @author JH支付 <858-8879-06@qq.com>
     */
    public function exportOrder()
    {
        $orderNo = input('get.order_no','');
        $outTradeNo = input('get.out_trade_no','');
        $status = input('get.status','');
        $money = floatval(input('get.money',0.00));
        $startTime = input('get.start_time',0);
        $endTime = input('get.end_time',0);
        $userId = session('users')['id'];
        $userName = session('users')['name'];

        $query = PayOrders::with('assists')
            ->User($userId);

        if ($orderNo || $outTradeNo || $status || $money || $startTime || $endTime){

            $query = $query->Status($status)
                ->No($orderNo)
                ->OutNo($outTradeNo)
                ->Money($money)
                ->Time($startTime, $endTime);
        }

        $coulumnName = [ '系统订单号', '商户订单号', '通道',  '应付','实付','条码金额','费率','接口手续费','创建时间','支付时间','回调时间','订单状态','回调状态','回调内容','重试次数'];
        $fieldName = [ 'order_no', 'out_trade_no','channel_id', 'pricing', 'money', 'amount', 'user_rate','fees','create_time', 'pay_time','callback_time@assists','status_str', 'callback_status_str',  'callback_content@assists', 'callback_count@assists'];
        $csvName = $userName. date('Y-m-d').'.csv';

        Csv::downCsv($query, $coulumnName, $fieldName,$csvName);
    }

    /**
     * 余额明细
     * @author LvGang JH支付 <85-88-2849084774@qq.com>
     * @return \think\response\View
     */
    public function userRecords()
    {
        $userId = session('users')['id'];
        $scene = input('scene');
        $type = input('type');
        $data = UserBalancesRecords::UserId($userId)
            ->Scene($scene)
            ->Type($type)
            ->order('id desc')
            ->paginate(15);

        return view('order/records',[
            'data'  => $data,
            'scene' => config('dictionary')['user_record_scene']
        ]);
    }

    /**
     * @author LvGang JH支付 <85、88-2849084774@qq.com>
     * @Note   导出余额明细
     */
    public function exportBalancesRecords()
    {
        $user = session('users');
        $scene = input('scene');
        $type = input('type');
        $query = UserBalancesRecords::UserId($user['id'])
            ->Scene($scene)
            ->Type($type);

        $coulumnName = ['操作场景', '金额类型', '操作前余额', '金额', '操作后余额', '说明'];
        $fieldName = ['scene_str', 'type_str', 'before_money', 'money', 'after_money', 'descript'];
        $csvName = $user['name'] . '_余额明细_' . date('Y-m-d').'.csv';

        Csv::downCsv($query, $coulumnName, $fieldName, $csvName);

    }


}