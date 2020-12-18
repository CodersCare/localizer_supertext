<?php

namespace Localizationteam\LocalizerSupertext\Api;

use Localizationteam\Localizer\Constants;
use PDO;
use TYPO3\CMS\Core\Crypto\PasswordHashing\Md5PasswordHash;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;

/**
 * ApiCalls Class used to make calls to the Localizer API
 *
 * @author      Peter Russ<peter.russ@4many.net>, Jo Hasenau<jh@cybercraft.de>
 * @package     TYPO3
 * @subpackage  localizer
 *
 */
class ApiCalls extends \Localizationteam\Localizer\Api\ApiCalls
{
    /**
     * @var int
     */
    protected $uid;

    /**
     * @var string
     */
    protected $customerId;

    /**
     * @var string
     */
    protected $communicationLanguage = 'de-CH';

    /**
     * @param int $type
     * @param string $url
     * @param string $workflow
     * @param string $projectKey
     * @param string $username
     * @param string $password
     */
    public function __construct(
        $type,
        $url = '',
        $workflow = '',
        $projectKey = '',
        $username = '',
        $password = '',
        $uid = ''
    ) {
        parent::__construct($type);
        $this->connectorName = 'Localizer Supertext Connector';
        $this->connectorVersion = ExtensionManagementUtility::getExtensionVersion('localizer_supertext');
        $this->uid = (int)$uid;
        $this->setUrl($url);
        $this->setWorkflow($workflow);
        $this->setProjectKey($projectKey);
        $this->setUsername($username);
        $this->setPassword($password);
    }

    /**
     * Checks the Supertext-Server settings like url, project key, login and password.
     * By default will close connection after check.
     * If there is any existing connection at check time this will be closed prior to check
     *
     * @param bool $closeConnectionAfterCheck
     * @return bool
     * @throws \Exception
     */
    public function areSettingsValid($closeConnectionAfterCheck = true)
    {
        if ($this->isConnected()) {
            $this->disconnect();
        }
        $areValid = $this->connect();
        if ($closeConnectionAfterCheck === true) {
            if ($areValid === true) {
                $this->disconnect();
            }
        }

        return $areValid;
    }

    /**
     * Checks if the token is set
     *
     * @return boolean True if the token is a non empty string, false otherwise
     */
    public function isConnected()
    {
        return !empty($this->token);
    }

    public function disconnect()
    {
        if (!$this->isConnected()) {
            return;
        }

        $this->token = null;
    }

    /**
     * Tries to connect to the Supertext-Server using the plugin parameters
     *
     * @return boolean true if the connection is successful, false otherwise
     * @throws \Exception This Exception contains details of an eventual error
     */
    public function connect()
    {
        if ($this->doesLocalizerExist()) {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $request = $requestFactory->request(
                $this->url . '/v1/accountcheck',
                'GET',
                [
                    'headers' => [
                        'User-Agent' => 'TYPO3 localizer_supertext 9.0.0',
                        'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->projectKey),
                        'Content-Type' => 'text/json',
                    ]
                ]
            );
            if ($request->getStatusCode() === 200) {
                $content = json_decode($request->getBody(), true);
                if ($content) {
                    $this->setToken($this->projectKey);
                }
            }
            return $this->isConnected();
        } else {
            throw new \Exception(
                'No Supertext-Server found at given URL ' . $this->url . '. Either the URL is wrong or Supertext-Server is not active!'
            );
        }
    }

    /**
     * @return bool
     */
    public function doesLocalizerExist()
    {
        $doesExist = false;
        $response = file_get_contents($this->url . '/v1/servertest');
        if ($response !== '') {
            $timeStamp = strtotime(json_decode($response));
            $doesExist = $timeStamp > 0 && $timeStamp <= time();
        }
        return $doesExist;
    }

    /**
     * @param resource $curl
     * @param string $content
     * @param string $methodName
     * @throws \Exception
     */
    private function checkResponse($curl, $content, $methodName = '')
    {
        $http_status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $this->lastError = '';
        if ($http_status_code != 200 && $http_status_code != 204) {
            $details = json_decode($content, true);
            if (is_array($details) === false) {
                $details = (array)$details;
            }
            $details['http_status_code'] = $http_status_code;
            if (curl_errno($curl) !== CURLE_OK) {
                $details['curl_error'] = curl_error($curl);
            }

            $this->lastError = $content;

            throw new \Exception(
                'Communication error with the Supertext-Server, see the details : (' . var_export(
                    $details,
                    true
                ) . ') and see the curl object : (' . var_export(
                    $curl,
                    true
                ) . ') and see the content : (' . var_export(
                    $content,
                    true
                ) . ') and see the calling method : (' . var_export(
                    $methodName,
                    true
                ) . ')'
            );
        }
    }

