<?php

namespace Doctrine\DBAL\Driver\IBMDB2;

use Doctrine\DBAL\Driver\FetchUtils;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use function assert;
use function db2_bind_param;
use function db2_execute;
use function db2_fetch_array;
use function db2_fetch_assoc;
use function db2_free_result;
use function db2_num_fields;
use function db2_num_rows;
use function db2_stmt_errormsg;
use function error_get_last;
use function fclose;
use function fwrite;
use function is_int;
use function is_resource;
use function ksort;
use function stream_copy_to_stream;
use function stream_get_meta_data;
use function tmpfile;
use const DB2_BINARY;
use const DB2_CHAR;
use const DB2_LONG;
use const DB2_PARAM_FILE;
use const DB2_PARAM_IN;

class DB2Statement implements Statement
{
    /** @var resource */
    private $stmt;

    /** @var mixed[] */
    private $bindParam = [];

    /**
     * Map of LOB parameter positions to the tuples containing reference to the variable bound to the driver statement
     * and the temporary file handle bound to the underlying statement
     *
     * @var mixed[][]
     */
    private $lobs = [];

    /**
     * Indicates whether the statement is in the state when fetching results is possible
     *
     * @var bool
     */
    private $result = false;

    /**
     * @param resource $stmt
     */
    public function __construct($stmt)
    {
        $this->stmt = $stmt;
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        return $this->bindParam($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($column, &$variable, $type = ParameterType::STRING, $length = null)
    {
        assert(is_int($column));

        switch ($type) {
            case ParameterType::INTEGER:
                $this->bind($column, $variable, DB2_PARAM_IN, DB2_LONG);
                break;

            case ParameterType::LARGE_OBJECT:
                if (isset($this->lobs[$column])) {
                    [, $handle] = $this->lobs[$column];
                    fclose($handle);
                }

                $handle = $this->createTemporaryFile();
                $path   = stream_get_meta_data($handle)['uri'];

                $this->bind($column, $path, DB2_PARAM_FILE, DB2_BINARY);

                $this->lobs[$column] = [&$variable, $handle];
                break;

            default:
                $this->bind($column, $variable, DB2_PARAM_IN, DB2_CHAR);
                break;
        }

        return true;
    }

    /**
     * @param int   $position Parameter position
     * @param mixed $variable
     *
     * @throws DB2Exception
     */
    private function bind($position, &$variable, int $parameterType, int $dataType) : void
    {
        $this->bindParam[$position] =& $variable;

        if (! db2_bind_param($this->stmt, $position, 'variable', $parameterType, $dataType)) {
            throw new DB2Exception(db2_stmt_errormsg());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function closeCursor()
    {
        $this->bindParam = [];

        if (! db2_free_result($this->stmt)) {
            return false;
        }

        $this->result = false;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function columnCount()
    {
        $count = db2_num_fields($this->stmt);

        if ($count !== false) {
            return $count;
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function execute($params = null)
    {
        if ($params === null) {
            ksort($this->bindParam);

            $params = [];

            foreach ($this->bindParam as $column => $value) {
                $params[] = $value;
            }
        }

        foreach ($this->lobs as [$source, $target]) {
            if (is_resource($source)) {
                $this->copyStreamToStream($source, $target);

                continue;
            }

            $this->writeStringToStream($source, $target);
        }

        $retval = db2_execute($this->stmt, $params);

        foreach ($this->lobs as [, $handle]) {
            fclose($handle);
        }

        $this->lobs = [];

        if ($retval === false) {
            throw new DB2Exception(db2_stmt_errormsg());
        }

        $this->result = true;

        return $retval;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchNumeric()
    {
        if (! $this->result) {
            return false;
        }

        return db2_fetch_array($this->stmt);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAssociative()
    {
        // do not try fetching from the statement if it's not expected to contain the result
        // in order to prevent exceptional situation
        if (! $this->result) {
            return false;
        }

        return db2_fetch_assoc($this->stmt);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchOne()
    {
        return FetchUtils::fetchOne($this);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAllNumeric() : array
    {
        return FetchUtils::fetchAllNumeric($this);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAllAssociative() : array
    {
        return FetchUtils::fetchAllAssociative($this);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn() : array
    {
        return FetchUtils::fetchColumn($this);
    }

    public function rowCount() : int
    {
        return @db2_num_rows($this->stmt);
    }

    /**
     * @return resource
     *
     * @throws DB2Exception
     */
    private function createTemporaryFile()
    {
        $handle = @tmpfile();

        if ($handle === false) {
            throw new DB2Exception('Could not create temporary file: ' . error_get_last()['message']);
        }

        return $handle;
    }

    /**
     * @param resource $source
     * @param resource $target
     *
     * @throws DB2Exception
     */
    private function copyStreamToStream($source, $target) : void
    {
        if (@stream_copy_to_stream($source, $target) === false) {
            throw new DB2Exception('Could not copy source stream to temporary file: ' . error_get_last()['message']);
        }
    }

    /**
     * @param resource $target
     *
     * @throws DB2Exception
     */
    private function writeStringToStream(string $string, $target) : void
    {
        if (@fwrite($target, $string) === false) {
            throw new DB2Exception('Could not write string to temporary file: ' . error_get_last()['message']);
        }
    }
}