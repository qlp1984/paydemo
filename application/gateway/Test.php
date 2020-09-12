<?php
namespace app\gateway\controller;

use lib\Http;
use app\common\service\Sign;

class Test
{
    public function index()
    {

        $mch_key = 'waIiIG5NA8BL4PcR8fyGi38wHeCtVuFm';
        $data = [
            'amount'        => mt_rand(1, 9) + 0.01,
            'appid'         => '1053671',
            'callback_url'  => url('gateway/index/ok'),
            'error_url'     => url('gateway/index/ok'),
            'success_url'   => url('gateway/index/ok'),
            'out_trade_no'  => date('YmdHis') . mt_rand(10000000, 99999999),
            'pay_type'      => 'wechat',
            'version'       => 'v1.1',
            'sign'          => '',

        ];
        $data['sign'] = model('Sign', 'service')->getSign($mch_key, $data);

        $res = Http::sendRequest('http://api.hocan.cn/index/unifiedorder?format=json', $data);
        dump($data);
        p($res);
    }

    public function test()
    {

        $data = [

        ];

        $res = Http::sendRequest('http://api.hocan.cn/index/unifiedorder?format=json', $data);
        p($res);
    }



}