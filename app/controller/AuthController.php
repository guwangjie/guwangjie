<?php

namespace app\api\controller\open;

use app\common\controller\ApiController;
use think\Db;
use think\Request;
use app\common\model\Users;
use app\common\model\Toutiao;
use GuzzleHttp\Client;
use think\exception\HttpException;
use Sms;
use app\common\model\UserGifts;

class AuthController extends ApiController{

    protected $users;
    protected $client;
    protected $toutiao;

    public function __construct(Request $request, Users $users, Client $client,Toutiao $toutiao)
    {
        parent::__construct($request);
        $this->users = $users;
        $this->client = $client;
        $this->toutiao = $toutiao;
    }

    public function register()
    {
        $this->check_sign($this->data);
//        $this->data['sms_code'] = trim($this->data['sms_code']);
//        $flag = $this->checkCode($this->data['sms_code'], $this->data['mobile'], 2);
//
//        if ($flag !== true) {
//            return $flag;
//        }

        $res = Db::name('users')->where('user_name',$this->data['mobile'])->whereOr('mobile',$this->data['mobile'])->field('user_id')->find();
        if(!empty($res)){
            return json_error('','该用户已注册',self::SYSTEM_ERROR);
        }
        Db::startTrans();
        try {
            $account = $this->request->param('account','');

            $idfa = $this->request->param('idfa','');
            $user_id = $this->users->register($this->data['mobile'], '', trim($this->data['password']),[],$account,$idfa);

            $token = $this->generateToken($this->data['mobile']);
            /*存储用户全局信息到redis*/
            $this->redis_store($token, $user_id, $this->data['mobile']);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            abort(400, $e->getMessage());
        }

        $id = Db::name('user_devices')->where('idfa','=',$idfa)->field('*')->find();
        if(!empty($id)) {
            if($id['os'] ==0){
                $sign = $this->toutiao->sign($id['aid'], $id['cid'], $id['idfa'], $id['mac'], $id['androidid'], $id['os'], $id['create_time'], $id['callback']);
            }else{
                $sign = $this->toutiao->iossign($id['aid'], $id['cid'], $id['idfa'], $id['mac'], $id['os'], $id['create_time'], $id['callback']);
            }

            $res = $this->toutiao->tou_post($id['callback'], $id['idfa'], $id['os'],$sign);
            $data = [
                'user_id' => $user_id,
                'os' => $id['os'],
                'idfa' => $idfa,
                'create_time' => time(),
                'res' => $res->ret,
                'msg' => $res->msg,

            ];

            Db::name('toutiao_log')->insert($data);
        }

        return $this->respondWithArray(['user_id'=>$user_id]);
    }

    protected function check_sign($params){
        if(!isset($params['sign'])){
            throw new HttpException(200,'签名验证失败',null,[],self::ERROR_NO_AUTH);
        }
        $sign = $params['sign'];
        unset($params['sign']);
        ksort($params);
        $query = '';
        foreach($params as $k=>$v){
            $query .= $k.'='.$v.'&';
        }

        $pro_validate_sign = md5('wuxiang'.substr(md5($query.self::PRO_SECRET), 0,-3));

        if($pro_validate_sign!=$sign){

            throw new HttpException(200,'签名验证失败',null,[],self::ERROR_NO_AUTH);
        }
    }

    public function check_member(){
        $mobile = $this->data['mobile'];
        if(!preg_match("/(^1[3|4|5|7|8|9]\d{9}$)/",$mobile)){
            return json_error('', '请检查手机号是否填写正确', self::INVALID_PARAMETER);
        }
        $data = [
            'vip_no'=>'',
            'mobile'=>$mobile,
            'open_id'=>'',
            'customer_id'=>'',
            'third_party_code'=>'',
            'wxConsumeNo'=>'',
            'password'=>'******' //不需验证,无效值
        ];
        $sign_url = 'http://openapi.bigaka.com/wx-api/newOpenApi';
        $APPID = '$1$2WJf9dVw$quG1uVh3sGd7RNve9AXkp1';
        $Secret =  '$1$kvEgpyVs$WzXu8bifSUbzNWwBCGFE9.';
        $ts = date('YmdHis');
        $store_code = 282;
        $order_str = json_encode($data);
        $sign = md5($APPID . $Secret . $ts . $order_str);
        $headerdata = array(
            'app_id:' . $APPID,
            'ts:' . $ts,
            'store_code:' . $store_code,
            'sign:' . $sign,
            'model:' . 'customer',
            'method:' . 'add',
            'Content-Type:' . 'application/json'
        );

        $res = $this->http($sign_url, $order_str, $headerdata);

        return $this->respondWithArray($res);
    }

