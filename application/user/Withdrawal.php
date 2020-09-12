<?php
/**
 * @author xi 2019/1/8 10:29
 * @Note
 */
namespace app\user\controller;
use app\user\controller\UserBase;
use app\common\model\UserWithdrawal;
use think\Paginator;
use think\Cache;
use lib\Csv;
use lib\GoogleAuth;

class Withdrawal extends UserBase
{

    public function _initialize()
    {

        //$this->success();
        //检测抢单信息。抢单成功，未处理，时间超过半个小时，自动回到等待抢单列表  rob_time（最后抢单时间）
        model('UserWithdrawal')->grabOrderNo();
        // 模型初始化

    }

    /**
     * @author xi 2019/1/8 10:39 JH支付 <2849084774@qq.com>
     * @Note 盘口提现订单列表
     */
    public function index(){
        //p($this->users);
        //帅选条件 type
        $type = input('post.type','0');
        $sOrderStime = input('s','');
        $sOrderTtime = input('t','');
        $order_no = input('order_no','');
        $out_order_no = input('out_order_no','');
        $where['user_id'] = $this->users['id'];
        $where['is_split'] = 0;
        if($type){
            $where['status'] = $type;
        }
        if($order_no){
            $where['order_no'] = $order_no;
        }
        if($out_order_no){
            $where['out_trade_no'] = $out_order_no;
        }
        if($sOrderStime && $sOrderTtime){
            // $where['rob_time'] = $sOrderBankType;
            $where['apply_time'] = ['between time',[$sOrderStime, $sOrderTtime]];
        }

        //实例化
        $userWithdrawal = UserWithdrawal::with('userBank.banks,muser');


         //找出所有符合条件的提现订单
         $withdrawalList = $userWithdrawal->where($where)->order('id desc, review asc')->paginate(10);



        $withdrawOrderStatus = config('dictionary')['withdraw_order_status'];//提现订单状态

        $view_data=[
            'users' => model('users')->where('id', session('users.id'))->find(),
            'bankType' => config('dictionary')['bank_type'],
            'withdrawalList' => $withdrawalList,
            'withdrawOrderStatus' => $withdrawOrderStatus
        ];

        //输出视图
        return view('withdrawal/withdrawal',$view_data);
    }

    /**
     * @author xi 2019/1/8 10:39 JH支付 <2849084774@qq.com>
     * @Note 盘口提现订单列表
     */
    public function indexSplit(){

        //帅选条件 type
        $type = input('post.type','0');
        $sOrderStime = input('s','');
        $sOrderTtime = input('t','');
        $order_no = input('order_no','');
        $out_order_no = input('out_order_no','');
        $where['user_id'] = $this->users['id'];
        $where['is_split'] = 1;
        if($type){
            $where['status'] = $type;
        }
        if($order_no){
            $where['order_no'] = $order_no;
        }
        if($out_order_no){
            $where['out_trade_no'] = $out_order_no;
        }
        if($sOrderStime && $sOrderTtime){
            // $where['rob_time'] = $sOrderBankType;
            $where['apply_time'] = ['between time',[$sOrderStime, $sOrderTtime]];
        }

        //实例化
        $userWithdrawal = UserWithdrawal::with([
            'userBank.banks',
            'muser',
            'assists' => function($query) {
                return $query->where(['status'=>['<>',3]]);
            },
            ]);


        //找出所有符合条件的提现订单
        $withdrawalList = $userWithdrawal->where($where)->order('id desc, review asc')->paginate(10);

        //p($withdrawalList);

        $withdrawOrderStatus = config('dictionary')['widthdrawal_assists_status'];//提现订单状态

        $view_data=[
            'users' => model('users')->where('id', session('users.id'))->find(),
            'bankType' => config('dictionary')['bank_type'],
            'withdrawalList' => $withdrawalList,
            'withdrawOrderStatus' => $withdrawOrderStatus
        ];

        //输出视图
        return view('withdrawal/withdrawal_split',$view_data);
    }

