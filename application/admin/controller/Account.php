<?php
/**
 * @Author Quincy  2019/1/8 下午5:24
 * @Note 收款账户控制器
 */

namespace app\admin\controller;

use app\socket\controller\Api;
use app\common\service\Pool;
use lib\Http;
use app\common\service\Sign;
use app\common\service\Account as AccountService;
use think\db\Expression;

class Account extends AdminBase
{
    /**
     * @author Mr.zhou  2019/1/9 14:14 JH支付 <2849084774@qq.com>
     * @Note  收款账号（通道)
     */
    public function index(){
        // 今天
        $nowTime = date('Y-m-d',time());
        // 昨天
        $zrTime = date('Y-m-d',strtotime("-1 day"));

        // 通道类型类别
        $channelsData = model('Channels')->where('switch', 1)->select();

        // 接收参数
        $seach_name = input('seach_name');
        $seach_receiving = intval(input('seach_receiving'));
        $seach_type = intval(input('seach_type'));
        $seach_channels = intval(input('seach_channels'));

        // 查询码商的所有收款账号 加添加筛选条件
        $where = $this->unitId ? ['a.unit_id' => $this->unitId] : [];
        $orWhere = [];

        if( $seach_type == 1 || $seach_type == 2 ){
            $where['a.type'] = $seach_type;
        }

        if( $seach_receiving == 1 || $seach_receiving == 2 ){
            $where['a.is_receiving'] = $seach_receiving;
        }

        if( $seach_name != ''  ){
            $where['a.receipt_name'] = $seach_name;
            $aId = model('MerchantsAccounts')->where(['receipt_name'=>$seach_name])->field('id')->find();
            $orWhere['merchant_account_id'] = $aId['id'];
        }

        if( $seach_channels != 0  ){
            $where['a.channel_id'] = $seach_channels;
            $orWhere['channel_id'] = $seach_channels;
        }

        $merchantsAccountsAllData = model('MerchantsAccounts')
            ->alias('a')
            ->with('MerchantsAccountsData')
            ->field('a.*,d.active_time as active_time')
            ->join('ln_merchants_accounts_data d', 'a.id=d.merchant_account_id')
            ->where($where)
            ->order('a.is_receiving asc, a.is_training desc, d.active_time desc')
            ->paginate(10,false, ['query'=>request()->param()]);

        //p(collection($merchantsAccountsAllData)->toArray());
        if ( !empty($merchantsAccountsAllData->all()) ){
            foreach ($merchantsAccountsAllData as $k => $v){
                // 今天
                $nowOrderData = model('PayOrders')->whereTime('create_time','between',deal_time(0))->where(['status'=>'4','merchant_account_id'=>$v['id']])->field('sum(money) as total_money,count(id) as total_count')->find();
                $merchantsAccountsAllData[$k]['today_total_money'] = (float)$nowOrderData['total_money'];
                $merchantsAccountsAllData[$k]['today_total_count'] = (float)$nowOrderData['total_count'];

                // 昨天
                $zrOrderData = model('PayOrders')->whereTime('create_time','between',deal_time(1))->where(['status'=>'4','merchant_account_id'=>$v['id']])->field('sum(money) as total_money,count(id) as total_count')->find();
                $merchantsAccountsAllData[$k]['yesterday_total_money'] = (float)$zrOrderData['total_money'];
                $merchantsAccountsAllData[$k]['yesterday_total_count'] = (float)$zrOrderData['total_count'];

                // 全部
                $allOrderData = model('PayOrders')->where(['status'=>'4','merchant_account_id'=>$v['id']])->field('sum(money) as money')->find();
                $merchantsAccountsAllData[$k]['all_money'] = (float)$allOrderData['money'];


                // 活跃时间
                if( $v['merchants_accounts_data']['active_time'] == 0 ){
                    $merchantsAccountsAllData[$k]['active_time'] = 50;
                } else {
                    $merchantsAccountsAllData[$k]['active_time'] = time() - $v['merchants_accounts_data']['active_time'];
                }

                // 所属码商
                $merchantsAccountsAllData[$k]['merchantsName'] = model('Merchants')->where(['id'=>$v['merchant_id']])->value('name');
                // 码商余额
                $merchantsAccountsAllData[$k]['merchantsBalance'] = model('MerchantsBalance')->where(['merchant_id'=>$v['merchant_id']])->value('balance');

                $merchantsAccountsAllData[$k]['accountsName'] = 0;
                // 是否是子商户
                if ( $v['parent_id'] > 0 ){
                    $merchantsAccountsAllData[$k]['accountsName'] =  model('MerchantsAccounts')->where('id='.$v['parent_id'])->value('name');;
                }

                //60分钟成功率
                $merchantsAccountsAllData[$k]['successRate1'] = model('PayOrders')->getOrderSuccessRate(['merchant_account_id'=>$v['id']],['create_time'=>['>',time()-3600] ],$v['unit_id']);
                //当日分钟成功率
                $merchantsAccountsAllData[$k]['successRate24'] = model('PayOrders')->getOrderSuccessRate(['merchant_account_id'=>$v['id']],['create_time'=>['between',deal_time(1)]],$v['unit_id']);
            }
        }
        // 订单信息
        $getPlatMoneyWhere = $this->unitId ? ['unit_id' => $this->unitId] : [];
        $money = model('PayOrders')->getPlatMoney('','','', $getPlatMoneyWhere); // 统计金额

        // 订单成功率 15
        $nowTime = time();

        $st1 = $nowTime-900;
        $st2 = $nowTime-1800;
        $st3 = $nowTime-3600;

        $str1 = 'create_time > '.$st1;
        $str2 = 'create_time > '.$st2;
        $str3 = 'create_time > '.$st3;

        // 15
        $orderSuccessOne = model('PayOrders')->getOrderSuccessRate($orWhere,$str1, $this->unitId);
        // 30
        $orderSuccessTwo = model('PayOrders')->getOrderSuccessRate($orWhere,$str2, $this->unitId);
        // 60
        $orderSuccessThree = model('PayOrders')->getOrderSuccessRate($orWhere,$str3, $this->unitId);

        $view_data=[
            'name'=>'member',
            'channelsData' => $channelsData,
            'merchantsAccountsAllData' => $merchantsAccountsAllData,
            'stoning' =>[
                'seach_receiving' => $seach_receiving,
                'seach_type' => $seach_type,
                'seach_name' => $seach_name,
                'seach_channels' => $seach_channels,
            ],
            'money' => $money,
            'orderSuccessOne' => $orderSuccessOne,
            'orderSuccessTwo' => $orderSuccessTwo,
            'orderSuccessThree' => $orderSuccessThree,
        ];

        return view('account/index',$view_data);
    }

