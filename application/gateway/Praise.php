<?php

/**
 *有赞云配置
 */
namespace app\gateway\controller;

require_once EXTEND_PATH.'youzan/vendor/autoload.php';

use app\common\service\RedisService;

class Praise
{
    private $redis;

    public function __construct()
    {
        // 调用Redis模型
        $redis = RedisService::getInstance();
        if (RedisService::$status !== true) {
            exception('redis服务出错' . RedisService::$status);
        }
        $this->redis = $redis;
    }

    /**
     *
     * 获取token
     *
     * @param $clientId       应用 client_id
     * @param $clientSecret   应用client_secret
     * @param $kdt_id         店铺ID
     * @return array          响应数组
     */
    private function getToken($kdt_id,$clientId,$clientSecret){
        $type = 'silent';
        $keys['kdt_id'] = $kdt_id;   // 店铺ID

        $accessToken = (new \Youzan\Open\Token($clientId, $clientSecret))->getToken($type, $keys);

        if ( isset($accessToken['access_token']) ){
            return ['code'=>1,'msg'=>'获取token成功！','access_token'=>$accessToken['access_token']];
        }

        return ['code'=>-1,'msg'=>'获取token失败！请检查是否配置成功！'];
    }

    /**
     * 获取订单支付链接
     *
     * @param string $order_no   订单号
     * @return array      支付链接
     * @throws \Exception
     */
    public function carateOrder($order_no=''){

        if ( empty($order_no) ){
            return ['code'=>-1,'msg'=>'非法操作！'];
        }

        $merchantAccountId = model('PayOrders')->where(['order_no' => $order_no])->value('merchant_account_id');
        $money = model('PayOrders')->where(['order_no' => $order_no])->value('money');

        // 获取token
        $str = 'praise:-'.$merchantAccountId;

        // 定义该模式的 轮训订单状态的次数  用socket定时器 去定时轮训  有单就加 减少直接查询数据库
        $str1 = 'praise-number';

        $accessToken = $this->redis->get($str);

        if ( empty($accessToken) ){
            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>38,'account_id'=>$merchantAccountId])->value('sign_data');

            if(empty($apiData) || $apiData == null){
                return ['code'=>0,'msg'=>'api参数错误'];
            }
            $apiData = json_decode($apiData,true);

            $accessTokenData = $this->getToken($apiData['appid'],$apiData['privateKey'],$apiData['publicKey']);

            if ( $accessTokenData['code'] == -1 ){
                model('PayOrders')->where(['order_no' => $order_no,'status'=>1])->update(['status'=>5,'err_msg'=>'有赞获取token失败！']);
                exception('订单获取二维码失败！请重新下单！');
            } else {
                $accessToken = $accessTokenData['access_token'];

                // 设置Redis
                $this->redis->set($str, $accessToken, 100);
            }
        }

        $client = new \Youzan\Open\Client($accessToken);
        $method = 'youzan.pay.qrcode.create';
        $apiVersion = '3.0.0';

        $params['qr_type'] = 'QR_TYPE_DYNAMIC';
        $params['qr_price'] = $money*100;
        $params['qr_name'] = $order_no;

        $response = $client->post($method, $apiVersion, $params);

        if ( isset($response['response']) ){
            // 加入列表
            $this->redis->lpush($str1,$order_no);
            model('PayOrders')->where(['order_no' => $order_no,'status'=>1])->update(['status'=>2,'clusterId'=>$response['response']['qr_id'],'qrcode'=>$response['response']['qr_url'],'DingDingJson'=>$response['response']['qr_code']]);
            return $response['response']['qr_url'];
        } else {
            model('PayOrders')->where(['order_no' => $order_no,'status'=>1])->update(['status'=>5,'err_msg'=>'有赞获取token失败！']);
            exception('订单获取二维码失败！请重新下单！');
        }
    }

    // 获取订单的状态
    public function getOrderStatus($order_no='')
    {
        if ( empty($order_no) ){
            return ['code'=>-1,'msg'=>'非法操作！','order_no'=>$order_no];
        }

        $qr_id = model('PayOrders')->where(['order_no' => $order_no])->value('clusterId');

        // 订单过期时间
        $deadline_time = model('PayOrders')->where(['order_no' => $order_no])->value('deadline_time');

        $merchantAccountId = model('PayOrders')->where(['order_no' => $order_no])->value('merchant_account_id');

        // 当前时间
        $nowTime = time();

        $str1 = 'praise-number';

        // 获取token
        $str = 'praise:-'.$merchantAccountId;

        // 获取token
        $accessToken = $this->redis->get($str);

        if ( empty($accessToken) ){
            /*** 获取通道的签名参数 start ***/
            $apiData = model('MerchantsAccountsSignData')->where(['channel_id'=>38,'account_id'=>$merchantAccountId])->value('sign_data');

            if(empty($apiData) || $apiData == null){
                return ['code'=>0,'msg'=>'api参数错误'];
            }
            $apiData = json_decode($apiData,true);

            $accessTokenData = $this->getToken($apiData['appid'],$apiData['privateKey'],$apiData['publicKey']);

            if ( $accessTokenData['code'] == -1 ){
                model('PayOrders')->where(['order_no' => $order_no,'status'=>1])->update(['status'=>5,'err_msg'=>'有赞获取token失败！']);
                exception('有赞获取token失败！请重新下单！');
            } else {
                $accessToken = $accessTokenData['access_token'];

                // 设置Redis
                $this->redis->set($str, $accessToken, 100);
            }
        }

        // 判断订单时间
        if ($nowTime > $deadline_time) {
            model('PayOrders')->where(['order_no' => $order_no,'status'=>2])->update(['status'=>3,'err_msg'=>'订单过期']);
            $this->redis->lRem($str1,$order_no,0);
            return json(['code' => -1, 'msg' => '订单已支付或者已过期']);
        }

        $client = new \Youzan\Open\Client($accessToken);
        $method = 'youzan.trades.qr.get';
        $apiVersion = '3.0.0';

        // 二维码的唯一ID
        $params['qr_id'] = $qr_id;

        // 获取结果
        $response = $client->post($method, $apiVersion, $params);

        if ( isset($response['response']) ){
            // 有人支付
            if ( $response['response']['total_results'] > 0 ){
                foreach ( $response['response']['qr_trades'] as $v ){
                    if ( $v['status'] == 'TRADE_RECEIVED' && $v['qr_name'] == $order_no  ){
                        $oidData['status'] = 4;
                        $oidData['pay_time'] = time();
                        $oidData['pay_trade_no'] = $v['tid'];
                        $oidData['callback_ip'] = '127.0.01';
                        $oidData['callback_source'] = 'mobile';

                        model('PayOrders')->where(['order_no' => $order_no,'status'=>2 ])->update($oidData);

                        (new Service())->callBackOne($order_no);

                        $this->redis->lRem($str1,$order_no,0);

//                        print_r(['code' => 1, 'msg' => '回调成功！']);
                    }
                }
            }
        }
    }

}