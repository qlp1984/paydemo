<?php
namespace app\user\controller;
use think\Controller;
use think\Request;
use app\common\controller\Base;


class UserBase extends Base
{
    protected $users = [];
    protected $notLogin = ['Login'];

    public function __construct()
    {
        parent::__construct();
        //判断接口网关是否正确
        $domain = config('base.domain');
        if (isset($domain['user']) && $_SERVER['HTTP_HOST'] != $domain['user']) {
            //exit(json_encode(['status' => -1, 'msg' => '接口请求网关域名不正确,请联系客服获取接口网关']));
        }

        //非 指定控制器
        if(!in_array(request()->controller(),$this->notLogin)){
            // 检测session
            if (!session('?users')) {
                header('Location: ' . url('user/Login/index'));
            }
        }

        $this->users = session('users');
        //p($this->users);
        // 检测账号是否禁用了
        if (model('Users')->where('id', session('users')['id'])->value('switch') == 2) {
            session('users', null);
            header('Location: ' . url('/user/Login/index'));
        }

        cookie('unitId', $this->users['unit_id']);

        $this->publicAssign();

    }

    /**
     * @author LvGang
     * @Note   公共变量
     */
    protected function publicAssign()
    {
        $unitId = session('users.unit_id');
        $this->assign([
            'setting' => getVariable('setting',$unitId)
        ]);
    }

}

?>