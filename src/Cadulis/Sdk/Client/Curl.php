<?php

namespace Cadulis\Sdk\Client;

class Curl
{

    use \Bixev\LightLogger\LoggerTrait;

    // cURL hex representation of version 7.30.0
    const NO_QUIRK_VERSION = 0x071E00;

    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';
    const METHOD_PUT = 'PUT';
    const METHOD_DELETE = 'DELETE';

    protected $_knownMethods = [
        self::METHOD_POST,
        self::METHOD_GET,
        self::METHOD_PUT,
        self::METHOD_DELETE,
    ];

    private static $CONNECTION_ESTABLISHED_HEADERS = [
        "HTTP/1.0 200 Connection established\r\n\r\n",
        "HTTP/1.1 200 Connection established\r\n\r\n",
    ];

    /**
     * @var resource
     */
    protected $_curlHandler;

    protected $_url;
    protected $_method = self::METHOD_GET;
    protected $_headers = [];
    protected $_postFields = [];
    protected $_httpResponseCode;

    /**
     * @param $url
     * @param string $method
     * @param array $headers
     * @param array $postFields
     * @param \Bixev\LightLogger\LoggerInterface|null $logger
     * @throws Exception
     */
    public function __construct($url, $method = self::METHOD_GET, array $headers = [], array $postFields = [], \Bixev\LightLogger\LoggerInterface $logger = null)
    {

        $this->_logger = $logger;

        $url = trim($url);
        if ($url == '') {
            throw new Exception('url cannot be empty');
        }
        $method = trim($method);
        if ($method == '') {
            throw new Exception('method cannot be empty');
        }
        if (array_search($method, $this->_knownMethods) === false) {
            throw new Exception('Unknown method : "' . $method . '"');
        }

        $this->_url = $url;
        $this->_method = $method;
        $this->_headers = $headers;
        $this->_postFields = $postFields;
    }

    protected function init($ch = null)
    {
        if ($ch !== null) {
            $this->_curlHandler = $ch;
        } else {
            $this->_curlHandler = curl_init();
        }
    }

    /**
     * @return resource
     */
    public function getCurlHandler()
    {
        return $this->_curlHandler;
    }

