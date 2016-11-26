<?php
/**
 * View.php
 * @author for minify Revin Roman
 * @author for ClientScriptView MGHollander
 * @link https://rmrevin.ru
 */

/**
 * ClientScriptView class is an extension on Yii's View to register JavaScript and CSS inside a view.
 * It keeps your code hightlighted and readable by your IDE.
 *
 * I've modified the code to remove the script ans style tags using a regular expression, because it may occur
 * that other HTML tags are used within a JavaScript of Style string.
 *
 * Usage is simular to {@link View::registerJs} and {@link View::registerCss}
 * The <script> and <style> tags are required for highlighting and IDE functionallity and will be removed automatically.
 *
 * Example:
 *
 * <?php $this->beginJs(); ?>
 * <script>
 * // Write your JavaScript here.
 * </script>
 * <?php $this->endJs(); ?>
 *
 * <?php $this->beginJs(View::POS_HEAD, 'unique-id'); ?>
 * <script type="text/javascript">
 * // Write your JavaScript here.
 * </script>
 * <?php $this->endJs(); ?>
 *
 * <?php $this->beginCss(); ?>
 * <style>
 * // Write your CSS here.
 * </style>
 * <?php $this->endCss(); ?>
 *
 * <?php $this->beginCss(['media' => 'screen'], 'unique-id'); ?>
 * <style type="text/css">
 * // Write your CSS here.
 * </style>
 * <?php $this->endCss(); ?>
 */

namespace rmrevin\yii\minify;

use yii\base\Event;
use yii\helpers\FileHelper;
use yii\web\Response;

/**
 * Class View
 * @package rmrevin\yii\minify
 */
class View extends \yii\web\View
{
    //
    //  ClientScriptView
    //

    /**
     * @see {@link View::registerJs} key parameter
     * @var string
     */
    protected $key;

    /**
     * @see {@link View::registerJs} position parameter
     * @var integer
     */
    protected $position;

    /**
     * @see {@link View::registerCss} options array
     * @var array
     */
    protected $options;


    //
    //  Minify
    //

    /**
     * @var bool
     */
    public $enableMinify = true;

    /**
     * @var string filemtime or sha1
     */
    public $fileCheckAlgorithm = 'sha1';

    /**
     * @var bool
     */
    public $concatCss = true;

    /**
     * @var bool
     */
    public $minifyCss = true;

    /**
     * @var bool
     */
    public $concatJs = true;

    /**
     * @var bool
     */
    public $minifyJs = true;

    /**
     * @var bool
     */
    public $minifyOutput = false;

    /**
     * @var bool
     */
    public $removeComments = true;

    /**
     * @var string path alias to web base (in url)
     */
    public $web_path = '@web';

    /**
     * @var string path alias to web base (absolute)
     */
    public $base_path = '@webroot';

    /**
     * @var string path alias to save minify result
     */
    public $minify_path = '@webroot/minify';

    /**
     * @var array positions of js files to be minified
     */
    public $js_position = [self::POS_END, self::POS_HEAD];

    /**
     * @var bool|string charset forcibly assign, otherwise will use all of the files found charset
     */
    public $force_charset = false;

    /**
     * @var bool whether to change @import on content
     */
    public $expand_imports = true;

    /**
     * @var int
     */
    public $css_linebreak_pos = 2048;

    /**
     * @var int|bool chmod of minified file. If false chmod not set
     */
    public $file_mode = 0664;

    /**
     * @var array schemes that will be ignored during normalization url
     */
    public $schemas = ['//', 'http://', 'https://', 'ftp://'];

    /**
     * @deprecated
     * @var bool do I need to compress the result html page.
     */
    public $compress_output = false;

    /**
     * @var array options for compressing output result
     *   * extra - use more compact algorithm
     *   * no-comments - cut all the html comments
     */
    public $compress_options = ['extra' => true];

    /**
     * @var array
     */
    public $excludeBundles = [];

    /**
     * @throws \rmrevin\yii\minify\Exception
     */
    public function init()
    {
        parent::init();

        $minify_path = $this->minify_path = (string)\Yii::getAlias($this->minify_path);
        if (!file_exists($minify_path)) {
            FileHelper::createDirectory($minify_path);
        }

        if (!is_readable($minify_path)) {
            throw new Exception('Directory for compressed assets is not readable.');
        }

        if (!is_writable($minify_path)) {
            throw new Exception('Directory for compressed assets is not writable.');
        }

        if (true === $this->enableMinify && (true === $this->minifyOutput || true === $this->compress_output)) {
            \Yii::$app->response->on(Response::EVENT_BEFORE_SEND, function (Event $Event) {
                /** @var Response $Response */
                $Response = $Event->sender;

                if ($Response->format === Response::FORMAT_HTML) {
                    if (!empty($Response->data)) {
                        $Response->data = HtmlCompressor::compress($Response->data, $this->compress_options);
                    }

                    if (!empty($Response->content)) {
                        $Response->content = HtmlCompressor::compress($Response->content, $this->compress_options);
                    }
                }
            });
        }
    }

    /**
     * @inheritdoc
     */
    public function endBody()
    {
        $this->trigger(self::EVENT_END_BODY);
        echo self::PH_BODY_END;

        foreach (array_keys($this->assetBundles) as $bundle) {
            if (!in_array($bundle, $this->excludeBundles, true)) {
                $this->registerAssetFiles($bundle);
            }
        }

        if (true === $this->enableMinify) {
            (new components\CSS($this))->export();
            (new components\JS($this))->export();
        }

        foreach (array_keys($this->assetBundles) as $bundle) {
            if (in_array($bundle, $this->excludeBundles, true)) {
                $this->registerAssetFiles($bundle);
            }
        }
    }


    //
    //  ClientScriptView
    //


    /**
     * Set attributes and turn on output buffering.
     */
    public function beginJs($position = self::POS_READY, $key = null) {
        $this->position = $position;
        $this->key = $key;

        ob_start();
        ob_implicit_flush(false);
    }

    /**
     * Get default position if non set, get current buffer contents, delete current output buffer and register the contents.
     * @see {@link CClientScript::registerScript} return description
     */
    public function endJs() {
        parent::registerJs(preg_replace('/\s*<\/?script(.*)>\s*/i', '', ob_get_clean()), $this->position, $this->key);
    }

    /**
     * Set attributes and turn on output buffering.
     */
    public function beginCss($options = [], $key = null) {
        $this->options = $options;
        $this->key = $key;

        ob_start();
        ob_implicit_flush(false);
    }

    /**
     * Get default position if non set, get current buffer contents, delete current output buffer and register the contents.
     * @see {@link CClientScript::registerScript} return description
     */
    public function endCss() {
        parent::registerCss(preg_replace('/\s*<\/?style(.*)>\s*/i', '', ob_get_clean()), $this->options, $this->key);
    }
}
