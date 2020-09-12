<?php
/**
 * @User LvGang  2019/1/10 0010 10:47
 * @Note
 */

namespace app\admin\validate;

use think\Validate;

class SystemsNoticesValidate extends Validate
{

    protected $rule = [
        'title'     =>  'require|max:30|chsAlphaNum', //chsAlphaNum 只能是汉字、字母和数字  token
        //'content'        =>  'min:6|max:20', // alphaDash 是否为字母和数字，下划线_及破折号-
        'crowd'     =>  'between:1,3',
        'order'     =>  'between:0,255',
        'is_stick'  =>  'between:1,2',
        'switch'    =>  'between:1,2',
    ];

    protected $message = [
        'title.chsAlphaNum'  => '用户名只能是汉字、字母和数字',
    ];

    protected $scene = [
        'add'     =>  ['name', 'passwd'],
        'edit'    =>  ['mch_key', 'passwd'],
    ];

}