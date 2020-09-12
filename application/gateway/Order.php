<?php
/**
 * @Author Quincy  2019/1/22 下午2:11
 * @Note 订单控制器
 */

namespace app\gateway\controller;

use app\common\service\Account;
use app\common\service\Sign;

class Order
{

    /**
     * 创建订单
     * JH支付 <8582849084774@qq.com>
     * @return \think\response\Json
     */
    public function index()
    {

        $data['return_type'] = input('post.return_type/s', 'pc');
        $data['mch_id'] = input('post.appid/s', '');
        $data['channel_name'] = input('post.pay_type/s', '');
        $data['callback_url'] = input('post.callback_url/s', '');
        $data['success_url'] = input('post.success_url/s', '');
        $data['error_url'] = input('post.error_url/s', '');
        $data['out_trade_no'] = input('post.out_trade_no/s', '');
        $data['amount'] = input('post.amount/f', '0.00');
//        echo (new Sign())->getSign('sF3SUymkCzQnUGnqRugUFgE2aqFBaYXq',$data);die;
        $data['sign'] = input('post.sign/s', '');

        //1：检查参数
        if(!in_array($data['return_type'],['app','pc'])){
            return json(['status'=>10001,'msg'=>'请传入返回网页类型']);
        }
        if(!$data['mch_id']){
            return json(['status'=>10003,'msg'=>'请传入用户key']);
        }
        if(!in_array($data['channel_name'],['wechat','alipay'])){
            return json(['status'=>10004,'msg'=>'请传入通道类型']);
        }
        if(!$data['callback_url']){
            return json(['status'=>10005,'msg'=>'请传入回调地址']);
        }
        if(!$data['out_trade_no']){
            return json(['status'=>10006,'msg'=>'请传入订单信息']);
        }
        if($data['amount'] <= 0){
            return json(['status'=>10007,'msg'=>'请传入支付金额']);
        }
        if(!$data['sign']){
            return json(['status'=>10008,'msg'=>'请传入签名']);
        }

        //2：用户
        $user = db('users')->where(['mch_id'=>$data['mch_id']])->field('id,switch,audit_status')->find();
        if(!$user){
            return json(['status'=>20001,'msg'=>'网站用户不存在']);
        }
        if($user['switch'] != '1'){
            return json(['status'=>20002,'msg'=>'网站用户状态已禁止']);
        }
        if($user['audit_status'] != '1'){
            return json(['status'=>20003,'msg'=>'网站用户状态未审核']);
        }

        //2：签名验证
        $signRes = (new Sign())->verifySign($data);
        if($signRes === false){
            return json(['status'=>30000,'msg'=>'签名验证失败']);
        }


        //3：通道、费率
        $channel = db('channels')->where(['code_name'=>$data['channel_name']])->find();
        if(!$channel){
            return json(['status'=>40002,'msg'=>'通道不存在']);
        }
        if($channel['switch'] != '1'){
            return json(['status'=>40003,'msg'=>'通道已关闭']);
        }
        //用户费率
        $channelId = $channel['id'];
        $rate = db('rates')->where(['type'=>2,'channel_id'=>$channelId,'user_id'=>$user['id']])->find();
        if(!$rate){
            $channelRate = $channel['rate'];
            if($channelRate<0){
                return json(['status'=>20004,'msg'=>'网站用户费率不存在']);
            }
            $data['user_rate'] = $channelRate;
        }
        if($rate['rate']<0){
            return json(['status'=>20005,'msg'=>'网站用户费率不正确']);
        }else{
            $data['user_rate'] = $rate['rate'];
        }

        //4：收款账户
        $account = (new Account())->getAccount('random',$channelId);
        if(!$account) {
            return json(['code'=>40001,'msg'=>'没有可用的通道']);
        }
        $account = json_decode($account,true);

        //5：码商费率
        $merRate = db('rates')->where(['type'=>1,'channel_id'=>$channelId,'merchant_id'=>$account['merchant_id']])->find();
        if(!$merRate){
            $channelRate = $channel['rate'];
            if($channelRate<0){
                return json(['status'=>20004,'msg'=>'码商用户费率不存在']);
            }
            $data['mer_rate'] = $channelRate;
        }
        if($rate['rate']<0){
            return json(['status'=>20005,'msg'=>'码商用户费率不正确']);
        }else{
            $data['mer_rate'] = $rate['rate'];
        }

        $orderService = new \app\common\service\Order();

        $orderNo = make_order_no();
        $orderType = 1;
        $data['user_id']=$user['id'];
        
        $result = $orderService->createOrder($orderType,$orderNo,$channelId,$account,$data);

        if($result['code'] != 1){
            return json(['status'=>50000,'msg'=>$result['msg'],'data'=>[]]);
        }

        
        return json(['status'=>200,'msg'=>'success','data'=>$result['data']]);
    }

    /**
     * 删除redis
     * JH支付 <2849084774@qq.com>
     */
    public function del()
    {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
        $redis->del('useRandom');
    }

    /**
     * 获取redis
     * JH支付 <8582849084774@qq.com>
     */
    public function get()
    {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
        $sets = $redis->sMembers('useRandom');
        dump($sets);
    }

}