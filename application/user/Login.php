<?php
namespace app\user\controller;

use think\Controller;

class Login extends Controller
{
    private $Users;

    public function _initialize()
    {
        //非登录模块检测
        // 检测session
        if (session('?users')) {
            header('Location: ' . url('user/Index/index'));
        }

    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   盘口登录
     * @return \think\response\View
     */
    public function index()
    {

        $this->assign([
            'setting' => getVariable('setting'),
            'qq'      => config('base.contact')['qq']
        ]);
        return $this->fetch();
    }

    /**
     * 用户登录操作
     * @author LvGang JH支付 <8879`06@qq.com>
     * @return \think\response\Json
     */
    public function userLogin()
    {
        if (request()->isPost()) {
            $postData = input('post.');

            // 验证
            $result = $this->validate($postData, 'UsersValidate.login');
            if(true !== $result){
                return json(['code' => 0, 'msg' => $result, 'data' => request()->token()]);
            }

            // 判断密码是否错误5次
            if (handleCache(request()->controller() . $postData['name'] . request()->ip()) > 4) {
                return json(['code' => 0, 'msg' => '你密码连续输错5次，账号冻结一个小时', 'data' => request()->token(), 'type' => 'passwd']);
            }

            return json(model('Users')->userLogin($postData));
        }

        return json(['code' => 0, 'msg' => '非法操作']);
    }

    /**
     * 退出登录
     * @author LvGang JH支付 <8`2849084774@qq.com>
     */
    public function logout()
    {
        session('users', null);
        header('Location: ' . url('user/Login/index'));
    }

}
