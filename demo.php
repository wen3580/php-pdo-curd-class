<?php
/**
 * Created by PhpStorm.
 * User: ywx
 * Date: 2019/8/16
 * Time: 13:34
 */
include 'lib/function.php';
include 'lib/Db.php';

$db = new Db();

$whe = [
    'uid'=>123,
    'isremove' => 0,
    'pid'=>['in',[2,3,5]]
];

$db->table('test')->where($whe)->select();
$db->table('test')->where($whe)->deBug()->select();
$db->table('test')->where($whe)->orderBy('pid desc')->groupBy('name')->select('xxx,www');
$db->table('test')->where($whe)->find();
$db->table('test')->where($whe)->delete();
$db->table('test')->where($whe)->update(['name'=>666]);
$db->table('test')->insert(['id'=>6,'name'=>666,'uid'=>321]);