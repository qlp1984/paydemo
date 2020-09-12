<?php
/**
 * @Author Quincy  2019/1/8 下午5:25
 * @Note 盘口用户指定码商
 */

namespace app\admin\controller;

use app\common\model\AdminLog;
use think\Validate;
use app\common\service\Account;


class Designation extends AdminBase
{

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   盘口用户管理首页
     * @return mixed
     */
    public function index()
    {
        $usersMdl = model('Users');
        $name = input('name', '');
        $status = input('status', '');

        $where = ['switch'=>1,'agent_id'=>0,'audit_status'=>1];

        $id ='';
        if($name) {
            $user = model('Users')->where($where)->where(['name|phone'=>$name])->UnitId($this->unitId)->value('id');
            $id = $user ?: -1;
        }


        $datas = $usersMdl
            ->UnitId($this->unitId)
            ->Id($id)
            ->where($where)
            ->order('id desc')
            ->paginate(10, false, ['query' => $this->request->param()]);
        //p(collection($datas->all())->toArray());

        $this->assign([
            'list'      => $datas->all(), // 数据列表
            'page'      => $datas->render(), // 分页
            'status'    => $usersMdl->_switch, // 账户状态
            'auditStatus'    => $usersMdl->_auditStatus, // 审核状态
        ]);

        return $this->fetch();
    }

    /** 查找码商
     * @author xi 2019/2/21 13:09
     * @Note
     * @return \think\response\View
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function setMerchant()
    {
        //dump($withdrawList->toArray());die;



        $merchant='';
        if ($this->request->isGet()) {
            $getData = input('get.','');

            //获取该商户的unit_id
            $unitId = model('Users')->where(['id'=>$getData['id']])->value('unit_id');

            //p($getData);
           // p($getData['name']);
            $merchant=[];
            if(!empty($getData['name'])){
                $where = ['id|phone|name'=>$getData['name']];
                $merchant = model('Merchants')->where($where)->UnitId($unitId)->find();
            }

        }
        $view_data=[
            'data'=>$merchant,
            'userid'=>$getData['id']
        ];

        //输出视图
        return view('designation/set_merchant',$view_data);

    }

    /** 插入指定码商关系
     * @author xi 2019/2/21 13:40
     * @Note
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function addDesignation(){
        if ($this->request->isPost()) {

            $postData = input('post.','');
            if(!$postData['mid'] || !$postData['uid']){
                return json(['code'=>0,'msg'=>'fail->参数错误']);
            }

            $userInfo = model('Users')->where(['id' => $postData['uid']])->find();
            // 检测盘口账号
            if (!$userInfo) {
                return json(['code'=>0,'msg'=>'非法操作->盘口账号错误']);
            }

            $merchantInfo = model('Merchants')->where(['id' => $postData['mid']])->find();
            // 检测码商账号
            if (empty($merchantInfo)) {
                return json(['code'=>0,'msg'=>'非法操作->码商账号错误']);
            }

           if($userInfo['unit_id'] != $merchantInfo['unit_id']){
               return json(['code'=>0,'msg'=>'非法操作->不同平台不能指定']);
           }

            //判断是否已经存在指定关系
            $is_designation = model('Designation')->where(['user_id'=>$postData['uid'],'merchant_id'=>$postData['mid']])->count();
            if($is_designation){
                return json(['code'=>0,'msg'=>'fail->该码商存在指定关系']);
            }

            //没有指定直接插入关系
            $res = model('Designation')->insert([
                'user_id'=>$postData['uid'],
                'merchant_id'=>$postData['mid'],
                'unit_id' => session('admin.id'),
                'add_time'=>time(),
                'status'=>1,
            ]);
            if($res){
                $unitId = ($this->unitId == false || $this->unitId == 0) ? '-1':$this->unitId;
                //插入指定关系池
                (new Account())->designationPool($unitId,$postData['uid'],$postData['mid'],'On');
                //写入记录
                addAdminLog($this->Admin['name'].'给商户（'.$postData['uid'].'）指定码商('.$postData['mid'].')','action');
                return json(['code'=>0,'msg'=>'success->指定码商成功']);
            }
        }
        return json(['code'=>0,'msg'=>'fail->绑定失败']);
    }

    /** 获取指定的码商列表
     * @author xi 2019/2/21 13:54
     * @Note
     * @return \think\response\View
     */
    public function getMerchant(){
        $list='';
        if ($this->request->isGet()) {
            $getData = input('get.','');
            //p($getData);
            // p($getData['name']);
            $merchant=[];
            if(!empty($getData['id'])){
                $list = model('Designation')->with('merchant')->where(['user_id'=>$getData['id']])->select();
            }
            //p($list);
        }
        $view_data=[
            'list'=>$list,
            'userid'=>$getData['id']
        ];

        //输出视图
        return view('designation/get_list',$view_data);
    }

    /**
     * 指定码商操作
     * JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     */
    public function qMerchnat(){
        if ($this->request->isPost()) {

            $postData = input('post.','');
            if(!$postData['id']){
                return json(['code'=>0,'msg'=>'fail->参数错误']);
            }
            //p($postData);
            //没有指定直接插入关系
            $res = model('Designation')->where(['id'=>$postData['id']])->delete();
            if($res){
                $unitId = ($this->unitId == false || $this->unitId == 0) ? '-1':$this->unitId;
                //删除指定关系池
                (new Account())->designationPool($unitId,$postData['uid'],$postData['mid'],'Off');
                //写入记录
                addAdminLog($this->Admin['name'].'给商户（'.$postData['uid'].'）取消指定码商('.$postData['mid'].')成功','action');
                return json(['code'=>0,'msg'=>'success->取消指定码商成功']);
            }
        }
        return json(['code'=>0,'msg'=>'fail->取消失败']);
    }

    /** 修改流量指定记录开关状态
     * @author xi 2019/5/29 20:37
     * @Note
     * @return \think\response\Json
     */
    public function statusMerchnat(){
        if ($this->request->isPost()) {
            $postData = input('post.','');
            if(!$postData['id']){
                return json(['code'=>0,'msg'=>'fail->参数错误']);
            }
            $data['status'] = $postData['status'] == 1 ? 0 : 1 ;
            $action = $data['status'] == 1 ? 'On' : 'Off' ;

            //更新状态
            $res = model('Designation')->update(['status'=>$data['status']],['id'=>$postData['id'],'user_id'=>$postData['uid']]);

            if($res){
                $unitId = ($this->unitId == false || $this->unitId == 0) ? '-1':$this->unitId;
                //删除指定关系池
                (new Account())->designationPool($unitId,$postData['uid'],$postData['mid'],$action);
            }
            //写入记录
            addAdminLog($this->Admin['name'].'给商户（'.$postData['uid'].'）修改指定码商('.$postData['mid'].')状态'.$action,'action');
            return json(['code'=>0,'msg'=>'success->操作成功']);

        }
        return json(['code'=>0,'msg'=>'操作失败']);
    }

}