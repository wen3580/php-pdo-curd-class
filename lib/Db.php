<?php
/**
 * Created by PhpStorm.
 * User: ywx
 * Date: 2019/7/10
 * Time: 14:57
 */


class Db
{
    private $conn;
    private $sql = [
        'where'   => null,
        'wheresql'   => null,
        'orderBy' => null,
        'limit'   => null,
        'up'   => null,
        'ins'   => null,
        'group'   => null,
        'fields'   => null,
    ];
    private $type;
    private $tablename;
    private $deBug = false;

    public function __construct( $data = [])
    {
        if($data){
            $this->_setDbDrive($data);
        }
    }

    private function _setDbDrive($dbConf){
        $dns = 'mysql:host='.$dbConf['db_ip'].';dbname='.$dbConf['db_name'].';charset=utf8mb4';

        try {
            $db = new \PDO($dns,$dbConf['db_user'],$dbConf['db_pass']);
        } catch(\PDOException $e) {
            die('Could not connect to the database:<br/>' . $e);
        }
        $this->conn[$dbConf['name']] = $db;
    }

    /**
     * 判断是否创建连接
     * @param $table
     */
    private function checkTable($table){
        //获取表库信息
        $db_info = _getDBIP($table);
        $this->sql = [];//重置缓存
        $this->type = $db_info['name'];//缓存库信息

        //初始化连接
        if(!isset($this->conn[$db_info['name']])){
            $this->_setDbDrive($db_info);
        }
        $this->tablename = $table;
    }

    public function table($tablename) {
        $this->checkTable($tablename);

        return $this;
    }

    public function select($fields = '*') {
        $sql = sprintf("SELECT %s FROM %s", $fields, $this->tablename);
        $this->sql['fields'] = $fields;
        if(!empty($this->sql['where'])) {
            $sql .= ' WHERE ' . $this->sql['wheresql'];
        }
        if(!empty($this->sql['orderBy'])) {
            $sql .= ' ORDER BY ' . $this->sql['orderBy'];
        }
        if(!empty($this->sql['limit'])) {
            $sql .= ' LIMIT ' . $this->sql['limit'];
        }
        if(!empty($this->sql['group'])) {
            $sql .= ' GROUP BY ' . $this->sql['group'];
        }

        return $this->_select($sql);
    }

    public function deBug(){
        $this->deBug = true;
        return $this;
    }

    public function find($fields = '*') {
        $this->limit(1);
        $result = $this->select($fields);
        return $result;
    }

    public function insert($data) {

        $keys = "`".implode('`,`', array_keys($data))."`";
        $values = ":i".implode(", :i", array_keys($data));
        $this->sql['ins'] = $data;

        $querySql = sprintf("INSERT INTO %s ( %s ) VALUES ( %s )", $this->tablename, $keys, $values);

        return $this->_insert($querySql);
    }

    public function delete() {
        if(!$this->sql['where']) return false;
        $querySql = sprintf("DELETE FROM %s WHERE ( %s )", $this->tablename, $this->sql['wheresql']);
        return $this->_update($querySql);
    }

    /**
     * 传入数组 ['apid'=>['+',15],'bpid'=>105] 目前二位数组仅支持 加减
     * @param $data
     * @return mixed
     */
    public function update($data) {
        $updateFields = [];
        foreach ($data as $key => $value) {
            if(!is_array($value)){
                $updateFields[] = "`$key`=:u{$key} ";
            }else{
                $updateFields[] = "`$key`= `{$key}` {$value[0]} {$value[1]}";
                unset($data[$key]);
            }
        }
        $this->sql['up'] = $data;
        $updateFields = implode(',', $updateFields);
        $sql = sprintf("UPDATE %s SET %s", $this->tablename, $updateFields);

        if(!empty($this->sql['where'])) {
            $sql .= ' WHERE ' . $this->sql['wheresql'];
        }

        return $this->_update($sql);
    }

    public function limit($limit, $limitCount = null) {
        if(!$limitCount) {
            $this->sql['limit'] = $limit;
        }else{
            $this->sql['limit'] = $limit .','. $limitCount;
        }
        return $this;
    }

    public function orderBy($orderBy) {
        $this->sql['orderBy'] = $orderBy;
        return $this;
    }

    public function groupBy($group) {
        $this->sql['group'] = $group;
        return $this;
    }

