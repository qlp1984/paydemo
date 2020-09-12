<?php
/**
 * @author Mr.zhou
 * 统计信息
 * Class StatisticalInformation
 * @package app\admin\controller
 */
namespace app\admin\controller;

class Stats extends AdminBase
{
    /**
     * @author Mr.zhou  2019/1/8 15:20 JH支付 <8·5`8`2849084774@qq.com>
     * @Note   统计信息首页
     * @return \think\response\View
     */
    public function index_old()
    {

        $color = ['#1890FF', '#FACC14', '#F39C12', '#2ECC71', '#2e4063', '#337ab7', '#33b793', '#33b793', '#126937', '#aac7b3', '#2e4063', '#337ab7', '#33b793', '#33b793', '#126937', '#aac7b3', '#2e4063', '#337ab7', '#33b793', '#33b793', '#126937', '#aac7b3']; // 颜色 （如果增加渠道，这里要增加颜色）

        // 获取七天（微信、支付宝）的成功统计
        $data = model('OrderDaily')->senveData($this->unitId);

        // 今天
        $nowTime = date('Y-m-d',time());

        // 7天前
        $senveDate = date('Y-m-d',strtotime("-7 day"));

        // 30天前
        $thirtyDate = date('Y-m-d',strtotime("-30 day"));

        // 获取通道今日收款
        $toDay = model('OrderDaily')->getToDayData('plat', $this->unitId);
        //p($toDay);

        // 7天通道收款
        $senveAllOrderData = model('OrderDaily')
            ->UnitId($this->unitId)
            ->whereIn('money_data_id', array_keys($toDay))
            ->where(['user_type'=>'plat'])
            ->where('date','>',$senveDate)
            ->where('date','<',$nowTime)
            ->field('sum(total_money) as money, sum(total_count) as menber')
            ->find();

        // 30天前
        $thirtyAllOrderData = model('OrderDaily')
            ->UnitId($this->unitId)
            ->whereIn('money_data_id', array_keys($toDay))
            ->where(['user_type'=>'plat'])
            ->where('date','>',$thirtyDate)
            ->where('date','<',$nowTime)
            ->field('sum(total_money) as money, sum(total_count) as menber')
            ->find();

        // 待审核提现订单
        $userWithdrawal['no'] = model('userWithdrawal')
            ->UnitId($this->unitId)
            ->where(['status' => 4, 'review' => 1])
            ->count();

        // 已提现订单
        $userWithdrawal['yes'] = model('userWithdrawal')
            ->UnitId($this->unitId)
            ->where(['status' => 4, 'review' => 2])
            ->count();

        // 订单
        $order = model('PayOrders')->getOrderSuccessRate([], [], $this->unitId);

        $view_data=[
            'color' => $color,
            'toDay' => $toDay,
            'order' => $order,
            'onLine' => model('MerchantsAccounts')->getonLineAccounts($this->unitId), // 统计开启网关和在线账号总数
            'data'  => $data,
            'wechat'=>$data['wechat'],
            'alipay'=>$data['alipay'],
            'sevenData'=> null, //model('OrderDaily')->getSevenData($color)
            'proportion' => null, //model('PayOrders')->orderChannelProportion(), // 百分比
            'senveAllOrderData' => $senveAllOrderData,
            'thirtyAllOrderData' => $thirtyAllOrderData,
            'userWithdrawal' => $userWithdrawal
        ];

        return view('stats/index_old',$view_data);
    }

    public function index()
    {

        // 查询条件
        $where = ['status' => 4];
        $payOrdersMdl = model('payOrders');

        // 微信支付宝总收入
        $data['alipayMoney'] = $payOrdersMdl->UnitId($this->unitId)->where($where)->where(['pay_type' => 1])->sum('money');
        $data['alipayNumber'] = $payOrdersMdl->UnitId($this->unitId)->where($where)->where(['pay_type' => 1])->count();
        $data['wechatMoney'] = $payOrdersMdl->UnitId($this->unitId)->where($where)->where(['pay_type' => 2])->sum('money');
        $data['wechatNumber'] = $payOrdersMdl->UnitId($this->unitId)->where($where)->where(['pay_type' => 2])->count();

        $data['alipayProportion'] = empty($data['alipayNumber']) ? '0.00' : round($data['alipayNumber'] / ($data['alipayNumber'] + $data['wechatNumber']) * 100, 2);
        $data['wechatProportion'] = empty($data['wechatNumber']) ? '0.00' : round($data['wechatNumber'] / ($data['alipayNumber'] + $data['wechatNumber']) * 100, 2);

        $this->assign([
            'data'              => $data,
            'senveData'         => model('OrderDaily')->senveData($this->unitId),
            'sevenOrderInfo'    => $payOrdersMdl->sevenOrderInfo($this->unitId),
        ]);

        return view();
    }



}
