<?php
namespace app\admin\controller;

use app\common\controller\Base;
use think\exception\HttpResponseException;
use think\Response;

class AdminBase extends Base
{
    protected $Admin=[];
    protected $notLogin = ['Login'];

    protected $unitId; // 平台id
    protected $template = ''; // 区分模板 （区分总平台和分平台）

    public function _initialize()
    {

        //判断接口网关是否正确
        $domain = config('base.domain');
        if (isset($domain['admin']) && $_SERVER['HTTP_HOST'] != $domain['admin']) {
           // exit(json_encode(['status' => -1, 'msg' => '接口请求网关域名不正确,请联系客服获取接口网关']));
        }

        //非 指定控制器
        if(!in_array(request()->controller(),$this->notLogin)){
            // 检测session
            if (!session('?admin')) {
                header('Location: ' . url('admin/Login/index'));
                exit;
            }

        }

        $this->Admin = session('admin');

        $nowAdmin = model('Admin')->where('id', session('admin')['id'])->field('switch, is_use_google')->find();

        // 检测账号是否禁用了
        if ($nowAdmin['switch'] == 2) {
            session('admin', null);
            header('Location: ' . url('/admin/Login/index'));
            exit;
        }

        // 如果开启了谷歌验证，上次登录没验证谷歌令牌，轻质退出登录
        if (request()->action() != 'google' && $nowAdmin['is_use_google'] == 1 && $this->Admin['is_use_google'] != $nowAdmin['is_use_google']) {
            session('admin', null);
            header('Location: ' . url('/admin/Login/index'));
            exit;
        }

        $this->checkRole(); // 验证权限

        $this->publicAssign(); // 实例化公共参数

        // 区分总平台和分平台管理员
        if ($this->Admin['id'] == -1 || $this->Admin['unit_id'] == -1) {
            $this->template = '_admin';
            $this->unitId = false;
        } else {
            $this->unitId = $this->Admin['unit_id'] ?: $this->Admin['id'];
        }
        cookie('unitId', $this->unitId);

    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * 赋值公共变量
     */
    protected function publicAssign()
    {

        $unitId = session('admin.unit_id');
        $this->assign([
            'member'    => session('merchants'),
            'setting'   => getVariable('setting',$unitId),
            'roleNode'  => model('RoleNode')->getCacheNodeData($this->Admin['id']),
        ]);
    }

    /**
     * 验证用户权限
     * @author LvGang 2019/2/25 0025 10:21 JH支付 <2849084774@qq.com>
     */
    protected function checkRole()
    {
        $adminId = session('admin.id');

        if ($adminId == -1) return true; // admin账号默认是超级管理员

        $controller = $this->request->controller();
        $action = $this->request->action();
        $adminRoleId = model('Admin')->where(['id' => $adminId])->value('role_node_id');
        $nowNodeId = model('RoleNode')->where(['controller' => strtolower($controller), 'action' => $action])->value('id');

        // 是否录入权限表
        if (empty($nowNodeId)) {
            $this->echoTemplate(0, '该权限不存在，请添加进权限管理');
        }

        // 判断是否有权限
        if (!in_array($nowNodeId, explode(',', $adminRoleId))) {
            $this->echoTemplate(0, '没有权限');
        }

        return true;
    }

    /**
     * 根据用户请求，返回不同类型数据
     * @author LvGang 2019/2/25 0025 10:54 JH支付 <2849084774@qq.com>
     * @param $code     错误码
     * @param $msg      提示信息
     * @return \think\response\Json
     */
    public function echoTemplate($code, $msg)
    {

        $responseType = $this->getResponseType();

        if ('html' == $responseType) {
            $this->error('没有权限');
        }

        $response = Response::create(['code' => $code, 'msg' => $msg], 'json');

        throw new HttpResponseException($response);

    }

    /**
     * 根据平台名称获取平台id（主平台返回false）
     * @author LvGang 2019/4/12 0012 10:57 JH支付 <2849084774@qq.com>
     * @param $unitName
     * @return bool
     */
    protected function getUnitId($unitName)
    {

        $result = model('Admin')->where(['name' => $unitName])->field('id, unit_id')->find();

        if (empty($result)) {
            $id = -999;
        } else {
            $id = $result['unit_id'] == -1 ? false : $result['id'];
        }

        cookie('unitId', $id); // cookie值也更新

        return $id;
    }


}
