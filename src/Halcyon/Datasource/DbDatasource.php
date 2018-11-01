<?php namespace October\Rain\Halcyon\Datasource;

use Db;
use Exception;
use Carbon\Carbon;
use October\Rain\Halcyon\Processors\Processor;
use October\Rain\Halcyon\Exception\CreateFileException;
use October\Rain\Halcyon\Exception\DeleteFileException;
use October\Rain\Halcyon\Exception\FileExistsException;

/**
 * Database based data source
 * 
 * Table Structure:
 *  - id, unsigned integer
 *  - path, varchar
 *  - content, largeText
 *  - file_size, unsigned integer // In bytes - NOTE: max file size of 4.29 GB represented with unsigned int in MySQL
 *  - updated_at, datetime
 */
class DbDatasource extends Datasource implements DatasourceInterface
{
    /**
     * The local path where the datasource can be found.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @var string The table name of the datasource
     */
    protected $table;
    
    /**
     * Create a new datasource instance.
     *
     * @param string $basePath The base path for this datasource instance
     * @param string $table The table for this database datasource
     * @return void
     */
    public function __construct($basePath, $table)
    {
        $this->basePath = $basePath;

        $this->table = $table;

        $this->postProcessor = new Processor;
    }
    
    /**
     * Get the QueryBuilder object
     *
     * @return QueryBuilder
     */
    protected function getQuery()
    {
        return Db::table($this->table);
    }
    
    /**
     * Helper to make file path.
     * 
     * @param string $dirName
     * @param string $fileName
     * @param string $extension
     * @return string
     */
    protected function makeFilePath($dirName, $fileName, $extension)
    {
        return $this->basePath . '/' . $dirName . '/' . $fileName . '.' . $extension;
    }

    /**
     * Returns a single template.
     *
     * @param  string  $dirName
     * @param  string  $fileName
     * @param  string  $extension
     * @return mixed
     */
    public function selectOne($dirName, $fileName, $extension)
    {
        try {
            $result = $this->getQuery()->where('path', $this->makeFilePath($dirName, $fileName, $extension))->firstOrFail();
            
            return [
                'fileName' => $fileName . '.' . $extension,
                'content'  => $result->content,
                'mtime'    => Carbon::parse($result->updated_at)->timestamp,
                'record'   => $result,
            ];
        } catch (Exception $ex) {
            return null;
        }
    }

    /**
     * Returns all templates.
     *
     * @param  string  $dirName
     * @param array $options Array of options, [
     *                          'columns'    => ['fileName', 'mtime', 'content'], // Only return specific columns
     *                          'extensions' => ['htm', 'md', 'twig'],            // Extensions to search for
     *                          'fileMatch'  => '*gr[ae]y',                       // Shell matching pattern to match the filename against using the fnmatch function
     *                          'orders'     => false                             // Not implemented
     *                          'limit'      => false                             // Not implemented
     *                          'offset'     => false                             // Not implemented
     *                      ];
     * @return array
     */
    public function select($dirName, array $options = [])
    {
        // Initialize result set
        $result = [];
        
        // Prepare query options
        extract(array_merge([
            'columns'     => null,  // Only return specific columns (fileName, mtime, content)
            'extensions'  => null,  // Match specified extensions
            'fileMatch'   => null,  // Match the file name using fnmatch()
            'orders'      => null,  // @todo
            'limit'       => null,  // @todo
            'offset'      => null   // @todo
        ], $options));

        if ($columns === ['*'] || !is_array($columns)) {
            $columns = null;
        }

        // Apply the dirName query
        $query = $this->getQuery()->where('path', 'like', $dirName . '%');

        // Apply the extensions filter
        if (is_array($extensions) && !empty($extensions)) {
            $query->where(function ($query) use ($extensions) {
                // Get the first extension to query for
                $query->where('path', 'like', '%' . '.' . array_pop($extensions));

                if (count($extensions)) {
                    foreach ($extensions as $ext) {
                        $query->orWhere('path', 'like', '%' . '.' . $ext);
                    }
                }
            });
        }

        $results = $query->get();

        foreach ($results as $item) {
            $resultItem = [];
            $fileName = pathinfo($item->path, PATHINFO_BASENAME);

            // Apply the fileMatch filter
            if (!empty($fileMatch) && !fnmatch($fileMatch, $fileName)) {
                continue;
            }

            // Apply the columns filter
            if (is_null($columns)) {
                $resultItem = [
                    'fileName' => $fileName,
                    'content'  => $item->content,
                    'mtime'    => Carbon::parse($item->updated_at)->timestamp,
                    'record'   => $item,
                ];
            } else {
                if (in_array('fileName', $columns)) {
                    $resultItem['fileName'] = $fileName;
                }

                if (in_array('content')) {
                    $resultItem['content'] = $item->content;
                }

                if (in_array('mtime')) {
                    $resultItem['mtime'] = Carbon::parse($item->updated_at)->timestamp;
                }

                if (in_array('record', $columns)) {
                    $resultItem['record'] = $item;
                }
            }

            $result[] = $resultItem;
        }

        return $result;
    }

