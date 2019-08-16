<?php
/**
 * Created by PhpStorm.
 * User: ywx
 * Date: 2019/8/16
 * Time: 13:32
 */
define('MAIN',realpath('../'));
define('LOG',MAIN . '/log');
const MASTER_EXPIRE   = 3600 *12;


const DB_IP   = '127.0.0.1';
const DB_NAME = 'test';
const DB_USER = 'root';
const DB_PASS = '123456';


function _getDBIP($tableName)
{
    switch ($tableName) {
        default:
            return array(
                'name' => 'server',
                'db_ip' => DB_IP,
                'db_name' => DB_NAME,
                'db_user' => DB_USER,
                'db_pass' => DB_PASS,
            );
            break;
        // MASTER DATA
        case 'xxx':
            return array(
                'name' => 'master',
                'db_ip' => DB_MAS_IP,
                'db_name' => DB_MAS_NAME,
                'db_user' => DB_MAS_USER,
                'db_pass' => DB_MAS_PASS,
            );
    }
}

/**
 * 写入日志
 * @param $data
 * @param string $remake
 * @param string $code
 */
function _writeLog($data, $remake = '', $code = 'in')
{
    try {
        $dir = LOG . '/' . date('Ym') . '/' . date('d');
        if (!is_dir($dir)) mkdir($dir, 0766, true) && chmod($dir, 0766);
        file_put_contents(
            $dir . '/' . $code . '.log',
            date('H:i:s') . '==>--' . $remake . '----' . json_encode($data) . '-----' . PHP_EOL,
            FILE_APPEND
        );
    } catch (Exception $e) { }
}

/**
 * 获取redis
 * @return bool|Redis
 */
function _getRedis()
{
    static $redis = null;

    if ($redis) {
        return $redis;
    }
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
//		$redis->select(1);
        return $redis;
    } catch (Exception $e) {
        _writeLog($e->getMessage(), 'create redis fail', 'error');

        return false;
    }
}
