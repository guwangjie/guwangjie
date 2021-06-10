<?php

namespace app\common\controller;

use app\common\model\Users;
use GuzzleHttp\Client;
use think\Controller;
use think\exception\HttpException;
use think\Request;

class ApiController extends Controller
{

    protected $statusCode = 200;
    protected $request;
    protected $data;
    protected $header;
    protected $source;
    protected $mendian;

    const CODE_WRONG_ARGS = 'GEN-FUBARGS';
    const CODE_NOT_FOUND = 'GEN-LIKETHEWIND';
    const CODE_INTERNAL_ERROR = 'GEN-AAAGGH';
    const CODE_UNAUTHORIZED = 'GEN-MAYBGTFO';
    const CODE_FORBIDDEN = 'GEN-GTFO';
    const CODE_INVALID_MIME_TYPE = 'GEN-UMWUT';

    const SYSTEM_ERROR = -1; //系统错误
    const EMPTY_ERROR = -2; //返回值为空
    const SUCCESS = 200; //请求成功
    const ERROR = 400; //失败
    const ERROR_NO_AUTH = 1000; //签名失败
    const EMPTY_PARAMETER = 450;
    const UNLOGIN = 2001; //未登录
    const INVALID_PARAMETER = 2002; //不合法的参数,缺少参数
    const INVALID_REQUEST = 2003; //不合法的请求格式
    const INVALID_TOKEN = 2004; //不合法TOKEN
    const REFRESH_TOKEN = 2005;//刷新token值标识符
    const CHECK_CODE_ERROR = 2006; //验证码错误
    const UN_REGISTERED = 2007; //未注册
    const REGISTERED = 2009; //已注册，直接登录
    const REDIRECT = 2010; //前端路由重定向标识符
    const ACCOUNT_SYSTEM = 2018;//系统账号
    const ACCOUNT_BAD = 2019;//账号异常
    const BEYOND_LIMIT = 2020; //超过上限
    const PAYMENT_ERROR = 2030;//支付失败
    const AUTH_FALSE = 0; //未认证
    const AUTH_TURE  = 1; //已认证
    const PAY_DONE   = 2040;
    const qinniu_url = 'https://qiniu.huacaijia.com';
    const ttl        = 2592000; /*-一个月的秒数*/
    const short_ttl  = 3600;
    const PRO_SECRET = 'huacaishangcheng';
    const USER_NEW_START_TIME = '2019-10-21 10:00:00';//邀新开始时间
    const USER_NEW_END_TIME = '2019-11-26';//邀新结束时间
    const USER_GIFT_START_TIME = '2019-10-21 00:00:00';//礼品券赠送开始时间
    const USER_GIFT_END_TIME = '2019-12-02 00:00:00';//礼品券赠送结束时间
    const MOBILE_ZHEZE='/^1[0-9]{10}$/';
    const BLACK_USER_IDS= "'-1'";
    const CONSIGNEE_BLACK_MOBILE='17558757405,13736456177';//地址手机号备注拉黑
    const ORDER_BLACK_USER_IDS="707089,712777,721361,721361,721361,721361,721361,721361,721361,721691,721679,739783,739761,739755,739755,282168,242023";//用户备注拉黑
    public function __construct(Request $request)
    {

        parent::__construct();
        $this->request = $request;
        $this->data = $request->param();

        $this->source = $this->request->header('source')? $this->request->header('source') :'';
        $this->mendian = $this->is_mendian();
        /*--未登录时默认折扣和等级*/
        $GLOBALS['user_id'] = 0;
    }

