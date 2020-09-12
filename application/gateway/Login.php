<?php
namespace app\gateway\controller;
use app\gateway\controller\GatewayBase;
use app\common\model\Users;
use think\Cache;

class Login extends GatewayBase
{
    public function _initialize()
    {
        // 检测session
//        if( is_array($_SESSION['user']) ){
//            print_r($_SESSION['user']);
//            header('Location: '."{:url('member/index/index')}".'');
//        }
    }



}
