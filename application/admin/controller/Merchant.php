<?php
/**
 * @Author Quincy  2019/1/10 上午11:58
 * @Note 码商控制器
 */

namespace app\admin\controller;

use app\common\model\Merchants;
use app\common\model\MerchantsBalanceRecords;
use app\common\model\MerchantsRecharge;
use app\common\model\PayOrders;
use xh\library\session;
use app\common\service\Account as serviceAccount;

class Merchant extends AdminBase
{

    /**
     * @Author Quincy  2019/1/10 下午1:39 JH支付 <2849084774@qq.com>
     * @Note 码商管理列表
     */
    public function index()
    {

        $unit_id = trim(input('get.unit_id','')); // 平台账号名称
        $name = trim(input('get.name',''));
        $status = trim(input('get.status',''));
        $auditStatus = trim(input('get.audit_status',''));

        $merchantsMdl = new Merchants();
        $where = [];

        // 获取 unitId
        $this->unitId = !empty($unit_id) ? $this->getUnitId($unit_id) : $this->unitId;

        $this->unitId && $where = ['unit_id' => $this->unitId];
        if($name) {
            $merId = $merchantsMdl->where(['name'=>$name])->whereOr(['phone'=>$name])->whereOr(['id'=>$name])->value('id') ?: -1;
            $where['id'] =['eq', $merId];
        }

        if($status) $where['switch'] = ['eq',$status];
        if($auditStatus) $where['audit_status'] = ['eq',$auditStatus];

        $data = $merchantsMdl->getMerchants($where);

        return view('merchant/index' . $this->template, ['channels'=>get_channels(),'data'=>$data]);
    }

    /**
     * @Author Quincy  2019/1/10 下午1:45 JH支付 <2849084774@qq.com>
     * @Note 添加码商
     */
    public function addMerchant()
    {
        $name = trim(input('post.name',''));
        $passwd = trim(input('post.passwd',''));
        $phone = trim(input('post.phone',''));
        $switch = trim(input('post.switch',''));
        $auditStatus = trim(input('post.audit_status',''));
        $merchantStatus = trim(input('post.merchant_status',''));
        $rate = trim(input('post.rate',''));
        $adminId = session('admin')['unit_id'];

        $merchants = new Merchants();

        if(!$name) return json(['status'=>0,'msg'=>'用户名称未填写']);
        $nameRow = $merchants->where(['name'=>$name])->find();
        if($nameRow) return json(['status'=>0,'msg'=>'该用户名称已存在，请重新输入']);
        if(!$passwd) return json(['status'=>0,'msg'=>'密码未填写']);
        if(!is_phone($phone)) return json(['status'=>0,'msg'=>'手机号码格式不正确']);
        $phoneRow = $merchants->where(['phone'=>$phone])->find();
        if($phoneRow) return json(['status'=>0,'msg'=>'该手机号码已存在，请重新输入']);
        if(!in_array($switch,[1,2])) return json(['status'=>0,'msg'=>'请输入合法的账户状态']);
        if(!in_array($auditStatus,[1,2])) return json(['status'=>0,'msg'=>'请输入合法的审核状态']);
        if(!in_array($merchantStatus,[1,2])) return json(['status'=>0,'msg'=>'请输入合法的子商户']);
        $rate = $merchants->dealRate($rate);
        if($rate === false || !is_array($rate) || empty($rate)) return json(['status'=>0,'msg'=>'请输入合法的费率设置']);

        $result = $merchants->addMerchant($adminId,$name,$passwd,$phone,$switch,$auditStatus,$rate,$merchantStatus);

        if($result['code'] != 1) return json(['status'=>0,'msg'=>$result['msg']]);

        return json(['status'=>1,'msg'=>'添加成功']);
    }

    /**
     * 查看码商的信息
     * JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     */
    public function getOneMerchant()
    {
        $merchantId =  input('post.merchant_id',0);
        $data = (new Merchants())->getOneMerchant($merchantId);

        return json(['status'=>1,'data'=>$data]);
    }

