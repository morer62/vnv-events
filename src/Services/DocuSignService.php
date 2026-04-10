<?php

namespace App\Services;

use App\Utils\ErrorLogging;
use Exception;
use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Client\ApiException;
use DocuSign\eSign\Configuration;
use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Api\EnvelopesApi\CreateEnvelopeOptions;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Text;
use DocuSign\eSign\Model\DateSigned;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Api\EnvelopesApi\GetRecipientViewOptions;
use DocuSign\eSign\Model\RecipientViewRequest;

class DocuSignService
{
    private string $integratorKey;
    private string $userId;
    private string $accountId;
    private string $basePath;
    private string $oAuthBasePath;
    private bool $isProduction;
    private ?ApiClient $apiClient = null;
    private string $projectRoot;

    public function __construct()
    {
        $this->integratorKey = $_ENV["DOCUSIGN_INTEGRATOR_KEY"] ?? "";
        $this->userId = $_ENV["DOCUSIGN_USER_ID"] ?? "";
        $this->accountId = $_ENV["DOCUSIGN_ACCOUNT_ID"] ?? "";
        $this->isProduction = ($_ENV["DOCUSIGN_ENVIRONMENT"] ?? "demo") === "production";
        
        $this->projectRoot = dirname(__DIR__, 2);
        
        if ($this->isProduction) {
            $this->basePath = "https://www.docusign.net/restapi";
            $this->oAuthBasePath = "account.docusign.com";
        } else {
            $this->basePath = "https://demo.docusign.net/restapi";
            $this->oAuthBasePath = "account-d.docusign.com";
        }
    }

    /**
     * Verifica si DocuSign está configurado
     */
    public function isConfigured(): bool
    {
        return !empty($this->integratorKey) && 
               !empty($this->userId) && 
               !empty($this->accountId);
    }