    /**
     * @author Mr.zhou  2019/1/5 17:17 JH支付 <2849084774@qq.com>
     * @Note 添加收款账号
     */
    public function add()
    {
        $data = input('post.');

        // 查询通道类型
        $channelsData = model('Channels')->where('id='.$data['channel_id'])->find();

        //if ( empty($channelsData) && $channelsData['name'] == '支付宝' ){
            //$data['is_alipay_account'] = intval(input('alipay_account'));
        //}
        unset($data['alipay_account']);

        $result = $this->validate($data,'app\member\validate\AddReceivingAccountValidate.add');

        if( $result != 1 ){
            return json(['status'=>-1,'msg'=>$result]);
        }
        $data['type'] = 1;
        // 写人
        $bool = model('MerchantsAccounts')->scopeAddMerchantsAccounts($data);

        if($bool){
            return json(['status'=>1,'msg'=>'添加成功']);
        }
        return json(['status'=>0,'msg'=>'添加失败']);
    }

    /**
     * @author Mr.zhou  2019/1/5 17:17 JH支付 <2849084774@qq.com>
     * @Note 修改收款账号
     */
    public function edit()
    {
        $data = input('post.');

        if ( !$data['id'] ){
            return json(['status'=>-1,'msg'=>'异常操作']);
        }

        // 验证
        if ($data['passwd'] == ''){
            unset($data['passwd']);
            $result = $this->validate($data,'app\member\validate\AddReceivingAccountValidate.editNoPass');
        }else {
            $result = $this->validate($data,'app\member\validate\AddReceivingAccountValidate.editYesPass');
        }

        if( $result != 1 ){
            return json(['status'=>-1,'msg'=>$result]);
        }

        $where['id'] = $data['id'];

        // 修改
        $bool = model('MerchantsAccounts')->where($where)->update($data);

        if($bool){
            // 日志
            addAdminLog('修改收款账号，编号：' . $data['id'], 'action');

            return json(['status'=>1,'msg'=>'修改成功']);
        }
        return json(['status'=>0,'msg'=>'修改失败']);
    }