    /*-
    初级验证 仅返回等级和折扣
    -*/
    protected function checkBasicAuth($rank=0,$sign=false){

            $GLOBALS['referer'] = $this->referer();
            /*-获取body或者header中的值-*/

            if(!empty($this->data['key'])){
                $key =$this->data['key'];
            }else if(!empty($this->request->header('key'))){
                $key =$this->request->header('key');
            }else{
                $key = '';
            }

            if($rank==0){
                if($key){
                    if(redis()->exists($key)){
                        $hmset = redis()->hGetAll($key);

                        $GLOBALS['key'] = $key;
                        $GLOBALS['user_id']   = $hmset['user_id'];
                        $GLOBALS['mobile']    = $hmset['mobile'];
                        if(isset($hmset['u_new'])){
                            $GLOBALS['u_new']=$hmset['u_new'];
                        }else{
                            $GLOBALS['u_new']=1;
                        }
                        /*-方便验证-*/
                        $this->data['user_id'] = $hmset['user_id'];
                    }
                }
            }
            if($rank==1){
                if(!empty($key)){
                    if(redis()->exists($key)){
                        $hmset = redis()->hGetAll($key);

                        $GLOBALS['key']       = $key;
                        $GLOBALS['user_id']   = $hmset['user_id'];
                        $GLOBALS['mobile']    = $hmset['mobile'];
                        /*-方便验证-*/
                        $this->data['user_id'] = $hmset['user_id'];
                    }else{
                        throw new HttpException(200,'登录超时,请重新登录',null,[],self::INVALID_TOKEN);
                    }
                }else{
                    throw new HttpException(200,'登录超时,请重新登录',null,[],self::UNLOGIN);
                }

            }

            //开启sign验证
            if($sign == true){

            }

    }

    protected function referer(){


        $source = $this->request->header('Source');
        $agent = $this->request->server('HTTP_USER_AGENT');
        if(isset($this->data['system'])&&$this->data['system']==='iOS'){
            return 'APP-IOS';
        }
        if(isset($this->data['system'])&&$this->data['system']==='Android'){
            return 'APP-Android';
        }
        if(isset($source) && $source == 'xcx'){
            return 'xcx';
        }
        if(isset($source) && $source == 'md-xcx'){
            return 'md';
        }
        if(isset($source) && $source == 'h5'){
            return 'h5';
        }
        if(isset($source) && $source == 'PC'){
            return 'PC';
        }

        if(strpos($agent, 'iPhone')||strpos($agent, 'iPad')||strpos($agent, 'iOS')){
            return 'APP-IOS';
        }
        if(strpos($agent, 'Android')){
            return 'APP-Android';
        }
        return 'other';


    }

    protected  function is_mendian(){
        if($this->source == 'xcx'){
            return 0;
        }elseif ($this->source == 'md-xcx'){
            return 1;
        } else{
            return 0;
        }
    }

    /*-
    产生token加密
    -*/
    protected function generateToken($u_phone){

        $time = time();
        $salt = config('others.')['salt'];
        $token = md5(md5($u_phone.$time.$salt.strval(rand(0,999999))));
        /*-查找redis库中是否存在重复的key  存在在递归  概率很小 不排除发生-*/
        if(redis()->exists($token)){
            return $this->generateToken($token);
        }
        return $token;
    }

    /*
    *签名验证
    */

    protected function check_sign($params,$head_str='cwj'){
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
        $pro_validate_sign = md5($head_str.substr(md5($query.self::PRO_SECRET), 0,-3));
        if($pro_validate_sign!=$sign){
            throw new HttpException(200,'签名验证失败',null,[],self::ERROR_NO_AUTH);
        }
    }
    protected function redis_store($token,$user_id,$mobile,$ttl=true){
        $User = new Users();
        $hmset = $User->update_user_info($user_id);
        $hmset['user_id'] = $user_id;
        $hmset['mobile'] = $mobile;
        $hmset['iat'] = time();//授权时间
//        $hmset['exp'] = time()+3600*24*30; //过期时间
        redis()->hMset($token,$hmset);
        /*-短期令牌-*/
        if($ttl === false){
            redis()->expire($token,static::short_ttl);
        }else{
            redis()->expire($token,static::ttl);
        }

        $this->setUniqueIndex($user_id,$token,false);
    }

    // 设置唯一索引 暂时不需要单点登录 只给最后的token一个标识符
    protected function setUniqueIndex($user_id,$token,$flag=false)
    {
        $UniqueIndex = sprintf("Unique:%s",$user_id);

        // 删除旧token数据
        if($flag === true){
            $beforeToken = redis()->get($user_id);
            if (!empty($beforeToken)) {
                redis()->del($beforeToken);
            }
        }

        // 更新唯一索引
        redis()->set($UniqueIndex,$token,['EX'=>static::ttl]);
    }


