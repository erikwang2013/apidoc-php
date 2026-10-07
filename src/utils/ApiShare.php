<?php
declare(strict_types=1);

namespace erikwang2013\apidoc\utils;

use erikwang2013\apidoc\Auth;
use erikwang2013\apidoc\exception\ErrorException;
use erikwang2013\apidoc\parses\ParseApiMenus;

/**
 * 接口分享
 *
 * 密码存储格式:缓存中 password 字段存的是 md5(明文) 摘要,不存明文。
 * 前端提交的密码本身也是 md5(明文),因此校验时直接比对两边摘要,切勿再次 md5。
 */
class ApiShare
{

    public static function getShareCacheKey($key)
    {
        return "share/apiShare_" . $key;
    }

    public function addApiShare($params){
        if (!empty($params['key'])) {
            //编辑
            if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', $params['key'])) {
                throw new ErrorException('field not found', ['field' => 'key']);
            }
            $cacheKey = "share/apiShare_" . $params['key'];
        } else {
            //新增
            $cacheKey = "share/apiShare_" . uniqid();
        }

        $data = [
            'name' => $params['name'],
            'type' => $params['type'],
        ];
        if (!empty($params['appKeys'])) {
            $data['appKeys'] = $params['appKeys'];
        }
        if (!empty($params['apiKeys'])) {
            $data['apiKeys'] = $params['apiKeys'];
        }
        if (!empty($params['password'])) {
            // 存 md5(明文),避免缓存文件中出现密码明文
            $data['password'] = md5($params['password']);
        } else if (!empty($params['key'])) {
            // 编辑时未传新密码:保留原密码,避免编辑分享把密码意外抹掉
            $oldData = (new Cache())->get($cacheKey);
            if (!empty($oldData['password'])) {
                $data['password'] = $oldData['password'];
            }
        }
        $data['create_at'] = date('Y-m-d h:i:s');
        $data['create_time'] = time();
        $res = (new Cache())->set($cacheKey, $data);
        return $res;
    }

    public function getSharePageList($config,$pageIndex,$pageSize){
        // 两端裁剪后再拼接: 兼容 APIDOC_STORAGE_PATH 未带结尾分隔符的部署(否则静默扫不到文件,列表恒空)
        $path = rtrim(APIDOC_STORAGE_PATH, "/\\") . '/' . trim((string)$config['cache']['folder'], "/\\") . "/share";

        $list = DirAndFile::getFileList($path);
        $data = [];
        $cache = new Cache();

        foreach ($list as $item) {
            $fileNameArr = explode("_", $item['name']);
            // 只处理 apiShare_<key>_<hash>.php 形式的文件,其余文件(含写入中的 .tmp)跳过,避免 notice
            if (($fileNameArr[0] ?? '') !== 'apiShare' || empty($fileNameArr[1]) || substr($item['name'], -4) !== '.php') {
                continue;
            }
            $cacheKey = "share/" . $fileNameArr[0] . "_" . $fileNameArr[1];
            $cacheData = $cache->get($cacheKey);
            if (empty($cacheData)) {
                continue;
            }
            $itemData = [
                'key' => $fileNameArr[1],
                'name' => $cacheData['name'],
                'type' => $cacheData['type'],
                'create_time' => $cacheData['create_time'],
                'create_at' => $cacheData['create_at'],
            ];
            $data[] = $itemData;
        }
        $data = Helper::arraySortByKey($data, 'create_time', SORT_DESC);
        $page = $pageIndex-1;
        $res = array_slice($data,$page*$pageSize,$pageSize);
        return [
            'total'=>count($data),
            'data'=>$res
        ];
    }


    public function getShareDetailByKey($shareKey)
    {
        if (empty($shareKey)) {
            throw new ErrorException('field not found', ['field' => 'shareKey']);
        }
        $cache = new Cache();
        $cacheKey = static::getShareCacheKey($shareKey);
        $cacheData = $cache->get($cacheKey);
        if (empty($cacheData)) {
            throw new ErrorException("share not exists");
        }
        return $cacheData;
    }

    public function checkShareAuth($config, $params)
    {
        if (empty($params['shareKey'])) {
            throw new ErrorException('field not found', ['field' => 'shareKey']);
        }
        $cacheData = $this->getShareDetailByKey($params['shareKey']);

        if (!empty($cacheData['password'])) {
            //验证密码
            if (empty($params['token'])) {
                throw new ErrorException("token not found");
            }
            // password 已是 md5(明文),与 createToken 的入参一致,不能再 md5
            $checkAuth = (new Auth($config))->checkToken($params['token'], $cacheData['password']);
            if (!$checkAuth) {
                throw new ErrorException("token error");
            }
        }
        // 不向上层泄漏密码摘要
        unset($cacheData['password']);
        return $cacheData;
    }

    public static function getAppShareApis(array $config, array $apps, $parentKey = "", $filterAppKeys = [], $isParseDetail = false)
    {
        $appList = [];
        $separ = !empty($parentKey) ? ',' : '';
        foreach ($apps as $app) {
            $appKey = $parentKey . $separ . $app['key'];
            if (!empty($app['items']) && count($app['items'])) {
                $items = static::getAppShareApis($config, $app['items'], $appKey, $filterAppKeys, $isParseDetail);
                $app['children'] = $items;
            } else {
                $app['appKey'] = $appKey;
                $apiData = (new ParseApiMenus($config))->renderApiMenus($appKey, $isParseDetail);
                $app['children'] = $apiData['data'];
            }
            if (!empty($filterAppKeys) && count($filterAppKeys) && !in_array($appKey, $filterAppKeys) && empty($app['items'])) {
                continue;
            }
            $app['menuKey'] = $appKey;
            $appList[] = $app;
        }

        return $appList;
    }

    public function getShareData($config, $key)
    {
        $shareData = $this->getShareDetailByKey($key);
        $filterAppKeys = !empty($shareData['appKeys']) ? $shareData['appKeys'] : [];
        $configApps = Helper::handleAppsConfig($config['apps'], false, $config);
        $appList = static::getAppShareApis($config, $configApps, "", $filterAppKeys, true);
        if ($shareData['type'] == 'api') {
            $appList = Helper::filterTreeNodesByKeys($appList, $shareData['apiKeys'], 'menuKey');
        }
        return [
            'shareData' => $shareData,
            'apiData' => $appList,
        ];
    }

    public function handleApiShareAction($config, $key, $index)
    {

        $actionConfig = $config['share']['actions'][$index];
        if (!empty($actionConfig['click'])) {
            $data = $this->getShareData($config, $key);
            $res = $actionConfig['click']($data['shareData'], $data['apiData']);
            return $res;
        }
        return false;

    }


}