    public function where($where) {
        if(!is_array($where)) {
            return null;
        }

        $crondsArr = [];

        foreach ($where as $key => $value) {
            if(!is_array($value)) {
                $crondsArr[] = "`$key`=:w{$key}";
                continue;
            }else if($value[0] == 'in'){//处理in逻辑
                $val = ' (';
                foreach($value[1] as $k=>$v){
                    if($k == 0){
                        $val .= ':win'.$k;
                    }else{
                        $val .= ',:win'.$k;
                    }
                }
                $val .= ')';
            }else{//处理 > < <> 等逻辑
                $val = ' :w' . $key;
            }
            $crondsArr[] = "`$key` ".$value[0]. $val;
        }

        $this->sql['wheresql'] = implode(' AND ', $crondsArr);
        $this->sql['where'] = $where;
        return $this;
    }

    public function whereOr($where) {
        if(!is_array($where)) {
            return null;
        }

        $crondsArr = [];
        foreach ($where as $key => $value) {
            $fieldValue = $value;
            if(is_array($fieldValue)) {
                $crondsArr[] = "`$key` ".$fieldValue[0]. ' :wo' . $key;
            }else{
                $crondsArr[] = "`$key`=:wo{$key}";
            }
        }

        $sql = implode(' OR ', $crondsArr);
        if(!empty($this->sql['wheresql'])){
            $this->sql['wheresql'] .=  ' AND ' . '(' . $sql . ')';
        }else{
            $this->sql['wheresql'] =  '(' . $sql . ')';
        }

        if(!empty($this->sql['whereor'])){
            $this->sql['whereor'] = array_merge($this->sql['whereor'], $where);
        }else{
            $this->sql['whereor'] = $where;
        }

        return $this;
    }

