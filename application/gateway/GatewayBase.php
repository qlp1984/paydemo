<?php

namespace app\gateway\controller;

use app\common\controller\Base;

class GatewayBase extends Base
{

    public function __construct()
    {
        parent::__construct();
        //判断接口网关是否正确
        $domain = config('base.domain');
        if ( isset($_SERVER['HTTP_HOST']) && isset($domain['api']) && $_SERVER['HTTP_HOST'] != $domain['api']) {
            return json(['status' => -1, 'msg' => '接口请求网关域名不正确,请联系客服获取接口网关']);
        }
    }
}
