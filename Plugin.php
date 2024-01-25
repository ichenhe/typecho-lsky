<?php

namespace TypechoPlugin\LskyProPlus;

use IXR\Exception;
use Typecho\Date;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Typecho\Common;
use Widget\Base\Options;
use Widget\Upload;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;


/**
 * 将开源图床 LskyPro v2 整合到 Typecho。
 *
 * @package LskyProPlus
 * @author Chenhe
 * @version 1.0.0
 * @link https://chenhe.me
 */
class Plugin implements PluginInterface
{
    const PLUGIN_NAME = 'LskyProPlus'; //插件名称

    private const CONFIG_LSKY_URL = 'lsky_url';
    private const CONFIG_LSKY_TOKEN = 'lsky_token';
    private const CONFIG_LSKY_STRATEGY_ID = "lsky_strategy_id";

    public static function activate(): void
    {
        \Typecho\Plugin::factory('Widget_Upload')->uploadHandle = array(__CLASS__, 'uploadHandle');
        \Typecho\Plugin::factory('Widget_Upload')->modifyHandle = array(__CLASS__, 'modifyHandle');
        \Typecho\Plugin::factory('Widget_Upload')->deleteHandle = array(__CLASS__, 'deleteHandle');
        \Typecho\Plugin::factory('Widget_Upload')->attachmentHandle = array(__CLASS__, 'attachmentHandle');
    }

    public static function deactivate()
    {

    }

    public static function config(Form $form): void
    {
        $html = <<<HTML
<p>
将图片附件托管到 <a target="_blank" href="https://docs.lsky.pro/docs/free/v2/">Lsky Pro v2</a> 开源图床（不支持旧版 API）。
</p>

<p>
作者：<a target="_blank" href="https://chenhe.me/">晨鹤</a>；
感谢：<a target="_blank" href="https://qzone.work/codes/725.html">莫名</a>
</p>
HTML;
        $desc = new Text('desc', NULL, '', '插件介绍', $html);
        $form->addInput($desc);

        $lskyUrl = new Text(self::CONFIG_LSKY_URL, NULL, '', 'Lsky Pro 地址', 'Lsky Pro 图床地址，必须以 <code>http(s)://</code> 开头，无需添加 api 后缀。');
        $form->addInput($lskyUrl);

        $token = new Text(self::CONFIG_LSKY_TOKEN, NULL, '', 'API Token', '格式类似 <code>Bearer  x|xxxxxxx</code>。留空则以游客身份上传。');
        $form->addInput($token);

        $token = new Text(self::CONFIG_LSKY_STRATEGY_ID, NULL, '', '储存策略', '可选。用于指定 Lsky Pro 储存策略 ID，对应 <code>strategy_id</code> 参数。只能输入整数，留空为默认。');
        $form->addInput($token);

        echo '<script>window.onload = function(){document.getElementsByName("desc")[0].type = "hidden";}</script>';
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function uploadHandle($file): bool|array
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);
        if (!Upload::checkFileType($ext)) {
            return false;
        }

        // Only upload image when tmp_name exists
        if (!empty($file['tmp_name']) && self::isImage($ext)) {
            return self::uploadImg($file, $ext);
        }

