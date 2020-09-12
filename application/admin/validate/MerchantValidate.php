<?php
/**
 * @Author Quincy  2019/1/10 下午5:31
 * @Note 码商验证器
 */

namespace  app\admin\validate;

use app\common\validate\BaseValidate;

class MerchantValidate extends BaseValidate
{
    protected $rule = [
        'products'=>'checkProducts'
    ];
    protected $singleRule = [
        'product_id'=>'require|isPositiveInteger',
        'count'=>'require|isPositiveInteger',
    ];

    protected function checkProducts($values)
    {
        if (empty($values)) {
            throw new ParameterException([
                'msg'=>'商品列表不能为空'
            ]);
        }
        foreach ($values as $value) {
            $this->checkProduct($value);
        }
        return true;
    }

    private function checkProduct($value) {
        $validate = new BaseValidate($this->singleRule);
        $result = $validate->check($value);
        if (!$result) {
            throw new ParameterException([
                'msg'=>'商品列表参数错误',
            ]);
        }
    }

}