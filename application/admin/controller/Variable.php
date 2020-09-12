<?php
/**
 * @User LvGang  2019/1/17 0017 10:47
 * @Note 系统变量
 */

namespace app\admin\controller;

class Variable extends AdminBase
{

    /**
     * @author LvGang JH支付 <28490847748882849084774@qq.com>
     * @Note   客服
     * @return mixed
     */
    public function service()
    {
        $act = $this->request->action();
        $this->assign([
            'ch_name'=> '客服管理',
            'act'   => $act,
            'data'  => getVariable($act, $this->Admin['id'])
        ]);

        return $this->fetch();
    }

    /**
     * @author LvGang JH支付 <28490847748887-906@qq.com>
     * @Note   修改设置
     * @return \think\response\Json
     */
    public function addEditValue()
    {
        if ($this->request->isPost()) {
            $postData = input('post.');
            $name = $postData['act'];
            $postData['code_expiration'] = isset($postData['code_expiration'])?$postData['code_expiration']:600;

            if(isset($postData['mUser']) && $postData['mUser'] > 0){
                $mUserId = $postData['mUser'];
                $mUser = model('Users')->field('mch_id,mch_key')->where(['id'=>$mUserId])->find();
                if(!$mUser){
                    return json(['code' => 0, 'msg' => '设置默认商户出错，找不到商户']);
                }
                $postData['mUser'] = [
                    'id' => $mUserId,
                    'appid' => $mUser['mch_id'],
                    'key' => $mUser['mch_key'],
                ];
            }else{
                unset($postData['mUser']);
            }
           // model('Merchants')->where(['id'=>['>',0]])->update(['code_expiration'=>$codeExpiration]);
            unset($postData['act']);
            //unset($postData['code_expiration']);
            return json(model('Variable')->addEditValue($name, $postData));
        }
        return json(['code' => 0, 'msg' => '非法操作']);
    }

    /**
     * @author LvGang JH支付 <284908477488879·06@qq.com>
     * @Note   基本设置
     * @return mixed
     */
    public function setting()
    {
        $setting = getVariable('setting',session('admin')['unit_id']);
        $act = $this->request->action();
        //p($setting);
        $this->assign([
            'ch_name'=> '基本设置',
            'act'   => $act,
            'url'   => model('AuthorizationUrl')->where('status',1)->value('url'),
            'data' => $setting,
            'urlDate'=> model('UrlRecords')->where('order_no',8888)->field('white_url,menber')->find(),
            'code_expiration' => isset($setting['code_expiration']) ? $setting['code_expiration'] : 600,
            'memberGrabSheet' => isset($setting['memberGrabSheet']) ? $setting['memberGrabSheet'] : 0,
            'mUser' => isset($setting['mUser']['id']) ? $setting['mUser']['id'] : 0,
            'userFee' => isset($setting['userFee']) ? $setting['userFee'] : 0,
            'proxyUser' => isset($setting['proxyUser']) ? $setting['proxyUser'] :'',
            'proxyPass' => isset($setting['proxyPass']) ? $setting['proxyPass'] :'',
        ]);
        return $this->fetch();
    }

    /**
     * @author LvGang JH支付 <28490847748887`906@qq.com>
     * @Note   轮询算法
     * @return mixed
     */
    public function algorithm()
    {
        $act = $this->request->action();
        $this->assign([
            'ch_name'=> '轮询算法',
            'act'   => $act,
            'data' => getVariable($act, $this->Admin['id'])
        ]);
        return $this->fetch();
    }

    /**
     * @author LvGang JH支付 <28490847748882849084774@qq.com>
     * @Note   版本控制
     * @return mixed
     */
    public function version()
    {
        $act = $this->request->action();
        $this->assign([
            'ch_name'=> '版本控制',
            'act'   => $act,
            'data' => getVariable($act, $this->Admin['id'])
        ]);
        return $this->fetch();
    }

