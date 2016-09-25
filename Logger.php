<?php

class Logger implements \SplObserver
{
    public function update(SplSubject $subject, $changedData = null)
    {
        $storePath = "logger_file.txt";

        if (!file_exists($storePath)) {
            if (!touch($storePath)) {
                throw new Exception("Could not create data store file $storePath. Details:" . getLastError());
            }
            if (!chmod($storePath, 0777)) {
                throw new Exception("Could not set read/write on data store file $storePath. " .
                    "Details:" . getLastError());
            }
        }
        if (!is_readable($storePath) || !is_writable($storePath)) {
            throw new Exception("Data store file $storePath must be readable/writable. Details:" . getLastError());
        }

        $result = file_put_contents($storePath, json_encode($changedData) . "\r\n", FILE_APPEND);
        if ($result === null) {
            throw new Exception("Write of data store file $storePath failed.  Details:" . getLastError());
        }
    }
}