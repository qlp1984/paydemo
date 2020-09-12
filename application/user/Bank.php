<?php
/**
 * @author xi 2019/1/8 10:29
 * @Note
 */
namespace app\user\controller;
use app\user\controller\UserBase;
use app\common\model\UserBanks;
use think\Paginator;
use think\Cache;

class Bank extends UserBase
{

    /**
     * @author xi 2019/1/15 14:23 JH支付 <85·8·*887`906@qq.com>
     * @Note   我的账户列表
     * @return \think\response\View
     * @throws \think\exception\DbException
     */
    public function index(){
        //实例化
        $userBanks = UserBanks::with('banks');

        $list = $userBanks->where(['user_id'=>$this->users['id']])->paginate(10);
        //p($list->toArray());

        //账户类型
        $bankTypeList = config('dictionary')['bank_type'];

        //获取银行列表
        $bankList = model('Banks')->bankList();

       // dump($bankTypeList);
        $view_data=[
            'accountList' => $list,
            'bankTypeList'=>$bankTypeList,
            'bankList'=>$bankList,
        ];

        //输出视图
        return view('bank/bankList',$view_data);
    }


    /**
     * @author xi 2019/1/15 15:45 JH支付 <85·8·*2849084774@qq.com>
     * @Note 添加收款账号
     * @return \think\response\Json
     */
    public function addAcount(){
        if (request()->isPost()) {
            $postData = input('post.');
            //dump($postData);die;

            //验证参数
            if($postData['account_type']==2){
                //类型为银行卡，验证是否选择银行卡
                $result = $this->validate($postData,'UserBanksValidate.addAcountBank');
            }else{
                $result = $this->validate($postData,'UserBanksValidate.addAcount');
            }
            if( $result != 1 ){
                return json(['code'=>-1,'msg'=>$result]);
            }

            //组装数据
            $insertData = [
                'user_id'          => $this->users['id'],
                'bank_type'        => $postData['account_type'],
                'account_number'   => $postData['account_number'],
                'account_name'     => $postData['account_name'],
                'create_time'     => time(),
            ];

            if($insertData['bank_type']=='2'){
                $insertData['bank_id'] = $postData['bank_id'];
                $insertData['branch'] = $postData['branch'];
            }

            //插入收款账号
            $insertRes = model('UserBanks')->insert($insertData);

            if($insertRes){
                trace(session('users')['name'].'商户添加收款账号：'.print_r($insertData,true),'addAcount');
                return json(['code'=>1,'msg'=>'恭喜您，成功添加收款账号']);
            }

        }
        return json(['code'=>-1,'msg'=>'添加失败']);


    }

    /**
     * @author xi 2019/1/15 16:53 JH支付 <85·8·*2849084774@qq.com>
     * @Note 编辑账号页面接口
     * @return \think\response\Json
     */
    public function editAccount(){

        if (request()->isGet()) {
            $getData = input('get.','');

            //验证
            $result = $this->validate($getData,'UserBanksValidate.id');
            if( $result != 1 ){
                return json(['code'=>-1,'msg'=>$result]);
            }


            $thisData = model('UserBanks')->userBankId($getData['id']);
            if(!empty($thisData)){
                return json(['code'=>1,'msg'=>'success','data'=>$thisData]);
            }
        }
        return json(['code'=>-1,'msg'=>'获取信息失败']);

    }

    /**
     * @author xi 2019/1/15 17:19  JH支付 <85·8·*2849084774@qq.com>
     * @Note 修改账户数据接口
     * @return \think\response\Json
     */
    public function editAcountData(){
        if (request()->isPost()) {
            $postData = input('post.');
            //dump($postData);die;

            $ids['id'] = $postData['edit_id'];

            //验证id
            $result = $this->validate($ids,'UserBanksValidate.id');
            if( $result != 1 ){
                return json(['code'=>-1,'msg'=>$result]);
            }

            //验证参数
            if($postData['account_type']==2){
                //类型为银行卡，验证是否选择银行卡
                $result = $this->validate($postData,'UserBanksValidate.addAcountBank');
            }else{
                $result = $this->validate($postData,'UserBanksValidate.addAcount');
            }
            if( $result != 1 ){
                return json(['code'=>-1,'msg'=>$result]);
            }

            //组装数据
            $insertData = [
                'bank_type'        => $postData['account_type'],
                'account_number'   => $postData['account_number'],
                'account_name'     => $postData['account_name'],
            ];


            if($insertData['bank_type']=='2'){
                $insertData['bank_id'] = $postData['bank_id'];
            }else{
                $insertData['bank_id'] = 0;
            }

            //插入收款账号
            $insertRes = model('UserBanks')->Id($postData['edit_id'])->UserId($this->users['id'])->update($insertData);

            if($insertRes){
                return json(['code'=>1,'msg'=>'恭喜您，成果修改收款账号']);
            }

        }
        return json(['code'=>-1,'msg'=>'保存失败']);
    }

    /**
     * @author xi 2019/1/16 11:30 JH支付 <85·8·*2849084774@qq.com>
     * @Note 删除商户数据
     * @return \think\response\Json
     */
    public function delAcount(){
        if (request()->isGet()) {
            $getData = input('get.','');

            //验证
            $result = $this->validate($getData,'UserBanksValidate.id');
            if( $result != 1 ){
                return json(['code'=>-1,'msg'=>$result]);
            }


            $id = model('UserBanks')->Id($getData['id'])->UserId($this->users['id'])->value('id');
            $thisData = model('UserBanks')->del($id);
            if($thisData){
                return json(['code'=>1,'msg'=>'成功删除数据']);
            }
        }
        return json(['code'=>-1,'msg'=>'获取信息失败']);
    }

    /**
     * @author xi 2019/2/12 20:32 JH支付 <85·8·*88790`6@qq.com>
     * @Note 验证收款账号唯一
     * @return \think\response\Json
     */
    public function isAccountNumber(){
        if (request()->isPost()) {
            $postData = input('post.','');

            if($postData['account_number']&&$postData['account_type']){
                $res = model('UserBanks')
                    ->where(['account_number'=>$postData['account_number'],'bank_type'=>$postData['account_type']])
                    ->find();
                if(empty($res)){
                    return json(['code'=>1,'msg'=>'success']);
                }
                return json(['code'=>0,'msg'=>'该账号已存在']);
            }

        }
        return json(['code'=>0,'msg'=>'收款账号不能为空']);
    }

}