    /**
     * Obtiene o crea el ApiClient de DocuSign
     */
    private function getApiClient(): ?ApiClient
    {
        if ($this->apiClient !== null) {
            return $this->apiClient;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                return null;
            }
            
            $config = new Configuration();
            $config->setHost($this->basePath);
            $config->addDefaultHeader('Authorization', 'Bearer ' . $accessToken);
            
            $this->apiClient = new ApiClient($config);
            
            return $this->apiClient;

        } catch (Exception $e) {
            return null;
        }
    }

    private function getAccessToken(): ?string
    {
        $privateKeyPath = $_ENV["DOCUSIGN_PRIVATE_KEY_PATH"] ?? 'storage/docusign/private.key';
        
        if (file_exists($privateKeyPath)) {
            // Ruta absoluta o relativa válida
        } else {
            $absolutePath = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $privateKeyPath);
            
            if (file_exists($absolutePath)) {
                $privateKeyPath = $absolutePath;
            } else {
                $cwdPath = getcwd() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $privateKeyPath);
                if (file_exists($cwdPath)) {
                    $privateKeyPath = $cwdPath;
                } else {
                    $parentPath = dirname(getcwd()) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $privateKeyPath);
                    if (file_exists($parentPath)) {
                        $privateKeyPath = $parentPath;
                    }
                }
            }
        }
        
        if (!file_exists($privateKeyPath)) {
            return null;
        }

        try {
            $privateKey = file_get_contents($privateKeyPath, true);
            
            $config = new Configuration();
            $config->setHost($this->basePath);
            $apiClient = new ApiClient($config);
            $apiClient->getOAuth()->setOAuthBasePath($this->oAuthBasePath);
            $scope = "signature";
            
            $response = $apiClient->requestJWTUserToken(
                $this->integratorKey,
                $this->userId,
                $privateKey,
                $scope
            );
            
            if (is_array($response) && isset($response[0])) {
                $oauthToken = $response[0];
                if (is_object($oauthToken) && method_exists($oauthToken, 'getAccessToken')) {
                    $accessToken = $oauthToken->getAccessToken();
                    if ($accessToken) {
                        return $accessToken;
                    }
                }
            }
            
            return null;

        } catch (ApiException $e) {
            $errorMessage = $e->getMessage();
            
            if (strpos($errorMessage, "Connection was reset") !== false || 
                strpos($errorMessage, "Recv failure") !== false ||
                strpos($errorMessage, "Connection timed out") !== false) {
                sleep(2);
                
                try {
                    $response = $apiClient->requestJWTUserToken(
                        $this->integratorKey,
                        $this->userId,
                        $privateKey,
                        $scope
                    );
                    
                    if (is_array($response) && isset($response[0])) {
                        $oauthToken = $response[0];
                        if (is_object($oauthToken) && method_exists($oauthToken, 'getAccessToken')) {
                            $accessToken = $oauthToken->getAccessToken();
                            if ($accessToken) {
                                return $accessToken;
                            }
                        }
                    }
                } catch (Exception $retryException) {
                    // Retry failed
                }
            }
            
            return null;
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
            if (strpos($errorMessage, "Connection was reset") !== false || 
                strpos($errorMessage, "Recv failure") !== false ||
                strpos($errorMessage, "Connection timed out") !== false) {
                sleep(2);
                
                try {
                    $response = $apiClient->requestJWTUserToken(
                        $this->integratorKey,
                        $this->userId,
                        $privateKey,
                        $scope
                    );
                    
                    if (is_array($response) && isset($response[0])) {
                        $oauthToken = $response[0];
                        if (is_object($oauthToken) && method_exists($oauthToken, 'getAccessToken')) {
                            $accessToken = $oauthToken->getAccessToken();
                            if ($accessToken) {
                                return $accessToken;
                            }
                        }
                    }
                } catch (Exception $retryException) {
                    // Retry failed
                }
            }
            
            return null;
        }
    }

    private function getPdfPageCount(string $pdfPath): int
    {
        try {
            $content = file_get_contents($pdfPath);
            if ($content === false) {
                return 1;
            }
            
            $count = 0;
            $matches = [];
            preg_match_all("/\/Count\s+(\d+)/", $content, $matches);
            
            if (!empty($matches[1])) {
                $count = max($matches[1]);
            } else {
                preg_match_all("/\/Page\W/", $content, $matches);
                $count = count($matches[0]);
            }
            
            return max(1, (int)$count);
        } catch (Exception $e) {
            return 1;
        }
    }

    public function createEnvelope(string $pdfPath, string $signerEmail, string $signerName, int $orderId, ?string $returnToken = null): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $apiClient = $this->getApiClient();
            if (!$apiClient) {
                return null;
            }

            $pdfContent = file_get_contents($pdfPath);
            if ($pdfContent === false) {
                return null;
            }

            $base64Pdf = base64_encode($pdfContent);
            
            if (empty($base64Pdf) || strlen($base64Pdf) < 100) {
                return null;
            }

            $document = new Document([
                'document_base64' => $base64Pdf,
                'name' => "Contract_Order_VNV-341{$orderId}.pdf",
                'file_extension' => 'pdf',
                'document_id' => '1'
            ]);

            $lastPageNumber = $this->getPdfPageCount($pdfPath);
            
            $signHere = new SignHere([
                'document_id' => '1',
                'page_number' => (string)$lastPageNumber,
                'recipient_id' => '1',
                'x_position' => '100',
                'y_position' => '700',
                'width' => '200',
                'height' => '80'
            ]);

            $dateSigned = new DateSigned([
                'document_id' => '1',
                'page_number' => (string)$lastPageNumber,
                'recipient_id' => '1',
                'x_position' => '300',
                'y_position' => '700',
                'date_format' => 'MM/dd/yyyy'
            ]);

            $timeSigned = new Text([
                'document_id' => '1',
                'page_number' => (string)$lastPageNumber,
                'recipient_id' => '1',
                'x_position' => '450',
                'y_position' => '700',
                'width' => '100',
                'height' => '20',
                'tab_label' => 'Time',
                'value' => date('h:i A'),
                'locked' => 'true',
                'required' => 'false'
            ]);

            $addressLabel = new Text([
                'document_id' => '1',
                'page_number' => (string)$lastPageNumber,
                'recipient_id' => '1',
                'x_position' => '300',
                'y_position' => '630',
                'width' => '250',
                'height' => '15',
                'tab_label' => 'AddressLabel',
                'name' => 'Address:',
                'value' => 'Address:',
                'required' => 'false',
                'locked' => 'true',
                'font' => 'helvetica',
                'font_size' => 'size10'
            ]);

            $address = new Text([
                'document_id' => '1',
                'page_number' => (string)$lastPageNumber,
                'recipient_id' => '1',
                'x_position' => '300',
                'y_position' => '650',
                'width' => '250',
                'height' => '20',
                'tab_label' => 'Address',
                'name' => 'Address',
                'value' => '',
                'required' => 'true',
                'font' => 'helvetica',
                'font_size' => 'size11'
            ]);

            $clientUserId = (string)$orderId . '_' . md5($signerEmail . $orderId);
            
            $signer = new Signer([
                'email' => $signerEmail,
                'name' => $signerName,
                'recipient_id' => '1',
                'routing_order' => '1',
                'client_user_id' => $clientUserId
            ]);

            $signer->settabs(new Tabs([
                'sign_here_tabs' => [$signHere],
                'date_signed_tabs' => [$dateSigned],
                'text_tabs' => [$addressLabel, $address, $timeSigned]
            ]));

            $recipients = new Recipients(['signers' => [$signer]]);

            $envelopeDefinition = new EnvelopeDefinition([
                'email_subject' => "Please sign the contract for Order VNV-341{$orderId}",
                'documents' => [$document],
                'recipients' => $recipients,
                'status' => 'sent'
            ]);

            $envelopesApi = new EnvelopesApi($apiClient);
            $envelopeSummary = $envelopesApi->createEnvelopeWithHttpInfo($this->accountId, $envelopeDefinition);
            $envelopeId = $envelopeSummary[0]->getEnvelopeId();
            
            if (!$envelopeId) {
                return null;
            }

            $recipientViewUrl = $this->getRecipientViewUrl($envelopeId, $signerEmail, $signerName, $clientUserId, $returnToken);
            
            if (!$recipientViewUrl) {
                return null;
            }

            return [
                'envelopeId' => $envelopeId,
                'recipientViewUrl' => $recipientViewUrl
            ];

        } catch (ApiException $e) {
            ErrorLogging::log($e);
            return null;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    private function getRecipientViewUrl(string $envelopeId, string $signerEmail, string $signerName, string $clientUserId, ?string $returnToken = null): ?string
    {
        try {
            $apiClient = $this->getApiClient();
            if (!$apiClient) {
                return null;
            }

            $returnUrl = ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/order-access?docusign_return=1&envelope_id=" . urlencode($envelopeId);
            if ($returnToken) {
                $returnUrl .= "&token=" . urlencode($returnToken);
            }
            
            $recipientViewRequest = new RecipientViewRequest([
                'authentication_method' => 'None',
                'client_user_id' => $clientUserId,
                'recipient_id' => '1',
                'return_url' => $returnUrl,
                'user_name' => $signerName,
                'email' => $signerEmail
            ]);

            $envelopesApi = new EnvelopesApi($apiClient);
            $viewUrl = $envelopesApi->createRecipientViewWithHttpInfo($this->accountId, $envelopeId, $recipientViewRequest);
            
            return $viewUrl[0]->getUrl();

        } catch (ApiException $e) {
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function getSignedDocumentPreviewUrl(string $envelopeId, string $documentName = null): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }
        
        $baseUrl = $this->isProduction 
            ? 'https://apps.docusign.com' 
            : 'https://apps-d.docusign.com';
        
        $documentId = '1';
        $filename = $documentName ? urlencode($documentName) : 'document.pdf';
        
        $previewUrl = $baseUrl . '/api/send/api/accounts/' . $this->accountId . '/envelopes/' . $envelopeId . '/documents/' . $documentId . '/preview/' . $filename;
        
        return $previewUrl;
    }

    public function getEnvelopeStatus(string $envelopeId): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $apiClient = $this->getApiClient();
            if (!$apiClient) {
                return null;
            }

            $envelopesApi = new EnvelopesApi($apiClient);
            $envelope = $envelopesApi->getEnvelope($this->accountId, $envelopeId);
            
            return $envelope->getStatus();
        } catch (Exception $e) {
            return null;
        }
    }

    public function downloadSignedDocument(string $envelopeId, string $savePath): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $envelopeId = trim($envelopeId);
        if (empty($envelopeId)) {
            return false;
        }

        try {
            $apiClient = $this->getApiClient();
            if (!$apiClient) {
                return false;
            }

            $envelopesApi = new EnvelopesApi($apiClient);
            $result = $envelopesApi->getDocumentWithHttpInfo($this->accountId, 'combined', $envelopeId);
            
            if (!is_array($result) || !isset($result[0])) {
                return false;
            }
            
            $documentData = $result[0];
            
            if ($documentData instanceof \SplFileObject) {
                $documentData->rewind();
                $pdfContent = '';
                while (!$documentData->eof()) {
                    $pdfContent .= $documentData->fgets();
                }
            } else {
                $pdfContent = $documentData;
            }
            
            if (empty($pdfContent)) {
                return false;
            }

            $dir = dirname($savePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $bytesWritten = file_put_contents($savePath, $pdfContent);
            
            if ($bytesWritten === false || $bytesWritten === 0) {
                return false;
            }

            return true;

        } catch (ApiException $e) {
            return false;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return false;
        }
    }
}
