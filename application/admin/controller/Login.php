<?php
/**
 * @User LvGang  2019/1/9 0009 16:32
 * @Note
 */

namespace app\admin\controller;

class Login extends AdminBase
{

    public function _initialize()
    {
        // 检测session
        if (session('?admin')) {
            header('Location: ' . url('admin/Index/index'));
        }
    }

    public function successrate(){

        $unitId = input('get.unit_id/s',false);

        if ( empty($unitId) ){
            $orWhere['unit_id'] = -1;
        } else {
            $orWhere['unit_id'] = $unitId;
        }

        $orWhere['order_status'] = 2;
        $nowTime = time();
        $st1 = $nowTime-900;
        $st2 = $nowTime-1800;
        $st3 = $nowTime-3600;

        $str1 = 'create_time > '.$st1;
        $str2 = 'create_time > '.$st2;
        $str3 = 'create_time > '.$st3;

        $orderSuccessOne = model('PayOrders')->getOrderSuccessRate($orWhere,$str1);
        $orderSuccessTwo = model('PayOrders')->getOrderSuccessRate($orWhere,$str2);
        $orderSuccessThree = model('PayOrders')->getOrderSuccessRate($orWhere,$str3);

        echo '<pre>';
        echo '15分钟成功率';
        print_r($orderSuccessOne);
        echo '<br>';
        echo '30分钟成功率';
        print_r($orderSuccessTwo);
        echo '<br>';
        echo '60分钟成功率';
        print_r($orderSuccessThree);
    }

    /**
     * @author LvGang JH支付 <28490847746@qq.com>
     * @Note   平台登录页
     * @return mixed
     */
    public function index()
    {

        $this->assign('setting', getVariable('setting', $this->unitId));
        return $this->fetch();
    }

    /**
     * 用户登录操作
     * @author LvGang JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     */
    public function userLogin()
    {
        if (request()->isPost()) {
            $postData = input('post.');

            // 验证
            $result = $this->validate($postData, 'AdminValidate.login');
            if(true !== $result){
                return json(['code' => 0, 'msg' => $result]);
            }

            // 判断密码是否错误5次
            if (handleCache(request()->controller() . $postData['name'] . request()->ip()) > 4) {
                return json(['code' => 0, 'msg' => '密码连续输错5次，账号冻结一个小时', 'data' => request()->token(), 'type' => 'passwd']);
            }

            return json(model('Admin')->userLogin($postData));
        }

        return json(['code' => 0, 'msg' => '非法操作']);
    }

    /**
     * 退出登录
     * @author LvGang JH支付 <2849084774@qq.com>
     */
    public function logout()
    {
        session('admin', null);
        header('Location: ' . url('admin/Login/index'));
    }

}