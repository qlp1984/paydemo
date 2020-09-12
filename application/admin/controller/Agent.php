<?php
/**
 * @Author Quincy  2019/1/10 上午11:58
 * @Note 码商控制器
 */

namespace app\admin\controller;

use app\common\model\Merchants;
use xh\library\session;

class Agent extends AdminBase
{

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   盘口用户管理首页
     * @return mixed
     */
    public function index()
    {
       // p($this->unitId);
        $usersMdl = model('Agents');
        $name = input('name', '');
        $status = input('status', '');

        $id ='';
        if($name) {
            $userId = $usersMdl->where(['name|phone'=>$name])->value('id');
            $id = $userId ? $userId : -1;
        }

        $channels = model('Channels')->where('switch', 1)->column('name, rate', 'id');

        $datas = $usersMdl
            ->UserId($id)
            ->UnitId($this->unitId)
            ->Switch($status)
            ->with([
                'agentsBalance',
                'rates' => function($query) use ($channels){
                    return $query->where(['type' => '3', 'channel_id' => ['in', array_keys($channels)]]);
                },
            ])
            ->order('id desc')
            ->paginate(10, false, ['query' => $this->request->param()]);

        //显示统计
        foreach($datas as $k=>$v){
            //统计所有下级码商的订单总量
            $datas[$k]['agentMerchantsSum'] = model('PayOrders')->agentMerchantsSum($v['id']);
        }

        //p($datas);

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
     * @author LvGang JH支付 <2849084774@qq.com>
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
                'audit_status'    => $postData['audit_status'],
                'is_tid'    => $postData['is_tid'],
                'unit_id'    => session('admin')['unit_id'],
            ];
            $channels = $postData['channels'];

            // 验证users数据
            $result = $this->validate($users,'UsersValidate.addUsers');
            if (true !== $result) {
                return json(['code' => 0, 'msg' => $result]);
            }
            $nameBool = model('Agents')->where('name',$users['name'])->count('id');
            $phoneool = model('Agents')->where('phone',$users['phone'])->count('id');

            // 验证name唯一 'name'   => 'unique:user',
            if ($nameBool) {
                return json(['code' => 0, 'msg' => '用户名已存在']);
            }

