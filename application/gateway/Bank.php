<?php
/**
 * Class Index
 * @package app\gateway\controller
 */

namespace app\gateway\controller;

use app\common\service\RedisService;

class Bank extends GatewayBase
{
    protected static $ReDB;

    public function _initialize()
    {
        // 调用Redis模型
        $redis = RedisService::getInstance();
        if(RedisService::$status !== true){
            exception('redis服务出错'.RedisService::$status);
        }
        self::$ReDB = $redis;
    }

    /**
     * pc监控网银通知回调地址
     * JH支付 <8588·*2849084774@qq.com>
     */
    public function pcCallBack()
    {
        $data = input('post.');

        // 限定
        if ( empty($data) ){
            echo '知道小猪佩奇吗？';
            exit;
        }

        // 判断秘钥
        if ( $data['key'] != config('base.key')['value'] ){
            echo 'error:key不对';
            exit;
        }
        $money = $data['Money'];//实际收款金额
        $bank_cart =$data['Mycard'];//收款卡号
        $money = floatval($money);  // 金额

        $bool = model('MerchantsAccounts')->where(['channel_id'=>4,'receipt_name'=>$bank_cart])->field('id,bank_name,channel_id,merchant_id')->find();

        if ( empty($bool) ){
            echo 'error:通道不存在'.$bank_cart;
            exit;
        }

        // 查订单
        $find_order =  model('PayOrders')
            ->where(['merchant_account_id'=>$bool['id'],'status'=>2,'amount'=>$money])
            ->order('id desc')
            ->field('id,deadline_time')->find();

        if ( empty($find_order) ){

            // 插入记录
            $noOrderData['channel_id'] = $bool['channel_id'];
            $noOrderData['merchant_id'] = $bool['merchant_id'];
            $noOrderData['user_id'] = 0;
            $noOrderData['merchant_account_id'] = $bool['id'];
            $noOrderData['money'] = $money;
            $noOrderData['order_no'] = $bank_cart;
            $noOrderData['callback_source'] = 'pc';
            $noOrderData['callback_ip'] = request()->ip();
            $noOrderData['create_time'] = time();
            $noOrderData['mark'] = '银行卡此订单找不到';

            // 无匹配订单
            model('PayNomatchOrders')->insert($noOrderData);

            echo 'error:无匹配订单';
            exit;
        }

        // 判断订单超时
        if ( $find_order['deadline_time'] < (time()-1) ){
            // 插入记录
            $noOrderData['channel_id'] = $bool['channel_id'];
            $noOrderData['merchant_id'] = $bool['merchant_id'];
            $noOrderData['user_id'] = 0;
            $noOrderData['merchant_account_id'] = $bool['id'];
            $noOrderData['money'] = $money;
            $noOrderData['order_no'] = $bank_cart;
            $noOrderData['callback_source'] = 'pc';
            $noOrderData['callback_ip'] = request()->ip();
            $noOrderData['create_time'] = time();
            $noOrderData['mark'] = '银行卡此订单已超时';

            // 无匹配订单
            model('PayNomatchOrders')->insert($noOrderData);

            echo 'error:无匹配订单';
            exit;
        }

        $oidData['status'] = 4;
        $oidData['pay_time'] = time();
        $oidData['callback_source'] = 'pc';
        $oidData['callback_ip'] = request()->ip();

        // 更新
        model('PayOrders')->where(['id'=>$find_order['id']])->update($oidData);

        //拼接key
        $redisKey = md5($bool['id']."_".$money);

        // 删除通道
        self::$ReDB->del($redisKey);

        // 更新关闭通道列表
        model('AccountOffRecord')->reduceNumber($bool['id']);

        echo 'Success';
        exit;

    }

}
