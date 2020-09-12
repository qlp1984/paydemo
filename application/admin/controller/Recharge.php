<?php
/**
 * @Author Quincy  2019/1/8 下午5:44 JH支付 <8·58`887`906@qq.com>
 * @Note 充值账号管理控制器
 */

namespace app\admin\controller;


class Recharge extends AdminBase
{
    /** 收款账号管理
     * @author xi 2019/5/16 15:19
     * @Note
     * @return \think\response\View
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function account(){
        //收款账户列表
        $list = model('RechargeAccounts')->UnitId($this->unitId)->paginate(10, true);

        foreach($list as $k=>$v){
            $list[$k]['account_count_money'] = model('RechargeLog')->where(['recharge_accounts_id'=>$v['id'],'status'=>1])->value('SUM(money)');
        }

        //银行列表
        $bankList = model('Banks')->field('id,name')->select();
        $view_data = [
            'list'      => $list->all(), // 数据列表
            'page'      => $list->render(), // 分页
            'bankList'  => $bankList
        ];
        return view('recharge/account_list', $view_data);
    }


    /** 添加收款账号
     * @author xi 2019/5/16 15:54
     * @Note
     * @return \think\response\Json
     */
    public function addAccount(){
        $postData = input('post.','');
        if(empty($postData)){
            return json(['code'=>0,'msg'=>'fail->参数错误']);
        }
        $postData['unit_id'] = session('admin')['unit_id'];

        //校检账号
        $is = model('RechargeAccounts')->where(['unit_id'=>$postData['unit_id'],'type'=>$postData['type'],'account'=>$postData['account']])->find();
        if($is){
            return json(['code'=>0,'msg'=>'添加失败,已存在收款账号']);
        }
        $data = [
            'unit_id' =>$postData['unit_id'],
            'name' =>$postData['name'],
            'type' =>$postData['type'],
            'account' =>$postData['account'],
            'text' =>$postData['txt'],
            'create_time' =>time(),
        ];
        if($postData['type']==2){
            $data['bank_id'] = $postData['bank'];
        }
        $id = model('RechargeAccounts')->insertGetId($data);
        if($id>0){
            return json(['code'=>1,'msg'=>'添加成功']);
        }

        return json(['code'=>0,'msg'=>'添加失败']);
    }


    /** 删除收款账号
     * @author xi 2019/5/16 16:22
     * @Note
     * @return \think\response\Json
     */
    public function delAccount(){
        $id = input('post.id','');
        if(empty($id)){
            return json(['code'=>0,'msg'=>'fail->参数错误']);
        }
        $is = model('RechargeAccounts')->where(['unit_id'=>session('admin')['unit_id'],'id'=>$id])->delete();
        if($is){
            return json(['code'=>1,'msg'=>'删除成功']);
        }
        return json(['code'=>1,'msg'=>'删除失败']);
    }

    /**
     * @author xi 2019/5/16 21:02
     * @Note    码商充值记录
     * @return \think\response\View
     */
    public function recharge(){

        //查找过期订单，设置过期，修改状态
        model('RechargeLog')->update(['status'=>2],['status'=>0,'create_time'=>[ '<=', time()-24*60*60 ]]);


        $adminId = session('admin')['id'];

        $order_no = input('get.order_no','');
        $sTime = input('get.st','');
        $tTime = input('get.tt','');

        $merchnat_id = input('get.merchnat_id','');
        $recharge_accounts_id = input('get.recharge_accounts_id','');

        //p($sTime.'|'.$tTime);
        $where = [];
        if ($sTime && $tTime) {
            $where['create_time'] = ['between time', [$sTime, $tTime]];
        }

        if($merchnat_id){
            $where['merchant_id'] = model('Merchants')->where(['name'=>$merchnat_id])->whereOr(['phone'=>$merchnat_id])->value('id');
        }

        if($order_no){
            $where['order_no'] = $order_no;
        }

        if($recharge_accounts_id){
            $where['recharge_accounts_id'] = $recharge_accounts_id;
        }
        $unit_id = $this->getUnitId(session('admin')['name']);

        //收款账户列表
        $accountlist = model('RechargeAccounts')->with('banks')->select();

        $list = model('RechargeLog')->with('merchants')->UnitId($unit_id)->where($where)->order('status asc,update_time desc')->paginate(10, true);


        //今日订单总
        $allLog = model('RechargeLog')->UnitId($unit_id)->field('count(id),sum(money),sum(money_true)')->find();

        $merchnatLog = [];

        if(isset($where['merchant_id']) && $where['merchant_id'] > 0 ){
            //今日订单总
            $merchnatLog = model('RechargeLog')->where(['merchant_id'=>$where['merchant_id']])->field('count(id),sum(money),sum(money_true)')->find();
            $merchnatLog['m_name'] = model('Merchants')->where(['id'=>$where['merchant_id']])->value('name');
        }
        $view_data = [
            'list'      => $list->all(), // 数据列表
            'page'      => $list->render(), // 分页
            'accountlist' =>$accountlist,
            'allLog' =>$allLog,
            'merchnatLog'=>$merchnatLog,
        ];
        return view('recharge/recharge_list', $view_data);
    }



