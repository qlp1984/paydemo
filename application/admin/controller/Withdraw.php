<?php
/**
 * @Author Quincy  2019/1/8 下午5:36
 * @Note 抢单管理控制器
 */

namespace app\admin\controller;
use app\common\model\UserWithdrawal;
use lib\Http;
use app\common\service\Sign;

class Withdraw extends AdminBase
{


    /**
     * @author xi 2019/1/17 10:13 JH支付 <2849084774888790`6@qq.com>
     * @Note 提现抢单列表页
     * @return \think\response\View
     * @throws \think\exception\DbException
     */
    public function index(){

        $sOrderNo = input('order_no','');
        $sOutOrderNo = input('out_order_no','');
        $sOrderAmount = input('order_amount','');
        $user_name = input('user_name','');
        $merchant_name = input('merchant_name','');
        $sOrderAccountName = input('order_account_name','');
        $sOrderStatus = input('order_status','');
        $sOrderReview = input('order_review','');
        $sOrderBankType = input('order_bank_type','');
        $sOrderStime = input('s','');
        $sOrderTtime = input('t','');

        $where=['is_split'=>0];

        if($sOrderAccountName){
            //找到收款账号ID
            $userBankIds = model('userBanks')->where(['account_name'=>$sOrderAccountName])->select();
            $inIds ='';
            if(!empty($userBankIds)){
                foreach($userBankIds as $k=>$v){
                    $inIds .= $v['id'].',';
                    //$inIds[] = $v['id'];
                }
            }
            $where['user_bank_id'] = ['IN',rtrim($inIds,',')];
        }

        // 码商id
        $merchant_id = '';
        if ($merchant_name) {
            $merchant_id = model('Merchants')->where(['name' => $merchant_name])->value('id') ?: -1;
        }

        // 盘口id
        $user_id = '';
        if ($user_name) {
            $user_id = model('Users')->where(['name' => $user_name])->value('id') ?: 0;
        }

        if($sOrderReview){
            $where['review'] = $sOrderReview;
        }
        if($sOrderBankType){
            $where['bank_type'] = $sOrderBankType;
        }
        if($sOrderStime && $sOrderTtime){
            // $where['rob_time'] = $sOrderBankType;
            $where['update_time'] = ['between time',[$sOrderStime, $sOrderTtime]];
        }

        $withdrawList = UserWithdrawal::with('userBank,muser,merchants')
            ->UnitId($this->unitId)
            ->Status($sOrderStatus)
            ->UserId($user_id)
            ->MerchantId($merchant_id)
            ->OrderNo($sOrderNo)
            ->OutTradeNo($sOutOrderNo)
            ->Amount($sOrderAmount)
            ->where($where)
            ->order('id desc')
//            ->order('review asc, apply_time desc')
            ->paginate(10);

        $bankTypeList = config('dictionary')['bank_type'];//收款账户类型
        $withdrawOrderStatus = config('dictionary')['withdraw_order_status'];//提现订单状态
        $reviewStatus = config('dictionary')['review_status'];//审核状态

        //dump($withdrawList->toArray());die;
        $view_data=[
            'withdrawList'         =>  $withdrawList,
            'bankTypeList'         =>  $bankTypeList,
            'withdrawOrderStatus'  =>  $withdrawOrderStatus,
            'reviewStatus'         =>  $reviewStatus,
            'statisticsMoney'      =>  model('UserWithdrawal')->statisticsMoney($user_id, $merchant_id) // 统计金额
        ];

        //输出视图
        return view('withdrawal/index',$view_data);
    }