    /**
     * @author xi 2019/1/8 13:43 JH支付 <2849084774@qq.com>
     * @Note 盘口取消提现订单操作
     */
    public function cancelOrder(){

        $postData['id']=input('post.order_id','0');
        $postData['remark']=input('post.order_txt','');

        //验证参数
        $result = $this->validate($postData,'app\member\validate\GrabSheetValidate');
        if( $result != 1 ){
            return json(['code'=>-1,'msg'=>$result]);
        }

        $orderInfo = model('UserWithdrawal')->where(['id'=>$postData['id']])->find();
        if(!empty($orderInfo)){
            if($orderInfo['is_split']==0){
                if($orderInfo['status']>2){
                    return  json(['code'=>'0','msg'=>'取消订单失败']);
                }
            }elseif ($orderInfo['is_split']==1){
                if($orderInfo['status']>1){
                    return  json(['code'=>'0','msg'=>'取消订单失败']);
                }
                $orderAssists = model('UserWithdrawalAssists')->where(['withdrawal_id'=>$postData['id'],'status'=>['<>',3]])->select();

                if(count($orderAssists)>=1){
                    return  json(['code'=>'0','msg'=>'取消订单失败']);
                }
            }
        }

        //取消提现订单
        $res = model('UserWithdrawal')->cancelOrder($postData);

        if($res == true){
            return json(['code'=>'1','msg'=>'取消订单成功']);
        }

        return  json(['code'=>'0','msg'=>'取消订单失败']);

    }


    /**
     * @author xi 2019/1/8 15:08 JH支付 <2849084774@qq.com>
     * @Note 校检盘口提现金额
     */
    public function isWithdrawal(){

        //接收提现金额
        $money = input('get.money','0');

        //对比余额
        $res = model('UsersBalance')->isWithdrawal($money);
        if($res){
            $ip = getIp();
            //获取绑定的ip
            $thisIp = model('UserWithdrawalIp')->where(['user_id'=>session('users')['id'],'status'=>1])->value('ip');
            if(!$thisIp || $ip != $thisIp){
                return  json(['code'=>'0','msg'=>'绑定的ip不一样，请重新提交申请ip']);
            }
            return  json(['code'=>'1','msg'=>'金额可以提现']);
        }

        return  json(['code'=>'0','msg'=>'余额不足请重新输入']);
    }

    /**
     * @author xi 2019/1/8 15:37 JH支付 <2849084774@qq.com>
     * @Note 提现申请，生成提现订单
     */
    public function withdrawalOrder(){

        //接收提现金额
        $money = input('get.money','0');
        $is_split = input('get.is_split','0'); // 是否可以分开抢单 1是 0否
        $userBankId = input('get.user_bank_id','0');
        $bankType = input('get.bank_type','0');
        $googleCode = input('get.googleCode');
        $is_use_google = input('get.is_use_google');

        if(!$userBankId>0){
            return  json(['code'=>'0','msg'=>'请选择提现收款账号']);
        }

        //校检提现金额
        $res = model('UsersBalance')->isWithdrawal($money);
        if(!$res){
            return  json(['code'=>'0','msg'=>'余额不足请重新输入']);
        }

        $ip = getIp();
        //获取绑定的ip
        $thisIp = model('UserWithdrawalIp')->where(['user_id'=>session('users')['id'],'status'=>1])->value('ip');
        if(!$thisIp || $ip != $thisIp){
            return  json(['code'=>'0','msg'=>'绑定的ip不一样，请重新提交申请ip']);
        }

        // 检测是否需要验证谷歌令牌
        if ($is_use_google == 1 && !(new GoogleAuth)->verifyCode(model('Users')->where('id', session('users.id'))->value('google_token'), $googleCode)) {
            return  json(['code'=>'0','msg'=>'谷歌令牌错误']);
        }

        //修改用户账户信息,并生成订单
        $res = model('UsersBalance')->updateBalance($money,$userBankId,$bankType, $is_split);

        if($res !== 1){
            return  json(['code'=>'0','msg'=>$res]);
        }
        trace(session('users')['name'].'商户申请提现：'.$money.$userBankId.$bankType,'withdrawalOrder');
        return  json(['code'=>'1','msg'=>'申请提现成功']);
    }

    /**
     * @author xi 2019/1/8 17:59 JH支付 <2849084774@qq.com>
     * @Note 获取对应类型的账户
     */
    public function userBank(){
        //获取账户类型
        $type = input('get.bank_type');

        //获取该用户该类型的全部收款账号
        $blist=model('UserBanks')->userBankList($type);
        if(!$blist){
            return  json(['code'=>'1','msg'=>'type->参数错误']);
        }
        $blistStr='<option value="0">请选择</option>';
        foreach($blist as $k => $v){
            $blistStr .= '<option value="'.$v['id'].'">'.$v['account_number'].'</option>';
        }
        return json(['code'=>'0','msg'=>'type->参数错误','data'=>$blistStr]);

    }


