<?php
namespace app\web\validate;

use think\Validate;

class WechatValidate extends Validate
{
    protected $rule = [
        'code'=>'require',
        'iv'=>'require',
        'encryptedData'=>'require',
        'mobile'=>'require|regex:/^1[0-9]{1}\d{9}$/',
        'sms_code'=>'require',
        'wxunionid'=>'require',
        'order_amount'=>'require|number',
    ];
    protected $message = [
        'code.require' => '请输入code',
        'mobile.require' => '请输入手机号码',
        'mobile.regex'   => '请填写正确的手机号码',
        'sms_code.require'=>'请输入短信验证码',
        'order_amount.require'=>'请输入订单金额',
    ];
    protected $scene = [
        'xcxlogin'  =>  ['code','encryptedData','iv'],
        'binding'   =>  ['mobile','wxunionid'],
        'cash_consume'=>['order_amount','code']
    ];
}