    /** 分发抢单列表
     * @author xi 2019/7/9 18:15
     * @Note
     * @return \think\response\View
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function indexsplit(){
        $sOrderNo = input('order_no','');
        $sOutOrderNo = input('out_order_no','');
        $sOrderAmount = input('order_amount','');
        $user_name = input('user_name','');
        $sOrderAccountName = input('order_account_name','');
        $sOrderStatus = input('order_status','');
        $sOrderReview = input('order_review','');
        $sOrderBankType = input('order_bank_type','');
        $sOrderStime = input('s','');
        $sOrderTtime = input('t','');

        $where=['is_split'=>1];

        if($sOrderAccountName){
            //找到收款账号ID
            $userBankIds = model('userBanks')->where(['account_name'=>$sOrderAccountName])->select();
            $inIds ='';
            if(!empty($userBankIds)){
                foreach($userBankIds as $k=>$v){
                    $inIds .= $v['id'].',';
                    //$inIds[] = $v['id'];
                }
            }
            $where['user_bank_id'] = ['IN',rtrim($inIds,',')];
        }


        // 盘口id
        $user_id = '';
        if ($user_name) {
            $user_id = model('Users')->where(['name' => $user_name])->value('id') ?: 0;
        }

        if($sOrderReview){
            $where['review'] = $sOrderReview;
        }
        if($sOrderBankType){
            $where['bank_type'] = $sOrderBankType;
        }
        if($sOrderStime && $sOrderTtime){
            // $where['rob_time'] = $sOrderBankType;
            $where['update_time'] = ['between time',[$sOrderStime, $sOrderTtime]];
        }

        //实例化
        $UserWithdrawal = UserWithdrawal::with([
            'userBank',
            'muser',
            'merchants',
            'assists' => function($query) {
                return $query->where(['status'=>['<>',3]]);
            },
        ]);

        $withdrawList = $UserWithdrawal
            ->UnitId($this->unitId)
            ->Status($sOrderStatus)
            ->UserId($user_id)
            ->OrderNo($sOrderNo)
            ->OutTradeNo($sOutOrderNo)
            ->Amount($sOrderAmount)
            ->where($where)
            ->order('id desc')
//            ->order('review asc, apply_time desc')
            ->paginate(10);

        $bankTypeList = config('dictionary')['bank_type'];//收款账户类型
        $withdrawOrderStatus = config('dictionary')['widthdrawal_assists_status'];//提现订单状态
        $reviewStatus = config('dictionary')['review_status'];//审核状态

        //dump($withdrawList->toArray());die;
        $view_data=[
            'withdrawList'         =>  $withdrawList,
            'bankTypeList'         =>  $bankTypeList,
            'withdrawOrderStatus'  =>  $withdrawOrderStatus,
            'reviewStatus'         =>  $reviewStatus,
            'statisticsMoney'      =>  model('UserWithdrawal')->statisticsMoneySplit($user_id) // 统计金额
        ];

        //输出视图
        return view('withdrawal/index_split',$view_data);
    }


    /**
     * @author xi 2019/1/17 10:32 JH支付 <28490847748882849084774@qq.com>
     * @Note 管理员取消订单
     * @return \think\response\Json
     */
    public function cancleWithdrawalOrder(){
        if (request()->isPost()) {
            $postData = input('post.','');

            //验证
            $result = $this->validate($postData,'WithdrawValidate.id');
            if( $result != 1 ){
                return json(['code'=>-1,'msg'=>$result]);
            }
           // p($postData);
            //管理员取消码商订单
            $res = model('UserWithdrawal')->adminCancleWithdrawOrder($postData);

            if($res){
                addAdminLog('抢单管理-取消抢单，抢单ID：' . $postData['id'], 'action'); // 日志
                return json(['code'=>1,'msg'=>'取消抢单成功']);
            }
            return json(['code'=>0,'msg'=>'参数错误']);
        }
        return json(['code'=>0,'msg'=>'参数错误']);
    }

    /**
     * @author xi 2019/1/24 18:21 JH支付 <284908477482849084774@qq.com>
     * @Note 查看订单详情
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

            $actionList = model('UserWithdrawalRecords')::with('merchant,user')
                ->where(['withdrawal_id'=>$postData['id']])
               // ->fetchSql(true)
                ->select();
            //p($actionList);
            $view_data=[
                'thisInfo'      =>  $thisInfo,
                'actionList'    =>  $actionList,
                'bankList'      => model('Banks')->select(),
            ];

            //输出视图
            return view('withdrawal/order_info',$view_data);
        }

    }

    /**
     * @author xi 2019/1/24 18:27 JH支付 <28490847748887`906@qq.com>
     * @Note 审核打款
     * @return \think\response\Json
     */
    public function orderReview(){
        if (request()->isPost()) {
            $postData = input('post.', '');

            //验证
            $result = $this->validate($postData, 'WithdrawValidate.id');
            if ($result != 1) {
                return json(['code' => -1, 'msg' => $result]);
            }

            //通过审核，增加码商余额
            $res = model('MerchantsBalance')->review($postData['id']);
            if($res){
                addAdminLog('抢单管理-审核通过，id：' . $postData['id'], 'action');
                return json(['code'=>1,'msg'=>'打款审核成功']);
            }
            return json(['code'=>0,'msg'=>'打款审核失败']);
        }
    }

