<?php

namespace PlatformCommunity\Flysystem\BunnyCDN;

use BunnyCDN\Storage\BunnyCDNStorage;
use BunnyCDN\Storage\Exceptions\BunnyCDNStorageException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Config;
use League\Flysystem\Exception;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\NotSupportedException;
use League\Flysystem\UnreadableFileException;

class BunnyCDNAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;
    use StreamedCopyTrait;

    /**
     * The BunnyCDN Storage Container
     * @var BunnyCDNStorage
     */
    protected $bunnyCDNStorage;

    /**
     * BunnyCDNAdapter constructor.
     * @param BunnyCDNStorage $bunnyCDNStorage
     */
    public function __construct(BunnyCDNStorage $bunnyCDNStorage)
    {
        $this->bunnyCDNStorage = $bunnyCDNStorage;
    }

    /**
     * @param $path
     * @param $contents
     * @param Config $config
     * @return bool
     */
    public function write($path, $contents, Config $config)
    {
        $temp_pointer = tmpfile();
        fwrite($temp_pointer, $contents);

        /** @var string $url */
        $url = stream_get_meta_data($temp_pointer)['uri'];

        try {
            $this->bunnyCDNStorage->uploadFile(
                $url,
                $this->bunnyCDNStorage->storageZoneName . '/' . $path
            );
        // @codeCoverageIgnoreStart
        } catch (BunnyCDNStorageException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        return true;
    }

    /**
     * @codeCoverageIgnore
     * @param string $path
     * @param resource $resource
     * @param Config $config
     * @return array|false|void
     */
    public function writeStream($path, $resource, Config $config)
    {
        throw new NotSupportedException('BunnyCDN does not support steam writing, use ->write() instead');
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return array|bool|false
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * @codeCoverageIgnore
     * @param string $path
     * @param resource $resource
     * @param Config $config
     * @return array|false|void
     */
    public function updateStream($path, $resource, Config $config)
    {
        throw new NotSupportedException('BunnyCDN does not support steam updating, use ->update() instead');
    }

    /**
     * @param string $path
     * @param string $newpath
     * @return bool
     * @throws Exception
     */
    public function rename($path, $newpath)
    {
        $this->write($newpath, $this->read($path), new Config());
        return $this->delete($path);
    }

    /**
     * @param string $path
     * @param string $newpath
     * @return bool
     * @throws Exception
     */
    public function copy($path, $newpath)
    {
        return $this->write($newpath, $this->read($path), new Config());

    }

    /**
     * @param string $path
     * @return bool
     */
    public function delete($path)
    {
        try {
            return !$this->bunnyCDNStorage->deleteObject($path);
        // @codeCoverageIgnoreStart
        } catch (BunnyCDNStorageException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param string $dirname
     * @return bool|void
     */
    public function deleteDir($dirname)
    {
        try {
            return !$this->bunnyCDNStorage->deleteObject($dirname);
        // @codeCoverageIgnoreStart
        } catch (BunnyCDNStorageException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param string $dirname
     * @param Config $config
     * @return array|false|void
     */
    public function createDir($dirname, Config $config)
    {
        $temp_pointer = tmpfile();
        fwrite($temp_pointer, '');

        /** @var string $url */
        $url = stream_get_meta_data($temp_pointer)['uri'];

        try {
            $this->bunnyCDNStorage->uploadFile(
                $url,
                $this->bunnyCDNStorage->storageZoneName . '/' . $dirname
            );
        // @codeCoverageIgnoreStart
        } catch (BunnyCDNStorageException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        return true;
    }

    /**
     * @param string $path
     * @return bool
     */
    public function has($path)
    {
        try {
            $path = $this->endsWith($path, '/') ? substr($path, 0, -1) : $path;
            $sub = explode('/', $path);
            $file = array_pop($sub);
            $directory = implode('/', $sub);

            return count(array_filter($this->bunnyCDNStorage->getStorageObjects(
                    $this->bunnyCDNStorage->storageZoneName . '/' . $directory
                ), function ($value) use ($file, $directory) {
                    return $value->Path . $value->ObjectName === '/' . $this->normalizePath(
                            $this->bunnyCDNStorage->storageZoneName . '/' . $directory . ((bool) $file ? '/' : '') . $file,
                            $file === null
                        );
                }, ARRAY_FILTER_USE_BOTH)) === 1;
        // @codeCoverageIgnoreStart
        } catch (BunnyCDNStorageException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param string $path
     * @return bool|string
     * @throws Exception
     */
    public function read($path)
    {
        $temp_pointer = tmpfile();

        try {
            $this->bunnyCDNStorage->downloadFile(
                $this->normalizePath($this->bunnyCDNStorage->storageZoneName . '/' . $path),
                stream_get_meta_data($temp_pointer)['uri']
            );
        // @codeCoverageIgnoreStart
        } catch (BunnyCDNStorageException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        return file_get_contents(stream_get_meta_data($temp_pointer)['uri']);
    }

    /**
     * @codeCoverageIgnore
     * @param string $path
     * @return array|false|void
     */
    public function readStream($path)
    {
        throw new NotSupportedException('BunnyCDN does not support steam reading, use ->read() instead');
    }

    /**
     * @param string $directory
     * @param bool $recursive
     * @return array|mixed
     * @throws BunnyCDNStorageException
     */
    public function listContents($directory = '', $recursive = false)
    {
        return $this->bunnyCDNStorage->getStorageObjects(
            $this->bunnyCDNStorage->storageZoneName . '/' . $directory
        );
    }

    /**
     * @param $path
     * @return mixed
     * @throws UnreadableFileException
     * @throws FileNotFoundException
     * @throws BunnyCDNStorageException
     */
    private function getIndividualFileMetadata($path)
    {
        $path = $this->endsWith($path, '/') ? substr($path, 0, -1) : $path;
        $sub = explode('/', $path);
        $file = array_pop($sub);
        $directory = implode('/', $sub);

        $files = array_filter($this->bunnyCDNStorage->getStorageObjects(
            $this->bunnyCDNStorage->storageZoneName . '/' . $directory
        ), function ($value) use ($file, $directory) {
            return $value->Path . $value->ObjectName === '/' . $this->normalizePath(
                $this->bunnyCDNStorage->storageZoneName . '/' . $directory . ((bool) $file ? '/' : '') . $file,
                $file === null
                );
        }, ARRAY_FILTER_USE_BOTH);

        // Check that the path isn't returning more than one file / folder
        if (count($files) > 1) {
            // @codeCoverageIgnoreStart
            throw new UnreadableFileException('More than one file was returned for path:"' . $path . '", contact package author.');
            // @codeCoverageIgnoreEnd
        }

        // Check 404
        if (count($files) === 0) {
            // @codeCoverageIgnoreStart
            throw new FileNotFoundException('Could not find file: "' . $path . '".');
            // @codeCoverageIgnoreEnd
        }

        return array_values($files)[0];
    }

    /**
     * @param string $path
     * @return array|false
     * @throws BunnyCDNStorageException
     */
    public function getMetadata($path)
    {
        try {
            return get_object_vars($this->getIndividualFileMetadata($path));
        // @codeCoverageIgnoreStart
        } catch (FileNotFoundException $e) {
            return false;
        } catch (UnreadableFileException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param string $path
     * @return integer
     * @throws BunnyCDNStorageException
     */
    public function getSize($path)
    {
        try {
            return $this->getIndividualFileMetadata($path)->Length;
        // @codeCoverageIgnoreStart
        } catch (FileNotFoundException $e) {
            return false;
        } catch (UnreadableFileException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @codeCoverageIgnore
     * @param string $path
     * @return array|false|void
     */
    public function getMimetype($path)
    {
        throw new NotSupportedException('BunnyCDN does not provide Mimetype information');
    }

    /**
     * @param string $path
     * @return array|false|void
     * @throws BunnyCDNStorageException
     */
    public function getTimestamp($path)
    {
        try {
            return strtotime($this->getIndividualFileMetadata($path)->LastChanged);
        // @codeCoverageIgnoreStart
        } catch (FileNotFoundException $e) {
            return false;
        } catch (UnreadableFileException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param $path
     * @param null $isDirectory
     * @return false|string|string[]
     * @throws Exception
     */
    private function normalizePath($path, $isDirectory = NULL)
    {
        $path = str_replace('\\', '/', $path);
        if ($isDirectory !== NULL) {
            if ($isDirectory) {
                if (!$this->endsWith($path, '/')) {
                    $path = $path . "/";
                }
            // @codeCoverageIgnoreStart
            } else if ($this->endsWith($path, '/') && $path !== '/') {
                throw new Exception('The requested path is invalid.');
            }
            // @codeCoverageIgnoreEnd
        }

        // Remove double slashes
        while (strpos($path, '//') !== false) {
            $path = str_replace('//', '/', $path);
        }

        // Remove the starting slash
        if (strpos($path, '/') === 0) {
            $path = substr($path, 1);
        }

        return $path;
    }

    /**
     * @codeCoverageIgnore
     * @param $haystack
     * @param $needle
     * @return bool
     */
    private function startsWith($haystack, $needle)
    {
        return (strpos($haystack, $needle) === 0);
    }

    /**
     * @param $haystack
     * @param $needle
     * @return bool
     */
    private function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length === 0) {
            return true;
        }

        return (substr($haystack, -$length) === $needle);
    }
}