    /**
     * @author Mr.zhou  2019/1/5 17:17 JH支付 <2849084774@qq.com>
     * @Note 修改网关
     */
    public function editReceiving()
    {
        $id = intval(input('id'));
        return json(['status'=>-1,'msg'=>'开启失败']);
        if ( !$id ){
            return json(['status'=>-1,'msg'=>'开启失败']);
        }

        // 查询活跃时间
        $data = model('MerchantsAccounts')::with('merchantsAccountsData')->where('id='.$id)->find()->toArray();

        $nowTime = time();
        if( $data['merchants_accounts_data']['active_time'] == 0 || $nowTime - $data['merchants_accounts_data']['active_time'] > 30  ){
            return json(['status'=>-1,'msg'=>'通道在线才能开启网关哦']);
        }

        // 判断是否转账模式
        if ( $data['is_alipay_account'] == 1 && empty($data['alipay_user_id']) ){
            // 推送获取支付宝的用户ID到客户端
            (new Api())->getAlipayUserId($data['receipt_name']);

            return json(['status'=>-1,'msg'=>'正在获取支付宝的用户ID']);
        }

        // 拼接数据
        $accountsdata['is_receiving'] = 1;

        if ( $data['is_receiving'] == 1 ) {
            $accountsdata['is_receiving'] = 2;
        }

        $nowTime = date('Y-m-d',time());

        // 查询今日收了多少款
        $nowOrderData = model('OrderDaily')
            ->where(['date'=>$nowTime,'user_type'=>'merchant','money_data_id'=>$data['id']])
            ->field('total_money')->find();

        // 收款上限
        if ( empty($nowOrderData) && $nowOrderData['total_money'] > $data['max_money'] ){
            $accountsdata['is_receiving'] = 2;

            $sign = 1;
        }

        $updateRes = model('MerchantsAccounts')->where( [ 'id' => $id ] )->update($accountsdata);

        if( $updateRes !== false){

            // author xi  更新在线码商池
            // (new AccountService())->test();

            if ( $accountsdata['is_receiving'] == 2 ){
                // 插入记录
                $dataLog['account_id'] = $id;
                $dataLog['type'] = $data['channel_id'];
                $dataLog['time'] = time();
                $dataLog['status'] = 2;

                (new Pool())->delByRandom($id);

                model('AccountLog')->insert($dataLog);

            } else {
                // 插入记录
                $dataLog['account_id'] = $id;
                $dataLog['type'] = $data['channel_id'];
                $dataLog['time'] = time();
                $dataLog['status'] = 1;

                model('AccountLog')->insert($dataLog);

                (new Pool())->addByRandom($id);
            }
            if( $accountsdata['is_receiving'] == 2 ){
                // 推送关闭网关
                (new Api())->lowerHairGatewayStatus($data['receipt_name']);
            }

            // 日志
            addAdminLog('修改编号为' . $id . '的网关。', 'action');

            if ( isset($sign) ){
                return json(['status'=>-1,'msg'=>'当前通道收款达到上限']);
            }

            return json(['status'=>1,'msg'=>'修改成功']);
        }
        return json(['status'=>0,'msg'=>'修改失败']);
    }