    public function prepare($ch = null)
    {

        $this->init($ch);

        curl_setopt($this->_curlHandler, CURLOPT_CUSTOMREQUEST, $this->_method);

        curl_setopt($this->_curlHandler, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($this->_curlHandler, CURLOPT_SSL_VERIFYPEER, true);
        // 1 is CURL_SSLVERSION_TLSv1, which is not always defined in PHP.
        curl_setopt($this->_curlHandler, CURLOPT_SSLVERSION, 1);
        curl_setopt($this->_curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_curlHandler, CURLOPT_HEADER, true);
        curl_setopt($this->_curlHandler, CURLOPT_ENCODING, 'gzip,deflate');

        $postFields = http_build_query($this->_postFields, '', '&');
        $url = $this->_url;
        if ($this->_method == static::METHOD_GET) {
            if ($postFields != '') {
                $url .= strpos($this->_url, '?') === false ? '?' : '&';
                $url .= $postFields;
            }
        } else {
            curl_setopt($this->_curlHandler, CURLOPT_POSTFIELDS, $postFields);
        }
        curl_setopt($this->_curlHandler, CURLOPT_URL, $url);
        $this->_headers[] = 'app-platform: api-client';
        curl_setopt($this->_curlHandler, CURLOPT_HTTPHEADER, $this->_headers);

        $this->log(
            [
                'cURL request' => [
                    'url'     => $url,
                    'method'  => $this->_method,
                    'headers' => $this->_headers,
                    'body'    => $this->_postFields,
                ],
            ]
        );
    }

    public function processResponse($responseBody)
    {
        $responseArray = json_decode($responseBody, true);
        if ($responseArray !== null) {
            return $responseArray;
        }

        return $responseBody;
    }

    /**
     * @param bool $process with json_decode post-processing
     *
     * @return mixed
     */
    public function exec($process = true)
    {

        $this->prepare();

        return $this->process($process);
    }

    /**
     * @param bool $process with json_decode post-processing
     *
     * @return mixed
     */
    public function process($process = true)
    {
        $response = curl_exec($this->_curlHandler);
		$this->_httpResponseCode = curl_getinfo($this->_curlHandler, CURLINFO_HTTP_CODE);

		if ($process) {
            $response = $this->processResponse($response);
        }

        curl_close($this->_curlHandler);

        if ($response === false) {
            $error = curl_error($this->_curlHandler);
            $code = curl_errno($this->_curlHandler);
            throw new Exception($error, $code);
        }
        $headerSize = curl_getinfo($this->_curlHandler, CURLINFO_HEADER_SIZE);

        list($responseHeaders, $responseBody) = $this->parseHttpResponse($response, $headerSize);
        $responseCode = curl_getinfo($this->_curlHandler, CURLINFO_HTTP_CODE);

        $this->log(
            [
                'cURL response' => [
                    'code'    => $responseCode,
                    'headers' => $responseHeaders,
                    'body'    => $responseBody,
                ],
            ]
        );

        if ($responseCode >= 300) {
            $responseArray = json_decode($responseBody, true);
            if ($responseArray !== null) {
                $errMsg = 'Error while executing request : ';
                if (isset($responseArray['message'])) {
                    $errMsg .= $responseArray['message'];
                    if (isset($responseArray['details'])) {
                        $errMsg .= ' (' . $responseArray['details'] . ')';
                    }
                }
            } else {
                $errMsg = 'Error while executing request : ' . $responseBody;
            }
            throw new Exception($errMsg, $responseCode);
        }

        return $responseBody;
    }

    /**
     * Used by the IO lib and also the batch processing.
     *
     * @param $respData
     * @param $headerSize
     * @return array
     */
    public function parseHttpResponse($respData, $headerSize)
    {
        // check proxy header
        foreach (self::$CONNECTION_ESTABLISHED_HEADERS as $established_header) {
            if (stripos($respData, $established_header) !== false) {
                // existed, remove it
                $respData = str_ireplace($established_header, '', $respData);
                // Subtract the proxy header size unless the cURL bug prior to 7.30.0
                // is present which prevented the proxy header size from being taken into
                // account.
                if (!$this->needsQuirk()) {
                    $headerSize -= strlen($established_header);
                }
                break;
            }
        }

        if ($headerSize) {
            $responseBody = substr($respData, $headerSize);
            $responseHeaders = substr($respData, 0, $headerSize);
        } else {
            $responseSegments = explode("\r\n\r\n", $respData, 2);
            $responseHeaders = $responseSegments[0];
            $responseBody = isset($responseSegments[1]) ? $responseSegments[1] :
                null;
        }

        $responseHeaders = $this->getHttpResponseHeaders($responseHeaders);

        return [$responseHeaders, $responseBody];
    }

    /**
     * Parse out headers from raw headers
     * @param mixed array or string
     * @return array
     */
    public function getHttpResponseHeaders($rawHeaders)
    {
        if (is_array($rawHeaders)) {
            return $this->parseArrayHeaders($rawHeaders);
        } else {
            return $this->parseStringHeaders($rawHeaders);
        }
    }

    private function parseStringHeaders($rawHeaders)
    {
        $headers = [];
        $responseHeaderLines = explode("\r\n", $rawHeaders);
        foreach ($responseHeaderLines as $headerLine) {
            if ($headerLine && strpos($headerLine, ':') !== false) {
                list($header, $value) = explode(': ', $headerLine, 2);
                $header = strtolower($header);
                if (isset($headers[$header])) {
                    $headers[$header] .= "\n" . $value;
                } else {
                    $headers[$header] = $value;
                }
            }
        }

        return $headers;
    }

    private function parseArrayHeaders($rawHeaders)
    {
        $header_count = count($rawHeaders);
        $headers = [];

        for ($i = 0; $i < $header_count; $i++) {
            $header = $rawHeaders[$i];
            // Times will have colons in - so we just want the first match.
            $header_parts = explode(': ', $header, 2);
            if (count($header_parts) == 2) {
                $headers[strtolower($header_parts[0])] = $header_parts[1];
            }
        }

        return $headers;
    }

    /**
     * Test for the presence of a cURL header processing bug
     *
     * {@inheritDoc}
     *
     * @return boolean
     */
    protected function needsQuirk()
    {
        $ver = curl_version();
        $versionNum = $ver['version_number'];

        return $versionNum < static::NO_QUIRK_VERSION;
    }

    public function getHttpResponseCode()
    {
		return $this->_httpResponseCode;
    }
}