    /** 平台直接确认提现订单
     * @author xi 2019/3/6 13:37
     * @Note
     * @return \think\response\Json
     */
    public function adminWithdrawalOrder(){
        if (request()->isPost()) {
            $postData = input('post.', '');

            //验证
            $result = $this->validate($postData, 'WithdrawValidate.id');
            if ($result != 1) {
                return json(['code' => -1, 'msg' => $result]);
            }

            //直接修改提现订单状态为已完成，已审核
            $res = model('UserWithdrawal')->update(['status'=>5,'review'=>2],['id'=>$postData['id']]);

            if($res){
                //写入抢单操作记录
                $addData['user_id'] = 0;
                $addData['merchant_id'] = 0;
                $addData['withdrawal_id'] = $postData['id'];
                $addData['remark'] = '平台直接确认';
                model('UserWithdrawalRecords')->addRecord($addData);
                addAdminLog('抢单管理-平台直接确认，id：' . $postData['id'], 'action');

                //回调接口
                $orderInfo = model('UserWithdrawal')->where(['id'=>$postData['id']])->find();
                if($orderInfo['is_api']==1){
                    $this->callback($orderInfo,true);
                }

                return json(['code'=>1,'msg'=>'打款审核成功']);
            }
            return json(['code'=>0,'msg'=>'打款审核失败']);
        }
    }

    /**
     * @author xi 2019/3/6 13:37
     * @Note    平台直接驳回提现订单
     * @return \think\response\Json
     */
    public function adminWithdrawalOrderbh(){
        if (request()->isPost()) {
            $postData = input('post.', '');

            //验证
            $result = $this->validate($postData, 'WithdrawValidate.id');
            if ($result != 1) {
                return json(['code' => -1, 'msg' => $result]);
            }

            $orderInfo = model('UserWithdrawal')->where(['id'=>$postData['id']])->find();
            $res = model('UserWithdrawal')->delOrderApi($orderInfo['order_no'],$orderInfo['user_id']);

            //回调接口
            if($orderInfo['is_api']==1){

                $this->callback($orderInfo,false);
            }

            return json($res);
        }
    }


    /** 平台回调提现qpi
     * @author xi 2019/4/3 15:11
     * @Note
     * @param $orderInfo
     * @param $is
     */
    public function callback($orderInfo,$is){
        //获取用户信息
        $userInfo = model('Users')->where(['id'=>$orderInfo['user_id']])->find();

        if(!$orderInfo['callback_url']){
            return false;
        }
        if(!$userInfo){
            return false;
        }

        //获取
        $accountInfo = model('UserBanks')->where(['id'=>$orderInfo['user_bank_id'],'user_id'=>$orderInfo['user_id']])->find();

        $app_key = $userInfo['mch_key'];
        $data =[
            'appid' => $userInfo['mch_id'],
            'order_no' => $orderInfo['order_no'],
            'out_trade_no' => $orderInfo['out_trade_no'],
            'account' => $accountInfo['account_number'],
            'bank_type' => $accountInfo['bank_type'] == 2 ? '银行卡':'支付宝',
            'money' => sprintf("%.2f",$orderInfo['amount']),
        ];
        $data['sign'] = (new Sign())->getSign($app_key, $data);
        $return = [
            'code' => $is==true ? 1 : 0,// 成功 0 驳回
            'msg' => $is==true ? '平台确认提现订单' : '平台驳回提现订单',// 成功 0 驳回
            'data' => $data,// 成功 0 驳回
        ];

        Http::sendRequest($orderInfo['callback_url'], $return);
    }

    /** 审核ip提现白名单列表
     * @author xi 2019/5/29 11:14
     * @Note
     * @return \think\response\View
     */
    public function userip(){

        $list = model('UserWithdrawalIp')::with('user')->UnitId($this->unitId)->order('status asc,id desc')->paginate(10);

        $view_data=[
            'list'      =>  $list,
        ];

        //输出视图
        return view('withdrawal/userip',$view_data);
    }

    /** 审核ip提现白名单
     * @author xi 2019/5/23 14:33
     * @Note
     * @return \think\response\Json
     */
    public function userWithdrawalIp(){

        $userId = input('post.user_id','');
        $ip = input('post.ip','');
        if(!$userId || !$ip){
            return json(['code'=>0,'msg'=>'参数错误']);
        }

        model('UserWithdrawalIp')->update(['status'=>1],['user_id'=>$userId,'status'=>0,'ip'=>$ip]);
        addAdminLog(session('admin')['name'].'管理员审核提现IP成功，商户（'.$userId.'）IP：' . $ip, 'action');
        return json(['code'=>1,'msg'=>'审核成功']);
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

    /**
     * @author xi 2019/7/11 18:37
     * @Note
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function splitOrderSuccss(){
        if (request()->isPost()) {
            $postData = input('post.', '');
            $model = model('UserWithdrawalAssists');
            $id = input('post.id');
            $orderInfo = $model->where(['id'=>$id,'status'=>['<>',3]])->find();
            if(empty($orderInfo)) return json(['code'=>0,'msg'=>'订单已取消']);
            //结算
            $return = $model->splitOrderSuccess($orderInfo);
           if($return['code']==1){
               addAdminLog('抢单管理-确认抢单，抢单ID：' . $postData['id'], 'action'); // 日志
           }
            return json($return);
        }
    }

}