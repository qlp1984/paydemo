<?php
/**
 * @User LvGang  2019/1/9 0009 18:04
 * @Note    平台管理
 */

namespace app\admin\controller;

use lib\Csv;

class Platform extends AdminBase
{

    /**
     * @author LvGang JH支付 <8·58·882849084774@qq.com>
     * @Note   首页
     * @return mixed
     */
    public function index()
    {
        $openChannel = model('Channels')->where('switch', 1)->column('id');
        $result = model('Admin')
            ->UnitId($this->unitId)
            ->with([
                'platformRates' => function ($query) use ($openChannel) {
                    return $query->where(['channel_id' => ['in', $openChannel]]);
                }])
            ->where(['unit_id' => ['neq', -1]])
            ->order('id', 'desc')
            ->paginate(10,false, ['query'=>request()->param()]);

        return view('platform/index', ['channels'=>get_channels(),'result'=>$result]);
    }

    /**
     * @author LvGang JH支付 <28490847742849084774@qq.com>
     * @Note   新增
     * @return \think\response\Json
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $postData = input('post.');

            // 费率
            $rate = trim($postData['rate']);
            unset($postData['rate']);

            // 平台名字
            $platformName = $postData['platform_name'];

            if(!$platformName)  return json(['status'=>0,'msg'=>'请输入平台名字']);

            if ( $this->unitId != false){
                //session('admin', null);
                //header('Location: ' . url('/admin/Login/index'));
                return json(['code' => 0, 'msg' => '只有总平台管理员才能操作']);
            }

            // 验证数据
            $result = $this->validate($postData, 'AdminValidate.addAdmin');
            if(true !== $result){
                return json(['code' => 0, 'msg' => $result]);
            }

            $adminMdl = model('Admin');

            // 验证用户名
            if ( $adminMdl->where(['name' => $postData['name']])->count() ) {
                return json(['code' => 0, 'msg' => '用户名已存在']);
            }

            // 验证手机号
            if ( $adminMdl->where(['phone' => $postData['phone']])->count() ) {
                return json(['code' => 0, 'msg' => '手机号已存在']);
            }

            $rate = model('Merchants')->dealRate($rate);

            if($rate === false || !is_array($rate) || empty($rate)) return json(['status'=>0,'msg'=>'请输入合法的费率设置']);

            $postData['role_node_id'] = db('role_group')->where('id', 1)->value('role_node_id');
            // 添加
            $bool = $adminMdl->addData($postData);

            if( $bool['code'] == 1 ){
                // 平台的ID
                $unitId = $bool['id'];

                // 先查询
                $platformRatesData = model('PlatformRates')->where('unit_id',$unitId)->field('id')->find();

                // 判断是否此平台已经设置了费率
                if ( empty($platformRatesData) ){
                    foreach ($rate as $k=>$v){
                        unset($rateData);
                        $rateData['channel_id'] = $k;
                        $rateData['rate'] = $v;
                        $rateData['unit_id'] = $unitId;
                        model('PlatformRates')->insert($rateData);
                    }
                }
            }
            return json(['code' => 1, 'msg' => '添加成功']);
            // return $adminMdl->addData($postData);
        }

        return json(['code' => -1, 'msg' => '请求出错']);
    }

    /**
     * 查看平台的信息
     * JH支付 <858·2849084774@qq.com>
     * @return \think\response\Json
     */
    public function getOneMerchant()
    {
        $merchantId =  input('post.merchant_id',0);
        $data = model('Admin')->getOneAdmin($merchantId);

        return json(['status'=>1,'data'=>$data]);
    }

    /**
     * @Author Quincy  2019/1/10 下午1:47 JH支付 <28490847747906@qq.com>
     * @Note 修改平台信息
     */
    public function editPlat()
    {
        $merchantId = input('post.merchant_id',0);
        $name = trim(input('post.name',''));
        $passwd = trim(input('post.passwd',''));
        $phone = trim(input('post.phone',''));
        $switch = trim(input('post.switch',''));
        $rate = trim(input('post.rate',''));

        $merchantsMdl = model('Admin');

        $merchant = $merchantsMdl->UnitId($this->unitId)->where(['id'=>$merchantId])->find();
        if(!$merchant)  return json(['status'=>0,'msg'=>'用户id不存在']);
        if(!$name) return json(['status'=>0,'msg'=>'用户名称未填写']);
        if($merchant['name']!== $name){
            $nameRow = $merchantsMdl->where(['name'=>$name, 'unit_id' => $this->unitId])->find();
            if($nameRow) return json(['status'=>0,'msg'=>'该用户名称已存在，请重新输入']);
        }
        if(!is_phone($phone)) return json(['status'=>0,'msg'=>'手机号码格式不正确']);
        if($merchant['phone'] != $phone){
            $phoneRow = $merchantsMdl->where(['phone'=>$phone, 'unit_id' => $this->unitId])->find();
            if($phoneRow) return json(['status'=>0,'msg'=>'该手机号码已存在，请重新输入']);
        }
        if(!in_array($switch,[1,2])) return json(['status'=>0,'msg'=>'请输入合法的账户状态']);
        $rate = $this->dealRate($rate);

        if($rate === false || !is_array($rate) || empty($rate)) return json(['status'=>0,'msg'=>'请输入合法的费率设置']);

        $result = $merchantsMdl->updateMerchant($name,$passwd,$phone,$switch,$rate,$merchant);

        if($result['code'] != 1) return json(['status'=>0,'msg'=>$result['msg']]);

        return json(['status'=>1,'msg'=>'更新成功']);
    }

