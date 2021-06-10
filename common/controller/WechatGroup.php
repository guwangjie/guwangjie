<?php


namespace app\common\controller;


use Doctrine\Common\Cache\RedisCache;
use EasyWeChat\Foundation\Application;
use function GuzzleHttp\Psr7\build_query;
use think\Db;
use think\facade\Config;

class WechatGroup
{
    protected $databaseConfig = [
        // 数据库类型
        'type'        => 'mysql',
        // 数据库连接DSN配置
        'dsn'         => '',
        // 服务器地址
        'hostname'    => 'rm-bp1k265211s4b4uv3o.mysql.rds.aliyuncs.com',
        // 数据库名
        'database'    => 'ecshoptest',
        // 数据库用户名
        'username'    => 'hyhuacai',
        // 数据库密码
        'password'    => 'Hy20202021',
        // 数据库连接端口
        'hostport'    => '3306',
        // 数据库连接参数
        'params'      => [],
        // 数据库编码默认采用utf8
        'charset'     => 'utf8',
        // 数据库表前缀
        'prefix'      => '',
    ];

    // 员工企微id
    protected $qwId = [
        'ZM09ygy_18069677156',   //园艺娘
        'ZM09ygy_15238213921',   //王帅
        'ZM09ygy_13735534317',   //孙辉
        'ZM09ygy_13018174776',   //王春梅
        'ZM09yqy_13806707394',   //沈兰
        'ZM09ygy_13758149421',   //张丹丹
        'ZM09ygy_15868381980',   //杨雪
        'ZM09ygy_15067371775'    //朱良锋
    ];
    // 用户id
    protected $userId;
    // 用户unionid
    protected $unionId;

    protected $errMsg;
    protected $errCode;

    protected $access_token;

    public function __construct($userId){
        $this->userId = $userId;
        /*$config = Config::get('wechat.huacai_config');
        $cacheDriver = new RedisCache();
        $cacheDriver->setRedis(redis());
        $config['cache'] = $cacheDriver;
        $this->access_token = (new Application($config))->access_token;*/
    }

    public function checkCouponRule($onlyOne = false) {
        $userId = $this->userId;
        /*if (!$this->isSubscribe($userId)){
            $this->errMsg = '请先关注公众号花彩！';
            $this->errCode = 441;
            return false;
        }*/
        /*if (!$this->findUnionid($userId)){
            $this->errMsg = '请先关注公众号花彩！';
            $this->errCode = 441;
            return false;
        }*/
        $this->findUnionid($userId);
        if (!$this->isFriend()){
            $this->errMsg = '请添加园艺娘为微信好友后再领取！';
            $this->errCode = 442;
            return false;
        }
        if ($onlyOne){
            if (!$this->isHaveChance($userId)){
                $this->errMsg = '您已经领过券了，不能重复领取！';
                $this->errCode = 443;
                return false;
            }
        }
        return true;
    }

    public function getErrMsg() {
        return $this->errMsg;
    }

    public function getErrCode() {
        return $this->errCode;
    }

    public function getUnionid() {
        return $this->unionId;
    }

    protected function isHaveChance($userId) {
        $num = Db::name('wechat_id')
            ->where('user_id', $userId)
            ->where('unionid', $this->unionId)
            ->count();
        if ($num > 0){
            return false;
        }
        return true;
    }

    protected function isFriend() {
//        if ($this->unionId){
            $num = Db::name('wechat_work')
                ->whereIn('qw_uid', $this->qwId)
                ->where('external_union_id', $this->unionId)
                ->count();
            if ($num > 0){
                return true;
            }
//        }
        return false;
    }

    protected function findUnionid($userId) {
        $unionid = Db::name('users')->where('user_id',$userId)->value('wxunionid');
        if ($unionid){
            $this->unionId = $unionid;
            return true;
        }
        Db::name('crontab_log')->insert(['crontab'=>'ecs_users表查找用户unionid','data'=>'未找到，用户ID：'.$userId,'date'=>date('Y-m-d H:i:s')]);
        return false;
    }

    protected function isSubscribe ($userId){
        $openId = Db::name('openid')
            ->where('user_id', $userId)
            ->where('platform', 'official_accounts')
            ->column('openid');
        if ($openId){
            $user = $this->getUserInfo($openId);
            if (isset($user['subscribe']) && $user['subscribe']){
                $this->unionId = $user['unionid'];
                return true;
            }
        }
        Db::name('crontab_log')->insert(['crontab'=>'查找用户openid','data'=>'未找到，用户ID：'.$userId,'date'=>date('Y-m-d H:i:s')]);
        return false;
    }

    protected function getUserInfo($openId) {
        $accessToken = $this->getAccessToken();
        $data = [
            'access_token' => $accessToken,
            'openid' => $openId
        ];
        $url = 'https://api.weixin.qq.com/cgi-bin/user/info?'.build_query($data);
        $user = $this->getUrl($url);
        return $user;
    }

    protected function getAccessToken() {
        return $this->access_token->getToken();
    }

    protected function getUrl($url){
        $headerArray =array("Content-type:application/json;","Accept:application/json");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch,CURLOPT_HTTPHEADER,$headerArray);
        $output = curl_exec($ch);
        curl_close($ch);
        $output = json_decode($output,true);
        return $output;
    }


    protected function postUrl($url,$data){
        $data  = json_encode($data);
        $headerArray =array("Content-type:application/json;charset='utf-8'","Accept:application/json");
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,FALSE);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl,CURLOPT_HTTPHEADER,$headerArray);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }
}