    /**
     * @Author Quincy  2019/1/10 下午1:47 JH支付 <2849084774@qq.com>
     * @Note 修改码商账号
     */
    public function editMerchant()
    {
        $merchantId = input('post.merchant_id',0);
        $name = trim(input('post.name',''));
        $passwd = trim(input('post.passwd',''));
        $phone = trim(input('post.phone',''));
        $weight = intval(input('post.weight',0));
        $switch = trim(input('post.switch',''));
        $auditStatus = trim(input('post.audit_status',''));
        $rate = trim(input('post.rate',''));

        $merchantsMdl = new Merchants();

        $merchant = $merchantsMdl->UnitId($this->unitId)->where(['id'=>$merchantId])->find();
        if(!$merchant)  return json(['status'=>0,'msg'=>'用户id不存在']);
        if(!$name) return json(['status'=>0,'msg'=>'用户名称未填写']);
        if($merchant['name']!== $name){
            $nameRow = $merchantsMdl->where(['name'=>$name])->find();
            if($nameRow) return json(['status'=>0,'msg'=>'该用户名称已存在，请重新输入']);
        }
        if(!is_phone($phone)) return json(['status'=>0,'msg'=>'手机号码格式不正确']);
        if($merchant['phone'] != $phone){
            $phoneRow = $merchantsMdl->where(['phone'=>$phone])->find();
            if($phoneRow) return json(['status'=>0,'msg'=>'该手机号码已存在，请重新输入']);
        }
        if(!in_array($switch,[1,2])) return json(['status'=>0,'msg'=>'请输入合法的账户状态']);
        if(!in_array($auditStatus,[1,2])) return json(['status'=>0,'msg'=>'请输入合法的审核状态']);
        $rate = $merchantsMdl->dealRate($rate);
        if($rate === false || !is_array($rate) || empty($rate)) return json(['status'=>0,'msg'=>'请输入合法的费率设置']);

        $result = $merchantsMdl->updateMerchant($name,$passwd,$phone,$weight,$switch,$auditStatus,$rate,$merchant);

        if($result['code'] != 1) return json(['status'=>0,'msg'=>$result['msg']]);

        addAdminLog('编辑码商用户ID：'. $merchantId);

        return json(['status'=>1,'msg'=>'更新成功']);
    }

    /**
     * @Author Quincy  2019/1/15 下午5:06 JH支付 <2849084774@qq.com>
     * @Note 修改余额
     */
    public function editBalance()
    {
        $merchantId = input('post.merchant_id',0);
        $operation = input('post.operation',''); // 类型 增加/increase 扣减/decrease
        $money = floatval(input('post.money',0.00));
        $txt = input('post.txt','无');
        $action = $operation == 'increase' ? '增加' : '扣减';

        if(!in_array($operation,['decrease','increase'])) json(['status'=>0,'msg'=>'请选择合法的增减操作']);
        if( $money <=0 ) return json(['status'=>0,'msg'=>'增加或扣减的金额不能小于或等于0']);

        $merchant = model('Merchants')->UnitId($this->unitId)->where(['id'=>$merchantId])->count();
        if(!$merchant)  return json(['status'=>0,'msg'=>'用户id不存在']);
        $balance = model('MerchantsBalance')->where(['merchant_id'=>$merchantId])->find();
        if(!$balance) return json(['status'=>0,'msg'=>'用户balance不存在']);

        $records = [
            'type'         => $operation == 'increase' ? 1 : 2,
            'scene'        => 2,
            'merchant_id'  => $merchantId,
            'before_money' => $balance['balance'],
            'money'        => $money,
            'after_money'  => $operation == 'increase' ? $balance['balance'] + $money : $balance['balance'] - $money,
            'source_table' => 'merchants',
            'data_id'      => $merchantId,
            'descript'     => '管理员('.session('admin')['id'].')' . $action . $money.'（操作说明：'.$txt.'）',
            'extra'        => '',
            'create_time'  => time()
        ];
        $act = $operation == 'increase' ? 'add' : 'reduce';
        $result = model('MerchantsBalance')->operationBalance($merchantId, $money, $act, $records);

        if($result['code'] != 1) return json(['status'=>0,'msg'=>$result['msg']]);

        //更新码商余额
        (new \app\common\service\Account())->updataMerchantBalancePool(session('admin')['unit_id'],$merchantId,$money,$act);

        addAdminLog($action . '码商用户ID：' . $merchantId . '的金额' . $money, 'action'); // 写入日志

        return json(['status'=>1,'msg'=>'更新成功']);
    }

    
    /**
     * @Author Quincy  2019/1/15 下午5:07 JH支付 <2849084774@qq.com>
     * @Note 修改状态
     */
    public function editSwitch()
    {
        $merchantId = input('post.merchant_id',0);

        $merchant = model('Merchants')->where(['id'=>$merchantId])->find();
        if(!$merchant)  return json(['status'=>0,'msg'=>'用户id不存在']);

        $result = (new Merchants())->updateSwitch($merchant);

        if($result !== 1) return json(['status'=>0,'msg'=>$result]);

        return json(['status'=>1,'msg'=>'更新成功']);
    }

    /**
     * 码商用户订单 JH支付 <2849084774@qq.com>
     * @param $id
     * @return \think\response\View
     */
    public function order($id)
    {
        $order = PayOrders::with('assists')
            ->with('users')
            ->Merchant($id)
            ->order('id desc')
            ->paginate(15,false,['query' => request()->param()]);

        $merchant = (new Merchants())->getOneMerchant($id);
        return view('merchant/order',['order'=>$order,'merchant'=>$merchant,'channels'=>get_channels()]);
    }

