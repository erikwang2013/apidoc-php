<?php
declare(strict_types=1);

namespace erikwang2013\apidoc;

use erikwang2013\apidoc\parses\ParseApiDetail;
use erikwang2013\apidoc\parses\ParseCodeTemplate;
use erikwang2013\apidoc\parses\ParseProtocol;
use erikwang2013\apidoc\parses\ParseApiMenus;
use erikwang2013\apidoc\parses\ParseMarkdown;
use erikwang2013\apidoc\protocol\debug\DebugProxy;
use erikwang2013\apidoc\protocol\debug\DebugSession;
use erikwang2013\apidoc\utils\ApiShare;
use erikwang2013\apidoc\utils\Cache;
use erikwang2013\apidoc\utils\ConfigProvider;
use erikwang2013\apidoc\utils\DirAndFile;
use erikwang2013\apidoc\utils\Helper;
use erikwang2013\apidoc\utils\Lang;
use erikwang2013\apidoc\utils\Request;
use erikwang2013\apidoc\ui\UiCapabilities;
use erikwang2013\apidoc\exception\ErrorException;

class Controller
{

    protected $config;

    protected $requestParams = [];

    protected $lang = "";

    /** requireAdmin() already verified the global credential; skip init(true) re-checks. */
    protected $adminVerified = false;

    public function init($checkAuth = false)
    {
        $this->config = ConfigProvider::get();
        if (isset($this->config['enable']) && $this->config['enable'] === false) {
            throw new ErrorException("apidoc close");
        }
        if (!empty($this->config['request_params'])) {
            $this->requestParams = $this->config['request_params'];
        } else {
            $this->requestParams = (new Request())->param();
        }
        if (empty($this->requestParams['token'])) {
            $authorization = (new Request())->header('Authorization');
            if (stripos($authorization, 'Bearer ') === 0) {
                $this->requestParams['token'] = trim(substr($authorization, 7));
            }
        }
        if (!empty($this->requestParams['lang']) && !empty($this->config['lang_register_function'])) {
            $this->lang = $this->requestParams['lang'];
            $this->config['lang_register_function']($this->lang);
        }
        if ($checkAuth && !$this->adminVerified) {
            (new Auth($this->config))->checkAuth($this->requestParams);
        }
    }

    /**
     * UIC-1 管理端鉴权:只认全局 token,不接受应用密码 token。
     * Auth::checkAuth 只要请求带 appKey 就会去校验应用密码,所以这里必须去掉 appKey。
     * 全局鉴权关闭时管理端保持开放,与 Go/Rust 的 requireAdmin 一致。
     */
    protected function requireAdmin(): void
    {
        $this->adminVerified = true;
        if (empty($this->config['auth']['enable'])) {
            return;
        }
        $params = $this->requestParams;
        unset($params['appKey']);
        (new Auth($this->config))->checkAuth($params);
    }

    /**
     * 验证权限
     * @param $config
     * @param $params
     */
    protected function checkAuth()
    {
        $config = $this->config;
        $params = $this->requestParams;
        if (!empty($params['shareKey'])) {
            //分享
            $shareData = (new ApiShare())->checkShareAuth($config, $params);
            $appKey = !empty($params['appKey']) ? $params['appKey'] : "";
            if (!empty($shareData['appKeys']) && !in_array($appKey, $shareData['appKeys'])) {
                throw new ErrorException("share not exists");
            }
            return $shareData;
        } else {
            (new Auth($config))->checkAuth($params);
        }
        return null;
    }

    /**
     * 分享为 api 类型时,只允许访问 apiKeys 内的接口
     * @param array|null $shareData checkAuth() 的返回值
     * @throws ErrorException
     */
    protected function assertShareApiAllowed($shareData, string $apiKey): void
    {
        if (is_array($shareData) && !ApiShare::isApiKeyAllowed($shareData, $apiKey)) {
            throw new ErrorException('share api not allowed');
        }
    }


    /**
     * 获取配置
     * @return \think\response\Json
     */
    public function getConfig()
    {
        $this->init(false);
        return Helper::showJson(0, "", $this->resolveFeConfig());
    }