    /**
     * @author Mr.zhou  2019/1/5 17:17 JH支付 <2849084774@qq.com>
     * @Note 删除账号
     */
    public function del(){
        $id = intval(input('id'));

        if ( !$id ){
            return json(['status'=>-1,'msg'=>'删除失败']);
        }

        // 写人
        $bool = model('MerchantsAccounts')->scopeDelMerchantsAccounts($id);

        if($bool){
            // 日志
            addAdminLog('删除收款账号，编号：' . $id, 'action');
            return json(['status'=>1,'msg'=>'删除成功']);
        }
        return json(['status'=>0,'msg'=>'删除失败']);
    }

    /**
     * @author Mr.zhou  2019/1/5 18:41 JH支付 <2849084774@qq.com>
     * @Note  收款账号的成功率
     */
    public function accountSuccessRate(){
        $id = intval(input('id'));

        if ( !$id ){
            return json(['status'=>-1,'msg'=>'异常操作']);
        }

        $arr['id'] = $id;

        $data = model('PayOrders')->accountSuccessRate($arr);

        return json(['status'=>1,'msg'=>$data]);
    }

    /**
     * @author Mr.zhou  2019/1/7 10:31 JH支付 <2849084774@qq.com>
     * @Note 码商充值记录
     */
    public function rechargeDetails()
    {

        $id = intval(input('id'));
        $merchant_id = intval(input('merchant_id'));
        $where = '';

        if( $id != ''  ){
            $where['order_no'] = $id;
        }
        if( $merchant_id != ''  ){
            $where['merchant_id'] = $merchant_id;
        }

        // 查询
        $merchantRechargeAllData = model('MerchantsRecharge')->where($where)->order('id','desc')->paginate(10,false, ['query'=>request()->param()]);

        $view_data=[
            'merchantRechargeAllData'=>$merchantRechargeAllData,
            'id' => $id,
            'merchant_id' => $merchant_id
        ];

        return view('account/recharge_details',$view_data);
    }

    /**
     * @author Mr.zhou  2019/1/22 15:41 JH支付 <858、2849084774@qq.com>
     * @Note  检测收款账号（单通道测试）
     */
    public function singleChannelTest(){
        $id = intval(input('id'));

        $type = intval(input('type'));

        if ( !$id || !$type ){
            return json(['status'=>-1,'msg'=>'异常操作']);
        }

        // 查询数据
        $data = model('MerchantsAccounts')::with('merchantsAccountsData')->where(['id'=>$id])->find()->toArray();

        if ( empty($data) ){
            return json(['status'=>-1,'msg'=>'异常操作']);
        }

        $nowTime = time();

        // 限制条件
        if( $data['is_receiving'] == 2 || $data['merchants_accounts_data']['active_time'] == 0 || $nowTime - $data['merchants_accounts_data']['active_time'] > 30  ){
            return json(['status'=>-1,'msg'=>'请开启网关']);
        }

        // 转账模式
        if ( $data['is_alipay_account'] == 1  ){
            // 判断是否有支付宝的userID
            if (  empty($data['alipay_user_id']) ){
                return json(['status'=>-1,'msg'=>'当前通道没配置好']);
            }
        }

        // 查询商户ID
        $appid = model('Merchants')->where(['id'=>$data['merchant_id']])->field('mch_id,mch_key')->find();

        // 类型
        switch ($data['channel_id']) {
            case '1':
                $postData['pay_type'] = 'alipay';
                break;
            case '2':
                $postData['pay_type'] = 'wechat';
                break;
            case '3':
                $postData['pay_type'] = 'alipay_red';
                break;
        }
        $postData['appid'] = $appid['mch_id'];
        $postData['callback_url'] = requestHeaderType() . config('base.domain')['api'] . '/gateway/index/ok';
        $postData['success_url'] = requestHeaderType(). config('base.domain')['api'] . '/gateway/index/ok';
        $postData['error_url'] = requestHeaderType(). config('base.domain')['api'] . '/gateway/index/ok';
        $postData['out_trade_no'] = date("YmdHis") . mt_rand(10000, 99999);
        $postData['amount'] = sprintf("%.2f", '0.01');
        $postData['out_uid'] = '';
        $postData['version'] = '';

        if ($type == 2) {
            $postData['amount'] = sprintf("%.2f", '5000.00');
        }
        $sign = (new Sign())->getSign($appid['mch_key'], $postData);

        $postData['sign'] = $sign;
        $postData['order_status'] = 1;
        $postData['receipt_name'] = $data['receipt_name'];


        $url = requestHeaderType() . config('base.domain')['api'] . '/gateway/index/unifiedorder?format=json';

        $bool = Http::sendRequest($url, $postData);

        // zhuang
        $bool = json_decode($bool['msg'], true);

        if ($bool['code'] == 200) {

            $headerUrl = requestHeaderType() . config('base.domain')['api'] . $bool['url'];

            return json(['status' => 1, 'msg' => '创建成功', 'url' => $headerUrl]);
        }

        return json(['status' => -1, 'msg' => $bool['msg']]);
    }