    /**
     * @author xi 2019/1/24 18:21 JH支付 <2849084774@qq.com>
     * @Note 获取提现订单
     * @return \think\response\Json
     */
    public function getOrderInfo(){
        if (request()->isGet()) {
            $postData = input('get.','');

            //验证
            $result = $this->validate($postData,'WithdrawValidate.id');
            if( $result != 1 ){
                return json(['code'=>-1,'msg'=>$result]);
            }

            //查看订单详情
            $thisInfo = UserWithdrawal::with('userBank.banks,muser,merchants')
                ->find($postData['id']);

            $bankType = config('dictionary')['bank_type'];
            if(isset($thisInfo->user_bank->bank_type) && isset($bankType[$thisInfo->user_bank->bank_type])){
                $thisInfo->user_bank->bank_type_str = $bankType[$thisInfo->user_bank->bank_type];
            }
            if($thisInfo->images){
                $imagesArray = explode('|',$thisInfo->images);
                $images='';
                foreach($imagesArray as $k=>$v){
                    $images.='<a href="'.$v.'" target="_blank"><img width="50px" src="'.$v.'" alt=""></a>';
                }
                $thisInfo->images = $images;
            }
            //dump($thisInfo->toArray());die;

            //插入提现抢单记录 TODO
            $actionList = model('UserWithdrawalRecords')::with('merchant,user')
                ->where(['withdrawal_id'=>$postData['id']])
                // ->fetchSql(true)
                ->select();
            //p($actionList);
            $view_data=[
                'thisInfo'         =>  $thisInfo,
                'actionList'         =>  $actionList,
            ];

            //输出视图
            return view('withdrawal/order_info',$view_data);
        }

    }

