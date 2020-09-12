<?php
/**
 * @User LvGang  2019/1/19 0019 18:57
 * @Note
 */

namespace app\user\controller;

class Log extends UserBase
{

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   日志首页
     * @return mixed
     */
    public function index()
    {
        $act = input('act', 'login');
        $config = [];
        $config['query']['act'] = $act;
        $this->assign(model('AdminLog')->getList(['uid' => session('users.id'), 'type' => $act, 'port' => 2], 10, $config, 'id desc'));
        return $this->fetch();
    }

}