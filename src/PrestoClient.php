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
    /**
     * The following parameters may be modified depending on your configuration
     */
    private $source = 'PhpPrestoClient';
    private $version = '0.1.0';
    private $maximumRetries = 5;
    private $prestoUser = 'presto';
    private $prestoSchema = 'default';
    private $prestoCatalog = 'hive';
    private $userAgent = '';

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

    public $HTTP_error;
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
        $this->prestoCatalog = $catalog;

        $this->headers = [
            'X-Presto-User: ' . $this->prestoUser,
            'X-Presto-Catalog: ' . $this->prestoCatalog,
            'X-Presto-Schema: ' . $this->prestoSchema,
            'User-Agent: ' . $this->userAgent
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
        $this->userAgent = $this->source . '/' . $this->version;

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
            $this->HTTP_error = $httpCode;
            throw new PrestoException("HTTP ERRROR: {$this->HTTP_error}");
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
            ));
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

        if (isset($decodedJson->nextUri)) {
            $this->nextUri = $decodedJson->nextUri;
        } else {
            $this->nextUri = false;
        }

        if (isset($decodedJson->id)) {
            $this->queryId = $decodedJson->id;
        }

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

        if (isset($decodedJson->infoUri)) {
            $this->infoUri = $decodedJson->infoUri;
        }

        if (isset($decodedJson->partialCancelUri)) {
            $this->partialCancelUri = $decodedJson->partialCancelUri;
        }

        if (isset($decodedJson->error)) {
            $this->error = $decodedJson->error;
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
