<?php
/**
 * @User LvGang  2019/1/8 0008 20:23
 * @Note
 */

namespace app\admin\validate;

use think\Validate;

class UsersValidate extends Validate
{
    protected $rule = [
        'name'          =>  'require|max:30|chsAlphaNum', //chsAlphaNum 只能是汉字、字母和数字  token
        'phone'         =>  'require|number|length:11',
        'passwd'        =>  'require|min:6|max:20|alphaDash', // alphaDash 是否为字母和数字，下划线_及破折号-
        'status'        =>  'number|between:1,3',
        'mch_key'       =>  'require|length:32',
        'google_token'  =>  'require|length:16',
    ];

    protected $message = [
        'name.chsAlphaNum'  => '用户名只能是汉字、字母和数字',
        'passwd.alphaDash'  => '密码只能是字母和数字，下划线_及破折号-',
    ];

    protected $scene = [
        'addUsers'      =>  ['name', 'phone', 'passwd', 'status'],
        'editUsers'     =>  ['name', 'phone', 'status'],
        'passwd'        =>  ['passwd']
    ];
}