    /**
     * @param string $sourceLanguage
     * @throws \Exception
     */
    public function setSourceLanguage($sourceLanguage)
    {
        if ($sourceLanguage !== '') {
            $projectLanguages = $this->getProjectLanguages();
            if (isset($projectLanguages[$sourceLanguage])) {
                $this->sourceLanguage = $sourceLanguage;
            } else {
                throw new \Exception(
                    'Source language ' . $sourceLanguage . ' not specified for this project ' .
                    $this->projectKey . '. Allowed ' . join(' ', array_keys($projectLanguages))
                );
            }
        }
    }

    /**
     * Calls the Supertext-Server API to retrieve the Supertext-Server project source and target
     * languages
     *
     * @return array the language pairs available in the Supertext-Server project like 'source' => 'target1' => 1
     *                                                                                   'target2' => 1
     *                                                                                   'targetX' => 1
     * @throws \Exception This Exception contains details of an eventual error
     */
    public function getProjectLanguages()
    {
        if ($this->projectLanguages === null) {
            $array = $this->getProjectInformation();
            $target = [];
            if (is_array($array['targetLocales'])) {
                foreach ($array['targetLocales'] as $num => $targetLocale) {
                    $target[$targetLocale] = 1;
                }
                $this->projectLanguages[$array['sourceLocale']] = $target;
            }
        }
        return $this->projectLanguages;
    }

    /**
     * @param bool $asJson
     * @return string|array
     * @throws \Exception
     */
    public function getProjectInformation($asJson = false)
    {
        if ($this->projectInformation === null) {
            if (!$this->isConnected()) {
                $this->connect();
            }

            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $request = $requestFactory->request(
                $this->url . '/v1/accountcheck',
                'GET',
                [
                    'headers' => [
                        'User-Agent' => 'TYPO3 localizer_supertext 9.0.0',
                        'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->projectKey),
                        'Content-Type' => 'text/json',
                    ],
                    'api_username' => $this->username,
                    'api_token' => $this->projectKey
                ]
            );
            if ($request->getStatusCode() === 200) {
                if (strpos($request->getHeaderLine('Content-Type'), 'application/json') === 0) {
                    $this->content = json_decode($request->getBody(), true);
                    /*foreach ($this->content['data'] as $key => $data) {
                        DebugUtility::debug($data);
                    }*/
                }
                return true;
            }

            $this->projectInformation = $this->content;
        }

        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $request = $requestFactory->request(
            $this->url . '/v1/accountcheck',
            'GET',
            [
                'headers' => [
                    'User-Agent' => 'TYPO3 localizer_supertext 9.0.0',
                    'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->projectKey),
                    'Content-Type' => 'text/json',
                ],
                'api_username' => $this->username,
                'api_token' => $this->projectKey
            ]
        );
        if ($request->getStatusCode() === 200) {
            if (strpos($request->getHeaderLine('Content-Type'), 'application/json') === 0) {
                $this->content = json_decode($request->getBody(), true);
                /*foreach ($this->content['data'] as $key => $data) {
                    DebugUtility::debug($data);
                }*/
            }
            return true;
        }
        return $asJson === true ? $this->projectInformation : json_decode($this->projectInformation, true);
    }

    /**
     *
     * Instructs the Supertext-Server to look for translated files in the Supertext-Server "out" directory.
     * If translated files are found, these will be aligned with the source file for the purpose of pretranslation.
     *
     * @param array $align
     * @throws \Exception
     */
    public function setAlign(array $align)
    {
        $this->align = $this->validateTargetLocales($align);
    }

    /**
     * @param array $locales
     * @return array validated locales should be same as input otherwise an exception will be thrown
     * @throws \Exception
     */
    private function validateTargetLocales(array $locales)
    {
        $validateLocales = [];
        $sourceLanguage = $this->getSourceLanguage();
        $projectLanguages = $this->getProjectLanguages();
        foreach ($locales as $locale) {
            if (isset($projectLanguages[$sourceLanguage][$locale])) {
                $validateLocales[] = $locale;
            } else {
                throw new \Exception(
                    $locale . ' not defined for this project ' . $this->projectKey
                    . '. Available locales ' . join(' ', array_keys($projectLanguages[$sourceLanguage]))
                );
            }
        }

        return $validateLocales;
    }

