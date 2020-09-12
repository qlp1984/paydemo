<?php
/**
 * @User LvGang  2019/1/22 0022 15:07
 * @Note 权限管理
 */

namespace app\admin\controller;

class Rbac extends AdminBase
{

    /**
     * @author LvGang JH支付 <284908477482849084774@qq.com>
     * @Note   账号列表
     * @return mixed
     */
    public function index()
    {

        $datas = model('Admin')->UnitId($this->Admin['unit_id'])->order('id desc')->paginate(10);
        $this->assign([
            'list'  => $datas->all(),   // 数据集
            'total' => $datas->total(), // 数据总数
            'page'  => $datas->render() // 分页样式
        ]);
        return $this->fetch();
    }

    /**
     * @author LvGang JH支付 <2849084774*87`906@qq.com>
     * @Note   查看角色权限
     * @return mixed
     */
    public function readRole()
    {

        $id = input('id', 1);
        $this->assign([
            'id'    => $id,
            'role'  => model('RoleNode')->getCacheNodeData($id)
        ]);
        return $this->fetch();
    }

    /**
     * 修改用户权限
     * @author LvGang 2019/2/18 0018 11:53 JH支付 <858*8-2849084774@qq.com>
     * @return \think\response\Json
     */
    public function handleChecked()
    {
        if ($this->request->isPost()) {
            $adminId = input('post.adminId'); // 操作的账号id
            $nodeId = input('post.nodeId/a', []); // 该账号所拥有的权限id

            // 检测是否自己
            if ($adminId != -1 && $adminId == $this->Admin['id']) {
                return json(['code' => 0, 'msg' => '不可以编辑自己权限']);
            }

            // 如果不是主平台管理员修改权限，只能修改他自己拥有的权限
            if ($this->Admin['id'] != -1) {
                $role_node_id = model('Admin')->where('id', session('admin.id'))->value('role_node_id');
                $role_node_arr = explode(',', $role_node_id);
                foreach ($nodeId as $v) {
                    if (!in_array($v, $role_node_arr)) {
                        return json(['code' => 0, 'msg' => '只能保存你自己拥有的权限,请先查看自己拥有哪些权限']);
                    }
                }
            }

            $res = model('Admin')->where('id', $adminId)->update(['role_node_id' => implode(',', $nodeId)]);
            if ($res) {
                handleCache('getCacheNodeData_' . $adminId, 'del');
                return json(['code' => 1, 'msg' => '操作成功']);
            }

        }
        return json(['code' => 0, 'msg' => '操作失败']);
    }

    /**
     * 新增管理员
     * @author LvGang 2019/2/20 0020 18:34 JH支付 <85***82849084774@qq.com>
     * @return mixed
     */
    public function addAdmin()
    {

        if ($this->request->isPost()) {
            $postData = input('post.');

            // 验证数据
            $result = $this->validate($postData, 'AdminValidate.addAdmin');
            if(true !== $result){
                return json(['code' => 0, 'msg' => $result]);
            }

            $adminMdl = model('Admin');

            // 验证用户名
            if ($adminMdl->where(['name' => $postData['name']])->count()) {
                return json(['code' => 0, 'msg' => '用户名已存在']);
            }

            // 验证手机号
            if ($adminMdl->where(['phone' => $postData['phone']])->count()) {
                return json(['code' => 0, 'msg' => '手机号已存在']);
            }

            return $adminMdl->addData($postData);
        }

        $this->assign([
            'adminId'   => 0,
            'type'      => '增加',
            'act'       => 'add',
            'data'      => null,
        ]);

        return json(['code' => 1, 'msg' => '', 'data' => $this->fetch('edit_admin')]);
    }

    /**
     * 编辑管理员
     * @author LvGang 2019/2/21 0021 10:38 JH支付 <28490847747*2849084774@qq.com>
     * @return mixed
     */
    public function editAdmin()
    {

        if ($this->request->isPost()) {
            $postData = input('post.');

            // 验证数据
            $result = $this->validate($postData, 'AdminValidate.addAdmin');
            if(true !== $result){
                return json(['code' => 0, 'msg' => $result]);
            }

            $adminMdl = model('Admin');

            return $adminMdl->editData($postData);
        }

        $adminId = input('adminId'); // 管理员id

        // 检测是否自己
        if ($adminId != -1 && $adminId == $this->Admin['id']) {
            return json(['code' => 0, 'msg' => '不可以编辑自己']);
        }

        $data = model('Admin')->where('id', $adminId)->find();
        $this->assign([
            'adminId'   => $adminId,
            'type'      => '编辑',
            'act'       => 'edit',
            'data'      => $data,
        ]);
        return json(['code' => 1, 'msg' => '', 'data' => $this->fetch('edit_admin')]);
    }



    /**
     * @author Mr.zhou  2019/1/5 17:17 JH支付 <85·8*882849084774@qq.com>
     * @Note   删除管理员
     */
    public function del()
    {
        $id = intval(input('post.id'));

        if (!$id) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }

        // 检测是否删除自己
        if ($id == $this->Admin['id']) {
            return json(['code' => 0, 'msg' => '不可以删除自己']);
        }

        // 写人
        $bool = model('Admin')->scopeDelAdmin($id);
        if ($bool !== false) {
            return json(['code' => 1, 'msg' => '删除成功']);
        }

        return json(['code' => 0, 'msg' => '删除失败']);
    }

    /**
     * 谷歌口令
     * @author LvGang 2019/4/15 0015 19:49 JH支付 <8582849084774@qq.com>
     * @return mixed
     */
    public function google()
    {

        if ($this->request->isPost()) {
            $passwd = input('post.passwd'); // 用户提交的明文密码
            $id = session('admin.id'); // 用户id从session取
            $model = model('Admin');
            $data = $model->where('id', $id)->field('google_token, passwd, passwd_salt')->find();
            // 验证登录密码
            if (!$model->checkPasswd($passwd, $data))
                return json(['code' => 0, 'msg' => '密码错误']);

            if (!$model->where('id', $id)->update(['is_use_google' => 2]))
                return json(['code' => 0, 'msg' => '修改is_use_google失败']);

            return json(['code' => 1, 'msg' => '操作成功']);
        }
        $this->assign('data', model('Admin')->where('id', session('admin.id'))->find());
        return $this->fetch();
    }



}