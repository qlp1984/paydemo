<?php
/**
 * @User LvGang  2019/1/29 0029 13:02
 * @Note 公共方法
 */

namespace app\admin\controller;

class AjaxCommon
{

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note    生成随机字符串
     */
    public function randStr()
    {
        $number = input('post.number', 32);
        echo get_rand_char($number);
    }

    public function getAuthKey()
    {
        echo config('base.auth_key');
    }

}