<?php

namespace bdk\DevUtil;

/**
 * Modify unit tests depending on
 *   what version of PHP we're testing
 *   what version of PHPUnit is being used.
 *
 * A better way of achieving this would be via file stream wrapper where
 * we don't actually modify the files
 */
class ModifyTests
{
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
            '/(function \S+\s*\([^)]*\))\s*:\s*void/',
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
}
