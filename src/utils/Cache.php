<?php

declare (strict_types = 1);

namespace erikwang2013\apidoc\utils;

/**
 * 文件缓存类
 */
class Cache
{
    /**
     * 配置参数
     * @var array
     */
    protected $options = [
        'expire'        => 0,
        'cache_subdir'  => true,
        'prefix'        => '',
        'path'          => '',
        'hash_type'     => 'md5',
        'data_compress' => false,
        'serialize'     => [],
    ];

    /**
     * 架构函数
     * @param array $options 参数
     */
    public function __construct( array $options = [])
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        if (empty($this->options['path'])) {
            // 默认目录与配置的 cache.folder 保持一致(默认 apidoc),
            // 避免写入用默认目录、而 ApiShare/Controller 按 folder 读路径导致分叉
            $folder = 'apidoc';
            try {
                $config = ConfigProvider::get();
                if (!empty($config['cache']['folder'])) {
                    $folder = trim((string)$config['cache']['folder'], "/\\");
                }
            } catch (\Throwable $e) {
                // 配置未初始化时使用默认目录
            }
            $this->options['path'] = APIDOC_STORAGE_PATH .'/'. $folder;
        }

        if (substr($this->options['path'], -1) != DIRECTORY_SEPARATOR) {
            $this->options['path'] .= DIRECTORY_SEPARATOR;
        }
    }

    /**
     * 取得变量的存储文件名
     * @access public
     * @param string $name 缓存变量名
     * @return string
     */
    public function getCacheKey(string $name): string
    {
        // 清洗路径:反斜杠归一为 /,按段丢弃空段与含 .. 的段
        // 保留子目录结构(如 share/apiShare_xxx),同时防止缓存键穿越缓存目录写入任意文件
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $name)) as $segment) {
            if ($segment === '' || strpos($segment, '..') !== false) {
                continue;
            }
            $segments[] = $segment;
        }
        $name = implode('/', $segments);
        $name = $name."_".hash($this->options['hash_type'], $name);

        if ($this->options['prefix']) {
            $name = $this->options['prefix'] . DIRECTORY_SEPARATOR . $name;
        }

        return $this->options['path'] . $name . '.php';
    }

    /**
     * 序列化数据
     * @access protected
     * @param mixed $data 缓存数据
     * @return string
     */
    protected function serialize($data): string
    {
        if (is_numeric($data)) {
            return (string) $data;
        }

        $serialize = $this->options['serialize'][0] ?? "serialize";

        return $serialize($data);
    }


    /**
     * 反序列化数据
     * @access protected
     * @param string $data 缓存数据
     * @return mixed
     */
    protected function unserialize($data)
    {
        if (is_numeric($data)) {
            return $data;
        }

        $unserialize = $this->options['serialize'][1] ?? "unserialize";

        if ($unserialize === 'unserialize') {
            return unserialize($data, ['allowed_classes' => false]);
        }

        return $unserialize($data);
    }


    /**
     * 获取有效期
     * @access protected
     * @param integer|DateTimeInterface|DateInterval $expire 有效期
     * @return int
     */
    protected function getExpireTime($expire): int
    {
        return (int) $expire;
    }

    /**
     * 获取缓存数据
     * @param string $name 缓存标识名
     * @return array|null
     */
    protected function getRaw(string $name)
    {
        $filename = $this->getCacheKey($name);

        if (!is_file($filename)) {
            return;
        }

        $content = @file_get_contents($filename);

        if (false !== $content) {
            $expire = (int) substr($content, 8, 12);
            $createTime = filemtime($filename);
            if (0 != $expire && time() - $expire > $createTime) {
                //缓存过期删除缓存文件
                DirAndFile::unlink($filename);
                return;
            }

            $content = substr($content, 32);

            if ($this->options['data_compress'] && function_exists('gzcompress')) {
                //启用数据压缩
                $content = gzuncompress($content);
            }

            return is_string($content) ? ['content' => $content, 'expire' => $expire,'create_time'=>$createTime] : null;
        }
    }

    /**
     * 读取缓存
     * @access public
     * @param string $name    缓存变量名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get($name, $default = null)
    {
        $raw = $this->getRaw($name);

        return is_null($raw) ? $default : $this->unserialize($raw['content']);
    }

    /**
     * 写入缓存
     * @access public
     * @param string        $name   缓存变量名
     * @param mixed         $value  存储数据
     * @param int|\DateTime $expire 有效时间 0为永久
     * @return bool
     */
    public function set($name, $value, $expire = null): bool
    {
        if (is_null($expire)) {
            $expire = $this->options['expire'];
        }

        $expire   = $this->getExpireTime($expire);
        $filename = $this->getCacheKey($name);

        $dir = dirname($filename);

        if (!is_dir($dir)) {
            try {
                mkdir($dir, 0755, true);
            } catch (\Exception $e) {
                // 创建失败
            }
        }

        $data = $this->serialize($value);

        if ($this->options['data_compress'] && function_exists('gzcompress')) {
            //数据压缩
            $data = gzcompress($data, 3);
        }

        $data   = "<?php\n//" . sprintf('%012d', $expire) . "\n exit();?>\n" . $data;

        // 先写临时文件再 rename 原子替换,避免并发下读到写了一半的文件
        $tmpFile = $filename . '.tmp';
        $result  = @file_put_contents($tmpFile, $data);

        if ($result !== false && @rename($tmpFile, $filename)) {
            clearstatcache();
            return true;
        }

        DirAndFile::unlink($tmpFile);

        return false;
    }



    /**
     * 删除缓存
     * @access public
     * @param string $name 缓存变量名
     * @return bool
     */
    public function delete($name): bool
    {
        return DirAndFile::unlink($this->getCacheKey($name));
    }

}