    /**
     * Determine the source language for a Supertext-Server project.
     * Will throw an exception if there are more so the source ha to be set
     *
     * @return string the source language
     * @throws \Exception
     */
    public function getSourceLanguage()
    {
        if ($this->sourceLanguage === '') {
            $projectLanguages = $this->getProjectLanguages();
            $sourceLanguages = array_keys($projectLanguages);
            if (count($sourceLanguages) === 1) {
                $this->sourceLanguage = $sourceLanguages[0];
            } else {
                throw new \Exception(
                    'For this project ' . $this->projectKey
                    . ' is more than one source language available. Please specify ' . join(' ', $sourceLanguages)
                );
            }
        }

        return $this->sourceLanguage;
    }

    /**
     * Deletes the specified file in the Supertext-Server
     *
     * @param String $filename Name of the file you wish to delete
     * @param String $source source language of the file
     * @throws \Exception This Exception contains details of an eventual error
     */
    public function deleteFile($filename, $source)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $curl = curl_init();
        curl_setopt(
            $curl,
            CURLOPT_URL,
            $this->url .
            '/api/files/file?token=' . urlencode($this->token) .
            '&locale=' . $source . '&filename=' . urlencode($filename) .
            '&folder='
        );
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
        $content = curl_exec($curl);

        $this->checkResponse($curl, $content, 'deleteFile');
    }

    /**
     * Retrieves work progress of the Supertext-Server for the specified files
     *
     * @param mixed $files Can be an array containing a list of file-names or false if you do no want to filter
     * (false by default)
     * @param int $skip Optional number, default is 0. Used for pagination. The files to skip.
     * @param int $count Optional number, default is 100. Used for pagination and indicates the total number of files
     *                   to return from this call. Make sure to specify a limit corresponding to your page
     *                   size (e.g. 100).
     * @return array corresponding to the json returned by the Supertext-Server API
     * @throws \Exception This Exception contains details of an eventual error
     */
    public function getWorkProgress($files = false, $skip = null, $count = null)
    {
        $response = [];

        if (!$this->isConnected()) {
            $this->connect();
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(CONSTANTS::TABLE_EXPORTDATA_MM);

        $deletedRestriction = GeneralUtility::makeInstance(DeletedRestriction::class);

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add($deletedRestriction);

        $cart = $queryBuilder->select('*')
            ->from(CONSTANTS::TABLE_EXPORTDATA_MM)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($this->uid, PDO::PARAM_INT))
            )
            ->setMaxResults(1)
            ->execute()
            ->fetch();

        if (!empty($cart) && !empty($cart['supertextid'])) {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $request = $requestFactory->request(
                $this->url . '/v1/order/' . $cart['supertextid'],
                'GET',
                [
                    'headers' => [
                        'User-Agent' => 'TYPO3 localizer_supertext 9.0.0',
                        'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->projectKey),
                        'Content-Type' => 'text/json',
                    ],
                    'api_username' => $this->username,
                    'api_token' => $this->projectKey
                ]
            );
            if ($request->getStatusCode() === 200) {
                $content = json_decode($request->getBody(), true);
                if ($content['Status'] === 'Delivered') {
                    if ($content['Files']) {
                        foreach ($content['Files'] as $file) {
                            if ($file['DocumentType'] === 'Final') {
                                $finalFile = $file['Id'] . '/' . $file['Name'];
                            }
                        }
                    }
                    $response['files'] = [
                        [
                            'status' => Constants::API_TRANSLATION_STATUS_TRANSLATED,
                            'file' => $finalFile,
                        ],
                    ];
                }
            }
        }
        return $response;
    }

    /**
     * Downloads the specified file
     *
     * @param String $filename Name of the file you wish to retrieve
     * @param String $folder Name of the folder where the file is located (usually the target language)
     * @return String The content of the file
     * @throws \Exception This Exception contains details of an eventual error
     */
    public function getFile($filename, $folder)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $content = '';

        $folder = GeneralUtility::trimExplode('\\', $folder);
        $folder = $folder[1];

        if (!empty($filename) && !empty($folder)) {
            /** @var $requestFactory RequestFactory **/
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            /** @var $fileRequest Response **/
            $fileRequest = $requestFactory->request(
                str_replace('/api', '', $this->url) . '/FileDownloads/File/' . $folder . '/' . $filename,
                'GET',
                [
                    'headers' => [
                        'User-Agent' => 'TYPO3 localizer_supertext 9.0.0',
                        'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->projectKey),
                        'Content-Type' => 'text/json',
                    ],
                    'api_username' => $this->username,
                    'api_token' => $this->projectKey
                ]
            );
            if ($fileRequest->getStatusCode() === 200) {
                $content = (string) $fileRequest->getBody();
            }
        }
        return $content;
    }

    /**
     * Tells the Supertext-Server to scan its files
     *
     * @throws \Exception This Exception contains details of an eventual error
     */
    public function scanFiles()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $curl,
            CURLOPT_URL,
            $this->url .
            '/api/files/operations/scan?token=' . urlencode($this->token)
        );
        curl_setopt($curl, CURLOPT_PUT, 1);
        $content = curl_exec($curl);

        $this->checkResponse($curl, $content, 'scanFiles');
    }

    /**
     * Asks to the Supertext-Server if a scan is required
     *
     * @return boolean True if a scan is required, false otherwise
     * @throws \Exception This Exception contains details of an eventual error
     */
    public function scanRequired()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $curl,
            CURLOPT_URL,
            $this->url .
            '/api/files/status?token=' . urlencode($this->token)
        );
        $content = curl_exec($curl);

        $this->checkResponse($curl, $content, 'scanRequired');

        $array = json_decode($content, true);
        if (is_array($array) && isset($array['scanRequired'])) {
            return (boolean)$array['scanRequired'];
        } else {
            throw new \Exception('unexpected result from: scan required');
        }
    }

    /**
     * This method empties the sandbox.
     *
     * If you organise your files in sub directories such as in "folder1000\file1.dox", etc. you may selectively empty
     * the sandbox by folder ("directory name" set to "folder1000").
     *
     * @param string $directoryName
     * @throws \Exception
     */
    public function sandboxClear($directoryName = '')
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $curl = curl_init();
        $url = $this->url .
            '/api/files/directory?token=' . urlencode($this->token) .
            '&locale=sandbox';

        if ($directoryName !== '') {
            $url .= '&directoryname=' . urlencode((string)$directoryName);
        }

        curl_setopt(
            $curl,
            CURLOPT_URL,
            $url
        );
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
        $content = curl_exec($curl);

        $this->checkResponse($curl, $content, 'sandBoxClear');
    }

    /**
     * Sends 1 file to the Supertext-Server 'in' folder
     *
     * @param String $fileContent The content of the file you wish to send
     * @param String $fileName Name the file will have in the Supertext-Server
     * @param string $source Source language of the file
     * @param bool $attachInstruction
     * @throws \Exception This Exception contains details of an eventual error
     */
    public function sendFile($fileContent, $fileName, $source, $attachInstruction = true)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        /*if ($attachInstruction === true) {
            $this->sendInstructions($fileName, $source);
        }*/

        $fh = fopen('php://temp/maxmemory:256000', 'w');
        if ($fh) {
            fwrite($fh, $fileContent);
        }

        $fileRequestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $fileRequest = $fileRequestFactory->request(
            $this->url . '/v1/files/files',
            'POST',
            [
                'headers' => [
                    'User-Agent' => 'TYPO3 localizer_supertext 9.0.0',
                    'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->projectKey),
                ],
                'multipart' => [
                    [
                        'Content-type' => 'multipart/form-data',
                        'name' => 'file',
                        'contents' => $fh,
                        'filename' => $fileName,
                    ],
                    [ 'name' => 'ElementId', 'contents' => 0 ],
                    [ 'name' => 'ElementTypeId', 'contents' => 2],
                    [ 'name' => 'DocumentTypeId', 'contents' => 1]
                ]
            ]
        );
        if ($fileRequest->getStatusCode() === 200) {
            $fileContent = json_decode($fileRequest->getBody(), true)[0];
            $fileId = (int)$fileContent['Id'];
            if ($fileId > 0) {
                $orderRequestFactory = GeneralUtility::makeInstance(RequestFactory::class);
                $referenceData = substr(GeneralUtility::makeInstance(Md5PasswordHash::class)->getHashedPassword($fileId), 0, 32);
                $options = [
                    'CallbackUrl' => 'none yet',
                    'ContentType' => 'text\/html',
                    'DeliveryId' => 1,
                    'OrderName' => $fileName,
                    'OrderTypeId' => 6,
                    'ReferenceData' => $referenceData,
                    'SystemName' => 'TYPO3',
                    'SystemVersion' => VersionNumberUtility::getCurrentTypo3Version(),
                    'ComponentName' => $this->connectorName,
                    'ComponentVersion' => $this->connectorVersion,
                    'SourceLang' => $source,
                    'TargetLanguages' => $this->locales,
                    'Files' => [
                        [
                            'Id' => $fileId,
                            'Comment' => 'Comment'
                        ]
                    ]
                ];
                $orderRequest = $orderRequestFactory->request(
                    $this->url . '/v1.1/translation/order',
                    'POST',
                    [
                        'headers' => [
                            'User-Agent' => 'TYPO3 localizer_supertext 9.0.0',
                            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->projectKey),
                            'Content-Type' => 'text/json',
                        ],
                        'body' => json_encode($options)
                    ]
                );
                $orderContent = json_decode($orderRequest->getBody(), true)[0];
                $orderId = (int)$orderContent['Id'];
                if ($orderId > 0 && $orderContent['ReferenceData'] === $referenceData) {
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(CONSTANTS::TABLE_EXPORTDATA_MM);
                    $queryBuilder
                        ->update(CONSTANTS::TABLE_EXPORTDATA_MM, 'export')
                        ->where(
                            $queryBuilder->expr()->eq('export.uid', $queryBuilder->createNamedParameter($this->uid))
                        )
                        ->set('export.identifier', $referenceData)
                        ->set('export.supertextid', $orderId)
                        ->execute();
                }
            }
        }
    }

    /**
     * (GET) /api/async/operation/status?token=&opid=
     *
     * @param string $operationId
     */

    /**
     *
     * Request counts/cost
     *
     * (@see http://documents.wordbee.com/display/bb/API+-+Sandbox+-+Counts+and+cost
     *
     * @param bool $includeCost
     * @return string returns a JSON object with property “op id” and which identifies the asynchronous operation
     * @throws \Exception
     */
    public function sandboxRequestCostAndCounts($includeCost = false)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $curl,
            CURLOPT_URL,
            $this->url .
            '/api/files/operations/sandbox/count?token=' . urlencode($this->token) .
            '&getcost=' . ($includeCost === true ? 'true' : 'false')
        );
        curl_setopt($curl, CURLOPT_PUT, 1);
        $operationId = curl_exec($curl);

        $this->checkResponse($curl, $operationId, 'sandboxRequestCostAndCounts');


        return $operationId;
    }

    /**
     * @param $operationId
     * @return string JSON string
     * @throws \Exception
     * @see http://documents.wordbee.com/display/bb/API+-+Sandbox+-+Counts+and+cost Returns
     */
    public function sandboxGetAsynchronousCostAndCountsResult($operationId)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $curl,
            CURLOPT_URL,
            $this->url .
            '/api/async/operation/status?token=' . urlencode($this->token) .
            '&opid=' . $operationId
        );
        $content = curl_exec($curl);

        $this->checkResponse($curl, $content, 'sandboxGetAsynchronousCostAndCountsResult');

        return $content;
    }

    /**
     * This method copies all sandbox content for regular translation.
     * This is equivalent to sending all the source content one by one to the Supertext-Server using the regular method.
     */
    public function sandboxCommitContent()
    {
        if ($this->isAlignSet()) {
            throw new \Exception(
                'Sandbox alignment limitation. ' .
                'Clear the sandbox and copy source content, instructions and translated content again to the Supertext-Server. ' .
                'For further information read http://documents.wordbee.com/display/bb/API+-+Sandbox+-+Commit+content'
            );
        } else {
            if (!$this->isConnected()) {
                $this->connect();
            }
            $content = [
                'token' => $this->token,
                'locale1' => 'sandbox',
                'locale2' => $this->getSourceLanguage(),
            ];
            $json = json_encode($content);

            $curl = curl_init();
            curl_setopt(
                $curl,
                CURLOPT_URL,
                $this->url .
                '/api/files/copy'
            );

            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
            $content = curl_exec($curl);

            $this->checkResponse($curl, $content, 'sandboxCommitContent');
        }
    }

}
