<?php
/**
 * @User xi  2019/1/15 0008 20:23
 * @Note
 */

namespace app\admin\validate;

use think\Validate;

class WithdrawValidate extends Validate
{
    protected $rule = [
        'id'               =>  'egt:1',
        'merchant_id'      =>  'egt:1',
        'user_id'          =>  'egt:1',
    ];

    protected $message = [
        'id.egt'            => 'id必须为正整数',
        'merchant_id.egt'   => 'merchant_id必须为正整数',
        'user_id.egt'       => 'user_id必须为正整数',
    ];

    protected $scene = [
        'id'                =>  ['id'],
        'ids'                =>  ['id,merchant_id,user_id'],
    ];
}