    /**
     * @author zhou JH支付 <28490847748882849084774@qq.com>
     * @Note   授权域名列表
     * @return mixed
     */
    public function authorizationUrlList()
    {
        $data = model('AuthorizationUrl')->field('app_id,id,url,status')->order('status asc,id asc')->paginate(10,false, ['query'=>request()->param()]);

        return view('variable/authorizationUrlList', ['data'=>$data]);

    }

    /**
     * @author zhou JH支付 <28490847748882849084774@qq.com>
     * @Note   添加授权域名
     * @return mixed
     */
    public function addAuthorizationUrl()
    {
        $data = input('post.');

        $data['url'] = $data['app_url'];

        unset($data['app_url']);

        $bool = model('AuthorizationUrl')->insertGetId($data);

        if ($bool){
            return json(['status' => 1, 'msg' => '添加成功']);
        }

        return json(['status' => -1, 'msg' => '添加失败']);
    }

    /**
     * @author zhou JH支付 <28490847748882849084774@qq.com>
     * @Note   删除授权域名
     * @return mixed
     */
    public function delAuthorizationUrl()
    {

        $id = input('post.id');

        $bool = model('AuthorizationUrl')::destroy($id);

        if ( $bool !== false ){
            return json(['status' => 1, 'msg' => '删除成功']);
        }

        return json(['status' => -1, 'msg' => '删除失败']);
    }

    /**
     * @author zhou JH支付 <28490847748882849084774@qq.com>
     * @Note   切换授权域名
     * @return mixed
     */
    public function editAuthorizationUrl()
    {
        $id = input('post.id');

        model('AuthorizationUrl')->where(['id'=>['>',0]])->update(['status'=>2]);

        $bool = model('AuthorizationUrl')->where('id',$id)->update(['status'=>1]);

        if ( $bool !== false ){
            return json(['status' => 1, 'msg' => '切换成功']);
        }

        return json(['status' => -1, 'msg' => '切换失败']);
    }

    /**
     * @author zhou JH支付 <28490847748882849084774@qq.com>
     * @Note   添加拦截域名配置
     * @return mixed
     */
    public function addUrlRecords()
    {
        if ($this->request->isPost()) {
            $postData = input('post.');

            $bool = model('UrlRecords')->where('order_no',8888)->value('id');

            if ( empty($bool) ){
                $postData['order_no'] = 8888;
                model('UrlRecords')->insert($postData);
            } else {
                model('UrlRecords')->where('order_no',8888)->update($postData);
            }
            return json(['status' => 1, 'msg' => '配置成功']);
        }

        if ($this->request->isGet()) {
            $arr = model('UrlRecords')->isBool();

            return json($arr);
        }
        return json(['code' => 0, 'msg' => '非法操作']);
    }

    /**
     * 数据管理
     * @author LvGang 2019/8/5 0005 17:05 JH支付 <8582849084774@qq.com>
     * @return \think\response\Json
     */
    public function data()
    {

        if ($this->request->isPost()) {
            $del_time = input('post.time');
            $delTime = !empty($del_time) ? strtotime($del_time) : 0;

            if (empty($del_time)) {
                return json(['code' => 0, 'msg' => '请选择时间节点']);
            }

            $where = ['create_time' => ['lt',$delTime]];
            $orderMdl = model('PayOrders');
            $assistsMdl = model('PayOrderAssists');
            $withdrawalMdl = model('UserWithdrawal');

            // 查询出临界id
            $orderId = $orderMdl->where($where)->order('id desc')->value('id');
            $assistsId = $assistsMdl->where($where)->order('id desc')->value('id');
            $withdrawalId = $withdrawalMdl->where($where)->order('id desc')->value('id');

            // 删除小于这个id的所有数据
            $orderMdl->where('id', '<=', $orderId)->delete();
            $assistsMdl->where('id', '<=', $assistsId)->delete();
            $withdrawalMdl->where('id', '<=', $withdrawalId)->delete();

            addAdminLog('删除' . $del_time . '之前的数据');
            return json(['code' => 1, 'msg' => '操作成功']);
        }

        return $this->fetch();
    }

}