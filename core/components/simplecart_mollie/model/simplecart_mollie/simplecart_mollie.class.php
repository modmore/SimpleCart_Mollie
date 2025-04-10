<?php

class simplecart_mollie
{
    /** Version indexes **/
    public $version_major = '3';
    public $version_minor = '0';
    public $version_patch = '1';
    public $version_release = 'pl';
    public $version_index = '';

    protected $modx;
    protected $chunks;
    public $config = [];

    /**
     * Constructor
     * @param modX $modx
     * @param array $config
     */
    public function __construct(modX $modx, array $config = array())
    {
        $this->modx =& $modx;

        $basePath = $this->modx->getOption('simplecart_mollie.core_path', $config, $this->modx->getOption('core_path') . 'components/simplecart_mollie/');
        $assetsPath = $this->modx->getOption('simplecart_mollie.assets_path', $config, $this->modx->getOption('assets_path') . 'components/simplecart_mollie/');
        $assetsUrl = $this->modx->getOption('simplecart_mollie.assets_url', $config, $this->modx->getOption('assets_url') . 'components/simplecart_mollie/');

        $this->config = array_merge(array(
            'basePath' => $basePath,
            'corePath' => $basePath,
            'lexiconPath' => $basePath . 'lexicon/',
            'modelPath' => $basePath . 'model/',
            'elementsPath' => $basePath . 'elements/',
            'chunksPath' => $basePath . 'elements/chunks/',
            'assetsPath' => $assetsPath,
            'assetsUrl' => $assetsUrl,
            'connectorUrl' => $assetsUrl . 'connector.php',

            'jsUrl' => $assetsUrl . 'js/',
            'cssUrl' => $assetsUrl . 'css/',
            'imgsUrl' => $assetsUrl . 'images/',
        ), $config);
    }

    public function getChunk($name, $properties = array())
    {
        $chunk = null;
        if (!isset($this->chunks[$name])) {
            $chunk = $this->modx->getObject('modChunk', array('name' => $name));
            if (empty($chunk) || !is_object($chunk)) {
                $chunk = $this->_getTplChunk($name);
                if ($chunk == false) {
                    return false;
                }
            }
            $this->chunks[$name] = $chunk->getContent();
        } else {
            $o = $this->chunks[$name];
            $chunk = $this->modx->newObject('modChunk');
            $chunk->setContent($o);
        }
        $chunk->setCacheable(false);
        return $chunk->process($properties);
    }

    private function _getTplChunk($name, $postfix = '.chunk.tpl')
    {
        $chunk = false;
        $f = $this->config['chunksPath'] . strtolower($name) . $postfix;
        if (file_exists($f)) {
            $o = file_get_contents($f);
            $chunk = $this->modx->newObject('modChunk');
            $chunk->set('name', $name);
            $chunk->setContent($o);
        }
        return $chunk;
    }

    /**
     * Returns the wanted version info
     * @param string $type Of the wanted version
     * @param string $separator Of the version keys
     * @return string|boolean false
     */
    public function getVersion($type = 'full', $separator = '.')
    {
        switch ($type) {
            case 'version':
                return $this->version_major . $separator . $this->version_minor . $separator . $this->version_patch;
            break;
            case 'major':
                return $this->version_major;
            break;
            case 'minor':
                return $this->version_minor;
            break;
            case 'patch':
                return $this->version_patch;
            break;
            case 'release':
                return $this->version_release;
            break;
            case 'index':
                return $this->version_index;
            break;

            case 'array':
                return array(
                'version_major' => $this->version_major,
                'version_minor' => $this->version_minor,
                'version_patch' => $this->version_patch,
                'release' => $this->version_release,
                'release_index' => $this->version_index,
                );
            break;

            case 'full':
            default:
                return $this->version_major . $separator . $this->version_minor . $separator . $this->version_patch . '-' . $this->version_release . $this->version_index;
            break;
        }
    }
}
