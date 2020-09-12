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
class Withdrawal extends GatewayBase
{

    public function _initialize()
    {


    }


    /**  请求获取商户余额信息 API
     * @author xi 2019/4/1 14:48
     * @Note
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getUsersBalance(){
        if (request()->isPost()) {

            $data['appid'] = input('post.appid',0);
            $data['sign'] = input('post.sign/s',0);

            if($data['appid']<=0 || empty($data['sign'])){
                return json(['code'=>0,'msg'=>'参数错误']);
            }

            if (!$data['sign']) {
                return json(['code' => 0, 'msg' => '请传入签名']);
            }
            //验证签名
            $res = (new Sign())->verifySign($data,2);
            if(!($res === true)){
                return json(['code'=>0,'msg'=>'签名错误']);
            }

            //获取用户ID
            $userId = model('Users')->where(['mch_id'=>$data['appid']])->value('id');
            //获取对应user_id的余额信息
            $userBalance = model('UsersBalance')->where(['user_id'=>$userId])->find();

            $returnData = [
                'balance' =>$userBalance['balance'],
                'total_money' =>$userBalance['money'],
                'use_balance' =>$userBalance['use_money'],
            ];
            return json(['code'=>1,'msg'=>'success','data'=>$returnData]);
        }
        return json(['code'=>0,'msg'=>'未接收到参数']);
    }


    /** 获取提现账号列表 API
     * @author xi 2019/4/1 16:58
     * @Note
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getUserAccountList(){
        if (request()->isPost()) {
            $data['appid'] = input('post.appid', 0);
            $data['type'] = input('post.type', '');//2 银行卡  1 支付宝 0 全部
            $data['sign'] = input('post.sign/s', 0);

            if($data['appid']<=0){
                return json(['code'=>0,'msg'=>'appid参数错误']);
            }
            if (!in_array($data['type'],[0,1,2])) {
                return json(['code' => 0, 'msg' => '请传入查询的账号类型']);
            }

            if (!$data['sign']) {
                return json(['code' => 0, 'msg' => '请传入签名']);
            }

            //验证签名
            $res = (new Sign())->verifySign($data,2);
            if(!($res === true)){
                return json(['code'=>0,'msg'=>'签名错误']);
            }

            //获取用户ID
            $userId = model('Users')->where(['mch_id'=>$data['appid']])->value('id');
            if(!$userId){
                return json(['code'=>0,'msg'=>'找不到用户信息']);
            }

            $where=['user_id'=>$userId];
            if($data['type']>0){
                $where=['bank_type'=>$data['type']];
            }
            $list = model('UserBanks')->field('id,bank_type,account_number,bank_id,account_name')->where($where)->select();
            if(empty($list)){
                return json(['code'=>0,'msg'=>'请先在平台添加提现账号']);
            }
            return json(['code'=>1,'msg'=>'success','data'=>$list]);
        }
        return json(['code'=>0,'msg'=>'未接收到参数']);
    }


    /** 商户申请提现API
     * @author xi 2019/4/1 15:41
     * @Note
     * @return \think\response\Json
     */
    public function creatWithdrawal(){
        if (request()->isPost()) {
            $data['appid'] = input('post.appid',0);
            $data['money'] = sprintf("%.2f", input('post.money/f', '0.00'));
            $data['account'] = input('post.account', '');
            $data['bank_id'] = input('post.bank_id', '');
            $data['bank_type'] = input('post.bank_type', '');
            $data['name'] = input('post.name', '');
            $data['callback'] = input('post.callback', '');
            $data['remark'] = input('post.remark/s', '');
            $data['out_trade_no'] = input('post.out_trade_no/s', '');
            $data['sign'] = input('post.sign/s','');

            if($data['appid']<=0){
                return json(['code'=>0,'msg'=>'appid参数错误']);
            }

            if ($data['money'] <= 0) {
                return json(['code' => 0, 'msg' => '请传入提现金额']);
            }

            if (!$data['account']) {
                return json(['code' => 0, 'msg' => '请传入提现账号']);
            }
            if (!$data['bank_type']) {
                return json(['code' => 0, 'msg' => '请传入提现账号类型']);
            }
            if (!$data['name']) {
                return json(['code' => 0, 'msg' => '请传入提现者姓名']);
            }
            if ($data['bank_type']==2) {
                if (!$data['bank_id']) {
                    return json(['code' => 0, 'msg' => '请传入银行代码ID']);
                }
            }
            if (!$data['callback']) {
                return json(['code' => 0, 'msg' => '请传入通知地址']);
            }
            if (!$data['out_trade_no']) {
                return json(['code' => 0, 'msg' => '请传入订单号']);
            }

            if (!$data['sign']) {
                return json(['code' => 0, 'msg' => '请传入签名']);
            }

            //验证签名

            $signMdl = new Sign();
            $res = $signMdl->verifySign($data,2);
            //p($signMdl->getError());

            if(!($res === true)){
                return json(['code'=>0,'msg'=>'签名错误']);
            }

            //获取用户ID
            $userId = model('Users')->where(['mch_id'=>$data['appid']])->value('id');
            if(!$userId){
                return json(['code'=>0,'msg'=>'找不到用户信息']);
            }

            //生成提现订单
            $res = model('UserWithdrawal')->creatWithdrawalOrder($data,$userId);

            if($res['code']==1){
                return json(['code'=>1,'msg'=>'申请提现成功','data'=>$res['data']]);
            }else{
                return json($res);
            }

        }
        return json(['code'=>0,'msg'=>'未接收到参数']);
    }