    //处理费率
    public function dealRate($rate)
    {
        $rate = rtrim($rate, ',');
        $arr = explode(',', $rate);

        foreach ($arr as $row) {
            $temp = explode('-', $row);
            $tempRate = $temp[1];
            if (floatval($tempRate) < 0 || $tempRate == '') $tempRate=0.01;

            $data[$temp[0]] = floatval($tempRate);
        }
        return $data;
    }

    /**
     * @author LvGang JH支付 <858-882849084774@qq.com>
     * @Note   订单流水
     */
    public function order()
    {
        $sOrderStime = input('s','');
        $sOrderTtime = input('t','');

        $openChannel = model('Channels')->where('switch', 1)->column('id');

        $where = [];
        if($sOrderStime && $sOrderTtime){
            $where['update_time'] = ['between time',[$sOrderStime, $sOrderTtime]];
        }

        $result = model('Admin')
            ->with([
                'platformRates' => function ($query) use ($openChannel) {
                    return $query->where(['channel_id' => ['in', $openChannel]]);
                }])
            ->group('unit_id')
            ->where(['unit_id' => ['neq', -1]])
            ->order('id', 'desc')
            ->field('platform_name,unit_id')
            ->paginate(5,false, ['query'=>request()->param()]);

        if (!empty($result)) {
            foreach ($result as $k=> $v){
                $rates = collection($v['platform_rates'])->toArray();
                foreach ($rates as $key => $val ){
                    $sueecss = model('PayOrders')->where($where)->where('unit_id',$v['unit_id'])->where('status',4)->where('channel_id',$val['channel_id'])->sum('money');
                    $callbackSueecss = model('PayOrders')->where($where)->where('unit_id',$v['unit_id'])->where('callback_status',2)->where('channel_id',$val['channel_id'])->sum('money');
                    $rates[$key]['sueecssMoney'] = $sueecss;
                    $rates[$key]['callbackSueecssMoney'] = $callbackSueecss;
                }

                $result[$k]['rates'] = $rates;
            }
        }


        return view('platform/order', ['result'=>$result]);
    }


    /**
     * @author LvGang JH支付 <858-882849084774@qq.com>
     * @Note   导出订单流水
     */
    public function exportOrder()
    {
        $sOrderStime = input('get.start_time', 0);
        $sOrderTtime = input('get.end_time', 0);

        $where = [];
        if($sOrderStime && $sOrderTtime){
            $where['update_time'] = ['between time',[$sOrderStime, $sOrderTtime]];
        }

        $result = model('Admin')->group('unit_id')->order('id', 'desc')->field('platform_name,unit_id')->select();

        foreach ($result as $k=>$v){
            $rates = model('PlatformRates')->where('unit_id',$v['unit_id'])->field('channel_id,rate')->select();
            if ( !empty($rates) ){
                foreach ( $rates as $key => $val ){
                    $sueecss = model('PayOrders')->where($where)->where('unit_id',$v['unit_id'])->where('status',4)->where('channel_id',$val['channel_id'])->sum('amount');
                    $callbackSueecss = model('PayOrders')->where($where)->where('unit_id',$v['unit_id'])->where('callback_status',2)->where('channel_id',$val['channel_id'])->sum('amount');
                    $rates[$key]['sueecssMoney'] = $sueecss;
                    $rates[$key]['callbackSueecssMoney'] = $callbackSueecss;
                }
            }
            $result[$k]['rates'] = $rates;
        }

        $coulumnName = [ '平台名字', '渠道','费率', '支付成功', '回调成功'];

        $fieldName = [ 'platform_name', 'channel_id','rate','sueecssMoney','callbackSueecssMoney'];
        $csvName = '平台流水-' . date('Y-m-d').'.csv';
        addAdminLog('导出历史订单统计');

        Csv::downCsv($result, $coulumnName, $fieldName,$csvName);
    }


}