    /**
     * 码商充值记录 JH支付 <2849084774@qq.com>
     * @param $id
     * @return \think\response\View
     */
    public function recharge($id)
    {
        $order = MerchantsRecharge::merchant($id)
            ->order('id desc')
            ->paginate(15,false,['query' => request()->param()]);
        $merchant = (new Merchants())->getOneMerchant($id);
        return view('merchant/recharge',['order'=>$order,'merchant'=>$merchant,'channels'=>get_channels()]);
    }

    /**
     * 出入金记录 JH支付 <2849084774@qq.com>
     * @param $id   码商id
     * @return \think\response\View
     */
    public function record($id)
    {
        $order = MerchantsBalanceRecords::Merchant($id)
            ->order('id desc')
            ->paginate(15,false,['query' => request()->param()]);

        $merchant = (new Merchants())->getOneMerchant($id);
        return view('merchant/record',['order'=>$order,'merchant'=>$merchant,'channels'=>get_channels()]);
    }

    /**
     * @author Mr.zhou  2019/1/5 17:17 JH支付 <2849084774@qq.com>
     * @Note   删除码商
     */
    public function del()
    {
        $id = intval(input('id'));

        if (!$id) {
            return json(['code' => 0, 'msg' => '删除失败']);
        }

        $count = model('Merchants')->UnitId($this->unitId)->where(['id' => $id])->count();
        if (!$count) {
            return json(['code' => 0, 'msg' => '操作错误']);
        }

        // 写人
        $bool = model('Merchants')->scopeDelMerchants($id);

        if ($bool !== false) {

            return json(['code' => 1, 'msg' => '删除成功']);
        }

        return json(['code' => 0, 'msg' => '删除失败']);
    }


    /** 码商类型通道管理页面
     * @author xi 2019/3/20 20:49
     * @Note
     * @return \think\response\Json|\think\response\View
     */
    public function channelList(){
        $merchantId = input('get.merchant_id','0');
        if($merchantId<1){
            return json(['code'=>0,'msg'=>'参数错误']);
        }
        $channelList =model('Channels')
            ->where(['switch'=>1])
            ->order('id asc')
            ->paginate(10,false,['query' => request()->param()]);

        foreach($channelList as $k=>$v){
            $is = model('MerchantsChannels')->where(['channel_id'=>$v['id'],'merchant_id'=>$merchantId])->value('switch');
            if($is=='0' || $v['switch']==2){
                $channelList[$k]['isSwitch']=0;
            }else{
                $channelList[$k]['isSwitch']=1;
            }
        }
        //p($channelList);

        return view('merchant/merchant_channel',['channelList'=>$channelList]);
    }


    /** 修改码商类型通道开关
     * @author xi 2019/3/20 21:15
     * @Note
     * @return \think\response\Json
     */
    public function editMerchantsChannels(){
        $merchantId = input('post.merchant_id','0');
        $channel_id = input('post.channel_id','0');

        $isC = model('Channels')->where(['id'=>$channel_id])->value('switch');
        if(!($isC==1)){
            return json(['code'=>0,'msg'=>'该类型通道已被禁止']);
        }

        $is = model('MerchantsChannels')->where(['channel_id'=>$channel_id,'merchant_id'=>$merchantId])->value('switch');
        if(!$is && $is!='0'){
            $res = model('MerchantsChannels')->insert(['channel_id'=>$channel_id,'merchant_id'=>$merchantId,'switch'=>'0']);
            if($res){
                return json(['code'=>1,'msg'=>'success']);
            }
            return json(['code'=>0,'msg'=>'fail']);
        }

        $switch = $is == 1 ? 0 : 1;

        $ures = model('MerchantsChannels')->where(['channel_id'=>$channel_id,'merchant_id'=>$merchantId])->update(['switch'=>$switch]);
        if($ures){
            return json(['code'=>1,'msg'=>'success']);
        }
        return json(['code'=>0,'msg'=>'fail']);
    }


    //关闭该码商所有通道下线
    public function offChannels(){
        $id = input('post.id','0');

        //查找该码商的所有通道
        $list = model('MerchantsAccounts')->where(['merchant_id'=>$id])->select();
        if(!empty($list)){
            foreach($list as $k=>$v){
                //修改状态
                model('MerchantsAccounts')->update(['is_training'=>0,'is_receiving'=>2],['merchant_id'=>$v['merchant_id'],'unit_id'=>$this->Admin['unit_id']]);
                //关闭网关
                (new serviceAccount())->updataMerchantPool($this->Admin['unit_id'],$id,$v['channel_id'],$v['id'],'off');
            }
        }
        return json(['code'=>1,'msg'=>'操作成功']);

    }


}