    /**
     * 防止重复提交表单redis锁
     * @param $class_name {方法名}
     * @param $ttl {锁时间}
     */
    public function submit_lock($class_name,$ttl){

        $lock_name = sprintf('%s:%d',$class_name,$GLOBALS['user_id']);

        if(redis(3)->exists($lock_name)){
            abort(500,'操作频繁,请稍后再试');
        }

        redis(3)->set($lock_name,1,['EX'=>$ttl]);
    }
    /**
     * 防止多次输入密码
     * @param $class_name {方法名}
     */
    public function password_lock($class_name){
        $lock_name = sprintf('%s:%d',$class_name,$GLOBALS['user_id']);
        $length=redis(3)->incrBy($lock_name,1);
        if($length>5){
            redis(3)->expire($lock_name,3600);//错误重置为一小时
            abort(500,'密码已连续输入五次错误,请一小时后重试');
        }
        redis(3)->expire($lock_name,86400);//记录一天时间
    }
    /**
     * 验证验证码
     * @param $sms_code {手机验证码}
     * @param $mobile   {手机号码}
     * @param $type {短信类型}
     *
     * @return bool
     */
    protected function checkCode($sms_code,$mobile,$type){

        $hash_name = sprintf('HUACAIJIA_SMS:%d:%s',$type,$mobile);

        $smsCheckCode = redis()->hGetAll($hash_name);
//        $smsCheckCode['sms_code'] = 111111;
//        $smsCheckCode['ttl'] = 111111;

        if(!$smsCheckCode){

            return json_error('','验证码错误1，请重新获取！',self::SYSTEM_ERROR);
        }

        if($smsCheckCode['ttl'] == 1){
            return json_error('','验证码错误已达上限，请重新获取验证码!',self::INVALID_PARAMETER);
        }

        if($smsCheckCode['sms_code'] != $sms_code){
            if($smsCheckCode['ttl']>1){
                $smsCheckCode['ttl']--;
                redis()->hSet($hash_name,'ttl',$smsCheckCode['ttl']);
                return json_error('','验证码错误，请重新获取',self::INVALID_PARAMETER);
            }

//            RedisII::delete($hash_name);
            return json_error('','验证码错误已达上限，请重新获取验证码!',self::INVALID_PARAMETER);
        }
        //正式环境开启
        redis()->del($hash_name);

        return true;

    }

    protected function _validate()
    {
        foreach (func_get_args() as $key) {
            if (! isset($this->data[$key])) {
                throw new \Exception("The $key parameter is required");
            }
        }
    }

    /**
     * 注册时同时注册k3门店会员
     * @param $username {手机验证码}
     * @param $mobile   {手机号码}
     * @param $flag   {insert|update}
     * @param $tui_mobile   {大客户手机号？}
     * @return bool
     */
    protected function get_mendian_cards($username,$mobile,$flag='insert',$tui_mobile='')
    {
        $client = new Client();

        $token=md5(date('Ymd').'hongyuebi');

        $url = sprintf("http://122.225.4.162:8000/y_ElectronicsCard.php?flag=%s&username=%s&fmobile=%s&Recommend_FMobile=%s&token=%s",$flag,$username,$mobile,$tui_mobile,$token);
        $response = $client->get($url);

        $arr=json_decode($response->getBody(),true);

        if($arr['result']=='successC2'&&$flag=='insert')
        {
            return  $arr['data'][0];
        }

        return false;


    }
    /**
     * Get the status code.
     *
     * @return int $statusCode
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * Set the status code.
     *
     * @param $statusCode
     * @return $this
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    /**
     * Repond a no content response.
     *
     * @return response
     */
    public function noContent()
    {
        return json(null, 204);
    }

    public function respondWithArray($array,$msg='请求成功',array $headers = [])
    {
        return json_success($array, $msg,$this->statusCode, $headers);
    }

    /**
     * Respond the error message.
     *
     * @param  string $message
     * @return json
     */
    public function respondWithError($message)
    {
//        if ($this->statusCode === 200) {
//            trigger_error(
//                "You better have a really good reason for erroring on a 200...",
//                E_USER_WARNING
//            );
//        }

        return json_error(NULL,$message,$this->statusCode);

    }

