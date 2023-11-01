<?php

namespace bdk\DevUtil;

use bdk\Debug;
use bdk\Debug\Utility\FileStreamWrapper;
use bdk\PubSub\Event;

/**
 * Modify unit tests depending on
 *   what version of PHP we're testing
 *   what version of PHPUnit is being used.
 */
class ModifyTests
{
    const REGEX = '/(function \S+\s*\([^)]*\))\s*:\s*void/';

    private $modifiedFiles = array();
    protected $dir;

    /**
     * Remove void return type from php < 7.1
     * Register shutdown function to revert changes
     *
     * @param string $dir test directory
     *
     * @return void
     */
    public function modify($dir)
    {
        if (PHP_VERSION_ID >= 70100) {
            return;
        }
        $this->dir = $dir;
        if (\class_exists('bdk\\Debug')) {
            $this->useFileStreamWrapper();
            return;
        }
        $files = $this->findFiles($this->dir, static function ($filepath) {
            return \preg_match('/\.php$/', $filepath) !== false
                && \preg_match('/\b(Mock|Fixture)\b/', $filepath) !== 1;
        });
        foreach ($files as $file) {
            $this->modifyFile($file);
        }
        \register_shutdown_function(array($this, 'revert'));
    }

    /**
     * Remove return void from files function declarations
     *
     * @param string $filepath php filepath
     *
     * @return void
     */
    public function modifyFile($filepath)
    {
        $content = \preg_replace_callback(
            self::REGEX,
            function ($matches) use ($filepath) {
                if (!isset($this->modifiedFiles[$filepath])) {
                    $this->modifiedFiles[$filepath] = array();
                }
                $this->modifiedFiles[$filepath][] = array(
                    'new' => $matches[1],
                    'original' => $matches[0],
                );
                return $matches[1];
            },
            \file_get_contents($filepath),
            -1, // no limit
            $count
        );
        if ($count > 0) {
            \file_put_contents($filepath, $content);
        }
    }

    /**
     * Revert changes to files
     *
     * @return void
     */
    public function revert()
    {
        foreach ($this->modifiedFiles as $filepath => $changes) {
            $content = \file_get_contents($filepath);
            foreach ($changes as $change) {
                $content = \str_replace($change['new'], $change['original'], $content);
            }
            \file_put_contents($filepath, $content);
        }
    }

    /**
     * Find all files in given directory optionally filtered by filter callback
     *
     * @param string   $dir    directory
     * @param callable $filter filter callable receives full filepath
     *
     * @return string[] filepaths
     */
    private function findFiles($dir, $filter = null)
    {
        $files = \glob($dir . '/*');
        foreach (\glob($dir . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = \array_merge($files, self::findFiles($dir));
        }
        if (\is_callable($filter)) {
            $files = \array_filter($files, $filter);
        }
        return $files;
    }

    /**
     * Register FileStreamWrapper and subscribe to event to modify tests on-the-fly
     * File not actualy modified / no need to revert
     *
     * @return void
     */
    private function useFileStreamWrapper()
    {
        $eventManager = Debug::getInstance()->eventManager;
        $eventManager->subscribe(Debug::EVENT_STREAM_WRAP, function (Event $event) {
            $filepath = $event['filepath'];
            $inDir = \strpos($filepath, $this->dir) === 0;
            if ($inDir === false || PHP_VERSION_ID >= 70100 || \preg_match('/\b(Mock|Fixture)\b/', $filepath) === 1) {
                return;
            }
            $event['content'] = \preg_replace(
                self::REGEX,
                '$1',
                $event['content'],
                -1 // no limit
            );
        }, PHP_INT_MAX);
        FileStreamWrapper::setEventManager($eventManager);
        FileStreamWrapper::register();
    }
}
