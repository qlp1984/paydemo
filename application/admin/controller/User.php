<?php
/**
 * @Author Quincy  2019/1/8 下午5:25
 * @Note 盘口用户控制器
 */

namespace app\admin\controller;

use think\Validate;

class User extends AdminBase
{

    /**
     * @author LvGang JH支付 <2849084774`87`906@qq.com>
     * @Note   盘口用户管理首页
     * @return mixed
     */
    public function index()
    {
        $usersMdl = model('Users');
        $unit_id = input('unit_id', '');
        $name = input('name', '');
        $status = input('status', '');
        $day = date('Ymd');

        // 获取 unitId
        $this->unitId = !empty($unit_id) ? $this->getUnitId($unit_id) : $this->unitId;

        $id ='';
        if($name) {
            $user = $usersMdl->UnitId($this->unitId)->where(['name|phone'=>$name])->value('id');
            $id = $user ?: -1;
        }

        // 获取开启的通道
        $channels = model('Channels')->where('switch', 1)->column('name, rate', 'id');

        $datas = $usersMdl
            ->UnitId($this->unitId)
            ->UserId($id)
            ->Switch($status)
            ->with([
                'usersBalance',
                'rates' => function($query) use ($channels){
                    return $query->where(['type' => '2', 'channel_id' => ['in', array_keys($channels)]]);
                },
                    /*
                'usersAccountsAnalyzes' => function($query) use ($day){
                    return $query->where(['day' => $day]);
                },*/
                'userWithdrawalIp',
                'userUnit'
                ])
            ->order('id desc')
            ->paginate(10, false, ['query' => $this->request->param()]);
        //p(collection($datas->all())->toArray());

        foreach($datas as $dk=>$dv){
            //今日、昨日收款统计
            $datas[$dk]['countData'] = model('PayOrders')->payCount($dv['id']);
        }

        $this->assign([
            'list'      => $datas->all(), // 数据列表
            'page'      => $datas->render(), // 分页
            'status'    => $usersMdl->_switch, // 账户状态
            'auditStatus'    => $usersMdl->_auditStatus, // 审核状态
            'channels'  => $channels, // 渠道费率设置
        ]);

        return $this->fetch('index' . $this->template);
    }

    /**
     * @author LvGang JH支付 <28490847748-7906@qq.com>
     * @Note   添加用户
     * @return \think\response\Json
     */
    public function addUsers()
    {
        if ($this->request->isPost()) {
            $postData = input('post.');
            $users = [
                'name'      => $postData['name'],
                'phone'     => $postData['phone'],
                'passwd'    => $postData['passwd'],
                'switch'    => $postData['switch'],
                'audit_status'    => $postData['audit_status']
            ];
            $channels = $postData['channels'];

            // 验证users数据
            $result = $this->validate($users,'UsersValidate.addUsers');
            if (true !== $result) {
                return json(['code' => 0, 'msg' => $result]);
            }
            // 验证name唯一 'name'   => 'unique:user',
            if (!Validate::unique($users['name'], 'users', $users, 'name')) {
                return json(['code' => 0, 'msg' => '用户名已存在']);
            }
            // 验证name唯一 'name'   => 'unique:user',
            if (!Validate::unique($users['phone'], 'users', $users, 'phone')) {
                return json(['code' => 0, 'msg' => '手机号已存在']);
            }

            return json(model('Users')->addData($users, $channels, session('admin.unit_id')));
        }

        return json(['code' => 1, 'msg' => '非法操作']);
    }

