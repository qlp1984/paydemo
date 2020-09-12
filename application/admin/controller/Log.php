<?php
/**
 * @Author Quincy  2019/1/8 下午5:32
 * @Note 日志控制器
 */

namespace app\admin\controller;


class Log extends AdminBase
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
        //$this->assign(model('AdminLog')->getList(['uid' => session('admin.id'), 'port' => 3, 'type' => $act], 10, $config, 'id desc'));

        //return $this->fetch();

        $where = ['port' => 3, 'type' => $act];
        $adminId = session('admin.id');
        if ($adminId != -1) {
            $where['uid'] = $adminId;
        }

        $datas = model('AdminLog')
            ->with('admin')
            ->where($where)
            ->order('id desc')
            ->paginate(10, false, $config);

        return $this->fetch('index', [
            'list'  => $datas->all(),   // 数据集
            'total' => $datas->total(), // 数据总数
            'page'  => $datas->render() // 分页样式
        ]);
    }

}