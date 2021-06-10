<?php


namespace app\common\controller;


use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;

class EsController
{
    static private $instance;

    private function __construct(){}

    private static function create(){
        $host   = config('es.host')   ?? 'es-cn-mp91kgdit000lw6rq.public.elasticsearch.aliyuncs.com';
        $port   = config('es.port')   ?? '9200';
        $scheme = config('es.scheme') ?? 'http';
        $user   = config('es.user')   ?? 'elastic';
        $pass   = config('es.pass')   ?? 'hongyue@es2020';

        $client = ClientBuilder::create()->setHosts([
            [
                'host'   => $host,
                'port'   => $port,
                'scheme' => $scheme,
                'user'   => $user,
                'pass'   => $pass
            ]
        ])->setConnectionPool('\Elasticsearch\ConnectionPool\SimpleConnectionPool', [])
            ->setRetries(10)->build();

        self::$instance = $client;
    }

    private static function createLocal(){
        $host   = 'localhost';
        $port   = '9200';
        $scheme = 'http';

        $client = ClientBuilder::create()->setHosts([
            [
                'host'   => $host,
                'port'   => $port,
                'scheme' => $scheme
            ]
        ])->setConnectionPool('\Elasticsearch\ConnectionPool\SimpleConnectionPool', [])
            ->setRetries(10)->build();

        self::$instance = $client;
    }

    public static function createClient(){
        if (empty(self::$instance) || !self::$instance instanceof Client) {
            self::create();
        }
        return self::$instance;
    }

    private function __clone(){}
}