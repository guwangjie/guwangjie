<?php


namespace app\common\controller;

use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;
use Qiniu\Auth;


class Upload
{
    protected $uploadManager;
    protected $BucketManager;
    protected $auth;
    private $token;
    protected $QiNiu_config = [
        'accesskey' => 'eQrq0nZE5RmFG4cuvfDQaZrrJ7-vEJZoWWOoC6OA',
        'secretkey' => 'revN1wyMelX6wc7mtMOY2B8m91F_vyRB_qctzz_d',
        'bucket' => 'hongyue-app',
    ];

    public function __construct()
    {
        $this->uploadManager = new UploadManager();

        $this->auth = new Auth($this->QiNiu_config['accesskey'],$this->QiNiu_config['secretkey']);
        $this->token =  $this->auth->uploadToken($this->QiNiu_config['bucket']);

        $this->BucketManager = new BucketManager($this->auth);
    }

    public function easy_upload($name,$temp_name)
    {

        $temp_name = __DIR__.'/'."../../../".'public'.$temp_name;
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $this->isImage($ext);
        $key = $this->file_path($ext,'Article');
        list($ret, $err) = $this->uploadManager->putFile($this->token,$key,$temp_name);

        if ($err !== null) {
            return $err;
        } else {

            return $ret['key'];
        }

    }

    public function single_upload($img,$prefix)
    {
        if(!$img){
            abort(400,'图片不能为空');
        }

        $ext = pathinfo($img['name'], PATHINFO_EXTENSION);
        $this->isImage($ext);
        $key = $this->file_path($ext,$prefix);
        list($ret, $err) = $this->uploadManager->putFile($this->token,$key,$img['tmp_name']);
        if ($err !== null) {
            return $err;
        } else {
            return $ret['key'];
        }

    }

    public function single_upload_stream($stream,$prefix='stream')
    {
        if(!$stream){
            abort(400,'图片不能为空');
        }

//        $ext = pathinfo($img['name'], PATHINFO_EXTENSION);
//        $this->isImage($ext);
        $key = $this->file_path('png',$prefix);
        list($ret, $err) = $this->uploadManager->put($this->token,$key,$stream);
        if ($err !== null) {
            return $err;
        } else {
            return $ret['key'];
        }

    }

    public function multi_arrange($img){

        $i=0;
        foreach($img as $key=>$file){

            //因为这时$_FILES是个三维数组，并且上传单文件或多文件时，数组的第一维的类型不同，这样就可以拿来判断上传的是单文件还是多文件
            if(is_string($file['name'])){
                //如果是单文件
                $files[$i]=$file;
                $i++;
            }elseif(is_array($file['name'])){
                //如果是多文件
                foreach($file['name'] as $key=>$val){
                    $files[$i]['name']=$file['name'][$key];
                    $files[$i]['type']=$file['type'][$key];
                    $files[$i]['tmp_name']=$file['tmp_name'][$key];
                    $files[$i]['error']=$file['error'][$key];
                    $files[$i]['size']=$file['size'][$key];
                    $i++;
                }
            }
        }
        return $files;
    }

    public function img_list($img){
        $i=0;
        if(is_string($img['name'])){
            //如果是单文件
            $files[$i]=$img;
            $i++;
        }elseif(is_array($img['name'])){
            //如果是多文件
            foreach($img['name'] as $key=>$val){
                $files[$i]['name']=$img['name'][$key];
                $files[$i]['type']=$img['type'][$key];
                $files[$i]['tmp_name']=$img['tmp_name'][$key];
                $files[$i]['error']=$img['error'][$key];
                $files[$i]['size']=$img['size'][$key];
                $i++;
            }
        }
        return $files;
    }

    public function delete($key){

        $res = $this->BucketManager->delete($this->QiNiu_config['bucket'],$key);
        if($res !== null){
            abort(400,'该文件不存在或已被删除');
        }else{
            abort(200,'删除成功');
        }
    }

    public function deleteByUrl($imageUrl) {
        $imagePath = str_replace('https://qiniu.huacaijia.com', '', $imageUrl);
        $imagePath = trim($imagePath, '/');
        $imagePath = trim($imagePath, '\\');
        $res = $this->BucketManager->delete($this->QiNiu_config['bucket'],$imagePath);
        if($res !== null){
            return false;
        }else{
            return true;
        }
    }

    private function file_path($ext,$prefix='default'){
        return sprintf('%s/%s/%s.%s',$prefix,date('Y-m-d'),md5(uniqid(rand())),$ext);
    }

    private function isImage($ext) {

        $filetype = ['jpg', 'jpeg', 'gif','GIF', 'bmp', 'png','MP4','mp4','JPG','apk','wmv','mov','PNG','JPEG','mp3'];
        if (!in_array($ext, $filetype))
        {
            abort('400','图片格式错误');
        }
        return true;
    }

    public function check_image_size($path){
        list($width,$height) = getimagesize($path);
        //图片尺寸为800*800
        if($width != 800 || $height != 800){
            abort(400,'图片尺寸错误');
        }
        return true;
    }

}