    /** 查询订单接口
     * @author xi 2019/4/3 17:10
     * @Note
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function queryOrder(){
        if (request()->isPost()) {
            $data['appid'] = input('post.appid',0);
            $data['order_no'] = input('post.order_no/s', '');
            $data['sign'] = input('post.sign/s','');

            if($data['appid']<=0){
                return json(['code'=>0,'msg'=>'appid参数错误']);
            }

            if (!$data['sign']) {
                return json(['code' => 0, 'msg' => '请传入签名']);
            }

            //验证签名
            $signMdl = new Sign();
            $res = $signMdl->verifySign($data,2);
            //p($signMdl->getError());

            if(!($res === true)){
                return json(['code'=>0,'msg'=>'签名错误']);
            }

            //获取用户ID
            $userId = model('Users')->where(['mch_id'=>$data['appid']])->value('id');
            if(!$userId){
                return json(['code'=>0,'msg'=>'找不到用户信息']);
            }

            //生成提现订单
            if($data['order_no']){
                $res = model('UserWithdrawal')->where(['user_id'=>$userId,'is_api'=>1])->where(['order_no'=>$data['order_no']])->whereOr(['out_trade_no'=>$data['order_no']])->find();
                $returnData = [
                    'id' => $res['id'],
                    'order_no' => $res['order_no'],
                    'out_trade_no' => $res['out_trade_no'],
                    'amount' => $res['amount'],
                    'new_amount' => $res['new_amount'],
                    'account_number' => json_decode($res['account_info'],true)['account_number'],
                    'account_name' => json_decode($res['account_info'],true)['name'],
                    'old_amount' => $res['old_amount'],
                    'callback_url' => $res['callback_url'],
                    'apply_time' => $res['apply_time'],
                    'deal_time' => $res['deal_time'],
                    'bank_id' => json_decode($res['account_info'],true)['bank_id'],
                    'status' => $res['status'] == 2 ? '2': ($res['status'] == 6 ? '3' : '1'),
                    'bank_type' => json_decode($res['account_info'],true)['bank_type'] == 2 ? '银行卡':'支付宝',
                ];
            }else{
                $res = model('UserWithdrawal')->where(['user_id'=>$userId,'is_api'=>1])->select();
                //p($res);
                foreach($res as $k=>$v){
                    $returnData [$k]= [
                        'id' => $v['id'],
                        'order_no' => $v['order_no'],
                        'out_trade_no' => $v['out_trade_no'],
                        'amount' => $v['amount'],
                        'new_amount' => $v['new_amount'],
                        'account_number' => json_decode($v['account_info'],true)['account_number'],
                        'account_name' => json_decode($v['account_info'],true)['name'],
                        'old_amount' => $v['old_amount'],
                        'callback_url' => $v['callback_url'],
                        'apply_time' => $v['apply_time'],
                        'deal_time' => $v['deal_time'],
                        'bank_id' => json_decode($v['account_info'],true)['bank_id'],
                        'status' => $v['status'] == 2 ? '2': ($v['status'] == 6 ? '3' : '1'),
                        'bank_type' => json_decode($v['account_info'],true)['bank_type'] == 2 ? '银行卡':'支付宝',
                    ];
                }
            }

            return json(['code'=>1,'msg'=>'success','data'=>$returnData]);


        }
        return json(['code'=>0,'msg'=>'未接收到参数']);
    }


    /** 取消/删除提现订单接口 api
     * @author xi 2019/4/2 14:10
     * @Note
     * @return \think\response\Json
     */
    public function delOrder(){
        if (request()->isPost()) {
            $data['appid'] = input('post.appid', 0);
            $data['order_no'] = input('post.order_no/s', '');
            $data['sign'] = input('post.sign/s', 0);

            if($data['appid']<=0){
                return json(['code'=>0,'msg'=>'appid参数错误']);
            }

            if (!$data['order_no']) {
                return json(['code' => 0, 'msg' => '请传入提现订单号']);
            }


            if (!$data['sign']) {
                return json(['code' => 0, 'msg' => '请传入签名']);
            }

            //验证签名
            $res = (new Sign())->verifySign($data,2);

            if(!($res === true)){
                return json(['code'=>0,'msg'=>'签名错误']);
            }

            //获取用户ID
            $userId = model('Users')->where(['mch_id'=>$data['appid']])->value('id');

            if(!$userId){
                return json(['code'=>0,'msg'=>'找不到用户信息']);
            }

            $res = model('UserWithdrawal')->delOrderApi($data['order_no'],$userId);
            return json($res);

        }
        return json(['code'=>0,'msg'=>'未接收到参数']);
    }