            if ($phoneool) {
                return json(['code' => 0, 'msg' => '手机号已存在']);
            }
            return json(model('Agents')->addData($users, $channels));
        }
        return json(['code' => 0, 'msg' => '非法操作']);
    }

    /**
     * 编辑用户
     * @author LvGang JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     * @throws \think\Exception
     */
    public function editUsers()
    {
        $model = model('Agents');
        if ($this->request->isPost()) {
            $postData = input('post.');
           // p($postData);
            $users = [
                'id'        => $postData['id'],
                'name'      => $postData['name'],
                'phone'     => $postData['phone'],
                'passwd'    => $postData['passwd'],
                'switch'    => $postData['switch'],
                'is_tid' => $postData['is_tid'],
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
            if ($model->where(['phone' => $postData['phone'], 'id' => ['neq', $postData['id']]])->count()) {
                return json(['code' => 0, 'msg' => '该号码已存在']);
            }
            return json($model->adminUpdate($users, $channels));
        }

        $id = input('id');
        $data = $model->with('agentsBalance')->find($id);
        //p($data->toArray());
       // p(model('Rates')->getUsersRates($id, 3));
        $this->assign([
            'id'            => $id,
            'data'          => $data,
            'status'        => $model->_switch, // 账户状态
            'auditStatus'   => $model->_auditStatus, // 审核状态
            'channels'      => model('Rates')->getUsersRates($id, 3), // 渠道费率设置
        ]);
        return json(['code' => 1, 'msg' => $this->fetch()]);
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   增/减余额
     * @return \think\response\Json
     */
    public function operationBalance()
    {
        if ($this->request->isPost()) {
            $act = input('post.act'); // 操作   增加 add, 减少 reduce
            $balance = input('post.balance'); // 变动金额
            $user_id = input('post.user_id'); // 用户id
            // 验证金额
            if (empty($balance) || !is_numeric($balance) || $balance <= 0) {
                return json(['code' => 0, 'msg' => '金额错误']);
            }

            $usersBalance = model('AgentsBalance')->where('agent_id', $user_id)->field('id, balance')->find();
            if (empty($usersBalance)) {
                return json(['code' => 0, 'msg' => '代理余额数据不存在']);
            }

            // 组装流水记录数据
            $actName = $act == 'add' ? '增加' : '减少';
            $after_money = $act == 'add' ? $usersBalance['balance'] + $balance : $usersBalance['balance'] - $balance;
            $balances_records = [
                'agent_id'       => $user_id,
                'scene'         => 3,
                'remark'      => '管理员' . $actName . '金额: ' . $balance,
                'type'          => $act == 'add' ? 1 : 2,
                'money'         => $balance,
                'before_money'  => $usersBalance['balance'],
                'after_money'   => $after_money,
                'data_id'       => 0,
                'create_time'   => time()
            ];

            return json(model('AgentsBalance')->operationBalance($user_id, $balance, $act, $balances_records));
        }
        return json(['code' => 0, 'msg' => '非法操作']);
    }

    /**
     * @Author Quincy  2019/1/15 下午5:07 JH支付 <2849084774@qq.com>
     * @Note 修改状态
     */
    public function editSwitch()
    {
        $merchantId = input('post.merchant_id',0);

        $merchant = db('merchants')->where(['id'=>$merchantId])->find();
        if(!$merchant)  return json(['status'=>0,'msg'=>'用户id不存在']);

        $result = (new Merchants())->updateSwitch($merchant);

        if($result !== 1) return json(['status'=>0,'msg'=>$result]);

        return json(['status'=>1,'msg'=>'更新成功']);
    }

    // 代理提现
    public function withdrawal(){

        // 接收参数
        $seach_name = input('seach_name');
        $seach_receiving = intval(input('seach_receiving'));

        $where = [];

        if ( $seach_name != '' ){
            $where['tid'] = model('Agents')->where('name',$seach_name)->value('id');
        }

        if ( $seach_receiving != '' ){
            $where['status'] = $seach_receiving;
        }

        $data = model('AgentsWithdrawal')->where($where)->order('id','desc')->paginate(10);

        if ( !empty($data) ){
            foreach ($data as $k => $v ){
                $pAgent = model('Agents')->where('id',$v['tid'])->value('name');
                $data[$k]['name'] = $pAgent;
            }
        }

        $view_data = [
            'data'  => $data,
            'stoning'                  => [
                'seach_receiving' => $seach_receiving,
                'seach_name'      => $seach_name
            ],
        ];
        return view('agent/withdrawal', $view_data);
    }

    // 修改提现
    public function editWithdrawal(){
        $data = input('post.');

        if ( !isset($data['name']) || !isset($data['id']) || !isset($data['type']) ){
            return json(['status' => -1, 'msg' => '非法操作']);
        }

        // 准备组装数据
        $arr['remark'] = $data['name'];
        $arr['status'] = 1;

        if ( $data['type'] == 2 ){
            $arr['status'] = 3;
        }

        $bool = model('AgentsWithdrawal')->where('id',$data['id'])->update($arr);

        if ( $bool !== false ){

            if ( $arr['status'] == 3 ){
                model('AgentsBalance')->backBalance($data['id'],$data['name']);
            }

            return json(['status' => 1, 'msg' => '操作成功']);
        }

        return json(['status' => -1, 'msg' => '操作失败']);
    }

    /**
     * @author mr.zhou JH支付 <2849084774@qq.com>
     * @Note   交易流水
     * @return \think\response\Json
     */
    public function record(){
        // 码商ID
        $id = input('id');
        $order = model('AgentsBalancesRecords')
            ->where(['agent_id'=>$id])
            ->order('id desc')
            ->paginate(15,false,['query' => request()->param()]);

        return view('agent/record',['order'=>$order]);
    }

    /**
     * 开启、禁用谷歌
     * @author LvGang 2019/6/4 0004 11:39 JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     */
    public function editGoogle()
    {
        if ($this->request->isPost()) {
            $postData = input('post.');
            $res = model('Agents')->where('id', $postData['id'])->update(['is_use_google' => $postData['value']]);
            if ($res) {
                $info = $postData['value'] == 1 ? '开启' : '关闭';
                addAdminLog($info . '代理编号为' . $postData['id'] . '的谷歌验证');
                return json(['code' => 1, 'msg' => $info . '成功']);
            }
        }
        return json(['code' => 0, 'msg' => '操作失败']);
    }

    /**
     * 删除代理
     * @author LvGang 2019/6/4 0004 12:09 JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     */
    public function del()
    {
        if ($this->request->isPost()) {
            $postData = input('post.');
            $res = model('Agents')->del($postData['id']);
            if ($res['code'] == 1) {
                addAdminLog('删除代理，编号为' . $postData['id']);
                return json(['code' => 1, 'msg' => '删除成功']);
            }
        }
        return json(['code' => 0, 'msg' => '操作失败']);
    }

}