    /**
     * 通道管理
     * @author LvGang 2019/3/8 0008 12:21 JH支付 <2849084774@qq.com>
     * @return mixed
     */
    public function channels()
    {
        $datas = model('Channels')->getList([], 10, ['query' => request()->param()], 'id');

        return $this->fetch('', $datas);
    }

    /**
     * 修改通道状态
     * @author LvGang 2019/3/8 0008 13:22 JH支付 <8582849084774@qq.com>
     * @return \think\response\Json
     */
    public function editChannelsSwitch()
    {
        if ($this->request->isPost()) {
            $model = model('Channels');
            $postData = input('post.');
            $switch = $postData['switch'] == 1 ? 2 : 1; // 1开启 2关闭
            $res = $model->where('id', $postData['id'])->update(['switch' => $switch]);
            if ($res) {
                $channelInfo = $model->where('id', $postData['id'])->find();

                // -----author xi 更新redis channel 池---------
                $channelId = $postData['id'];
                $action = ( $switch == 1 ) ? 'On' : 'Off';
                //更新类型通道池
                (new AccountService())->channelPool($channelId,$channelInfo['type'],$action);
                // -----author xi 更新redis channel 池---------

                $name = $channelInfo['name'];
                $act = $switch == 1 ? '开启' : '关闭';

                addAdminLog($act . $name . '通道'); // 日志

                return json(['code' => 1, 'msg' => '修改成功']);
            } else {
                return json(['code' => 0, 'msg' => '修改失败']);
            }
        }

        return json(['code' => 0, 'msg' => '非法操作']);
    }

    /** 强制下线当前收款通道
     * @author xi 2019/6/13 10:34
     * @Note
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function closeAccount(){
        $id = input('post.id',0);
        if($id){

            //查找当前通道信息
            $accountInfo = model('MerchantsAccounts')->where(['id'=>$id])->find();

            //修改状态
            $res = model('MerchantsAccounts')->update(['is_training'=>0,'is_receiving'=>2],['id'=>$id]);
            if($res){
                //关闭网关
                (new AccountService())->updataMerchantPool($this->Admin['unit_id'],$accountInfo['merchant_id'],$accountInfo['channel_id'],$id,'off');
                return json(['code' => 1, 'msg' => '操作成功']);
            }
        }
        return json(['code' => 0, 'msg' => '操作失败']);
    }

}