    public function http($url, $data, $header = array())
    {
        $ch = curl_init(); //初始化curl
        curl_setopt($ch, CURLOPT_URL, $url);//设置链接
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);//设置HTTP头
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//设置是否返回信息
        curl_setopt($ch, CURLOPT_POST, 1);//设置为POST方式
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);//要提交的的数据

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //不验证证书下同
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); //
        $res = curl_exec($ch);//执行curl
        curl_close($ch); //关闭curl链接

        return $res;
    }

    public function crmRegister(){

        $mobile = $this->data['phone'];
        $user_id = $this->sign_up($mobile,$password='');

        $insert = [
            'avatar'=>empty($this->data['logo']) ? '' : $this->data['logo'],
            'sex'=>empty($this->data['sex']) ? '' : $this->data['sex'],
            'nick'=>empty($this->data['nickName']) ? '' : $this->data['nickName'],
            'wxopenid'=>empty($this->data['openId']) ? '' : $this->data['openId'],
        ];

        try{
            Db::name('users')->where('user_id',$user_id)->update($insert);
            Sms::send_sms($mobile,Sms::np_register_new);
        }catch(\Exception $e){
            abort(500,$e->getMessage());
        }

        return $this->respondWithArray(['user_id'=>$user_id]);
    }

    public function mallRegister(){

        $mobile = $this->data['phone'];
        $user_id = $this->sign_up($mobile,$password='');

        try{
            Sms::send_sms($mobile,Sms::np_register_new);
        }catch(\Exception $e){
            abort(500,$e->getMessage());
        }

        return $this->respondWithArray(['user_id'=>$user_id]);
    }

    public function sign_up($mobile,$password)
    {
        $res = Db::name('users')->where('user_name',$mobile)->whereOr('mobile',$mobile)->field('user_id')->find();
        if(!empty($res)){
            return json_error('','该用户已注册',self::SYSTEM_ERROR);
        }

        Db::startTrans();
        try {
            $account = $this->request->param('account','open_default');

            $idfa = $this->request->param('idfa','');
            $user_id = $this->users->register($mobile, '', trim($password),[],$account,$idfa);

            $token = $this->generateToken($mobile);
            /*存储用户全局信息到redis*/
            $this->redis_store($token, $user_id, $mobile);
            /*返回个人信息*/
//            $info = $this->users->user_info($user_id);
            /*黑五注册用户赠送礼品券*/
            if(date('Y-m-d H:i:s')>=self::USER_GIFT_START_TIME&&date('Y-m-d H:i:s')<=self::USER_GIFT_END_TIME){
                (new UserGifts())->add_user_gifts($user_id);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            abort(400, $e->getMessage());
        }


        $id = Db::name('user_devices')->where('idfa','=',$idfa)->field('*')->find();
        if(!empty($id)) {
            if($id['os'] ==0){
                $sign = $this->toutiao->sign($id['aid'], $id['cid'], $id['idfa'], $id['mac'], $id['androidid'], $id['os'], $id['create_time'], $id['callback']);
            }else{
                $sign = $this->toutiao->iossign($id['aid'], $id['cid'], $id['idfa'], $id['mac'], $id['os'], $id['create_time'], $id['callback']);
            }

            $res = $this->toutiao->tou_post($id['callback'], $id['idfa'], $id['os'],$sign);
            $data = [
                'user_id' => $user_id,
                'os' => $id['os'],
                'idfa' => $idfa,
                'create_time' => time(),
                'res' => $res->ret,
                'msg' => $res->msg,

            ];

            Db::name('toutiao_log')->insert($data);

        }

        return $user_id;
    }
}

