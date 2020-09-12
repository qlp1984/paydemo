<?php
/**
 * @User LvGang  2019/1/9 0009 17:08
 * @Note    平台账号验证
 */

namespace app\admin\validate;

use think\Validate;

class AdminValidate extends Validate
{

    protected $rule = [
        'name'          =>  'require|max:30|chsAlphaNum', //chsAlphaNum 只能是汉字、字母和数字  token
        'passwd'        =>  'min:6|max:20|alphaDash', // alphaDash 是否为字母和数字，下划线_及破折号-
        'phone'         =>  'require|number|length:11',
        'mch_key'       =>  'require|length:32',
        'google_token'  =>  'require|length:16',
    ];

    protected $message = [
        'name.chsAlphaNum'  => '用户名只能是汉字、字母和数字',
        'passwd.alphaDash'  => '密码只能是字母和数字，下划线_及破折号-',
    ];

    protected $scene = [
        'login'         =>  ['name', 'passwd'],
        'userConfig'    =>  ['mch_key', 'passwd'],
        'addAdmin'      =>  ['name', 'passwd', 'phone'],
    ];

}