    /**
     * 分享/全局鉴权后的前端配置; legacy getConfig 与 uiConfig 共用
     */
    protected function resolveFeConfig(): array
    {
        $params = $this->requestParams;
        if (!empty($params['shareKey'])) {
            // 接口分享
            $shareData = (new ApiShare())->checkShareAuth($this->config, $params);
            if (!empty($shareData['appKeys'])) {
                return ConfigProvider::getFeConfig($shareData['appKeys']);
            }
            return ConfigProvider::getFeConfig();
        }
        (new Auth($this->config))->checkAuth($params);
        return ConfigProvider::getFeConfig();
    }

    /**
     * 验证密码
     */
    public function verifyAuth()
    {
        $this->init();
        $config = $this->config;
        $params = $this->requestParams;
        if (empty($params['password'])) {
            throw new ErrorException("password not found");
        }
        $appKey = !empty($params['appKey']) ? $params['appKey'] : "";
        if (!empty($params['shareKey'])) {
            // 接口分享
            $shareData = (new ApiShare())->getShareDetailByKey($params['shareKey']);
            // 缓存的 password 为 md5(明文),前端提交的也是 md5(明文),直接比对摘要
            if (!empty($shareData['password']) && hash_equals($shareData['password'], (string)$params['password'])) {
                $hasAuth = (new Auth($config))->createToken($params['password']);
            } else {
                throw new ErrorException("password error");
            }
        } else {
            if (!$appKey && !(!empty($config['auth']) && $config['auth']['enable'])) {
                throw new ErrorException("password error");
            }
            $hasAuth = (new Auth($config))->verifyAuth($params['password'], $appKey);
        }
        $res = [
            "token" => $hasAuth
        ];
        return Helper::showJson(0, "", $res);
    }