    /** 平台处理提现订单接口
     * @author xi 2019/4/1 20:24
     * @Note
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function isDealOrder(){
        if (request()->isPost()) {
            $data['appid'] = input('post.appid',0);
            $data['order_no'] = input('post.order_no/s', '');
            $data['type'] = input('post.type', '');//1 确认 2 驳回
            $data['remark'] = input('post.remark/s', '');
            $data['sign'] = input('post.sign/s',0);

            if($data['appid']<=0){
                return json(['code'=>0,'msg'=>'appid参数错误']);
            }

            if (!$data['order_no']) {
                return json(['code' => 0, 'msg' => '请传入提现订单号']);
            }

            if (!in_array($data['type'],[1,2])) {
                return json(['code' => 0, 'msg' => '请传入提现账号']);
            }

            if (!$data['sign']) {
                return json(['code' => 0, 'msg' => '请传入签名']);
            }

            //验证签名
            $res = (new Sign())->verifySign($data,2,'admin');

            if(!($res === true)){
                return json(['code'=>0,'msg'=>'签名错误']);
            }

            //获取用户ID
            $adminId = model('Admin')->where(['mch_id'=>$data['appid']])->value('id');

            if(!$adminId){
                return json(['code'=>0,'msg'=>'找不到用户信息']);
            }
            if($data['type']==1){
                $updateData = [
                    'status'=>5,
                    'review'=>2,
                ];
                $remart = '管理员确认';
            }else{
                //驳回
                $updateData = [
                    'status'=>2,
                    'review'=>1,
                ];
                $remart = '管理员驳回';
            }

            $orderInfo = model('UserWithdrawal')->where(['order_no'=>$data['order_no']])->find();
            if(!$orderInfo){
                return json(['code'=>0,'msg'=>'找不到提现订单信息']);
            }

            $res = model('UserWithdrawal')->update($updateData,['order_no'=>$data['order_no']]);
            if($res){

                //插入抢单操作记录
                $addData['user_id'] = $orderInfo['user_id'];
                $addData['merchant_id'] = 0;
                $addData['withdrawal_id'] = $orderInfo['id'];
                $addData['remark'] = $remart;
                model('UserWithdrawalRecords')->addRecord($addData);

                return json(['code'=>1,'msg'=>'操作成功']);
            }

        }
        return json(['code'=>0,'msg'=>'未接收到参数']);
    }




    //测试请求接口
    public function getsign(){

        // echo '0713E94E3CA0EDB11F66D851BAB09648'.'<br/>';
        $app_key='sF3SUymkCzQnUGnqRugUFgE2aqFBaYXq';

        $data =[
            'appid'=>'88888888',
            'money' => '100.00',
            'account' => '466464616664631646465365',
            'bank_id' => '2',
            'bank_type' => '2',
            'name' =>'zhangsan',
            'remark' =>'zhangsan',
            'callback' =>'http://x.hocan.cn/',
            //'pay_type'     => 'alipay',
            'out_trade_no' => 'tx'.time(),
            //'amount'       => '10.00',
            //'amount_true'=>'0.01',
            //'callback_url'=>'http://',
           // 'success_url'  => 'http://',
           // 'error_url'    => 'http://',
            //'version'=>'v1.1',
           // 'out_uid'      => 'xxxx',
        ];

        $data['sign'] = (new Sign())->getSign($app_key, $data);
        $res = Http::post('http://api.hocan.cn/withdrawal/creatWithdrawal',$data);
        echo ($res);

    }

}
