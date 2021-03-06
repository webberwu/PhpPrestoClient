<?php
/*
PrestoClient provides a way to communicate with Presto server REST interface. Presto is a fast query
engine developed by Facebook that runs distributed queries against Hadoop HDFS servers.

Copyright 2013 Xtendsys | xtendsys.net

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at:

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
 */

namespace Presto;

use Presto\PrestoException;

class PrestoClient
{
    //Do not modify below this line
    private $queryId = '';
    private $nextUri = '';
    private $infoUri = '';
    private $partialCancelUri = '';
    private $state = 'NONE';
    private $error;

    private $url;
    private $headers;
    private $result;
    private $request;

    public $httpError;
    public $data = [];
    public $columns = [];

    /**
     * Constructs the presto connection instance
     *
     * @param $connectUrl
     * @param $catalog
     */
    public function __construct($connectUrl, $catalog)
    {
        $this->url = rtrim($connectUrl, '/');

        $this->headers = [
            'X-Presto-User: presto',
            "X-Presto-Catalog: $catalog",
            'X-Presto-Schema: default',
        ];
    }

    /**
     * Return Data as an array. Check that the current status is FINISHED
     *
     * @return array|false
     */
    public function getData()
    {
        if ($this->state != 'FINISHED') {
            return false;
        }

        return $this->data;
    }

    /**
     * prepares the query
     *
     * @param  $query
     * @return bool
     * @throws PrestoException
     */
    public function query($query)
    {

        $this->data = [];

        $this->request = $query;
        //check that no other queries are already running for this object
        if ($this->state === 'RUNNING') {
            return false;
        }

        $connect = curl_init();
        curl_setopt($connect, CURLOPT_URL, $this->url . '/v1/statement');
        curl_setopt($connect, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($connect, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($connect, CURLOPT_POST, 1);
        curl_setopt($connect, CURLOPT_POSTFIELDS, $this->request);

        $this->result = curl_exec($connect);
        $this->getVarFromResult();

        $httpCode = curl_getinfo($connect, CURLINFO_HTTP_CODE);

        if ($httpCode != '200') {
            $this->httpError = $httpCode;
            throw new PrestoException("HTTP ERRROR: {$this->httpError}", $this->httpError ?: 503);
        }

        //set status to RUNNING
        curl_close($connect);
        $this->state = 'RUNNING';

        return true;
    }

    public function getQueryId()
    {
        return $this->queryId;
    }

    /**
     * waits until query was executed
     *
     * @return bool
     * @throws PrestoException
     */
    public function waitQueryExec()
    {

        $this->getVarFromResult();

        while ($this->nextUri) {
            usleep(500000);
            $this->result = file_get_contents($this->nextUri);
            $this->getVarFromResult();
        }

        if ($this->state === 'FAILED') {
            throw new PrestoException(sprintf(
                '%s (%d): %s',
                $this->error->errorName,
                $this->error->errorCode,
                isset($this->error->message) ? $this->error->message : ''
            ), $this->error->errorCode);
        }

        if ($this->state !== 'FINISHED') {
            throw new PrestoException('Incoherent State at end of query');
        }

        return true;
    }

    /**
     * Provide Information on the query execution
     * The server keeps the information for 15minutes
     * Return the raw JSON message for now
     *
     * @return string
     */
    public function getInfo()
    {
        $connect = curl_init();
        curl_setopt($connect, CURLOPT_URL, $this->infoUri);
        curl_setopt($connect, CURLOPT_HTTPHEADER, $this->headers);
        $infoRequest = curl_exec($connect);
        curl_close($connect);

        return $infoRequest;
    }

    private function getVarFromResult()
    {
        // Retrieve the variables from the JSON answer
        $decodedJson = json_decode($this->result);

        $this->nextUri = $decodedJson->nextUri ?? false;
        $this->queryId = $decodedJson->id ?? '';
        $this->infoUri = $decodedJson->infoUri ?? '';
        $this->partialCancelUri = $decodedJson->partialCancelUri ?? '';
        $this->error = $decodedJson->error ?? null;

        if (isset($decodedJson->columns)) {
            $this->columns = array_map(
                function ($c) {
                    return $c->name;
                },
                $decodedJson->columns
            );
        }

        if (isset($decodedJson->data)) {
            $this->data = array_merge(
                $this->data,
                array_map(
                    function ($d) {
                        return array_combine($this->columns, $d);
                    },
                    $decodedJson->data
                )
            );
        }

        if (isset($decodedJson->stats)) {
            $status = $decodedJson->stats;
            $this->state = $status->state;
        }
    }

    /**
     * cancel the query
     *
     * @param string $queryId
     * @return bool
     */
    public function cancel($queryId)
    {
        $connect = curl_init();
        curl_setopt($connect, CURLOPT_URL, $this->url . "/v1/query/$queryId");
        curl_setopt($connect, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($connect, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_exec($connect);
        $httpCode = curl_getinfo($connect, CURLINFO_HTTP_CODE);
        curl_close($connect);

        if ($httpCode != '204') {
            return false;
        }

        return true;
    }
}