    /**
     * Respond the error of 'Forbidden'
     *
     * @param  string $message
     * @return json
     */
    public function errorForbidden($message = 'Forbidden')
    {
        return $this->setStatusCode( self::CODE_FORBIDDEN)
            ->respondWithError($message);
    }

    /**
     * Respond the error of 'Resource Not Found'
     *
     * @param  string $message
     * @return json
     */
    public function errorNotFound($message = 'Resource Not Found')
    {
        return $this->setStatusCode(self::CODE_NOT_FOUND)
            ->respondWithError($message) ;
    }

    /**
     * Respond the error of 'Unauthorized'.
     *
     * @param  string $message
     * @return json
     */
    public function errorUnauthorized($message = 'Unauthorized')
    {
        return $this->setStatusCode(self::CODE_UNAUTHORIZED)
            ->respondWithError($message);
    }

    /**
     * Respond the error of 'Wrong Arguments'.
     *
     * @param  string $message
     * @return json
     */
    public function errorWrongArgs($message = 'Wrong Arguments')
    {
        return $this->setStatusCode(self::CODE_WRONG_ARGS)
            ->respondWithError($message);
    }
    /**
     * 判断json重复
     *
     * @param  string $message
     * @return 布尔
     */
    public function check_unique($json)
    {
        $last_arr=[];
        foreach ($json as $item){
            if($item!=0){
                $last_arr[]=$item;
            }
        }
        $unique=array_unique($last_arr);
        if(count($unique)!=count($last_arr)) abort(403,'优惠券重复');
        return true;
    }
    public function http_post($url,$data = null){
        $postdata = http_build_query($data);
        $opts = array('http' =>
            array(
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => $postdata
            )
        );
        $context = stream_context_create($opts);
        $result = file_get_contents($url, false, $context);
        return $result;
    }

    public function create_ticket($appid,$key,$url,$uid,$orderNo,$totalAmount,$goods){
        $nonceStr = $this->getRandom(32);
        $timestamp = time();
        $data = [
            'nonceStr' => (string) $nonceStr,
            'timestamp' => (string) $timestamp,
//            'appid' => (string) $appid,
            'key' => (string) $key,
            'uid' => (int) $uid,
            'orderNo' => (string) $orderNo,
            'totalAmount' => (float) $totalAmount,
            'goods' => json_encode($goods)
        ];
        //签名
        asort($data,2);
        $data['sign'] = sha1(implode('', $data));
        unset($data['key']);
        $data['appid'] = (string) $appid;
        $res = $this->http_post($url,$data);
        return json_decode($res,true);
    }

    public function get_ticket($appid,$key,$url,$uid,$status){
        $nonceStr = $this->getRandom(32);
        $timestamp = time();
        $data = [
            'nonceStr' => (string) $nonceStr,
            'timestamp' => (string) $timestamp,
            'key' => (string) $key,
            'uid' => (int) $uid,
            'status' => (int) $status
        ];
        //签名
        asort($data,2);
        $data['sign'] = sha1(implode('', $data));
        unset($data['key']);
        $data['appid'] = (string) $appid;
        $res = $this->http_post($url,$data);
        $res = json_decode($res,true);
        return $res['data'];
    }

    public function ticket_detail($appid,$key,$url,$orderNo,$code){
        $nonceStr = $this->getRandom(32);
        $timestamp = time();
        $data = [
            'nonceStr' => (string) $nonceStr,
            'timestamp' => (string) $timestamp,
            'key' => (string) $key,
            'orderNo' => (string) $orderNo,
            'code' => (string) $code
        ];
        //签名
        asort($data,2);
        $data['sign'] = sha1(implode('', $data));
        unset($data['key']);
        $data['appid'] = (string) $appid;
        $res = $this->http_post($url,$data);
        $res = json_decode($res,true);
        return $res['data'];
    }

    public function getRandom($length){
        $str="0123456789abcdefghijklmnopqrstuvwxyz";
        $key = "";
        for($i=0;$i<$length;$i++)
        {
            $key .= $str[mt_rand(0,strlen($str)-1)];
        }
        return $key;
    }
}
