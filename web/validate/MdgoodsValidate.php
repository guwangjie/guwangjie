<?php

namespace app\web\validate;

use think\Validate;

class MdgoodsValidate extends  Validate
{
    protected $rule = [
        'bar_code'=>'require',
        'shop_id'=>'require|number'
    ];

    protected $message = [
        'bar_code.require' => '商品条形码不能为空',
    ];

    protected $scene = [
        'check'  =>  ['bar_code','shop_id'],
    ];
}