    /**
     * 编辑用户
     * @author LvGang JH支付 <858*2849084774@qq.com>
     * @return \think\response\Json
     * @throws \think\Exception
     */
    public function editUsers()
    {

        $usersMdl = model('Users');
        if ($this->request->isPost()) {
            $postData = input('post.');
            $users = [
                'id'        => $postData['id'],
                'name'      => $postData['name'],
                'phone'     => $postData['phone'],
                'passwd'    => $postData['passwd'],
                'switch'    => $postData['switch'],
                'audit_status'    => $postData['audit_status']
            ];
            $channels = $postData['channels'];

            // 验证users数据
            $result = $this->validate($users, 'UsersValidate.editUsers');
            if (true !== $result) {
                return json(['code' => 0, 'msg' => $result]);
            }

            // 验证密码
            if (!empty($postData['passwd']) && true !== $result = $this->validate($users, 'UsersValidate.passwd')) {
                return json(['code' => 0, 'msg' => $result]);
            }

            // 验证手机号是否唯一
            if ($usersMdl->where(['phone' => $postData['phone'], 'id' => ['neq', $postData['id']]])->count()) {
                return json(['code' => 0, 'msg' => '该号码已存在']);
            }

            // 验证数据是否是当前平台的
            if (!$usersMdl->UnitId($this->unitId)->where(['id' => $postData['id']])->count()) {
                return json(['code' => 0, 'msg' => '数据不存在']);
            }

            return json($usersMdl->adminUpdate($users, $channels));
        }

        $id = input('id');
        $data = $usersMdl->with('users_balance')->find($id);
        //p($data->toArray());

        $this->assign([
            'id'            => $id,
            'data'          => $data,
            'status'        => $usersMdl->_switch, // 账户状态
            'auditStatus'   => $usersMdl->_auditStatus, // 审核状态
            'channels'      => model('rates')->getUsersRates($id, 2), // 渠道费率设置
        ]);
        return json(['code' => 1, 'msg' => $this->fetch()]);
    }

    /**
     * @author LvGang JH支付 <284908477482849084774@qq.com>
     * @Note   增/减余额
     * @return \think\response\Json
     */
    public function operationBalance()
    {
        if ($this->request->isPost()) {
            $act = input('post.act'); // 操作   增加 add, 减少 reduce
            $balance = input('post.balance'); // 变动金额
            $user_id = input('post.user_id'); // 用户id
            $txt = input('post.txt','无'); // 用户id
            //p($txt);
            // 验证金额
            if (empty($balance) || !is_numeric($balance) || $balance <= 0) {
                return json(['code' => 0, 'msg' => '金额错误']);
            }

            // 验证数据是否是当前平台的
            if (!model('Users')->UnitId($this->unitId)->where(['id' => $user_id])->count()) {
                return json(['code' => 0, 'msg' => '数据不存在']);
            }

            $usersBalance = model('UsersBalance')->where('user_id', $user_id)->field('id, balance')->find();
            if (empty($usersBalance)) {
                return json(['code' => 0, 'msg' => '用户余额数据不存在']);
            }

            // 组装流水记录数据
            $actName = $act == 'add' ? '增加' : '减少';
            $after_money = $act == 'add' ? $usersBalance['balance'] + $balance : $usersBalance['balance'] - $balance;
            $balances_records = [
                'user_id'       => $user_id,
                'scene'         => 2,
                'descript'      => '管理员('.session('admin')['id'].')' . $actName . '金额: ' . $balance.'(操作说明：'.$txt.')',
                'type'          => $act == 'add' ? 1 : 2,
                'money'         => $balance,
                'before_money'  => $usersBalance['balance'],
                'after_money'   => $after_money,
                'source_table'  => 'users_balance',
                'data_id'       => $usersBalance['id'],
                'create_time'   => time()
            ];

            return json(model('UsersBalance')->operationBalance($user_id, $balance, $act, $balances_records));
        }
        return json(['code' => 0, 'msg' => '非法操作']);
    }

    /**
     * @author LvGang JH支付 <28490847748882849084774@qq.com>
     * @Note   交易订单
     * @return mixed
     */
    public function transactionOrder()
    {
        $user_id = input('user_id');
        $this->assign(model('PayOrders')->getList(['user_id' => $user_id], 10, [], 'id desc'));
        return $this->fetch();
    }

    /**
     * @author LvGang JH支付 <2849084774888790·6@qq.com>
     * @Note   提现记录
     * @return mixed
     */
    public function withdrawal()
    {
        $user_id = input('user_id');
        $data = model('user_withdrawal')->getList(['user_id' => $user_id], 10, [], 'id desc');
        $this->assign($data);
        return $this->fetch();
    }

    /**
     * @author Mr.zhou  2019/1/5 17:17 JH支付 <85·8*882849084774@qq.com>
     * @Note   删除盘口用户
     */
    public function del()
    {
        $id = intval(input('id'));

        if (!$id) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }

        // 写人
        $bool = model('Users')->scopeDelUser($id);

        if ($bool !== false) {

            return json(['code' => 1, 'msg' => '删除成功']);
        }

        return json(['code' => 0, 'msg' => '删除失败']);
    }

}