    /**
     * 导出excel
     * JH支付 <8`588`2849084774@qq.com>
     */
    public function exportOrder()
    {
        $csvName = '提现订单';
        //帅选条件 type
        $type = input('type','0');
        $sOrderStime = input('start','');
        $sOrderTtime = input('end','');
        $where['user_id'] = $this->users['id'];
        if($type>0){
            $where['status'] = $type;
        }
        if($sOrderStime && $sOrderTtime){
            // $where['rob_time'] = $sOrderBankType;
            $where['apply_time'] = ['between time',[$sOrderStime, $sOrderTtime]];
        }
        //p($where);
        //实例化
        $userWithdrawal = UserWithdrawal::with('userBank,merchants')
            ->with('merchants')
            ->where($where);

        //找出所有符合条件的提现订单
//        $withdrawalList = $userWithdrawal->where($where)->order('create_time desc');


        $coulumnName = [ '系统ID', '订单号', '提现前金额',  '提现金额','提现后金额','订单状态','接口手续费','申请时间','抢单码商','码商ID','上次抢单时间','抢单次数','提现账号','提现账号类型','收款人姓名','审核状态'];
        $fieldName = [ 'id', 'order_no','old_amount', 'amount', 'new_amount', 'status_str','fees','apply_time', 'name@merchants','merchant_id','rob_time','rob_count', 'account_number@user_bank',  'bank_type_str','account_name@user_bank', 'review_str'];
        $csvName = $csvName. date('Y-m-d').'.csv';

        Csv::downCsv($userWithdrawal, $coulumnName, $fieldName,$csvName);
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   用户确认到账
     * @return \think\response\Json
     */
    public function updateStatus()
    {
        if (request()->isPost()) {
            $model = model('UserWithdrawal');
            $id = input('post.id');
            $data = $model->where('id', $id)->field('id, user_id, merchant_id, status')->find();
            if ($data['status'] != 3)
                return json(['code' => 0, 'msg' => '操作错误']);

            $records = [
                'merchant_id'   => $data['merchant_id'],
                'user_id'       => $data['user_id'],
                'remark'        => '用户确认收到打款',
                'withdrawal_id' => $data['id']
            ];
            $res = $model->updateStatus($id, 4, $records);

            if ($res != true) {
                return json(['code' => 0, 'msg' => $res]);
            }

            addAdminLog('确认收到打款', 'action'); // 日志

            return json(['code' => 1, 'msg' => '操作成功']);
        }
        return json(['code' => 0, 'msg' => '操作错误']);
    }


    public function updateStatusNot(){
        if (request()->isPost()) {
            $model = model('UserWithdrawal');
            $id = input('post.id');
            $data = $model->where('id', $id)->field('id, user_id, merchant_id, status')->find();
            if ($data['status'] != 3)
                return json(['code' => 0, 'msg' => '操作错误']);

            $records = [
                'merchant_id'   => $data['merchant_id'],
                'user_id'       => $data['user_id'],
                'remark'        => '用户未收到打款',
                'withdrawal_id' => $data['id']
            ];
            $res = $model->updateStatus($id, 2, $records);

            if ($res != true) {
                return json(['code' => 0, 'msg' => $res]);
            }

            addAdminLog('确认未收到打款', 'action'); // 日志

            return json(['code' => 1, 'msg' => '操作成功']);
        }
        return json(['code' => 0, 'msg' => '操作错误']);
    }

    /** 提交申请提现IP
     * @author xi 2019/6/4 10:21
     * @Note
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function withdrawalIp(){
        $ip = input('post.ip','');
        if(!$ip){
            return json(['code'=>0,'msg'=>'参数错误']);
        }

        //删除以往IP
       model('UserWithdrawalIp')->where(['user_id'=>session('users')['id']])->delete();

        //插入数据
        model('UserWithdrawalIp')->insert([
            'status'=>0,
            'user_id'=>session('users')['id'],
            'ip'=>$ip,
            'create_time'=>time(),
            'unit_id'=>session('users')['unit_id']
        ]);


        return json(['code'=>1,'msg'=>'成功提交申请，等待管理员审核']);

    }

    /** 获取该提现订单，被抢额度记录
     * @author xi 2019/7/9 14:37
     * @Note
     * @return \think\response\Json|\think\response\View
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function merchnatWithdrawal(){
        if (request()->isGet()) {
            $postData = input('get.', '');

            if(!$postData['id']) return json(['code'=>0,'msg'=>'参数错误']);

            $list = model('UserWithdrawalAssists')->with('merchants')->where(['withdrawal_id'=>$postData['id']])->paginate(15);

            //查看订单详情
            $orderInfo = model('UserWithdrawal')->find($postData['id']);

            //已被抢单额度
            $moneyed = model('UserWithdrawalAssists')->where(['withdrawal_id'=>$postData['id'],'status'=>['<>',3]])->sum('money');

            //剩余额度
            $moneying = $orderInfo['amount'] - $moneyed;
            $view_data=[
                'status_code'=>config('dictionary')['widthdrawal_assists_status'],
                'list' => $list,
                'orderInfo'=>$orderInfo,
                'moneyed'=>$moneyed,
                'moneying'=>$moneying,
            ];

            //输出视图
            return view('withdrawal/merchant_withdrawal',$view_data);
        }
    }

    /** 操作记录
     * @author xi 2019/7/9 16:34
     * @Note
     * @return \think\response\Json|\think\response\View
     */
    public function getOrderRecords(){
        if (request()->isGet()) {
            $postData = input('get.', '');

            if(!$postData['id']) return json(['code'=>0,'msg'=>'参数错误']);
            if(!$postData['merchant_id']) return json(['code'=>0,'msg'=>'参数错误']);

            $list = model('UserWithdrawalRecords')->with('merchant,user')->where(['split_id'=>$postData['id'],'merchant_id'=>$postData['merchant_id']])->select();


            $view_data=[
                'list' => $list,
            ];

            //输出视图
            return view('withdrawal/withdrawal_record',$view_data);
        }
    }

    /** 用户确认收道打款
     * @author xi 2019/7/11 11:15
     * @Note
     * @return \think\response\Json
     */
    public function splitOrderSuccss(){
        if (request()->isPost()) {
            $model = model('UserWithdrawalAssists');
            $id = input('post.id');
            $data = $model->where('id', $id)->find();
            if ($data['status'] != 2)
                return json(['code' => 0, 'msg' => '操作错误']);

            $records = [
                'merchant_id'   => $data['merchant_id'],
                'user_id'       => $data['user_id'],
                'money'        => $data['money'],
                'remark'        => '商户:'.$this->users['name'].'（ID：'.$this->users['id'].'）确认收到打款,金额:'. $data['money'],
                'withdrawal_id' => $data['withdrawal_id'],
                'split_id' => $data['id']
            ];
            $res = $model->updateStatus($id, 4, $records);

            if ($res != true) {
                return json(['code' => 0, 'msg' => $res]);
            }

            addAdminLog('商户:'.$this->users['name'].'（ID：'.$this->users['id'].'）确认收到打款,金额:'. $data['money'], 'action'); // 日志

            return json(['code' => 1, 'msg' => '操作成功']);
        }
        return json(['code' => 0, 'msg' => '操作错误']);
    }

}