    private function _select($sql){
        if($this->type == 'master') {
            //确认redis缓存数据
            if($data = $this->_checkRedisMasterData()){
                return $data;
            }
        }

        $stmt = $this->conn[$this->type]->prepare($sql);

        $stmt = $this->_setWhereParam($stmt);

        if($this->deBug){
            return $stmt->debugDumpParams();
        }

        $ret = $stmt->execute();

        if(!$ret) return null;
        if($this->sql['limit'] == 1){
            $retData = $stmt->fetch(\PDO::FETCH_ASSOC);
        }else{
            $retData = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $re_data = $this->_formatTransform($retData);

        return $this->_wirteRedisMasterData($re_data);
    }

    private function _update($sql){
        $stmt = $this->conn[$this->type]->prepare($sql);

        $stmt = $this->_setWhereParam($stmt);

        //update
        if(!empty($this->sql['up'])){
            foreach($this->sql['up'] as $k=>$v){
                $stmt->bindValue('u'.$k,trim($v,'\''));
            }
        }

        if($this->deBug){
            return $stmt->debugDumpParams();
        }

        $ret = $stmt->execute();

        return $ret;
    }

    private function _insert($sql){
        $stmt = $this->conn[$this->type]->prepare($sql);

        //install
        if(!empty($this->sql['ins'])){
            foreach($this->sql['ins'] as $k=>$v){
                $stmt->bindValue('i'.$k,$v);
            }
        }

        $ret = $stmt->execute();

        if($ret) {
            return $this->conn[$this->type]->lastInsertId();
        }

        return false;
    }

    private function _setWhereParam($stmt){
        //绑定参数 where
        if(!empty($this->sql['where'])){
            foreach($this->sql['where'] as $k=>$v){
                if(!is_array($v)){
                    $val = $v;
                }else
                if($v[0] == 'in'){
                    foreach($v[1] as $key=>$v1){
                        $stmt->bindValue('win'.$key,$v1);
                    }
                    continue;
                }else{
                    $val = $v[1];
                }
                $stmt->bindValue('w'.$k,$val);
            }
        }

        if(!empty($this->sql['whereor'])){
            foreach($this->sql['whereor'] as $k=>$v){
                if(!is_array($v)){
                    $val = $v;
                }else{
                    $val = $v[1];
                }
                $stmt->bindValue('wo'.$k,$val);
            }
        }
        return $stmt;
    }

    public function close() {
        return $this->conn = null;
    }

    //开启事务
    public function startTrans($tab = 'item'){

        //准备数据库资源
        $this->table($tab);
        if(empty($this->conn['server'])){
            exit();
        }
        $this->conn['server']->beginTransaction();
    }

    //提交事务
    public function dbCommit(){
        if(empty($this->conn['server'])){
            exit();
        }
        $this->conn['server']->commit();
    }

    //回滚事务
    public function dbRollBack($data = '',$e = ''){
        if(empty($this->conn['server'])){
            exit();
        }
        $this->conn['server']->rollBack();
        if(!empty($data) &&!empty($e)){
            _writeLog($data,'update-error--'.date('Y-m-d H:i:s').$e->getMessage(),'error');
        }
    }

    /**
     * 原生查询
     * @param $querySql
     * @return mixed
     */
    public function query($table, $querySql) {
        $this->checkTable($table);
        $querystr = strtolower(trim(substr($querySql,0,6)));
        $stmt = $this->conn[$this->type]->prepare($querySql);

        $ret = $stmt->execute();
        $this->sql = [];
        if(!$ret) var_dump($stmt->errorInfo());

        if($querystr == 'select') {
            $retData = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $this->_formatTransform($retData);
        }elseif($ret && $querystr == 'insert') {
            return $this->conn[$this->type]->lastInsertId();
        }else{
            return $this->_formatTransform($ret);
        }
    }

    private function _formatTransform($data){

        if(!is_array($data))return $data;

        if($this->tablename == 'round_star'
            || $this->tablename == 'chapter_reward'
            || $this->tablename == 'dailyround'
        ){

        }elseif($this->tablename == 'userdatas' || $this->tablename == 'userDatas'){
            array_walk_recursive($data, function(&$value , $key){

                if($key == 'coin'){
                    $value = (string)$value;
                }elseif(ctype_digit($value)){
                    $value = (int)$value;
                }elseif(is_json($value)){
                    // jsonチェック
                    $value = json_decode($value, true);
                }
            });

        }else{
            array_walk_recursive($data, function(&$value, $key){

                if(ctype_digit($value) && $key != 'name'){
                    $value = (int)$value;
                }elseif(is_json($value)){
                    $value = json_decode($value, true);
                }
            });
        }

        return $data;
    }

    /**
     * 二维数组多条更新
     * @param $data
     */
    public function updateAll($data){

        if(!empty($data['table'])){
            $table = $data['table'];
            $whe = ['id'=>$data['id']];
            unset($data['table']);
            unset($data['id']);
            $t = $this->table($table)->where($whe)->insert($data);
            if(!$t)return false;
        }else{
            foreach($data as $k=>$v){
                $table = $v['table'];
                $whe = ['id'=>$v['id']];
                unset($v['table']);
                unset($v['id']);
                $t = $this->table($table)->where($whe)->update($v);
                if(!$t)return false;
            }
        }

        return true;
    }

    /**
     * 多条数据插入
     * @param $data
     */
    public function insertAll($data){

        if(!empty($data['table'])){
            $table = $data['table'];
            unset($data['table']);
            $t = $this->table($table)->insert($data);
            if(!$t)return false;
        }else{
            foreach($data as $k=>$v){
                $table = $v['table'];
                unset($v['table']);
                $t = $this->table($table)->insert($v);
                if(!$t)return false;
            }
        }


        return true;

    }

    /**
     * @param $table
     * @param $on
     * @param string $join
     * @param string $t1
     */
    public function join($table, $on, $join = ' left ', $t1 = ' a '){
        $this->tablename .= $t1 . $join . $table . $on;
    }

    //确认缓存并获取
    private function _checkRedisMasterData(){
        if(_getRedis()->exists($this->_getMasterRedisName())){
            $data = json_decode(_getRedis()->get($this->_getMasterRedisName()),true);
            return $data;
        }
        return false;
    }

    //写入缓存，设置有效期
    private function _wirteRedisMasterData($data){
        if($this->type != 'master') return $data;
        _getRedis()->set($this->_getMasterRedisName(),json_encode($data));
        _getRedis()->expire($this->_getMasterRedisName(),MASTER_EXPIRE);
        $this->sql = [];
        return $data;
    }

    //获取缓存名
    private function _getMasterRedisName(){
        $arr = [
            'string',
            $this->tablename,
            json_encode($this->sql['where']),
            $this->sql['limit'],
            $this->sql['orderBy'],
            $this->sql['group'],
            $this->sql['fields'],
        ];
        $str = implode('_',$arr);
        return $str;
    }
}