        // Use typecho default upload
        return self::typechoUploadHandle($file, $ext);
    }

    /**
     * Typecho 的上传文件处理函数，删除了插件调用。
     *
     * Copied from: https://github.com/typecho/typecho/blob/206880ba714d80bfb0638aeacb49c58b8fb1b327/var/Widget/Upload.php#L353
     *
     * @param array $file 上传的文件
     * @param string $ext
     * @return array|false
     */
    public static function typechoUploadHandle(array $file, string $ext): array|false
    {
        $date = new Date();
        $path = Common::url(
                defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : Upload::UPLOAD_DIR,
                defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__
            ) . '/' . $date->year . '/' . $date->month;

        //创建上传目录
        if (!is_dir($path)) {
            if (!self::makeUploadDir($path)) {
                return false;
            }
        }

        //获取文件名
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $path . '/' . $fileName;

        if (isset($file['tmp_name'])) {
            //移动上传文件
            if (!@move_uploaded_file($file['tmp_name'], $path)) {
                return false;
            }
        } elseif (isset($file['bytes'])) {
            //直接写入文件
            if (!file_put_contents($path, $file['bytes'])) {
                return false;
            }
        } elseif (isset($file['bits'])) {
            //直接写入文件
            if (!file_put_contents($path, $file['bits'])) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($path);
        }

        //返回相对存储路径
        return [
            'name' => $file['name'],
            'path' => (defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : Upload::UPLOAD_DIR)
                . '/' . $date->year . '/' . $date->month . '/' . $fileName,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => Common::mimeContentType($path)
        ];
    }

    /**
     * Typecho 的修改文件处理函数，删除了插件调用。
     *
     * Copied from: https://github.com/typecho/typecho/blob/206880ba714d80bfb0638aeacb49c58b8fb1b327/var/Widget/Upload.php#L169
     *
     * @param array $content
     * @param array $file
     * @return array|false
     */
    public static function typechoModifyHandle(array $content, array $file): array|false
    {
        $path = Common::url(
            $content['attachment']->path,
            defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__
        );
        $dir = dirname($path);

        //创建上传目录
        if (!is_dir($dir)) {
            if (!self::makeUploadDir($dir)) {
                return false;
            }
        }

        if (isset($file['tmp_name'])) {
            @unlink($path);

            //移动上传文件
            if (!@move_uploaded_file($file['tmp_name'], $path)) {
                return false;
            }
        } elseif (isset($file['bytes'])) {
            @unlink($path);

            //直接写入文件
            if (!file_put_contents($path, $file['bytes'])) {
                return false;
            }
        } elseif (isset($file['bits'])) {
            @unlink($path);

            //直接写入文件
            if (!file_put_contents($path, $file['bits'])) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($path);
        }

        //返回相对存储路径
        return [
            'name' => $content['attachment']->name,
            'path' => $content['attachment']->path,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        ];
    }


    public static function deleteHandle(array $content): bool
    {
        $ext = self::getSafeName($content['title']);
        $imageId = $content['attachment']->img_id;

        // Only delete image on LskyPro if id exists
        if (!empty($imageId) && self::isImage($ext)) {
            return self::deleteImg($imageId);
        }

        return @unlink(__TYPECHO_ROOT_DIR__ . '/' . $content['attachment']->path);
    }

    /**
     * 修改文件处理函数,如果需要实现自己的文件哈希或者特殊的文件系统,请在options表里把modifyHandle改成自己的函数
     *
     * @param array $content 老文件
     * @param array $file 新上传的文件
     */
    public static function modifyHandle(array $content, array $file): bool|array
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);
        if ($content['attachment']->type != $ext) {
            return false;
        }


        $oldImageId = $content['attachment']->img_id;
        if (!empty($oldImageId) && self::isImage($ext)) {
            self::deleteImg($oldImageId);

            // Only upload image when tmp_name exists
            if (!empty($file['tmp_name']) && self::isImage($ext)) {
                return self::uploadImg($file, $ext);
            } else {
                return self::typechoModifyHandle($content, $file);
            }
        }
        return self::typechoModifyHandle($content, $file);
    }

    public static function attachmentHandle(array $content): string
    {
        $ext = self::getSafeName($content['title']);
        if (self::isImage($ext)) {
            return $content['attachment']->path ?? '';
        }

        // typecho default
        // Copied from: https://github.com/typecho/typecho/blob/206880ba714d80bfb0638aeacb49c58b8fb1b327/var/Widget/Upload.php#L50
        $options = Options::alloc();
        return Common::url(
            $content['attachment']->path,
            defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : $options->siteUrl
        );
    }

    /**
     * 获取安全的文件名。
     *
     * Copied from: https://github.com/typecho/typecho/blob/v1.2.1/var/Widget/Upload.php
     *
     * @param string $name
     * @return string
     */
    private static function getSafeName(string &$name): string
    {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * 创建上传路径
     *
     * Copied from: https://github.com/typecho/typecho/blob/206880ba714d80bfb0638aeacb49c58b8fb1b327/var/Widget/Upload.php#L261C12-L261C12
     *
     * @param string $path 路径
     * @return boolean
     */
    private static function makeUploadDir(string $path): bool
    {
        $path = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last, 0755)) {
            return false;
        }

        return self::makeUploadDir($path);
    }

    private static function isImage(string $ext): bool
    {
        $img_ext_arr = array('heic', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'tiff', 'bmp');
        return in_array($ext, $img_ext_arr);
    }

    private static function uploadImg($file, $ext): bool|array
    {
        if (empty($file['tmp_name'])) {
            return false;
        }
        $imgFile = $file['tmp_name'] . '.' . $ext;
        // rename the uploaded file to make sure it has the correct ext name
        if (!rename($file['tmp_name'], $imgFile)) {
            return false;
        }

        $options = Helper::options()->plugin(self::PLUGIN_NAME);
        $lskyClient = new LskyproClient($options[self::CONFIG_LSKY_URL], $options[self::CONFIG_LSKY_TOKEN],
            $options[self::CONFIG_LSKY_STRATEGY_ID]);
        $res = $lskyClient->upload($imgFile);
        unlink($imgFile);

        if (empty($res['status'])) {
            return false;
        }

        return [
            'img_id' => $res['data']['key'],
            'name' => $res['data']['name'],
            'path' => $res['data']['links']['url'],
            'size' => $res['data']['size'],
            'type' => $ext,
            'mime' => $res['data']['mimetype']
        ];
    }

    private static function deleteImg(string $imageId): bool
    {
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
        $lskyClient = new LskyproClient($options[self::CONFIG_LSKY_URL], $options[self::CONFIG_LSKY_TOKEN],
            $options[self::CONFIG_LSKY_STRATEGY_ID]);
        $res = $lskyClient->delete($imageId);

        return !empty($res['status']);
    }

}