    /**
     * Creates a new template.
     *
     * @param  string  $dirName
     * @param  string  $fileName
     * @param  string  $extension
     * @param  string  $content
     * @return bool
     */
    public function insert($dirName, $fileName, $extension, $content)
    {
        $path = $this->makeFilePath($dirName, $fileName, $extension);

        // Check for an existing record
        if ($this->selectOne($dirName, $fileName, $extension)) {
            throw (new FileExistsException())->setInvalidPath($path);
        }

        try {
            $record = [
                'path'       => $path,
                'content'    => $content,
                'file_size'  => mb_strlen($content, '8bit'),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ];

            $this->getQuery()->insert($record);

            return $record['file_size'];
        }
        catch (Exception $ex) {
            throw (new CreateFileException)->setInvalidPath($path);
        }
    }

    /**
     * Updates an existing template.
     *
     * @param  string  $dirName
     * @param  string  $fileName
     * @param  string  $extension
     * @param  string  $content
     * @param  string  $oldFileName Defaults to null
     * @param  string  $oldExtension Defaults to null
     * @return int
     */
    public function update($dirName, $fileName, $extension, $content, $oldFileName = null, $oldExtension = null)
    {
        $path = $this->makeFilePath($dirName, $fileName, $extension);

        // Check if this file has been renamed
        if (!is_null($oldFileName)) {
            $fileName = $oldFileName;
        }
        if (!is_null($oldExtension)) {
            $extension = $oldExtension;
        }

        // Get the existing record
        $record = $this->selectOne($dirName, $fileName, $extension)['record'];

        // Update the existing record
        try {
            $fileSize = mb_strlen($content, '8bit');

            $this->getQuery()->where('id', $record->id)->update([
                'path'       => $path,
                'content'    => $content,
                'file_size'  => $fileSize,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);

            return $fileSize;
        }
        catch (Exception $ex) {
            throw (new CreateFileException)->setInvalidPath($path);
        }
    }

    /**
     * Run a delete statement against the datasource.
     *
     * @param  string  $dirName
     * @param  string  $fileName
     * @param  string  $extension
     * @return int
     */
    public function delete($dirName, $fileName, $extension)
    {
        try {
            // Get the existing record
            $record = $this->selectOne($dirName, $fileName, $extension)['record'];

            // Attempt to delete the existing record
            $this->getQuery()->where('id', $record->id)->delete();
        }
        catch (Exception $ex) {
            throw (new DeleteFileException)->setInvalidPath($this->makeFilePath($dirName, $fileName, $extension));
        }
    }

    /**
     * Return the last modified date of an object
     *
     * @param  string  $dirName
     * @param  string  $fileName
     * @param  string  $extension
     * @return int
     */
    public function lastModified($dirName, $fileName, $extension)
    {
        try {
            return $this->selectOne($dirName, $fileName, $extension)['mtime'];
        }
        catch (Exception $ex) {
            return null;
        }
    }

    /**
     * Generate a cache key unique to this datasource.
     *
     * @param  string  $name
     * @return string
     */
    public function makeCacheKey($name = '')
    {
        return crc32($this->basePath . $name);
    }
}