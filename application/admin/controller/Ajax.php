<?php
/**
 * ajax 公共请求方法
 * @User LvGang  2019/1/8 0008 10:46
 * @Note
 */

namespace app\admin\controller;

use think\Db;
use lib\GoogleAuth;

class Ajax
{

    public function __construct()
    {

        if (session('?admin') === false) {
            header('Location: ' . url('admin/Login/index'));
            exit;
        }
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note    生成随机字符串
     */
    public function randStr()
    {
        $number = input('post.number', 32);
        echo get_rand_char($number);
    }

    /**
     * @author LvGang JH支付 <284908477488879`06@qq.com>
     * @Note   修改某个字段值
     * @return int|string
     */
    public function editTableParam()
    {

        $table      = input('post.table'); // 表名
        $param      = input('post.param'); // 字段名
        $value      = input('post.value'); // 修改后的值
        $id         = input('post.id', 'id'); // 主键
        $idValue    = input('post.idValue'); // 主键值
        return Db::name($table)->where([$id => $idValue])->update([$param => $value]);
    }

    /**
     * @author LvGang JH支付 <28490847748882849084774@qq.com>
     * @Note   生成谷歌秘钥
     */
    public function createGoogleSecret()
    {
        echo (new GoogleAuth())->createSecret();
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   获取Google动态口令url
     * @return \think\response\Json
     */
    public function getQRCodeGoogleUrl()
    {

        $scene = input('post.scene', 'merchants'); // 场景 （merchants, users, admin）
        $passwd = input('post.passwd');
        //$arr = ['merchants' => '码商', 'users' => '盘口', 'admin' => '平台'];
        $data = Db::name($scene)->where('id', session($scene . '.id'))->field('google_token, passwd, passwd_salt')->find();

        // 验证是否保存了密钥
        if (empty($data['google_token']))
            return json(['code' => 0, 'msg' => '请先保存秘钥']);

        // 验证登录密码
        if (!model('Admin')->checkPasswd($passwd, $data))
            return json(['code' => 0, 'msg' => '密码错误']);

        $name = request()->domain() . ':' . $scene . ':' . session($scene . '.phone');
        $data = (new GoogleAuth())->getQRCodeGoogleUrl($name, $data['google_token']);
        return json(['code' => 1, 'data' => $data]);
    }

    /**
     * 商户配置——生成密钥操作
     * 验证谷歌口令，返回新的谷歌秘钥
     * @author LvGang 2019/3/16 0016 17:31 JH支付 <2849084774@qq.com>
     * @return \think\response\Json
     */
    public function checkGoogleAuth()
    {
        $googleCode = input('post.googleCode');
        $userId = session('admin.id');
        $google_token = model('Admin')->where('id', $userId)->value('google_token');
        $res = (new GoogleAuth)->verifyCode($google_token, $googleCode, 2);
        if (!$res)
            return json(['code' => 0, 'msg' => '口令验证失败']);

        return json(['code' => 1, 'msg' => '口令验证成功，保存密钥后原密钥将会失效', 'data' => (new GoogleAuth())->createSecret()]);
    }

    /**
     * @author LvGang JH支付 <2849084774@qq.com>
     * @Note   上传图片
     */
    public function uploadImgs()
    {

        $file = $_FILES;
        if (!empty($file)) {
            $result = uploadImgs($file);
            return json($result);
        }
        return json(['code' => 0, 'msg' => '操作错误']);
    }

}