    /**
     * 获取api文档菜单
     */
    public function getApiMenus()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        if (empty($params['appKey'])) {
            throw new ErrorException("appkey not found");
        }
        $appKey = $params['appKey'];
        $shareData = $this->checkAuth();
        $currentAppConfig = Helper::getCurrentAppConfig($appKey);
        $currentApp = $currentAppConfig['appConfig'];
        $apiData = $this->getApiMenusByAppKey($appKey);
        $groups = !empty($currentApp['groups']) ? $currentApp['groups'] : [];
        if (!empty($params['shareKey']) && $shareData['type'] == 'api') {
            $apiData['data'] = Helper::filterTreeNodesByKeys($apiData['data'], $shareData['apiKeys'], 'menuKey');
        }
        $json = [
            'data' => $apiData['data'],
            'app' => $currentApp,
            'groups' => $groups,
            'tags' => $apiData['tags'],
        ];
        return Helper::showJson(0, "", $json);
    }

    protected function getApiMenusByAppKey($appKey)
    {
        $config = $this->config;
        if (!empty($config['cache']) && $config['cache']['enable']) {
            $cacheKey = Helper::getCacheKey('apiMenu_v2', $appKey, $this->lang);
            $cacheData = (new Cache())->get($cacheKey);
            if ($cacheData && empty($this->requestParams['reload'])) {
                $apiData = $cacheData;
            } else {
                // 生成数据并缓存
                $apiData = (new ParseApiMenus($config))->renderApiMenus($appKey);
                (new Cache())->set($cacheKey, $apiData);
            }
        } else {
            // 生成数据
            $apiData = (new ParseApiMenus($config))->renderApiMenus($appKey);
        }
        return $apiData;
    }


    /**
     * 获取所有api文档菜单
     */
    public function getAllApiMenus()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        $cache = null;
        // v2: v1 缓存里可能存过应用密码明文,换 key 强制失效
        $cacheKey = Helper::getCacheKey('allApiMenus_v2', 'all', $this->lang);
        if (!empty($config['cache']) && $config['cache']['enable']) {
            $cache = new Cache();
            $cacheData = $cache->get($cacheKey);
            if ($cacheData !== null && empty($params['reload'])) {
                return Helper::showJson(0, "", $cacheData);
            }
        }
        // true: 移除应用密码,导出数据里绝不能出现明文密码
        $configApps = Helper::handleAppsConfig($config['apps'], true, $config);
        $data = ApiShare::getAppShareApis($config, $configApps, "", [], false);
        if ($cache !== null) {
            $cache->set($cacheKey, $data);
        }
        return Helper::showJson(0, "", $data);
    }

    /**
     * 获取接口明细
     * @return array
     */
    public function getApiDetail()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        if (empty($params['path'])) {
            throw new ErrorException("path not found");
        }
        $appKey = !empty($params['appKey']) ? $params['appKey'] : "";
        $apiKey = urldecode($params['path']);
        $this->assertShareApiAllowed($this->checkAuth(), $apiKey);
        if (!empty($config['cache']) && $config['cache']['enable']) {
            // 清洗 path 防止 ../ 等穿越写缓存文件
            $cachePath = str_replace(['/', '\\', '..'], '', (string)$params['path']);
            $cacheKey = Helper::getCacheKey('apiDetail_v2', $appKey, $this->lang, $cachePath);
            $cacheData = (new Cache())->get($cacheKey);
            if ($cacheData && empty($params['reload']) && ParseProtocol::cacheFresh($cacheData)) {
                $res = $cacheData;
            } else {
                // 生成数据并缓存
                $res = (new ParseApiDetail($config))->renderApiDetail($appKey, $apiKey);
                (new Cache())->set($cacheKey, $res);
            }
        } else {
            // 生成数据
            $res = (new ParseApiDetail($config))->renderApiDetail($appKey, $apiKey);
        }
        // 指纹只用于缓存校验,不向前端泄漏服务器路径
        unset($res['_definitionFingerprints']);
        $res['appKey'] = $appKey;
        return Helper::showJson(0, "", $res);
    }


    /**
     * 获取md菜单
     * @return array
     */
    public function getMdMenus()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        $appKey = "";
        if (!empty($params['appKey'])) {
            // 获取指定应用
            $appKey = $params['appKey'];
        }
        $this->checkAuth();
        $docs = (new ParseMarkdown($config))->getDocsMenu($appKey, $this->lang);
        return Helper::showJson(0, "", $docs);

    }

    /**
     * 获取md文档内容
     * @return \think\response\Json
     */
    public function getMdDetail()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        $this->checkAuth();
        try {
            if (empty($params['path'])) {
                throw new ErrorException("mdPath not found");
            }
            if (empty($params['appKey'])) {
                throw new ErrorException("appkey not found");
            }

            $path = urldecode($params['path']);
            $content = (new ParseMarkdown($config))->getContent($params['appKey'], $path, $this->lang);
            $res = [
                'content' => $content,
            ];
            return Helper::showJson(0, "", $res);

        } catch (ErrorException $e) {
            return Helper::showJson($e->getCode(), $e->getMessage());
        }
    }


    /**
     * 创建代码生成器
     * @return array
     */
    public function createGenerator()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        $res = (new generator\Index($config))->create($params);
        return Helper::showJson(0, "", $res);
    }

    /**
     * 删除所有接口缓存
     * @return array
     */
    public function cancelAllCache()
    {
        $this->init(true);
        $config = $this->config;
        $path = APIDOC_STORAGE_PATH . $config['cache']['folder']. '/apis';
        $res = DirAndFile::deleteDir($path);
        return Helper::showJson(0, "", $path);
    }

    /**
     * 生成所有接口缓存
     * @return array
     */
    public function createAllCache()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        $apps = Helper::getAllApps($config['apps']);
        $cache = new Cache();
        DirAndFile::deleteDir(APIDOC_STORAGE_PATH . $config['cache']['folder'] . '/' . 'apis');
        if (!empty($apps) && count($apps)) {
            try {
                foreach ($apps as $app) {
                    // 缓存菜单
                    $appKey = $app['appKey'];
                    $controllerData = (new ParseApiMenus($config))->renderApiMenus($appKey);
                    if (!empty($controllerData['data']) && count($controllerData['data'])) {
                        foreach ($controllerData['data'] as $controller) {
                            if (!empty($controller['children']) && count($controller['children'])) {
                                foreach ($controller['children'] as $item) {
                                    if (!empty($item['url']) && !empty($item['menuKey'])) {
                                        $apiDetail = (new ParseApiDetail($config))->renderApiDetail($appKey, urldecode($item['menuKey']));
                                        $apiDetailCacheKey = Helper::getCacheKey('apiDetail_v2', $appKey, $this->lang, $item['menuKey']);
                                        $cache->set($apiDetailCacheKey, $apiDetail);
                                    }
                                }
                            }
                        }
                    }
                    $cacheKey = Helper::getCacheKey('apiMenu_v2', $appKey, $this->lang);
                    $cache->set($cacheKey, $controllerData);
                }
            } catch (\ReflectionException $e) {
                DirAndFile::deleteDir(APIDOC_STORAGE_PATH . $config['cache']['folder'] . '/' . 'apis');
                throw new ErrorException($e->getMessage());
            }
        }
        return Helper::showJson(0, "", true);
    }

    /**
     * 生成代码模板内容
     * @return array
     */
    public function renderCodeTemplate()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        $code = (new ParseCodeTemplate($config))->renderCode($params);
        return Helper::showJson(0, "", [
            'code' => $code
        ]);

    }


    /**
     * 添加接口分享
     * @return array
     */
    public function addApiShare()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        if (empty($params['name'])) {
            throw new ErrorException('field not found', ['field' => 'name']);
        }
        if (empty($params['type'])) {
            throw new ErrorException('field not found', ['field' => 'type']);
        }
        // 未知 type 会让按 apiKeys 过滤失效,必须 fail closed
        if (!in_array($params['type'], ['all', 'app', 'api'], true)) {
            throw new ErrorException('field not found', ['field' => 'type']);
        }
        if ($params['type'] == 'app' && (!is_array($params['appKeys'] ?? null) || empty($params['appKeys']))) {
            throw new ErrorException('field not found', ['field' => 'appKeys']);
        }
        if ($params['type'] == 'api' && (!is_array($params['apiKeys'] ?? null) || empty($params['apiKeys']))) {
            throw new ErrorException('field not found', ['field' => 'apiKeys']);
        }
        $res = (new ApiShare())->addApiShare($params);
        return Helper::showJson(0, "", $res);
    }

    /**
     * 获取接口分享分页列表
     * @return array
     */
    public function getApiShareList()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        $pageIndex = !empty($params['pageIndex']) ? $params['pageIndex'] : 1;
        $pageSize = 5;
        $res = (new ApiShare())->getSharePageList($config, $pageIndex, $pageSize);
        return Helper::showJson(0, "", $res);
    }

    /**
     * 获取接口分享记录明细
     * @return array
     */
    public function getApiShareDetail()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        if (empty($params['key'])) {
            throw new ErrorException('field not found', ['field' => 'key']);
        }
        $cacheData = (new ApiShare())->getShareDetailByKey($params['key']);
        unset($cacheData['password']); // 不向前端暴露分享密码明文
        return Helper::showJson(0, "", $cacheData);
    }

    /**
     * 删除接口分享记录
     * @return array
     */
    public function deleteApiShare()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        if (empty($params['key'])) {
            throw new ErrorException('field not found', ['field' => 'key']);
        }
        $cacheKey = ApiShare::getShareCacheKey($params['key']);
        $res = (new Cache())->delete($cacheKey);
        return Helper::showJson(0, "", $res);
    }

    /**
     * 处理接口分享操作
     * @return array
     */
    public function handleApiShareAction()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        if (empty($params['key'])) {
            throw new ErrorException('field not found', ['field' => 'key']);
        }
        if (!isset($params['index'])) {
            throw new ErrorException('field not found', ['field' => 'index']);
        }
        $res = (new ApiShare())->handleApiShareAction($config, $params['key'], $params['index']);
        return Helper::showJson(0, "", $res);
    }

    /**
     * 调试中继:单次请求
     */
    public function debugSend()
    {
        $this->init(false);
        $params = $this->requestParams;
        $shareData = $this->checkAuth();
        $this->assertShareApiAllowed($shareData, urldecode((string)($params['path'] ?? '')));
        $denied = $this->debugAuthDenied($params);
        if ($denied !== null) {
            return $denied;
        }
        $res = (new DebugProxy($this->config))->send(
            (string)($params['appKey'] ?? ''),
            // 与 getApiDetail 一致:客户端可传编码后的 path
            urldecode((string)($params['path'] ?? '')),
            (array)($params['input'] ?? []),
            $this->debugRateKey($params)
        );
        return Helper::showJson(0, "", $res);
    }

    /**
     * 调试中继:建立流式会话
     */
    public function debugStart()
    {
        $this->init(false);
        $params = $this->requestParams;
        $shareData = $this->checkAuth();
        $this->assertShareApiAllowed($shareData, urldecode((string)($params['path'] ?? '')));
        $denied = $this->debugAuthDenied($params);
        if ($denied !== null) {
            return $denied;
        }
        $res = (new DebugProxy($this->config))->start(
            (string)($params['appKey'] ?? ''),
            // 与 getApiDetail 一致:客户端可传编码后的 path
            urldecode((string)($params['path'] ?? '')),
            (array)($params['input'] ?? []),
            $this->debugRateKey($params)
        );
        return Helper::showJson(0, "", $res);
    }

    /**
     * 调试中继:拉取流式消息
     */
    public function debugPoll()
    {
        $this->init(false);
        $params = $this->requestParams;
        $shareData = $this->checkAuth();
        $this->assertShareApiAllowed($shareData, $this->debugSessionApiKey($params));
        $denied = $this->debugAuthDenied($params);
        if ($denied !== null) {
            return $denied;
        }
        $res = (new DebugProxy($this->config))->poll(
            (string)($params['sessionId'] ?? ''),
            $this->debugRateKey($params)
        );
        return Helper::showJson(0, "", $res);
    }

    /**
     * 调试中继:结束流式会话
     */
    public function debugStop()
    {
        $this->init(false);
        $params = $this->requestParams;
        $shareData = $this->checkAuth();
        $this->assertShareApiAllowed($shareData, $this->debugSessionApiKey($params));
        $denied = $this->debugAuthDenied($params);
        if ($denied !== null) {
            return $denied;
        }
        $res = (new DebugProxy($this->config))->stop(
            (string)($params['sessionId'] ?? ''),
            $this->debugRateKey($params)
        );
        return Helper::showJson(0, "", $res);
    }

    /**
     * poll/stop 没有 path,用会话记录里的 apiKey 校验分享范围
     */
    protected function debugSessionApiKey(array $params): string
    {
        $session = DebugSession::get((string)($params['sessionId'] ?? ''));
        return (string)($session['apiKey'] ?? '');
    }

    /**
     * 中继鉴权开关:配置缺失时默认要求 token/shareKey
     * 仅凭"有 token"不够:全局 auth 与应用密码都未配置时该 token 不会被校验,视为匿名
     * @return array|null 拒绝响应;放行返回 null
     */
    protected function debugAuthDenied(array $params): ?array
    {
        $requireAuth = $this->config['protocols']['debug']['relay_require_auth'] ?? true;
        if (!$requireAuth) {
            return null;
        }
        if (empty($params['token']) && empty($params['shareKey'])) {
            return Helper::showJson(0, "", ['ok' => false, 'error' => 'authentication required']);
        }
        if (empty($params['shareKey'])) {
            $authValidated = !empty($this->config['auth']['enable']);
            if (!$authValidated) {
                try {
                    $appKey = (string)($params['appKey'] ?? '');
                    $app = Helper::getCurrentAppConfig($appKey)['appConfig'] ?? [];
                    $authValidated = !empty($app['password']);
                } catch (\Throwable $e) {
                    $authValidated = false;
                }
            }
            if (!$authValidated) {
                return Helper::showJson(0, "", [
                    'ok' => false,
                    'error' => 'relay requires auth: enable auth or configure an app password',
                ]);
            }
        }
        return null;
    }

    /**
     * 限流标识:按 checkAuth() 实际校验过的凭证分桶
     * shareKey 优先;token 仅在 auth 开启或应用配置了密码时有效
     */
    protected function debugRateKey(array $params): string
    {
        $shareKey = (string)($params['shareKey'] ?? '');
        if ($shareKey !== '') {
            return 'share:' . $shareKey;
        }
        $token = (string)($params['token'] ?? '');
        if ($token === '') {
            return 'anonymous';
        }
        $tokenValidated = !empty($this->config['auth']['enable']);
        if (!$tokenValidated) {
            try {
                $appKey = (string)($params['appKey'] ?? '');
                $app = Helper::getCurrentAppConfig($appKey)['appConfig'] ?? [];
                $tokenValidated = !empty($app['password']);
            } catch (\Throwable $e) {
                $tokenValidated = false;
            }
        }
        return $tokenValidated ? 'token:' . $token : 'anonymous';
    }

    // ---------- UIC-1 aliases ----------

    public function uiCapabilities()
    {
        $this->init(false);
        return Helper::showJson(0, "", UiCapabilities::fromConfig($this->config));
    }

    public function uiConfig()
    {
        $this->init(false);
        $config = $this->resolveFeConfig();
        $config['capabilities'] = UiCapabilities::fromConfig($this->config);
        return Helper::showJson(0, "", $config);
    }

    public function uiMenus()
    {
        return $this->getApiMenus();
    }

    public function uiDetail()
    {
        return $this->getApiDetail();
    }

    public function uiDocMenus()
    {
        return $this->getMdMenus();
    }

    public function uiDocDetail()
    {
        return $this->getMdDetail();
    }

    public function uiDebugSend()
    {
        return $this->debugSend();
    }

    public function uiDebugStart()
    {
        return $this->debugStart();
    }

    public function uiDebugPoll()
    {
        return $this->debugPoll();
    }

    public function uiDebugStop()
    {
        return $this->debugStop();
    }

    /**
     * UIC-1 password login: plaintext over HTTPS, Bearer token afterwards.
     */
    public function uiVerifyAuth()
    {
        $this->init();
        $params = $this->requestParams;
        $password = (string)($params['password'] ?? '');
        if ($password === '') {
            throw new ErrorException("password not found");
        }
        $appKey = (string)($params['appKey'] ?? '');
        if (!empty($params['shareKey'])) {
            $shareData = (new ApiShare())->getShareDetailByKey((string)$params['shareKey']);
            if (empty($shareData)) {
                throw new ErrorException("share not exists");
            }
            if (empty($shareData['password'])) {
                throw new ErrorException("password error");
            }
            // Share passwords are stored as md5(plaintext) for legacy compatibility.
            if (!hash_equals((string)$shareData['password'], md5($password))) {
                throw new ErrorException("password error");
            }
            // 缓存里已是 md5(明文),后续 ApiShare::checkShareAuth 也用它校验,
            // 因此 token 必须用摘要生成,不能用提交的明文。
            $token = (new Auth($this->config))->createToken((string)$shareData['password']);
        } else {
            $token = (new Auth($this->config))->verifyPlain($password, $appKey);
        }
        return Helper::showJson(0, "", ['token' => $token]);
    }

    /**
     * 导出详情叶子节点必须稳定的字段;缺省时补空,避免 UI 导出器按字段取值时崩溃
     */
    private const EXPORT_DETAIL_DEFAULTS = [
        'name' => '',
        'title' => '',
        'menuKey' => '',
        'method' => '',
        'url' => '',
        'desc' => '',
        'header' => [],
        'query' => [],
        'routeParam' => [],
        'param' => [],
        'responseSuccess' => [],
        'responseError' => [],
        'responseStatus' => [],
        'protocol' => [],
        'channel' => [],
        'definitions' => [],
        'validation' => ['ok' => true, 'errors' => [], 'warnings' => []],
    ];

    public function uiAddApiShare()
    {
        $this->init(false);
        $this->requireAdmin();
        return $this->addApiShare();
    }

    public function uiGetApiShareList()
    {
        $this->init(false);
        $this->requireAdmin();
        return $this->getApiShareList();
    }

    public function uiGetApiShareDetail()
    {
        $this->init(false);
        $this->requireAdmin();
        $params = $this->requestParams;
        if (empty($params['key'])) {
            throw new ErrorException('field not found', ['field' => 'key']);
        }
        $data = (new ApiShare())->getShareDetailByKey($params['key']);
        unset($data['password']);
        // ShareRecord 契约要求 key;legacy 详情响应里没有
        $data['key'] = (string)$params['key'];
        return Helper::showJson(0, "", $data);
    }

    public function uiDeleteApiShare()
    {
        $this->init(false);
        $this->requireAdmin();
        return $this->deleteApiShare();
    }

    public function uiHandleApiShareAction()
    {
        $this->init(false);
        $this->requireAdmin();
        // UIC-1: 分享动作只走声明式 {action,params},绝不执行 legacy PHP 回调
        return Helper::showJson(0, "", false);
    }

    public function uiCacheClear()
    {
        $this->init(false);
        $this->requireAdmin();
        $this->cancelAllCache();
        return Helper::showJson(0, "", ['ok' => true, 'mode' => 'server']);
    }

    public function uiCacheWarm()
    {
        $this->init(false);
        $this->requireAdmin();
        $this->createAllCache();
        return Helper::showJson(0, "", ['ok' => true, 'mode' => 'server', 'message' => 'cache rebuilt']);
    }

    /**
     * UIC-1 生成器别名。PHP legacy 生成器会执行 middleware、写源码、可能建表,
     * 因此 capabilities 声明 generator.sideEffects=true,UI 保持 legacy 的成功提示语义,
     * 不把它当作无副作用的声明式预览。
     */
    public function uiGenerator()
    {
        $this->init(false);
        $this->requireAdmin();
        return $this->createGenerator();
    }

    public function uiRenderCodeTemplate()
    {
        $this->init(false);
        $this->requireAdmin();
        return $this->renderCodeTemplate();
    }

    /**
     * 批量导出:直接复用菜单/详情解析,不向自身发 HTTP 请求。
     * key 为分享记录 key(属主鉴权后按分享范围过滤),appKey 限定单个应用,都为空时导出全部应用。
     */
    public function uiExportAllData()
    {
        $this->init(false);
        $params = $this->requestParams;
        $config = $this->config;
        $globalAuth = !empty($config['auth']['enable']);
        if (!empty($params['key'])) {
            // key 是属主导出(range 选择);只认全局 token,应用密码 token 不能读其他分享
            $this->requireAdmin();
            $shareData = (new ApiShare())->getShareDetailByKey((string)$params['key']);
            if (!$globalAuth && !empty($shareData['password'])) {
                // 全局鉴权关闭时管理端是开放的,带密码的分享仍须分享 token
                $token = (string)($params['token'] ?? '');
                if ($token === '' || !(new Auth($config))->checkToken($token, (string)$shareData['password'])) {
                    throw new ErrorException('token error');
                }
            }
            unset($shareData['password']);
        } else {
            $shareData = $this->checkAuth();
        }
        $appKey = (string)($params['appKey'] ?? '');
        $filterAppKeys = [];
        if (is_array($shareData)) {
            if ($appKey !== '') {
                if (!empty($shareData['appKeys']) && !in_array($appKey, $shareData['appKeys'], true)) {
                    throw new ErrorException('share not exists');
                }
                $filterAppKeys = [$appKey];
            } else {
                $filterAppKeys = !empty($shareData['appKeys']) ? $shareData['appKeys'] : [];
            }
        } elseif ($appKey !== '') {
            $filterAppKeys = [$appKey];
        }
        // true: 导出树不含应用密码明文(protected app 只留 hasPassword)
        $apps = !empty($config['apps']) ? Helper::handleAppsConfig($config['apps'], true, $config) : [];
        $apiData = ApiShare::getAppShareApis($config, $apps, "", $filterAppKeys, true);
        if (is_array($shareData) && ($shareData['type'] ?? '') === 'api') {
            $apiData = Helper::filterTreeNodesByKeys($apiData, (array)($shareData['apiKeys'] ?? []), 'menuKey');
        }
        if ($shareData === null && $appKey === '' && !$globalAuth) {
            // 全局鉴权关闭且匿名导出全部:只导出无密码应用,应用密码不能被绕过
            $apiData = $this->stripProtectedApps($apiData);
        }
        return Helper::showJson(0, "", ['apiData' => $this->normalizeExportTree($apiData)]);
    }

    /**
     * 移除带密码的应用节点(handleAppsConfig(..., true) 会给它们标 hasPassword)
     */
    private function stripProtectedApps(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !empty($node['hasPassword'])) {
                continue;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->stripProtectedApps($node['children']);
            }
            $result[] = $node;
        }
        return $result;
    }

    /**
     * 给导出叶子补齐契约字段;app/分组节点原样保留(UI 递归取带 appKey 的叶子 app)
     */
    private function normalizeExportTree(array $nodes, string $parentAppKey = ''): array
    {
        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }
            $appKey = !empty($node['appKey']) ? (string)$node['appKey'] : $parentAppKey;
            if (!empty($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->normalizeExportTree($node['children'], $appKey);
                continue;
            }
            if (!isset($node['url']) && !isset($node['method'])) {
                // 空应用/分组节点,不是接口叶子
                continue;
            }
            foreach (self::EXPORT_DETAIL_DEFAULTS as $field => $default) {
                if (!array_key_exists($field, $node)) {
                    $node[$field] = $default;
                }
            }
            $node['appKey'] = $appKey;
        }
        unset($node);
        return $nodes;
    }

}