    /** 下发充值
     * @author xi 2019/5/17 14:33
     * @Note
     * @return \think\response\Json
     */
    public function accountLog(){
        $id = input('post.id','');
        $accountId = input('post.account_id','');
        $money = input('post.money','');

        if(empty($id) || empty($accountId)){
            return json(['code'=>0,'msg'=>'fail->参数错误']);
        }

        if($money<= 0 ){
            return json(['code'=>0,'msg'=>'上分充值失败']);
        }

        //管理员确认码商上分

        //银行列表
        $bankList = model('Banks')->field('id,name')->select();

        $unit_id = $this->getUnitId(session('admin')['name']);
        $accountInfo = $is = model('RechargeAccounts')->UnitId($unit_id)->where(['id'=>$accountId])->find();

        $info = '姓名：【'.$accountInfo['name'].'】<br/>账号:【'.$accountInfo['account'].'】';
        if($accountInfo['type']==2){
            $info .= '<br/>银行名称：';
            foreach($bankList as $k=>$v){
                if($accountInfo['bank_id'] == $v['id']){
                    $info .= '【'.$v['name'].'】';
                }
            }
        }


        //查找充值信息
        $thisinfo = model('RechargeLog')->where(['id'=>$id,'status'=>0])->find();

        if(!$thisinfo){
            return json(['code'=>0,'msg'=>'上分处理失败']);
        }

        //c查找码商余额信息
        $balance = model('MerchantsBalance')->where(['merchant_id'=>$thisinfo['merchant_id']])->find();

        $records = [
            'type'         => 1,
            'scene'        => 2,
            'merchant_id'  => $thisinfo['merchant_id'],
            'before_money' => $balance['balance'],
            'money'        => $money,
            'after_money'  => $balance['balance'] + $money,
            'source_table' => 'merchants',
            'data_id'      => $id,
            'descript'     => '管理员确认充值上分' . $money,
            'extra'        => '',
            'create_time'  => time()
        ];
        //p('222');
        $res = model('MerchantsBalance')->operationBalance($thisinfo['merchant_id'],$money,'add',$records);
        if($res['code']==1){
            //修改充值订单
            model('RechargeLog')->update(
                [
                    'status'=>1,
                    'type'=>$accountInfo['type'],
                    'recharge_accounts_id'=>$accountId,
                    'info'=>$info,
                    'money_true'=>$money,
                    'account'=>$accountInfo['account']
                ],[
                'id'=>$id,
                'status'=>0
            ]);

            //写入操作记录
            model('AdminLog')->addData(session('admin')['name'].'处理充值上分，充值订单：【'.$thisinfo['order_no'].'】，金额：【'.$money.'】','action',session('admin'),session('admin')['unit_id']);

            //更新码商余额
            (new \app\common\service\Account())->updataMerchantBalancePool(session('admin')['unit_id'],$thisinfo['merchant_id'],$money,'add');

            return json(['code'=>1,'msg'=>'上分处理成功']);
        }

        return json(['code'=>0,'msg'=>'fail->上分处理失败']);
    }

}