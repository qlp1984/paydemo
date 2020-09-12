<?php
/**
 * @User LvGang  2019/1/9 0009 18:04
 * @Note    系统公告
 */

namespace app\admin\controller;

class Notices extends AdminBase
{

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   首页
     * @return mixed
     */
    public function index()
    {

        $title = input('post.title');
        $crowd = input('post.crowd');
        $switch = input('post.switch');
        $is_stick = input('post.is_stick');
        //$datas = model('SystemsNotices')->getList([], 10, $config = [], 'order asc');
        $datas = model('SystemsNotices')
            ->Title($title)
            ->Crowd($crowd)
            ->Switch($switch)
            ->IsStick($is_stick)
            ->order('order')
            ->paginate(10);
        $this->assign([
            'list'  => $datas->all(),
            'page'  => $datas->render(),
        ]);
        return $this->fetch();
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   新增
     * @return \think\response\Json
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $postData = input('post.');

            // 验证
            $res = $this->validate($postData, 'SystemsNoticesValidate');
            if (true !== $res) {
                return json(['code' => 0, 'msg' => $res]);
            }

            $result = model('SystemsNotices')->addEditData($postData, false);

            // 日志
            $result['code'] == 1 && addAdminLog('新增系统公告，id：' . $result['data']);

            return json($result);
        }
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   编辑
     * @return \think\response\Json
     */
    public function edit()
    {
        if ($this->request->isPost()) {
            $postData = input('post.');

            // 验证
            $res = $this->validate($postData, 'SystemsNoticesValidate');
            if (true !== $res) {
                return json(['code' => 0, 'msg' => $res]);
            }

            $result = model('SystemsNotices')->addEditData($postData);

            // 日志
            $result['code'] == 1 && addAdminLog('修改系统公告，id：' . $result['data']);

            return json($result);
        }
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   删除
     */
    public function del()
    {
        if ($this->request->isPost()) {
            $id = input('post.id');

            addAdminLog('删除系统公告，id：' . $id); // 日志

            echo model('SystemsNotices')->where('id', $id)->delete();
        }
    }


}