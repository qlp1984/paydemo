<?php
namespace app\user\controller;
use think\Cache;
use think\Db;

class Index extends UserBase
{
	public function _initialize()
    {
        // 检测session
        if (!session('?users')) {
            header('Location: ' . url('user/Login/index'));
        }

        parent::_initialize();

    }

    /**
     * @author LvGang JH支付 <85·8**8879*06@qq.com>
     * @Note   盘口首页
     * @return \think\response\View
     */
    public function index()
    {

        $noticesMdl = model('SystemsNotices');
        $noticesWhere = ['switch' => 1, 'crowd' => ['in', '2,3']];
        $notices = $noticesMdl->getList($noticesWhere, 5, [], 'order asc');
        $noticesList = collection($notices['list'])->toArray();

        $userId = session('users')['id'];
        $nowTime = strtotime(date('Y-m-d',time()));

        // 订单信息
        $money = model('PayOrders')->getPlatMoney($userId,'',''); // 统计金额
        $cashRecord = model('Users')->getCashRecord($userId,[],$nowTime); // 出入金、抢单

        $bankType = config('dictionary')['bank_type'];

        //ip白名单
        $showIp = 0;
        $thisIp = getIp();
        $wip = model('UserWithdrawalIp')->where(['status'=>1,'user_id'=>$userId])->value('ip');
        if($thisIp != $wip){
            $showIp = 1;
        }


		$view_data = [
            'money'         => $money,
            'cashRecord'    => $cashRecord,
            'bankType'      => $bankType,
            'noticesList'   => $notices['list'], // 公告列表（5条）
            'service'       => getVariable('service', $this->users['unit_id']), // 联系客服
            'isStick'       => cookie('alert') == 1 && !empty($noticesList) && min(array_column($noticesList, 'is_stick')) != 2, //是否弹窗
            'stickId'       => $noticesMdl->where($noticesWhere)->order(['order asc'])->value('id'), // 公告弹窗ID
            'users'         => model('users')->with('usersBalance')->where('id', session('users.id'))->find(),
            'apiDomain'     => url('gateway/index/unifiedorder') . '?format=json',
            'showIp'        => $showIp,
		];

        // 销毁第一次登录记录
        cookie('alert', null);

		return view('index', $view_data);
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   商户配置
     * @return \think\response\View
     */
    public function userconfig()
    {

        if (request()->isPost()) {
            $postData = input('post.');
            $postData['id'] = session('users.id');

            //验证
            $result = $this->validate($postData, 'UsersValidate.userConfig');
            if(true !== $result){
                // 验证失败 输出错误信息
                return json(['code' => 0, 'msg' => $result]);
            }

            return json(model('Users')->editData($postData));
        }

		return view('user/user_config', [
		    'data'  => Db::name('users')->where('id', session('users.id'))->find(),
            'rates' => model('Rates')->getUsersRates(session('users.id'), 2)
        ]);
    }

    /**
     * 关闭谷歌验证（1. 验证密码 2. 修改字段值）
     * @author LvGang 2019/2/13 0013 19:47 JH支付 <8582849084774@qq.com>
     * @return \think\response\Json
     */
    public function closeGoogle()
    {
        $passwd = input('post.passwd'); // 用户提交的明文密码
        $id = session('users.id'); // 用户id从session取
        $model = model('Users');
        $data = $model->where('id', $id)->field('google_token, passwd, passwd_salt')->find();
        // 验证登录密码
        if (!$model->checkPasswd($passwd, $data))
            return json(['code' => 0, 'msg' => '密码错误']);

        if (!$model->where('id', $id)->update(['is_use_google' => 2]))
            return json(['code' => 0, 'msg' => '修改is_use_google失败']);

        return json(['code' => 1, 'msg' => '操作成功']);
    }

    /**
     * 清除缓存
     * @author LvGang 2019/2/16 0016 14:47 JH支付 <28490847742849084774@qq.com>
     * @return \think\response\Json
     */
    public function clearCache()
    {
        $res = deleteFile();
        return json(['code' => (int)$res]);
    }

    /**
     * 获取提现信息
     * @author LvGang 2019/2/16 0016 14:47 JH支付 <28490847742849084774@qq.com>
     * @return \think\response\Json
     */
    public function getWithdrawal()
    {
        $id = session('users.id'); // 用户id从session取

        // 查询是否有提现订单
        $bool = model('UserWithdrawal')->where(['status'=>3,'user_id'=>$id])->field('order_no')->find();
        if ( !empty($bool) ){
            $msg = '您的提现订单'.$bool['order_no'].'已处理，请及时确认。';
            return json(['code' => 1,'msg'=>$msg]);
        }
        return json(['code' => 0,'msg'=>0]);
    }


}
